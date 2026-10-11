<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Router;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$router = new Router();
(require $root . '/src/routes.php')($router);

$router->dispatch(Request::fromGlobals())->send();
