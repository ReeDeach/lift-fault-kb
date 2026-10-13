<?php

declare(strict_types=1);

use App\Admin\AdminRepository;
use App\Auth\IdentityResolver;
use App\Controller\AdminController;
use App\Controller\CatalogController;
use App\Controller\CodeController;
use App\Controller\DiagnoseController;
use App\Controller\HealthController;
use App\Controller\JournalController;
use App\Controller\SearchController;
use App\Diagnosis\CandidateFinder;
use App\Diagnosis\Diagnoser;
use App\Http\AdminAuth;
use App\Journal\JournalDrafter;
use App\Journal\JournalRepository;
use App\Http\Router;
use App\Repository\CatalogRepository;
use App\Repository\FaultCodeRepository;
use App\Search\ManticoreIndex;
use App\Search\SearchService;
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

    $search = static fn (): SearchService => new SearchService(
        $services->manticore(),
        $services->pdo(),
        new FaultCodeRepository($services->pdo()),
    );

    $router->get('/api/search', static fn ($r) => (new SearchController(
        new CatalogRepository($services->pdo()),
        $search(),
    ))->search($r));

    // Диагностика работает и без Manticore (только по коду), и без ИИ (список причин из базы)
    $router->post('/api/diagnose', static function ($r) use ($services, $search) {
        $services->rateLimiter()->hit($r, 'diagnose');

        return (new DiagnoseController(
            new CatalogRepository($services->pdo()),
            new CandidateFinder(
                $services->pdo(),
                new FaultCodeRepository($services->pdo()),
                ManticoreIndex::isConfigured() ? $search : null,
            ),
            new Diagnoser($services->languageModel()),
        ))->diagnose($r);
    });

    // Личный журнал механика: вход по аккаунту Tailscale
    $journal = static fn (): JournalController => new JournalController(
        $services->identity(),
        new JournalRepository($services->pdo()),
        new JournalDrafter($services->languageModel()),
    );
    $router->get('/api/me', static fn ($r) => $journal()->me($r));
    $router->post('/api/me/link', static fn ($r) => $journal()->link($r));
    $router->get('/api/mechanics', static fn ($r) => $journal()->mechanics($r));
    $router->get('/api/lifts', static fn ($r) => $journal()->lifts($r));
    $router->post('/api/journal/draft', static function ($r) use ($services, $journal) {
        $services->rateLimiter()->hit($r, 'draft');

        return $journal()->draft($r);
    });
    $router->get('/api/journal', static fn ($r) => $journal()->index($r));
    $router->post('/api/journal', static fn ($r) => $journal()->create($r));
    $router->delete('/api/journal/{id}', static fn ($r) => $journal()->delete($r));

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
};
