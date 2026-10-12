<?php

declare(strict_types=1);

use App\Controller\CatalogController;
use App\Controller\CodeController;
use App\Controller\HealthController;
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

    // Следующие этапы:
    // GET  /api/search     — полнотекстовый поиск (Manticore)
    // POST /api/diagnose   — ИИ-диагностика
    // CRUD /api/admin/...  — ввод данных
};
