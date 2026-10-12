<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CatalogRepository;
use App\Support\Disclaimer;

final class CatalogController
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    public function manufacturers(Request $request): Response
    {
        $items = $this->catalog->manufacturers();

        return Response::json(['items' => $items, 'total' => count($items), 'disclaimer' => Disclaimer::TEXT]);
    }

    public function models(Request $request): Response
    {
        $items = $this->catalog->models($request->queryString('manufacturer'));

        return Response::json(['items' => $items, 'total' => count($items), 'disclaimer' => Disclaimer::TEXT]);
    }
}
