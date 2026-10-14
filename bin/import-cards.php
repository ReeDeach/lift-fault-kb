<?php

declare(strict_types=1);

/**
 * Загрузка «Карточек лифтов» (выгрузка по маршрутам механиков) в базу: механики, лифты
 * с номерами 1С, подъездом, моделью, этажностью, заказчиком.
 *
 *   python bin/lift-cards.py private/brigade/*.doc > private/brigade/cards.json
 *   php bin/import-cards.php private/brigade/cards.json [--substitute] [--dry-run]
 *
 * Карточки — главный источник. Лифты, которые уже есть в базе, не пересоздаются (на них
 * ссылаются журнал и заявки), а дополняются:
 *   1. лифт с тем же номером 1С — где бы он ни был;
 *   2. лифт механика по тому же адресу (по порядку: подъезд, номер);
 *   3. лифт механика в том же доме, записанном иначе («176А» и «176"А"», «13» и «13/1») —
 *      адрес берётся из карточки.
 * Остальные строки карточек — новые лифты. Лифты механика, которых в карточках нет,
 * удаляются, если на них ничего не ссылается, иначе остаются без механика.
 *
 * --substitute: файл замещения (в нём под заголовком маршрута есть подзаголовки других
 * механиков) главнее основного — лифты маршрута закрепляются за замещающими.
 * --dry-run: всё в транзакции с откатом, только отчёт.
 */

use App\Database\Connection;
use App\Repairs\RepairsRepository;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$substitute = in_array('--substitute', $args, true);
$file = current(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--'))) ?: '';
$rows = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($rows)) {
    fwrite(STDERR, "Использование: php bin/import-cards.php private/brigade/cards.json [--substitute] [--dry-run]\n");
    exit(2);
}

/** «Кузьмич А.В.» → «Кузьмич А. В.» */
$personName = static fn (string $n): string => trim((string) preg_replace('/([А-ЯЁ])\.\s*(?=[А-ЯЁ]\.)/u', '$1. ', preg_replace('/\s+/u', ' ', $n)));

/**
 * Адрес из карточки: подъезд отдельно, кавычки у литеры убраны, обычный регистр.
 * «МОСКОВСКАЯ 267"А"4п.» → ['Московская 267А', '4'].
 *
 * @return array{string, ?string}
 */
