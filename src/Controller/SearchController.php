<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CatalogRepository;
use App\Search\Documents;
use App\Search\QueryBuilder;
use App\Search\SearchService;
use App\Support\Disclaimer;
use PDOException;

final class SearchController
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly SearchService $search,
    ) {
    }

    /**
     * GET /api/search?q=двери не закрываются&type=symptoms,causes&model=0401К&limit=20&offset=0
     */
    public function search(Request $request): Response
    {
        $fields = [];

        $q = $request->queryString('q');
        if ($q === null) {
            $fields['q'] = 'Введите текст запроса';
        } elseif (mb_strlen($q) > QueryBuilder::MAX_LENGTH) {
            $fields['q'] = 'Не длиннее ' . QueryBuilder::MAX_LENGTH . ' символов';
        } elseif (QueryBuilder::words($q) === []) {
            $fields['q'] = 'В запросе нет ни одного слова';
        }

        $kinds = [];
        $type = $request->queryString('type');
        if ($type !== null) {
            $kinds = array_values(array_unique(array_filter(array_map('trim', explode(',', $type)))));
            $unknown = array_diff($kinds, array_keys(Documents::KINDS));
            if ($unknown !== []) {
                $fields['type'] = 'Допустимые разделы: ' . implode(', ', array_keys(Documents::KINDS));
            }
        }

        if ($fields !== []) {
            throw new HttpException(400, 'Некорректные параметры запроса', $fields);
        }

        $limit = $request->queryInt('limit', 20, 1, 50);
        $offset = $request->queryInt('offset', 0, 0, 1000);

        $modelIds = $this->catalog->resolveModelIds($request->queryString('model'), $request->queryString('manufacturer'));
        $owner = $modelIds === null ? null : ['models' => $modelIds, 'controllers' => $this->catalog->controllerIds($modelIds)];

        try {
            $result = $this->search->search((string) $q, $kinds, $owner, $limit, $offset);
        } catch (PDOException $e) {
            error_log('search: ' . $e->getMessage());
            throw new HttpException(503, 'Поиск временно недоступен');
        }

        return Response::json([
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'disclaimer' => Disclaimer::TEXT,
        ]);
    }
}
