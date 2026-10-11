<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public const DISCLAIMER = 'Справочная система. Не заменяет документацию производителя '
        . 'и правила техники безопасности. Работы на лифтовом оборудовании выполняет '
        . 'только обученный и допущенный персонал.';

    public function __invoke(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'lift-fault-kb',
            'time' => gmdate('c'),
            'disclaimer' => self::DISCLAIMER,
        ]);
    }
}
