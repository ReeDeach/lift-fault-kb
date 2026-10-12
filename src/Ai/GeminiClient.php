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

    /** @var Closure(string, array<string, string>, string, int): array{status: int, body: string} */
    private readonly Closure $transport;

    /**
     * @param list<string> $models основная модель и запасные по порядку
     * @param (Closure(string, array<string, string>, string, int): array{status: int, body: string})|null $transport
     *        отправка POST-запроса; в тестах подменяется
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $models,
        private readonly int $timeout = 25,
        ?Closure $transport = null,
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
            Env::get('AI_MODEL', 'gemini-flash-latest'),
            ...explode(',', Env::get('AI_FALLBACK_MODELS', 'gemini-flash-lite-latest')),
        ])));

        return new self($key, array_values(array_unique($models)), (int) Env::get('AI_TIMEOUT', '25'));
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
        foreach ($this->models as $model) {
            $response = ($this->transport)(
                sprintf(self::ENDPOINT, rawurlencode($model)),
                ['Content-Type' => 'application/json', 'X-goog-api-key' => $this->apiKey],
                $body,
                $this->timeout,
            );

            if ($response['status'] === 200) {
                return ['data' => $this->parse($response['body']), 'model' => $model];
            }

            $errors[] = $model . ': ' . ($response['status'] === 0 ? 'нет ответа' : 'HTTP ' . $response['status']);
            if (!in_array($response['status'], [0, ...self::TRY_NEXT], true)) {
                break;
            }
        }

        throw new AiException('ИИ недоступен (' . implode('; ', $errors) . ')');
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
