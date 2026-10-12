<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    /**
     * Читает переменную окружения: сначала то, что загрузил phpdotenv ($_ENV, $_SERVER),
     * затем настоящее окружение процесса. Пустая строка считается «не задано».
     */
    public static function get(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
