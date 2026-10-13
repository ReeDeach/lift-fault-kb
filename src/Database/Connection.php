<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;

final class Connection
{
    /**
     * @param string|null $database имя базы вместо DB_NAME
     * @param class-string<PDO> $class подкласс PDO (замеры подставляют записывающий)
     */
    public static function fromEnv(?string $database = null, string $class = PDO::class): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            (int) Env::get('DB_PORT', '3306'),
            $database ?? Env::get('DB_NAME', 'lift_kb'),
        );

        return new $class($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Та же collation, что у таблиц: иначе MySQL 8 сравнивает строки сидов
            // и столбцы в разных collation и может падать с Illegal mix of collations.
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    public static function isConfigured(): bool
    {
        return Env::get('DB_HOST') !== '';
    }
}
