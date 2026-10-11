<?php

declare(strict_types=1);

namespace Tests;

use App\Controller\HealthController;
use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testHealthEndpointIsWiredInRoutes(): void
    {
        $router = new Router();
        (require __DIR__ . '/../src/routes.php')($router);

        $response = $router->dispatch(new Request('GET', '/api/health'));
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->status);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('lift-fault-kb', $data['service']);
    }

    public function testResponseCarriesSafetyDisclaimer(): void
    {
        $response = (new HealthController())(new Request('GET', '/api/health'));
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(HealthController::DISCLAIMER, $data['disclaimer']);
    }
}
