<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Http\Router;

return static function (Router $router): void {
    $router->get('/api/health', new HealthController());

    // Следующие этапы:
    // GET  /api/codes      — поиск по коду неисправности
    // GET  /api/search     — полнотекстовый поиск (Manticore)
    // POST /api/diagnose   — ИИ-диагностика
    // CRUD /api/admin/...  — ввод данных
};
