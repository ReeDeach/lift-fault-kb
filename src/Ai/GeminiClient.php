<?php

declare(strict_types=1);

namespace App\Ai;

use App\Support\Env;
use Closure;

/**
 * Клиент Gemini API (generateContent) со строгим JSON-ответом по схеме.
 *
 * Модели пробуются по очереди: если основная перегружена (429, 5xx), не отвечает или снята
 * с публикации (404), запрос уходит следующей из AI_FALLBACK_MODELS. Ошибки ключа и запроса
 * (400, 401, 403) не повторяются: от смены модели они не исправятся.
 *
 * «Перегружена» (503) Google отвечает за секунду-другую, и через секунду та же модель часто
 * уже отвечает: такой ответ сначала повторяется на той же модели ($retries раз), а уже потом
 * запрос уходит запасной. Все попытки вместе укладываются в $budget секунд.
 *
 * Сбойная модель ставится на паузу: после 503 — на минуту, без ответа — на 15 минут, после 429
 * (исчерпан лимит бесплатного ключа) — до сброса квоты, сколько указал Google. Модели на паузе
 * не пробуются; если на паузе все, ответ сразу приходит без ИИ, а не через таймауты.
 *
 * Модели Gemini 3 думают перед ответом; для справочника хватает короткого размышления
 * (thinkingLevel: low), а ответ приходит в разы быстрее.
 */
