<?php

declare(strict_types=1);

use App\Admin\AdminRepository;
use App\Controller\AdminController;
use App\Controller\CatalogController;
use App\Controller\CodeController;
use App\Controller\HealthController;
use App\Http\AdminAuth;
use App\Http\Router;
use App\Repository\CatalogRepository;
use App\Repository\FaultCodeRepository;
use App\Support\Services;

/**
 * Подключение к БД создаётся лениво, поэтому репозитории строятся внутри обработчиков:
 * /api/health работает и без базы.
 */
return static function (Router $router, ?Services $services = null): void {
    $services ??= new Services();

    $catalog = static fn (): CatalogController => new CatalogController(new CatalogRepository($services->pdo()));
    $codes = static fn (): CodeController => new CodeController(
        new CatalogRepository($services->pdo()),
        new FaultCodeRepository($services->pdo()),
    );

    $router->get('/api/health', new HealthController());

    $router->get('/api/manufacturers', static fn ($r) => $catalog()->manufacturers($r));
    $router->get('/api/models', static fn ($r) => $catalog()->models($r));
    $router->get('/api/codes', static fn ($r) => $codes()->index($r));
    $router->get('/api/codes/{id}', static fn ($r) => $codes()->show($r));

    // Админ-API: каждый обработчик закрыт токеном
    $admin = static fn (): AdminController => new AdminController(new AdminRepository($services->pdo()), $services->searchIndex());
    $token = $services->adminToken();
    $guarded = static fn (string $method): callable => AdminAuth::guard($token, static fn ($r) => $admin()->$method($r));

    $router->get('/api/admin/{entity}', $guarded('list'));
    $router->post('/api/admin/{entity}', $guarded('create'));
    $router->get('/api/admin/{entity}/{id}', $guarded('show'));
    $router->patch('/api/admin/{entity}/{id}', $guarded('update'));
    $router->delete('/api/admin/{entity}/{id}', $guarded('delete'));
    $router->put('/api/admin/{entity}/{id}/{target}/{targetId}', $guarded('link'));
    $router->delete('/api/admin/{entity}/{id}/{target}/{targetId}', $guarded('unlink'));

    // Следующие этапы:
    // GET  /api/search     — полнотекстовый поиск (Manticore)
    // POST /api/diagnose   — ИИ-диагностика
};
