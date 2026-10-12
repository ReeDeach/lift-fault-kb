<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CatalogRepository;
use App\Repository\FaultCodeRepository;
use App\Support\CodeNormalizer;
use App\Support\Disclaimer;

final class CodeController
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly FaultCodeRepository $codes,
    ) {
    }

    /**
     * GET /api/codes?model=0401К&code=E05&expand=tree
     * Без code — все коды модели (или производителя). Без model — коды из всех вариантов устройств.
     */
    public function index(Request $request): Response
    {
        $fields = [];

        $code = $request->queryString('code');
        if ($code !== null) {
            $code = CodeNormalizer::toLatin($code);
            if (!CodeNormalizer::isValidCode($code)) {
                $fields['code'] = 'Код: до 32 символов, буквы, цифры, точка, дефис и подчёркивание';
            }
        }

        $expand = $request->queryString('expand');
        if ($expand !== null && $expand !== 'tree') {
            $fields['expand'] = 'Допустимо только значение tree';
        }

        if ($fields !== []) {
            throw new HttpException(400, 'Некорректные параметры запроса', $fields);
        }

        $limit = $request->queryInt('limit', 20, 1, 100);
        $offset = $request->queryInt('offset', 0, 0, 1_000_000);

        $modelIds = $this->catalog->resolveModelIds(
            $request->queryString('model'),
            $request->queryString('manufacturer'),
        );

        $result = $this->codes->search($modelIds, $code, $limit, $offset);
        $items = $expand === 'tree' ? $this->codes->withTree($result['items']) : $result['items'];

        return Response::json([
            'items' => $items,
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'disclaimer' => Disclaimer::TEXT,
        ]);
    }

    /**
     * GET /api/codes/{id} — один код сразу с деревом причин и ремонтов.
     */
    public function show(Request $request): Response
    {
        $id = $request->param('id');
        if ($id === null || !ctype_digit($id)) {
            throw new HttpException(404, 'Код не найден');
        }

        $item = $this->codes->find((int) $id);
        if ($item === null) {
            throw new HttpException(404, 'Код не найден');
        }

        return Response::json([
            'item' => $this->codes->withTree([$item])[0],
            'disclaimer' => Disclaimer::TEXT,
        ]);
    }
}
