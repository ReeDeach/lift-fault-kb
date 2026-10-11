<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Connection
{
    public static function fromEnv(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', '127.0.0.1'),
            (int) self::env('DB_PORT', '3306'),
            self::env('DB_NAME', 'lift_kb'),
        );

        return new PDO($dsn, self::env('DB_USER', 'root'), self::env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function isConfigured(): bool
    {
        return self::env('DB_HOST') !== '';
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