final class GeminiClient implements LanguageModel
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Коды ответа, после которых имеет смысл попробовать другую модель */
    private const TRY_NEXT = [404, 408, 429, 500, 502, 503, 504];

    /** Сколько секунд пропускать модель после сбоя, чтобы каждый запрос не ждал её таймаут заново */
    private const COOLDOWN = 900;

    /** Пауза после «перегружена» (503): обычно это ненадолго */
    private const COOLDOWN_BUSY = 60;

    /** Предел паузы после 429: дольше суток не ждём, даже если Google просит */
    private const COOLDOWN_MAX = 86400;

    /** Быстрые ответы «перегружена», после которых стоит повторить ту же модель */
    private const RETRY_SAME = [503];

    /** @var Closure(string, array<string, string>, string, int): array{status: int, body: string} */
    private readonly Closure $transport;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param list<string> $models основная модель и запасные по порядку
     * @param (Closure(string, array<string, string>, string, int): array{status: int, body: string})|null $transport
     *        отправка POST-запроса; в тестах подменяется
     * @param string|null $stateFile где запоминать сбойные модели (null — не запоминать)
     * @param int $retries сколько раз повторить ту же модель после 429/503
     * @param int $budget предел времени на все попытки, секунд (0 — без предела)
     * @param (Closure(float): void)|null $sleep пауза перед повтором; в тестах подменяется
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $models,
        private readonly int $timeout = 12,
        ?Closure $transport = null,
        private readonly ?string $stateFile = null,
        private readonly int $retries = 0,
        private readonly int $budget = 0,
        ?Closure $sleep = null,
    ) {
        $this->transport = $transport ?? self::curl(...);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    public static function fromEnv(): ?self
    {
        $key = Env::get('AI_API_KEY');
        if ($key === '') {
            return null;
        }

        $models = array_values(array_filter(array_map('trim', [
            Env::get('AI_MODEL', 'gemini-3.5-flash'),
            ...explode(',', Env::get('AI_FALLBACK_MODELS', 'gemini-3.1-flash-lite,gemini-3.5-flash-lite')),
        ])));

        $timeout = max(3, (int) Env::get('AI_TIMEOUT', '15'));

        return new self(
            $key,
            array_values(array_unique($models)),
            $timeout,
            null,
            sys_get_temp_dir() . '/lift-kb-ai-models.json',
            2,
            max($timeout, (int) Env::get('AI_BUDGET', '35')),
        );
    }

    public function generate(string $system, string $prompt, array $schema): array
    {
        $deadline = $this->budget > 0 ? microtime(true) + $this->budget : null;
        $errors = [];
        foreach ($this->order() as $model) {
            $body = $this->body($model, $system, $prompt, $schema);
            for ($attempt = 0; ; $attempt++) {
                $left = $deadline === null ? $this->timeout : (int) floor($deadline - microtime(true));
                if ($left < 3) {
                    $errors[] = 'время ожидания вышло';
                    break 2;
                }
                $response = ($this->transport)(
                    sprintf(self::ENDPOINT, rawurlencode($model)),
                    ['Content-Type' => 'application/json', 'X-goog-api-key' => $this->apiKey],
                    $body,
                    min($this->timeout, $left),
                );

                if ($response['status'] === 200) {
                    $this->remember($model, false);

                    return ['data' => $this->parse($response['body']), 'model' => $model];
                }
                if ($attempt < $this->retries && in_array($response['status'], self::RETRY_SAME, true)) {
                    ($this->sleep)(0.8 * ($attempt + 1));
                    continue;
                }
                break;
            }

            $errors[] = $model . ': ' . ($response['status'] === 0 ? 'нет ответа' : 'HTTP ' . $response['status']);
            if (!in_array($response['status'], [0, ...self::TRY_NEXT], true)) {
                break;
            }
            $this->remember($model, true, self::cooldown($response));
        }

        throw new AiException('ИИ недоступен (' . ($errors === [] ? 'все модели на паузе после сбоев' : implode('; ', $errors)) . ')');
    }

    /**
     * На сколько секунд отложить модель после сбоя.
     *
     * @param array{status: int, body: string} $response
     */
    private static function cooldown(array $response): int
    {
        if ($response['status'] === 429) {
            // Google пишет, когда сбросится квота: "retryDelay": "38093s"
            $delay = preg_match('/"retryDelay"\s*:\s*"(\d+)(?:\.\d+)?s"/', $response['body'], $m) ? (int) $m[1] : self::COOLDOWN;

            return max(self::COOLDOWN_BUSY, min(self::COOLDOWN_MAX, $delay));
        }

        return $response['status'] === 503 ? self::COOLDOWN_BUSY : self::COOLDOWN;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function body(string $model, string $system, string $prompt, array $schema): string
    {
        $config = ['responseMimeType' => 'application/json', 'responseSchema' => $schema, 'temperature' => 0.2];
        if (str_starts_with($model, 'gemini-3')) {
            $config['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }

        return json_encode([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => $config,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Модели в порядке попыток без тех, что на паузе после сбоя: каждый запрос не ждёт
     * их таймаут заново. Пустой список — на паузе все.
     *
     * @return list<string>
     */
    private function order(): array
    {
        $paused = $this->state();
        $now = time();

        return array_values(array_filter($this->models, static fn (string $m): bool => ($paused[$m] ?? 0) <= $now));
    }

    private function remember(string $model, bool $failed, int $cooldown = self::COOLDOWN): void
    {
        if ($this->stateFile === null) {
            return;
        }
        $state = $this->state();
        if ($failed) {
            $state[$model] = time() + $cooldown;
        } elseif (!isset($state[$model])) {
            return;
        } else {
            unset($state[$model]);
        }
        @file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }

    /**
     * @return array<string, int> модель => до какого времени она на паузе
     */
    private function state(): array
    {
        if ($this->stateFile === null || !is_file($this->stateFile)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($this->stateFile), true);

        return is_array($data) ? array_filter($data, 'is_int') : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $body): array
    {
        $response = json_decode($body, true);
        $candidate = is_array($response) ? ($response['candidates'][0] ?? null) : null;
        $text = $candidate['content']['parts'][0]['text'] ?? null;

        if (!is_string($text)) {
            $reason = $candidate['finishReason'] ?? $response['promptFeedback']['blockReason'] ?? 'нет текста';
            throw new AiException('Пустой ответ модели: ' . $reason);
        }

        $data = json_decode($text, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new AiException('Ответ модели — не JSON-объект');
        }

        return $data;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 — сетевая ошибка или таймаут
     */
    private static function curl(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_map(static fn (string $k, string $v): string => "$k: $v", array_keys($headers), $headers),
            CURLOPT_POSTFIELDS => $body,
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $result === false ? 0 : $status, 'body' => $result === false ? '' : (string) $result];
    }
}
