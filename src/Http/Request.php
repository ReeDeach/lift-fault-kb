<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $params Параметры маршрута, например {id}
     * @param array<string, string> $headers Имена заголовков в нижнем регистре
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $params = [],
        public readonly array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $body = [];
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, 'Тело запроса должно быть корректным JSON-объектом');
            }
            $body = $decoded;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return new self($method, self::normalize($path), $_GET, $body, [], $headers);
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $params, $this->headers);
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    /**
     * Строковый параметр запроса. Пустая строка считается «не задан».
     * Массив (code[]=x) — ошибка клиента.
     */
    public function queryString(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(400, "Параметр $name должен быть строкой");
        }

        return trim($value);
    }

    /**
     * Целочисленный параметр запроса с границами.
     */
    public function queryInt(string $name, int $default, int $min, int $max): int
    {
        $value = $this->query[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new HttpException(400, "Параметр $name должен быть целым числом");
        }
        if (!preg_match('/^\d+$/', (string) $value)) {
            throw new HttpException(400, "Параметр $name должен быть целым числом");
        }

        return max($min, min($max, (int) $value));
    }

    private static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
