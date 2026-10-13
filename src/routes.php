<?php

declare(strict_types=1);

use App\Admin\AdminRepository;
use App\Auth\IdentityResolver;
use App\Comms\CommsRepository;
use App\Controller\AdminController;
use App\Controller\CatalogController;
use App\Controller\CodeController;
use App\Controller\CommsController;
use App\Controller\DiagnoseController;
use App\Controller\HealthController;
use App\Controller\JournalController;
use App\Controller\PushController;
use App\Controller\RepairsController;
use App\Controller\SearchController;
use App\Diagnosis\CandidateFinder;
use App\Diagnosis\Diagnoser;
use App\Http\AdminAuth;
use App\Journal\JournalDrafter;
use App\Journal\JournalRepository;
use App\Push\PushRepository;
use App\Repairs\RepairsRepository;
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

    // Связь в бригаде: общий чат и заявки на запчасти мастеру, с фото
    $comms = static fn (): CommsController => new CommsController(
        $services->identity(),
        new CommsRepository($services->pdo()),
        $services->photoStore(),
        $services->notifier(),
    );
    $router->post('/api/uploads', static function ($r) use ($services, $comms) {
        $services->rateLimiter()->limit($r, 'upload', 120, 'фото');

        return $comms()->upload($r);
    });
    $router->get('/api/files/{token}', static fn ($r) => $comms()->file($r));
    $router->get('/api/chat', static fn ($r) => $comms()->chat($r));
    $router->post('/api/chat', static function ($r) use ($services, $comms) {
        $services->rateLimiter()->limit($r, 'message', 300, 'сообщений');

        return $comms()->postChat($r);
    });
    $router->get('/api/requests', static fn ($r) => $comms()->requests($r));
    $router->post('/api/requests', static function ($r) use ($services, $comms) {
        $services->rateLimiter()->limit($r, 'message', 300, 'сообщений');

        return $comms()->createRequest($r);
    });
    $router->get('/api/requests/{id}', static fn ($r) => $comms()->showRequest($r));
    $router->post('/api/requests/{id}/messages', static function ($r) use ($services, $comms) {
        $services->rateLimiter()->limit($r, 'message', 300, 'сообщений');

        return $comms()->postToRequest($r);
    });
    $router->get('/api/updates', static fn ($r) => $comms()->updates($r));

    // Заявки на ремонт из ежедневного отчёта 1С
    $repairs = static fn (): RepairsController => new RepairsController(
        $services->identity(),
        new RepairsRepository($services->pdo()),
        $services->notifier(),
    );
    $router->post('/api/repairs/import', static function ($r) use ($services, $repairs) {
        $services->rateLimiter()->limit($r, 'repairs', 60, 'загрузок отчёта');

        return $repairs()->import($r);
    });
    $router->get('/api/repairs', static fn ($r) => $repairs()->index($r));
    $router->post('/api/repairs/{id}/lift', static fn ($r) => $repairs()->assignLift($r));
    $router->post('/api/repairs/{id}/journal', static fn ($r) => $repairs()->linkJournal($r));

    // Push-уведомления: подписка устройства и пробное уведомление
    $push = static fn (): PushController => new PushController(
        $services->identity(),
        new PushRepository($services->pdo()),
        $services->notifier(),
    );
    $router->get('/api/push', static fn ($r) => $push()->show($r));
    $router->post('/api/push/subscribe', static fn ($r) => $push()->subscribe($r));
    $router->post('/api/push/unsubscribe', static fn ($r) => $push()->unsubscribe($r));
    $router->post('/api/push/test', static function ($r) use ($services, $push) {
        $services->rateLimiter()->limit($r, 'push-test', 30, 'проверок');

        return $push()->test($r);
    });

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
