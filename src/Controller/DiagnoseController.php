<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diagnosis\CandidateFinder;
use App\Diagnosis\CodeDetector;
use App\Diagnosis\Diagnoser;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CatalogRepository;
use App\Search\QueryBuilder;
use App\Support\CodeNormalizer;
use App\Support\Disclaimer;

/**
 * POST /api/diagnose {"description": "...", "model": "0401К", "code": "45"}
 */
final class DiagnoseController
{
    private const MIN_LENGTH = 10;
    private const MAX_LENGTH = 2000;

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CandidateFinder $finder,
        private readonly Diagnoser $diagnoser,
    ) {
    }

    public function diagnose(Request $request): Response
    {
        $body = $request->body;
        $fields = [];

        foreach (array_keys($body) as $name) {
            if (!in_array($name, ['description', 'model', 'manufacturer', 'code'], true)) {
                $fields[(string) $name] = 'Неизвестное поле';
            }
        }

        $description = $body['description'] ?? null;
        if (!is_string($description) || mb_strlen(trim($description)) < self::MIN_LENGTH) {
            $fields['description'] = 'Опишите неисправность: не короче ' . self::MIN_LENGTH . ' символов';
        } elseif (mb_strlen($description) > self::MAX_LENGTH) {
            $fields['description'] = 'Не длиннее ' . self::MAX_LENGTH . ' символов';
        } elseif (QueryBuilder::words($description) === []) {
            $fields['description'] = 'В описании нет ни одного слова';
        }

        $strings = [];
        foreach (['model', 'manufacturer', 'code'] as $name) {
            $value = $body[$name] ?? null;
            if ($value !== null && !is_string($value)) {
                $fields[$name] = 'Ожидается строка';
                continue;
            }
            $strings[$name] = $value === null || trim($value) === '' ? null : trim($value);
        }

        $codes = null;
        if (($strings['code'] ?? null) !== null) {
            $codes = CodeNormalizer::parseList($strings['code']);
            if ($codes === null) {
                $fields['code'] = CodeNormalizer::LIST_RULE;
            }
        }

        if ($fields !== []) {
            throw new HttpException(400, 'Ошибка в данных', $fields);
        }

        $modelIds = $this->catalog->resolveModelIds($strings['model'], $strings['manufacturer']);
        $owner = $modelIds === null ? null : ['models' => $modelIds, 'controllers' => $this->catalog->controllerIds($modelIds)];

        $description = trim($description);

        // Поле кода пустое, но код написан в тексте («ошибка 49, 50») — берём его оттуда
        $detected = [];
        if ($codes === null) {
            $detected = CodeDetector::detect($description);
            $codes = $detected === [] ? null : $detected;
        }

        $candidates = $this->finder->find($description, $modelIds, $owner, $codes);
        $result = $this->diagnoser->diagnose(
            ['description' => $description, 'model' => $strings['model'], 'code' => $codes === null ? null : implode(' ', $codes)],
            $candidates,
        );

        return Response::json($result + ['detected_codes' => $detected, 'disclaimer' => Disclaimer::TEXT]);
    }
}