$splitAddress = static function (string $raw): array {
    $a = preg_replace('/^(?:ИМ|И)\s+(?=ПР-Т)/u', '', trim($raw));
    $entrance = null;
    if (preg_match('/[\s,]*(\d{1,2})\s*(?:п\.?|П|под\.?)\s*$/u', $a, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
        $entrance = $m[1][0];
        $a = substr($a, 0, $m[0][1]);
    } elseif (preg_match('/\s+п\.\s*(\d{1,2})\s*$/u', $a, $m, PREG_OFFSET_CAPTURE)) {
        $entrance = $m[1][0];
        $a = substr($a, 0, $m[0][1]);
    }
    $a = preg_replace('/"([А-ЯЁA-Z])"/u', '$1', $a);
    $a = preg_replace('/(\d)\s+([А-ЯЁ])$/u', '$1$2', trim($a, " ,\t"));
    $a = mb_convert_case(mb_strtolower($a), MB_CASE_TITLE);
    // После точки — с заглавной («Я.купалы» → «Я. Купалы»), сокращения — строчными
    $a = preg_replace_callback('/(?<=\.)(\p{Ll})/u', static fn ($m) => mb_strtoupper($m[1]), $a);
    $a = preg_replace('/^(\p{Lu})\.(?=\p{Lu})/u', '$1. ', $a);
    $a = strtr($a, ['Пр-Т' => 'пр-т', 'П-Т' => 'п-т', 'Р-Н' => 'р-н', ' К.' => ' к.', 'Г.' => 'г.', ' Общ' => ' общ.']);
    // Сокращённые названия улиц — полностью: «Сов.Конституц.27», «Советск.Констит.28» → «Советской Конституции …»
    $a = preg_replace('/^(?:Сов\.?\s*|Советск\.?\s*|Советской\s+|С\.\s*)Констит\S*?[.\s]\s*/u', 'Советской Конституции ', $a);
    $a = preg_replace('/^Молодогвард\S*?[.\s]\s*/u', 'Молодогвардейская ', $a);
    $a = preg_replace('/^3-Й\s+Заводск\S*\s*Пер\.?\s*/u', '3-й Заводской пер. ', $a);

    return [trim((string) preg_replace('/\s+/u', ' ', $a)), $entrance];
};

$canonReg = static fn (string $r): string => RepairsRepository::canonicalReg($r);
$baseKey = static fn (string $key): string => (string) preg_replace('/^(.*?\d+).*$/u', '$1', $key);

// Кто ведёт лифт: файл замещения (есть подзаголовки других механиков) или основной маршрут
$subFiles = [];
foreach ($rows as $r) {
    if ($r['owner'] !== $r['mechanic']) {
        $subFiles[$r['file']] = true;
    }
}
$cards = [];
$seen = [];
// Сначала строки, которые главнее: при --substitute — замещение, иначе — основные файлы
usort($rows, static fn ($a, $b) => (isset($subFiles[$a['file']]) === $substitute ? 0 : 1) <=> (isset($subFiles[$b['file']]) === $substitute ? 0 : 1));
foreach ($rows as $r) {
    if (!$substitute && isset($subFiles[$r['file']])) {
        continue;
    }
    $reg = $canonReg((string) $r['reg']);
    if ($reg !== '' && isset($seen[$reg])) {
        continue;
    }
    if ($reg !== '') {
        $seen[$reg] = true;
    }
    [$address, $entrance] = $splitAddress((string) $r['address']);
    if ($address === '') {
        continue;
    }
    $cards[] = [
        'mechanic' => $personName((string) ($substitute ? $r['owner'] : $r['mechanic'])),
        'reg' => $reg === '' ? null : $reg,
        'address' => mb_substr($address, 0, 190),
        'key' => RepairsRepository::addressKey($address),
        'entrance' => $entrance,
        'equipment' => (string) $r['model'] !== '' ? mb_substr((string) $r['model'], 0, 40) : null,
        'floors' => $r['floors'] ?: null,
        'client' => trim((string) $r['client']) !== '' ? mb_substr(trim((string) $r['client']), 0, 190) : null,
        'installed' => trim((string) $r['installed']) !== '' ? (string) $r['installed'] : null,
    ];
}
// Механики из заголовков (в том числе те, у кого при замещении не осталось лифтов)
$people = [];
foreach ($rows as $r) {
    $people[$personName((string) $r['mechanic'])] = true;
    $people[$personName((string) $r['owner'])] = true;
}

$pdo = Connection::fromEnv();
$pdo->beginTransaction();
$notes = [];
$stats = ['механиков новых' => 0, 'лифтов дополнено' => 0, 'адрес из карточки' => 0, 'лифтов новых' => 0, 'удалено лишних' => 0, 'снято с механика' => 0];
try {
    $mechanicId = [];
    foreach ($pdo->query('SELECT id, name FROM mechanics')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $mechanicId[$personName($m['name'])] = (int) $m['id'];
    }
    $addMechanic = $pdo->prepare('INSERT INTO mechanics (name) VALUES (?)');
    foreach (array_keys($people) as $name) {
        if (!isset($mechanicId[$name])) {
            $addMechanic->execute([$name]);
            $mechanicId[$name] = (int) $pdo->lastInsertId();
            $stats['механиков новых']++;
        }
    }

    $lifts = $pdo->query('SELECT id, address, object_name, lift_no, reg_number, mechanic_id FROM lifts')->fetchAll(PDO::FETCH_ASSOC);
    $byReg = [];
    foreach ($lifts as $l) {
        if ($l['reg_number'] !== null && $l['reg_number'] !== '') {
            $byReg[$canonReg($l['reg_number'])] = (int) $l['id'];
        }
    }
    $claimed = [];
    $update = $pdo->prepare(
        'UPDATE lifts SET mechanic_id = ?, reg_number = ?, entrance = ?, equipment = ?, floors = ?, client = ?, installed = ? WHERE id = ?'
    );
    $rename = $pdo->prepare('UPDATE lifts SET address = ?, lift_no = lift_no + 100 WHERE id = ?');
    $apply = static function (array $card, int $liftId) use ($update, $mechanicId, &$claimed): void {
        $update->execute([$mechanicId[$card['mechanic']], $card['reg'], $card['entrance'], $card['equipment'], $card['floors'], $card['client'], $card['installed'], $liftId]);
        $claimed[$liftId] = true;
    };

    // 1. По номеру 1С
    $left = [];
    foreach ($cards as $card) {
        if ($card['reg'] !== null && isset($byReg[$card['reg']]) && !isset($claimed[$byReg[$card['reg']]])) {
            $apply($card, $byReg[$card['reg']]);
            $stats['лифтов дополнено']++;
        } else {
            $left[] = $card;
        }
    }

    // 2–3. Лифты механика по адресу, затем по дому
    $order = static fn (array $a, array $b): int => [(int) $a['entrance'], (string) $a['reg']] <=> [(int) $b['entrance'], (string) $b['reg']];
    usort($left, $order);
    $touched = [];
    foreach ([false, true] as $byHouse) {
        $rest = [];
        foreach ($left as $card) {
            $mech = $mechanicId[$card['mechanic']];
            $match = null;
            foreach ($lifts as $l) {
                $id = (int) $l['id'];
                if (isset($claimed[$id]) || (int) $l['mechanic_id'] !== $mech) {
                    continue;
                }
                $key = RepairsRepository::addressKey($l['address']);
                if ($byHouse ? $baseKey($key) === $baseKey($card['key']) : $key === $card['key']) {
                    if ($match === null || (int) $l['lift_no'] < (int) $match['lift_no']) {
                        $match = $l;
                    }
                }
            }
            if ($match === null) {
                $rest[] = $card;
                continue;
            }
            if ($byHouse) {
                $rename->execute([$card['address'], $match['id']]);
                $notes[] = "адрес по карточке: {$match['address']} → {$card['address']}";
                $touched[$card['address']] = true;
                $stats['адрес из карточки']++;
            }
            $apply($card, (int) $match['id']);
            $stats['лифтов дополнено']++;
        }
        $left = $rest;
    }

    // Новые лифты
    // Временный номер 151+: окончательные номера ставятся ниже, по подъездам
    $insert = $pdo->prepare('INSERT INTO lifts (mechanic_id, address, lift_no) VALUES (?, ?, ?)');
    $temp = [];
    foreach ($left as $card) {
        $temp[$card['address']] = ($temp[$card['address']] ?? 150) + 1;
        $insert->execute([$mechanicId[$card['mechanic']], $card['address'], $temp[$card['address']]]);
        $apply($card, (int) $pdo->lastInsertId());
        $touched[$card['address']] = true;
        $stats['лифтов новых']++;
    }

    // Лифты механиков из карточек, которых в карточках нет
    $cardMechanics = array_unique(array_map(static fn ($c) => $mechanicId[$c['mechanic']], $cards));
    $refs = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM journal_entries WHERE lift_id = ?) + (SELECT COUNT(*) FROM repair_calls WHERE lift_id = ?) '
        . '+ (SELECT COUNT(*) FROM part_requests WHERE lift_id = ?)'
    );
    foreach ($lifts as $l) {
        $id = (int) $l['id'];
        if (isset($claimed[$id]) || !in_array((int) $l['mechanic_id'], $cardMechanics, true)) {
            continue;
        }
        $refs->execute([$id, $id, $id]);
        if ((int) $refs->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM lifts WHERE id = ?')->execute([$id]);
            $notes[] = "нет в карточках, удалён: {$l['address']}" . ($l['object_name'] !== '' ? " [{$l['object_name']}]" : '') . ", лифт {$l['lift_no']}";
            $stats['удалено лишних']++;
        } else {
            $pdo->prepare('UPDATE lifts SET mechanic_id = NULL WHERE id = ?')->execute([$id]);
            $notes[] = "нет в карточках, снят с механика (есть записи): {$l['address']}, лифт {$l['lift_no']}";
            $stats['снято с механика']++;
        }
        $touched[$l['address']] = true;
    }

    // Номера лифтов по адресу заново, по подъезду и номеру 1С. Сначала всем 200+, потом 1..N —
    // так номера не сталкиваются (временные: переименованные 101+, новые 151+)
    if ($claimed !== []) {
        $in = implode(',', array_fill(0, count($claimed), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT address FROM lifts WHERE id IN ($in)");
        $stmt->execute(array_keys($claimed));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $address) {
            $touched[$address] = true;
        }
    }
    foreach (array_keys($touched) as $address) {
        $stmt = $pdo->prepare('SELECT id, object_name FROM lifts WHERE address = ? ORDER BY object_name, CAST(entrance AS UNSIGNED), reg_number, id');
        $stmt->execute([$address]);
        $group = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($group as $i => $l) {
            $pdo->prepare('UPDATE lifts SET lift_no = ? WHERE id = ?')->execute([200 + $i, $l['id']]);
        }
        $n = [];
        foreach ($group as $l) {
            $n[$l['object_name']] = ($n[$l['object_name']] ?? 0) + 1;
            $pdo->prepare('UPDATE lifts SET lift_no = ? WHERE id = ?')->execute([$n[$l['object_name']], $l['id']]);
        }
    }

    // Заявки 1С без лифта: сопоставить по новым номерам
    $matched = $pdo->exec(
        'UPDATE repair_calls c JOIN lifts l ON l.reg_number = UPPER(TRIM(TRAILING \'н\' FROM TRIM(TRAILING \'Н\' FROM c.lift_reg))) '
        . 'SET c.lift_id = l.id, c.mechanic_id = l.mechanic_id WHERE c.lift_id IS NULL'
    );
    $stats['заявок 1С сопоставлено'] = (int) $matched;

    if ($dry) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dry ? "Пробный прогон, ничего не записано.\n" : "Загружено.\n");
echo 'Карточек: ', count($cards), ($substitute ? ' (с замещением)' : ''), "\n";
foreach ($stats as $k => $v) {
    echo "  $k: $v\n";
}
if (in_array('--show-addresses', $args, true)) {
    echo '  адреса: ', implode(' | ', array_unique(array_map(static fn ($c) => $c['address'] . ($c['entrance'] ? ' [п.' . $c['entrance'] . ']' : ''), $cards))), "
";
}
foreach ($notes as $note) {
    echo "  · $note
";
}
$byMechanic = array_count_values(array_column($cards, 'mechanic'));
ksort($byMechanic);
foreach ($byMechanic as $name => $n) {
    echo "  $name: $n\n";
}
