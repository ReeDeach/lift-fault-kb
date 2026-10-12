<?php

declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Собирает поисковые документы из MySQL. Один документ — одна запись базы знаний:
 * код, симптом, причина, ремонт (с шагами) или случай. Связи между ними в документ
 * не входят, поэтому изменение связи не требует переиндексации.
 */
final class Documents
{
    /** Номер раздела входит в id документа: id = раздел * 1e9 + id записи. */
    public const KINDS = ['codes' => 1, 'symptoms' => 2, 'causes' => 3, 'fixes' => 4, 'cases' => 5];

    private const KIND_FACTOR = 1_000_000_000;

    private const QUERIES = [
        'codes' => 'SELECT id, code, title, description AS body, COALESCE(model_id, 0) AS model_id, '
            . 'COALESCE(controller_id, 0) AS controller_id FROM fault_codes',
        'symptoms' => "SELECT id, '' AS code, title, COALESCE(description, '') AS body, 0 AS model_id, "
            . '0 AS controller_id FROM symptoms',
        'causes' => "SELECT id, '' AS code, title, COALESCE(description, '') AS body, 0 AS model_id, "
            . '0 AS controller_id FROM causes',
        'fixes' => "SELECT f.id, '' AS code, f.title, CONCAT_WS(' ', f.summary, f.safety_note, "
            . "(SELECT GROUP_CONCAT(s.instruction ORDER BY s.step_no SEPARATOR ' ') FROM fix_steps s WHERE s.fix_id = f.id)) AS body, "
            . '0 AS model_id, 0 AS controller_id FROM fixes f',
        'cases' => "SELECT c.id, COALESCE(fc.code, '') AS code, '' AS title, CONCAT_WS(' ', c.description, c.notes) AS body, "
            . 'COALESCE(c.model_id, 0) AS model_id, 0 AS controller_id FROM cases c '
            . 'LEFT JOIN fault_codes fc ON fc.id = c.fault_code_id',
    ];

    private const ID_COLUMN = ['codes' => 'id', 'symptoms' => 'id', 'causes' => 'id', 'fixes' => 'f.id', 'cases' => 'c.id'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function docId(string $kind, int $id): int
    {
        return self::KINDS[$kind] * self::KIND_FACTOR + $id;
    }

    /**
     * @return array<string, mixed>|null null — записи нет
     */
    public function one(string $kind, int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::QUERIES[$kind] . ' WHERE ' . self::ID_COLUMN[$kind] . ' = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->shape($kind, $row);
    }

    /**
     * Все документы раздела пачками, чтобы не держать в памяти всю базу.
     *
     * @return iterable<list<array<string, mixed>>>
     */
    public function batches(string $kind, int $size = 500): iterable
    {
        $lastId = 0;
        $column = self::ID_COLUMN[$kind];

        while (true) {
            $stmt = $this->pdo->prepare(self::QUERIES[$kind] . " WHERE $column > ? ORDER BY $column LIMIT $size");
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return;
            }
            $lastId = (int) end($rows)['id'];

            yield array_map(fn (array $row): array => $this->shape($kind, $row), $rows);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(string $kind, array $row): array
    {
        return [
            'id' => self::docId($kind, (int) $row['id']),
            'kind' => $kind,
            'entity_id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'body' => (string) ($row['body'] ?? ''),
            'model_id' => (int) $row['model_id'],
            'controller_id' => (int) $row['controller_id'],
        ];
    }
}
