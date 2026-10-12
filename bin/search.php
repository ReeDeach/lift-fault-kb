<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\Documents;
use App\Search\ManticoreIndex;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$command = $argv[1] ?? 'status';

try {
    $index = new ManticoreIndex(ManticoreIndex::connect(), new Documents(Connection::fromEnv()));

    switch ($command) {
        case 'rebuild':
            $started = microtime(true);
            $counts = $index->rebuild();
            printf("Индекс пересобран за %.2f с:\n", microtime(true) - $started);
            foreach ($counts as $kind => $n) {
                echo "  $kind: $n\n";
            }
            break;

        case 'status':
            $counts = $index->status();
            echo $counts === [] ? "Индекс пуст.\n" : "Документов в индексе:\n";
            foreach ($counts as $kind => $n) {
                echo "  $kind: $n\n";
            }
            break;

        default:
            fwrite(STDERR, "Использование: php bin/search.php [rebuild|status]\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
