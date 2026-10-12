<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Поисковый индекс, который админ-API обновляет после каждой записи.
 * $entity — раздел из App\Admin\Schema (codes, symptoms, causes, fixes, cases).
 */
interface SearchIndex
{
    public function sync(string $entity, int $id): void;

    public function remove(string $entity, int $id): void;
}
