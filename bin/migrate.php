<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrator;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$command = $argv[1] ?? 'status';

try {
    $migrator = new Migrator(Connection::fromEnv(), $root . '/database/migrations');

    switch ($command) {
        case 'migrate':
            $done = $migrator->migrate();
            echo $done === []
                ? "Нечего применять: схема актуальна.\n"
                : "Применено:\n  " . implode("\n  ", $done) . "\n";
            break;

        case 'seed':
            $done = $migrator->seed($root . '/database/seeds');
            echo $done === []
                ? "Сидов нет.\n"
                : "Выполнено:\n  " . implode("\n  ", $done) . "\n";
            break;

        case 'status':
            $applied = $migrator->applied();
            $pending = $migrator->pending();
            echo 'Применено: ' . count($applied) . "\n";
            foreach ($applied as $name) {
                echo "  [x] $name\n";
            }
            echo 'Ожидает: ' . count($pending) . "\n";
            foreach ($pending as $name) {
                echo "  [ ] $name\n";
            }
            break;

        default:
            fwrite(STDERR, "Использование: php bin/migrate.php [migrate|seed|status]\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
