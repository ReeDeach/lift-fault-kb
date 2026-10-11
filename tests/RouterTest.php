<?php

declare(strict_types=1);

namespace Tests;

use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testKnownRouteReturns200(): void
    {
        $r = new Router();
        $r->add('GET', '/a', fn () => ['x' => 1]);
        $this->assertSame([200, ['x' => 1]], $r->dispatch('GET', '/a'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $this->assertSame(404, (new Router())->dispatch('GET', '/nope')[0]);
    }

    public function testWrongMethodReturns405(): void
    {
        $r = new Router();
        $r->add('GET', '/a', fn () => []);
        $this->assertSame(405, $r->dispatch('POST', '/a')[0]);
    }
}
