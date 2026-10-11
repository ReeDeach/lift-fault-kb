<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesStaticRoute(): void
    {
        $router = (new Router())->get('/api/ping', fn () => Response::json(['pong' => true]));

        $response = $router->dispatch(new Request('GET', '/api/ping'));

        $this->assertSame(200, $response->status);
        $this->assertSame('{"pong":true}', $response->body);
    }

    public function testPassesRouteParameters(): void
    {
        $router = (new Router())->get(
            '/api/models/{id}/codes/{code}',
            fn (Request $r) => Response::json([$r->param('id'), $r->param('code')]),
        );

        $response = $router->dispatch(new Request('GET', '/api/models/7/codes/E05'));

        $this->assertSame('["7","E05"]', $response->body);
    }

    public function testUnknownRouteReturns404(): void
    {
        $response = (new Router())->dispatch(new Request('GET', '/nope'));

        $this->assertSame(404, $response->status);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $router = (new Router())->get('/api/ping', fn () => Response::json([]));

        $response = $router->dispatch(new Request('POST', '/api/ping'));

        $this->assertSame(405, $response->status);
        $this->assertSame('GET', $response->headers['Allow']);
    }

    public function testHandlerExceptionBecomes500WithoutLeakingDetails(): void
    {
        $router = (new Router())->get('/boom', function () {
            throw new \RuntimeException('секретная деталь');
        });

        $response = $router->dispatch(new Request('GET', '/boom'));

        $this->assertSame(500, $response->status);
        $this->assertStringNotContainsString('секретная деталь', $response->body);
    }
}
