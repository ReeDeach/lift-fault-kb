<?php

declare(strict_types=1);

/**
 * Сброс демо-копии: убирает всё, что насохраняли посетители, и загружает вымышленные
 * демо-данные заново (database/demo). База знаний (коды, причины) не трогается.
 *
 *   docker compose -f docker-compose.demo.yml exec php php bin/demo-reset.php
 *
 * Работает только при DEMO_MODE=1, чтобы случайно не стереть рабочий журнал.
 */

use App\Database\Connection;
use App\Database\Migrator;
use App\Support\Env;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

if (Env::get('DEMO_MODE') !== '1') {
    fwrite(STDERR, "Отказ: это не демо-копия (DEMO_MODE не равен 1). Рабочий журнал не трогаю.\n");
    exit(2);
}

$pdo = Connection::fromEnv();
foreach (['journal_entries', 'lifts', 'mechanics', 'rate_limits'] as $table) {
    $pdo->exec("DELETE FROM $table");
}

$done = (new Migrator($pdo, $root . '/database/migrations'))->seed($root . '/database/demo');
$count = static fn (string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();

echo 'Демо сброшено: ' . implode(', ', $done) . "\n";
printf("  механиков %d, лифтов %d, записей журнала %d\n", $count('mechanics'), $count('lifts'), $count('journal_entries'));
