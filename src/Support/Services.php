<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Connection;
use App\Search\SearchIndex;
use Closure;
use PDO;

/**
 * Простейший контейнер: подключение к БД создаётся лениво, при первом обращении,
 * поэтому маршруты, которым база не нужна (например /api/health), работают без неё.
 */
final class Services
{
    private ?PDO $pdo = null;

    /**
     * @param (Closure(): PDO)|null $pdoFactory
     * @param (Closure(self): ?SearchIndex)|null $searchIndexFactory
     */
    public function __construct(
        private readonly ?Closure $pdoFactory = null,
        private readonly ?string $adminToken = null,
        private readonly ?Closure $searchIndexFactory = null,
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->pdoFactory !== null
                ? ($this->pdoFactory)()
                : Connection::fromEnv();
        }

        return $this->pdo;
    }

    public function adminToken(): string
    {
        return $this->adminToken ?? Env::get('ADMIN_TOKEN');
    }

    /**
     * Индекс, который обновляется после записи через админ-API. null — поиска нет.
     */
    public function searchIndex(): ?SearchIndex
    {
        return $this->searchIndexFactory !== null ? ($this->searchIndexFactory)($this) : null;
    }
}
