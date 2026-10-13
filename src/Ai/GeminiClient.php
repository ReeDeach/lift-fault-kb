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
 */
final class GeminiClient implements LanguageModel
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Коды ответа, после которых имеет смысл попробовать другую модель */
    private const TRY_NEXT = [404, 408, 429, 500, 502, 503, 504];

    /** Сколько секунд пропускать модель после сбоя, чтобы каждый запрос не ждал её таймаут заново */
    private const COOLDOWN = 300;

    /** @var Closure(string, array<string, string>, string, int): array{status: int, body: string} */
    private readonly Closure $transport;

    /**
     * @param list<string> $models основная модель и запасные по порядку
     * @param (Closure(string, array<string, string>, string, int): array{status: int, body: string})|null $transport
     *        отправка POST-запроса; в тестах подменяется
     * @param string|null $stateFile где запоминать сбойные модели (null — не запоминать)
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $models,
        private readonly int $timeout = 12,
        ?Closure $transport = null,
        private readonly ?string $stateFile = null,
    ) {
        $this->transport = $transport ?? self::curl(...);
    }

    public static function fromEnv(): ?self
    {
        $key = Env::get('AI_API_KEY');
        if ($key === '') {
            return null;
        }

        $models = array_values(array_filter(array_map('trim', [
            Env::get('AI_MODEL', 'gemini-flash-lite-latest'),
            ...explode(',', Env::get('AI_FALLBACK_MODELS', 'gemini-3.5-flash')),
        ])));

        return new self(
            $key,
            array_values(array_unique($models)),
            max(3, (int) Env::get('AI_TIMEOUT', '15')),
            null,
            sys_get_temp_dir() . '/lift-kb-ai-models.json',
        );
    }

    public function generate(string $system, string $prompt, array $schema): array
    {
        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                'temperature' => 0.2,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $errors = [];
        foreach ($this->order() as $model) {
            $response = ($this->transport)(
                sprintf(self::ENDPOINT, rawurlencode($model)),
                ['Content-Type' => 'application/json', 'X-goog-api-key' => $this->apiKey],
                $body,
                $this->timeout,
            );

            if ($response['status'] === 200) {
                $this->remember($model, false);

                return ['data' => $this->parse($response['body']), 'model' => $model];
            }

            $errors[] = $model . ': ' . ($response['status'] === 0 ? 'нет ответа' : 'HTTP ' . $response['status']);
            if (!in_array($response['status'], [0, ...self::TRY_NEXT], true)) {
                break;
            }
            $this->remember($model, true);
        }

        throw new AiException('ИИ недоступен (' . implode('; ', $errors) . ')');
    }

    /**
     * Модели в порядке попыток: недавно сбойные — в конец, чтобы не ждать их таймаут
     * на каждом запросе. Совсем их не убираем: если упали все, пробуем всё равно.
     *
     * @return list<string>
     */
    private function order(): array
    {
        $failed = $this->state();
        $now = time();
        $healthy = array_values(array_filter($this->models, static fn (string $m): bool => ($failed[$m] ?? 0) + self::COOLDOWN < $now));
        $sick = array_values(array_diff($this->models, $healthy));

        return [...$healthy, ...$sick];
    }

    private function remember(string $model, bool $failed): void
    {
        if ($this->stateFile === null) {
            return;
        }
        $state = $this->state();
        if ($failed) {
            $state[$model] = time();
        } elseif (!isset($state[$model])) {
            return;
        } else {
            unset($state[$model]);
        }
        @file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }

    /**
     * @return array<string, int> модель => время последнего сбоя
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
