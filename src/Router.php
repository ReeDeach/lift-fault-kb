<?php

declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    /** @return array{0:int,1:array<mixed>} */
    public function dispatch(string $method, string $path): array
    {
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            return [200, $handler()];
        }
        foreach ($this->routes as $paths) {
            if (isset($paths[$path])) {
                return [405, ['error' => 'method_not_allowed']];
            }
        }
        return [404, ['error' => 'not_found']];
    }
}
