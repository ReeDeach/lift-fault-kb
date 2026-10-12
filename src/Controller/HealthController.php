<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Support\Disclaimer;

final class HealthController
{
    public const DISCLAIMER = Disclaimer::TEXT;

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
