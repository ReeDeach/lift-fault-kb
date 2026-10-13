<?php

declare(strict_types=1);

/**
 * Загрузка списка лифтов бригады из CSV (разделитель «;», первая строка — заголовок):
 *
 *   механик;адрес;объект;лифтов;примечание
 *   Иванов И. И.;Пионерская 40;;2;
 *   Иванов И. И.;Я. Купалы 100;Склады;5;
 *
 *   php bin/import-lifts.php private/lifts.csv [участок]
 *
 * Для каждой строки создаются лифты №1..N на адресе и закрепляются за механиком.
 * Повторный запуск ничего не дублирует: обновляет закрепление и примечание.
 * Лифты, которых в файле нет, не удаляются (по ним может быть журнал).
 * Файл с адресами и фамилиями хранится в private/ и в репозиторий не попадает.
 */

use App\Database\Connection;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$file = $argv[1] ?? '';
$section = isset($argv[2]) && trim($argv[2]) !== '' ? trim($argv[2]) : null;
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Использование: php bin/import-lifts.php private/lifts.csv [участок]\n");
    exit(2);
}

$handle = fopen($file, 'rb');
$header = fgetcsv($handle, 0, ';', '"', '');
$header = array_map(static fn ($h): string => mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
$need = ['механик', 'адрес', 'объект', 'лифтов', 'примечание'];
if (array_diff($need, $header) !== []) {
    fwrite(STDERR, 'В первой строке нужны столбцы: ' . implode(';', $need) . "\n");
    exit(2);
}
$col = array_flip($header);

$pdo = Connection::fromEnv();
$pdo->beginTransaction();

try {
    $findMechanic = $pdo->prepare('SELECT id FROM mechanics WHERE name = ?');
    $addMechanic = $pdo->prepare('INSERT INTO mechanics (name, section) VALUES (?, ?)');
    $setSection = $pdo->prepare('UPDATE mechanics SET section = COALESCE(?, section) WHERE id = ?');
    $upsertLift = $pdo->prepare(
        'INSERT INTO lifts (mechanic_id, address, object_name, lift_no, notes) VALUES (?, ?, ?, ?, ?) AS new '
        . 'ON DUPLICATE KEY UPDATE mechanic_id = new.mechanic_id, notes = COALESCE(new.notes, lifts.notes)'
    );

    $stats = ['механиков' => 0, 'адресов' => 0, 'лифтов' => 0];
    $mechanicIds = [];
    $line = 1;

    while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $line++;
        if ($row === [null] || trim(implode('', $row)) === '') {
            continue;
        }
        $name = trim((string) ($row[$col['механик']] ?? ''));
        $address = trim(preg_replace('/\s+/u', ' ', (string) ($row[$col['адрес']] ?? '')) ?? '');
        $object = trim((string) ($row[$col['объект']] ?? ''));
        $count = (int) ($row[$col['лифтов']] ?? 0);
        $note = trim((string) ($row[$col['примечание']] ?? ''));

        if ($name === '' || $address === '' || $count < 1 || $count > 20) {
            throw new RuntimeException("Строка $line: нужны механик, адрес и число лифтов от 1 до 20");
        }

        if (!isset($mechanicIds[$name])) {
            $findMechanic->execute([$name]);
            $id = $findMechanic->fetchColumn();
            if ($id === false) {
                $addMechanic->execute([$name, $section]);
                $id = $pdo->lastInsertId();
                $stats['механиков']++;
            } else {
                $setSection->execute([$section, $id]);
            }
            $mechanicIds[$name] = (int) $id;
        }

        for ($no = 1; $no <= $count; $no++) {
            $upsertLift->execute([$mechanicIds[$name], $address, $object, $no, $note !== '' ? $note : null]);
        }
        $stats['адресов']++;
        $stats['лифтов'] += $count;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Загружено: ' . implode(', ', array_map(static fn ($k, $v): string => "$k $v", array_keys($stats), $stats)) . " (новых механиков — первое число)\n";
foreach ($mechanicIds as $name => $id) {
    $n = $pdo->query("SELECT COUNT(*) FROM lifts WHERE mechanic_id = $id")->fetchColumn();
    echo "  $name: $n лифтов\n";
}
