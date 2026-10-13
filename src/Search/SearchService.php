<?php

declare(strict_types=1);

namespace App\Search;

use App\Repository\FaultCodeRepository;
use PDO;

/**
 * Полнотекстовый поиск по базе знаний: запрос в Manticore, затем проверка и дополнение
 * найденного данными из MySQL. Документы, которых в базе уже нет (индекс отстал),
 * в ответ не попадают.
 */
final class SearchService
{
    private const MARK_OPEN = "\x02";
    private const MARK_CLOSE = "\x03";

    public function __construct(
        private readonly PDO $manticore,
        private readonly PDO $pdo,
        private readonly FaultCodeRepository $codes,
    ) {
    }

    /**
     * @param list<string> $kinds разделы из Documents::KINDS, пустой список — все
     * @param array{models: list<int>, controllers: list<int>}|null $owner коды только этих моделей и устройств
     * @param float $share доля слов запроса, которая должна найтись (см. QueryBuilder::build)
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $query, array $kinds, ?array $owner, int $limit, int $offset, float $share = 0.6): array
    {
        $match = QueryBuilder::build($query, $share);
        if ($match === null) {
            return ['items' => [], 'total' => 0];
        }

        $where = ['MATCH(?)'];
        $params = [$match];

        if ($kinds !== []) {
            $where[] = 'kind IN (' . implode(', ', array_fill(0, count($kinds), '?')) . ')';
            array_push($params, ...$kinds);
        }

        if ($owner !== null) {
            // Фильтр по модели касается только кодов: симптомы, причины и ремонты общие
            $ownerFilter = ["kind != 'codes'", 'model_id IN (' . implode(', ', $owner['models'] ?: [0]) . ')'];
            if ($owner['controllers'] !== []) {
                $ownerFilter[] = 'controller_id IN (' . implode(', ', $owner['controllers']) . ')';
            }
            $where[] = '(' . implode(' OR ', $ownerFilter) . ')';
        }

        $highlight = "before_match='" . self::MARK_OPEN . "', after_match='" . self::MARK_CLOSE . "'";
        $stmt = $this->manticore->prepare(
            "SELECT kind, entity_id, WEIGHT() AS score, "
            . "HIGHLIGHT({{$highlight}, limit=0}, 'title') AS title_hl, "
            . "HIGHLIGHT({{$highlight}, limit=240, around=8}, 'body') AS snippet "
            . 'FROM ' . ManticoreIndex::TABLE . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY score DESC, id ASC LIMIT $offset, $limit OPTION ranker=proximity_bm25, field_weights=(title=3, code=3, body=1), max_matches=" . ($offset + $limit)
        );
        ManticoreIndex::execute($stmt, $params);
        $hits = $stmt->fetchAll();

        $meta = $this->manticore->query("SHOW META LIKE 'total_found'")->fetch();
        $total = (int) ($meta['Value'] ?? $meta['value'] ?? count($hits));

        return ['items' => $this->enrich($hits), 'total' => $total];
    }

    /**
     * Подсветка из Manticore приходит служебными символами: текст экранируется,
     * затем они заменяются на <mark>, поэтому HTML из данных в ответ не попадает.
     */
    public static function markup(string $text): string
    {
        return str_replace(
            [self::MARK_OPEN, self::MARK_CLOSE],
            ['<mark>', '</mark>'],
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<array<string, mixed>>
     */
    private function enrich(array $hits): array
    {
        $idsByKind = [];
        foreach ($hits as $hit) {
            $idsByKind[$hit['kind']][] = (int) $hit['entity_id'];
        }

        $records = [];
        foreach ($idsByKind as $kind => $ids) {
            $records[$kind] = $this->records($kind, $ids);
        }

        $items = [];
        foreach ($hits as $hit) {
            $id = (int) $hit['entity_id'];
            $record = $records[$hit['kind']][$id] ?? null;
            if ($record === null) {
                continue;
            }

            $items[] = [
                'type' => $hit['kind'],
                'id' => $id,
                'title' => $record['title'],
                'title_html' => $hit['title_hl'] !== '' ? self::markup((string) $hit['title_hl']) : null,
                'snippet_html' => $hit['snippet'] !== '' ? self::markup((string) $hit['snippet']) : null,
                'score' => (int) $hit['score'],
            ] + array_diff_key($record, ['title' => true]);
        }

        return $items;
    }

    /**
     * Записи из MySQL по id. Для кодов — с владельцем, для случаев — с кодом и исходом.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function records(string $kind, array $ids): array
    {
        if ($kind === 'codes') {
            $result = [];
            foreach ($this->codes->findMany($ids) as $code) {
                $result[$code['id']] = [
                    'title' => $code['title'],
                    'code' => $code['code'],
                    'owner' => $code['owner'],
                    'is_safety_related' => $code['is_safety_related'],
                ];
            }

            return $result;
        }

        $in = implode(',', array_map('intval', $ids));
        $sql = match ($kind) {
            'symptoms', 'causes', 'fixes' => "SELECT id, title FROM $kind WHERE id IN ($in)",
            'cases' => 'SELECT c.id, LEFT(c.description, 120) AS title, c.occurred_on, c.outcome, fc.code '
                . "FROM cases c LEFT JOIN fault_codes fc ON fc.id = c.fault_code_id WHERE c.id IN ($in)",
        };

        $result = [];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            unset($row['id']);
            $result[$id] = $row;
        }

        return $result;
    }
}
