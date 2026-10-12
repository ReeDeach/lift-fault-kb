<?php

declare(strict_types=1);

namespace App\Http;

use Throwable;

/**
 * Минимальный роутер: статические пути и параметры вида /api/codes/{id}.
 * Обработчик — callable(Request): Response. Маршруты проверяются в порядке добавления,
 * побеждает первый подходящий по пути и методу.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $this->compile($pattern),
            'handler' => $handler,
        ];

        return $this;
    }

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): self
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            try {
                return ($route['handler'])($request->withParams($params));
            } catch (HttpException $e) {
                return Response::error($e->getMessage(), $e->status, $e->fields);
            } catch (Throwable $e) {
                return Response::error('Внутренняя ошибка сервера', 500);
            }
        }

        if ($allowed !== []) {
            $response = Response::error('Метод не поддерживается', 405);

            return new Response(
                $response->status,
                $response->body,
                $response->headers + ['Allow' => implode(', ', array_unique($allowed))],
            );
        }

        return Response::error('Маршрут не найден', 404);
    }

    private function compile(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $regex . '$#u';
    }
}
