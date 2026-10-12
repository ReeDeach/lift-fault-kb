<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\AdminAuth;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef';

    private function dispatch(string $token, ?string $authorization): Response
    {
        $router = new Router();
        $router->get('/api/admin/x', AdminAuth::guard($token, static fn (): Response => Response::json(['ok' => true])));
        $headers = $authorization === null ? [] : ['authorization' => $authorization];

        return $router->dispatch(new Request('GET', '/api/admin/x', headers: $headers));
    }

    public function testAdminApiIsOffWithoutToken(): void
    {
        $this->assertSame(403, $this->dispatch('', 'Bearer anything')->status);
        $this->assertSame(403, $this->dispatch('short', 'Bearer short')->status);
    }

    public function testMissingOrWrongTokenIs401(): void
    {
        $missing = $this->dispatch(self::TOKEN, null);
        $wrong = $this->dispatch(self::TOKEN, 'Bearer ' . self::TOKEN . 'x');
        $basic = $this->dispatch(self::TOKEN, 'Basic ' . self::TOKEN);

        $this->assertSame(401, $missing->status);
        $this->assertSame('Bearer', $missing->headers['WWW-Authenticate']);
        $this->assertSame(401, $wrong->status);
        $this->assertSame(401, $basic->status);
    }

    public function testCorrectTokenReachesHandler(): void
    {
        $response = $this->dispatch(self::TOKEN, 'bearer ' . self::TOKEN);

        $this->assertSame(200, $response->status);
    }
}
