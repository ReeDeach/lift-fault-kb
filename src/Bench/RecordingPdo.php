<?php

declare(strict_types=1);

namespace App\Bench;

use PDO;
use PDOStatement;

/**
 * PDO, который запоминает выполненные SELECT вместе с параметрами.
 * Нужен замерам: EXPLAIN снимается с тех запросов, которые на самом деле строят
 * репозитории, а не с копий SQL, которые могут разойтись с кодом.
 */
final class RecordingPdo extends PDO
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $log = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class, [$this]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record($query, []);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /**
     * @param list<mixed> $params
     */
    public function record(string $sql, array $params): void
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
            $this->log[] = ['sql' => $sql, 'params' => array_values($params)];
        }
    }
}
