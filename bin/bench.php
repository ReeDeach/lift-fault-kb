<?php

declare(strict_types=1);

/**
 * Замеры запросов на синтетических данных: до и после миграции с индексами.
 *
 *   php bin/bench.php                 — отчёт в консоль
 *   php bin/bench.php --write         — и в docs/performance.md
 *   php bin/bench.php --scale=2       — данных вдвое больше
 *
 * Работает в отдельной базе BENCH_DB (по умолчанию lift_kb_bench), которую пересоздаёт.
 * Нужны права CREATE и DROP DATABASE: запускайте от root MySQL.
 */

use App\Bench\DataGenerator;
use App\Bench\QueryBench;
use App\Bench\RecordingPdo;
use App\Database\Connection;
use App\Database\Migrator;
use App\Support\Env;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

const INDEX_MIGRATION = '007_indexes.sql';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$options = getopt('', ['write', 'scale:']);
$scale = max(1, (int) ($options['scale'] ?? 1));
$bench = Env::get('BENCH_DB', 'lift_kb_bench');

if ($bench === Env::get('DB_NAME', 'lift_kb') || preg_match('/^[a-z0-9_]+$/', $bench) !== 1) {
    fwrite(STDERR, "BENCH_DB должна отличаться от рабочей базы и состоять из a-z, 0-9, _\n");
    exit(2);
}

$log = static fn (string $message) => fwrite(STDERR, $message . "\n");

try {
    $admin = Connection::fromEnv();
    $admin->exec("DROP DATABASE IF EXISTS `$bench`");
    $admin->exec("CREATE DATABASE `$bench` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    /** @var RecordingPdo $pdo */
    $pdo = Connection::fromEnv($bench, RecordingPdo::class);
    $migrator = new Migrator($pdo, $root . '/database/migrations');
    $migrator->migrate(INDEX_MIGRATION);

    $log("Генерация данных (масштаб $scale)...");
    $started = microtime(true);
    $counts = (new DataGenerator($pdo, $scale))->run();
    $log(sprintf('Готово за %.1f с', microtime(true) - $started));

    $analyze = static fn () => $pdo->query('ANALYZE TABLE ' . implode(', ', array_keys($counts)))->fetchAll();
    $analyze();

    $log('Замеры до индексов...');
    $before = (new QueryBench($pdo))->run();

    $applied = $migrator->migrate();
    $analyze();
    $log('Применено: ' . ($applied === [] ? 'ничего' : implode(', ', $applied)));

    $log('Замеры после индексов...');
    $after = (new QueryBench($pdo))->run();

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $admin->exec("DROP DATABASE `$bench`");
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

$report = report($version, $counts, $before, $after, $applied);
echo $report;

if (isset($options['write'])) {
    @mkdir($root . '/docs');
    file_put_contents($root . '/docs/performance.md', $report);
    $log('Отчёт записан в docs/performance.md');
}

/**
 * @param array<string, int> $counts
 * @param array<string, array<string, mixed>> $before
 * @param array<string, array<string, mixed>> $after
 * @param list<string> $applied
 */
function report(string $version, array $counts, array $before, array $after, array $applied): string
{
    $md = "# Замеры запросов: до и после индексов\n\n";
    $md .= "Отчёт создан командой `php bin/bench.php --write` на синтетических данных "
        . "(MySQL $version).\n";
    $md .= 'Миграция с индексами: ' . ($applied === [] ? '—' : '`' . implode('`, `', $applied) . '`') . ".\n\n";

    $md .= "## Объём данных\n\n| Таблица | Строк |\n|---|---:|\n";
    foreach ($counts as $table => $n) {
        $md .= "| `$table` | " . number_format($n, 0, '.', ' ') . " |\n";
    }

    $md .= "\n## Время (медиана)\n\n| Сценарий | До, мс | После, мс | Ускорение |\n|---|---:|---:|---:|\n";
    foreach ($before as $name => $b) {
        $a = $after[$name];
        $md .= sprintf(
            "| %s | %s | %s | %s |\n",
            $name,
            number_format($b['ms'], 1, '.', ' '),
            number_format($a['ms'], 1, '.', ' '),
            $a['ms'] > 0 ? '×' . number_format($b['ms'] / $a['ms'], 1, '.', '') : '—',
        );
    }

    $md .= "\n## Планы запросов\n\nКолонки EXPLAIN: `type` — способ доступа (`ALL` — полный просмотр таблицы), "
        . "`key` — использованный индекс, `rows` — сколько строк MySQL ожидает прочитать.\n";
    foreach ($before as $name => $b) {
        $md .= "\n### $name\n";
        foreach (['До' => $b['plans'], 'После' => $after[$name]['plans']] as $label => $plans) {
            $md .= "\n**$label**\n";
            foreach ($plans as $plan) {
                $md .= "\n```sql\n" . wordwrap($plan['sql'], 110) . "\n```\n\n";
                $md .= "| table | type | key | rows | filtered | Extra |\n|---|---|---|---:|---:|---|\n";
                foreach ($plan['rows'] as $row) {
                    $md .= sprintf(
                        "| %s | %s | %s | %s | %s | %s |\n",
                        $row['table'] ?? '', $row['type'] ?? '', $row['key'] ?? '', $row['rows'] ?? '',
                        $row['filtered'] ?? '', $row['Extra'] ?? '',
                    );
                }
            }
        }
    }

    return $md;
}
