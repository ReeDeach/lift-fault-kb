<?php

declare(strict_types=1);

use App\Router;

require __DIR__ . '/../vendor/autoload.php';

$router = new Router();
$router->add('GET', '/api/health', fn () => ['status' => 'ok']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$status, $body] = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($body, JSON_UNESCAPED_UNICODE);
