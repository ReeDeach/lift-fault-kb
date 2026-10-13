<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Support\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private PDO $pdo;
    private string $action;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан.');
        }
        $this->pdo = Connection::fromEnv();
        $this->action = 'test' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket LIKE ?')->execute([$this->action . '%']);
        }
    }

    private function request(string $user): Request
    {
        return new Request('POST', '/api/diagnose', headers: ['x-demo-user' => $user]);
    }

    public function testUserLimitPerHour(): void
    {
        $limiter = new RateLimiter($this->pdo, 2, 100);
        $limiter->hit($this->request('a'), $this->action);
        $limiter->hit($this->request('a'), $this->action);
        $limiter->hit($this->request('b'), $this->action);

        try {
            $limiter->hit($this->request('a'), $this->action);
            $this->fail('Третий запрос за час должен быть отклонён');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('2 в час', $e->getMessage());
        }
    }

    public function testDailyLimitForEveryone(): void
    {
        $limiter = new RateLimiter($this->pdo, 100, 2);
        $limiter->hit($this->request('a'), $this->action);
        $limiter->hit($this->request('b'), $this->action);

        $this->expectException(HttpException::class);
        $limiter->hit($this->request('c'), $this->action);
    }

    public function testTailscaleAccountWinsOverDemoHeader(): void
    {
        $request = new Request('POST', '/', headers: ['tailscale-user-login' => 'Ivan@Example.com', 'x-demo-user' => 'spoof']);

        $this->assertSame('ivan@example.com', RateLimiter::who($request));
    }
}
