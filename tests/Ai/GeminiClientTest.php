<?php

declare(strict_types=1);

namespace Tests\Ai;

use App\Ai\AiException;
use App\Ai\GeminiClient;
use PHPUnit\Framework\TestCase;

/**
 * Клиент Gemini без сети: HTTP-ответы подставляются через transport.
 */
final class GeminiClientTest extends TestCase
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /**
     * @param list<array{status: int, body: string}> $responses
     */
    private function client(array $responses, array $models = ['main', 'spare']): GeminiClient
    {
        $this->requests = [];

        return new GeminiClient('test-key', $models, 5, function (string $url, array $headers, string $body) use (&$responses): array {
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return array_shift($responses);
        });
    }

    private static function ok(string $text): array
    {
        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    public function testSendsSchemaKeyAndSystemInstruction(): void
    {
        $result = $this->client([self::ok('{"answer": "Минск"}')])->generate('Система', 'Вопрос', ['type' => 'OBJECT']);

        $this->assertSame(['data' => ['answer' => 'Минск'], 'model' => 'main'], $result);
        $request = $this->requests[0];
        $this->assertStringEndsWith('/models/main:generateContent', $request['url']);
        $this->assertSame('test-key', $request['headers']['X-goog-api-key']);
        $this->assertStringNotContainsString('test-key', $request['url'], 'Ключ не должен попадать в URL');

        $body = json_decode($request['body'], true);
        $this->assertSame('Система', $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame('Вопрос', $body['contents'][0]['parts'][0]['text']);
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame(['type' => 'OBJECT'], $body['generationConfig']['responseSchema']);
    }

    public function testOverloadedModelFallsBackToNext(): void
    {
        $result = $this->client([['status' => 503, 'body' => '{}'], self::ok('{"a": 1}')])->generate('s', 'p', []);

        $this->assertSame('spare', $result['model']);
        $this->assertCount(2, $this->requests);
    }

    public function testTimeoutAndRemovedModelAlsoFallBack(): void
    {
        $result = $this->client([['status' => 0, 'body' => ''], ['status' => 404, 'body' => '{}'], self::ok('{"a": 1}')], ['a', 'b', 'c'])
            ->generate('s', 'p', []);

        $this->assertSame('c', $result['model']);
    }

    public function testBadKeyIsNotRetriedOnOtherModels(): void
    {
        $client = $this->client([['status' => 403, 'body' => '{}'], self::ok('{"a": 1}')]);

        try {
            $client->generate('s', 'p', []);
            $this->fail('Ожидалось исключение');
        } catch (AiException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
        $this->assertCount(1, $this->requests);
    }

    public function testAllModelsDownThrows(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('main: HTTP 429; spare: HTTP 503');

        $this->client([['status' => 429, 'body' => ''], ['status' => 503, 'body' => '']])->generate('s', 'p', []);
    }

    public function testFailedModelIsSkippedForAWhile(): void
    {
        $state = sys_get_temp_dir() . '/gemini-state-' . bin2hex(random_bytes(4)) . '.json';
        $calls = [];
        $responses = [['status' => 0, 'body' => ''], self::ok('{"a": 1}'), self::ok('{"a": 2}')];
        $client = new GeminiClient('k', ['slow', 'spare'], 5, function (string $url) use (&$calls, &$responses): array {
            $calls[] = $url;

            return array_shift($responses);
        }, $state);

        $this->assertSame('spare', $client->generate('s', 'p', [])['model']);
        // Второй запрос сразу идёт к запасной, не дожидаясь таймаута сбойной
        $this->assertSame('spare', $client->generate('s', 'p', [])['model']);
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('/spare:', $calls[2]);
        @unlink($state);
    }

    public function testOverloadIsRetriedOnSameModelBeforeFallback(): void
    {
        $calls = [];
        $pauses = [];
        $responses = [['status' => 503, 'body' => '{}'], ['status' => 503, 'body' => '{}'], self::ok('{"a": 1}')];
        $client = new GeminiClient('k', ['gemini-3.5-flash', 'spare'], 5, function (string $url, array $h, string $body) use (&$calls, &$responses): array {
            $calls[] = [$url, json_decode($body, true)];

            return array_shift($responses);
        }, null, 2, 0, function (float $s) use (&$pauses): void {
            $pauses[] = $s;
        });

        $this->assertSame('gemini-3.5-flash', $client->generate('s', 'p', [])['model']);
        $this->assertCount(3, $calls);
        $this->assertSame([0.8, 1.6], $pauses);
        $this->assertSame(['thinkingLevel' => 'low'], $calls[0][1]['generationConfig']['thinkingConfig'], 'Gemini 3 — короткое размышление');
    }

    public function testExhaustedQuotaPausesModelUntilResetAndAllPausedFailsAtOnce(): void
    {
        $state = sys_get_temp_dir() . '/gemini-state-' . bin2hex(random_bytes(4)) . '.json';
        $calls = 0;
        $quota = ['status' => 429, 'body' => '{"error": {"details": [{"retryDelay": "38093s"}]}}'];
        $responses = [$quota, ['status' => 0, 'body' => '']];
        $client = new GeminiClient('k', ['main', 'spare'], 5, function () use (&$calls, &$responses): array {
            $calls++;

            return array_shift($responses);
        }, $state, 2);

        try {
            $client->generate('s', 'p', []);
            $this->fail('Ожидалось исключение');
        } catch (AiException) {
        }
        $this->assertSame(2, $calls, '429 не повторяется на той же модели');
        $paused = json_decode((string) file_get_contents($state), true);
        $this->assertGreaterThan(time() + 38000, $paused['main'], 'Пауза до сброса квоты');
        $this->assertLessThanOrEqual(time() + 900, $paused['spare']);

        // Следующий запрос не ждёт таймаутов: обе модели на паузе
        try {
            $client->generate('s', 'p', []);
            $this->fail('Ожидалось исключение');
        } catch (AiException $e) {
            $this->assertStringContainsString('на паузе', $e->getMessage());
        }
        $this->assertSame(2, $calls);
        @unlink($state);
    }

    public function testTimeoutIsNotRetriedOnSameModelAndOldModelsGetNoThinkingConfig(): void
    {
        $result = $this->client([['status' => 0, 'body' => ''], self::ok('{"a": 1}')])->generate('s', 'p', []);

        $this->assertSame('spare', $result['model']);
        $this->assertArrayNotHasKey('thinkingConfig', json_decode($this->requests[0]['body'], true)['generationConfig']);
    }

    public function testBudgetLimitsEachAttempt(): void
    {
        $timeouts = [];
        $client = new GeminiClient('k', ['a', 'b'], 20, function (string $url, array $h, string $b, int $timeout) use (&$timeouts): array {
            $timeouts[] = $timeout;

            return ['status' => 0, 'body' => ''];
        }, null, 0, 10);

        try {
            $client->generate('s', 'p', []);
            $this->fail('Ожидалось исключение');
        } catch (AiException) {
        }
        $this->assertLessThanOrEqual(10, $timeouts[0], 'Таймаут попытки не больше общего предела');
    }

    public function testBlockedOrNonJsonAnswerThrows(): void
    {
        $blocked = ['status' => 200, 'body' => json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']])];

        try {
            $this->client([$blocked], ['main'])->generate('s', 'p', []);
            $this->fail('Ожидалось исключение');
        } catch (AiException $e) {
            $this->assertStringContainsString('SAFETY', $e->getMessage());
        }

        $this->expectException(AiException::class);
        $this->client([self::ok('не json')], ['main'])->generate('s', 'p', []);
    }
}
