<?php

declare(strict_types=1);

namespace App\Bench;

use PDOStatement;

final class RecordingStatement extends PDOStatement
{
    protected function __construct(private readonly RecordingPdo $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->record($this->queryString, $params ?? []);

        return parent::execute($params);
    }
}
