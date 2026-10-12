<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Connection;
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
     */
    public function __construct(
        private readonly ?Closure $pdoFactory = null,
        private readonly ?string $adminToken = null,
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
}
