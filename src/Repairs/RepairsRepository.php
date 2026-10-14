<?php

declare(strict_types=1);

namespace App\Repairs;

use App\Auth\Identity;
use App\Http\HttpException;
use DateTimeImmutable;
use PDO;

/**
 * Заявки на ремонт из отчётов 1С.
 *
 * Лифт заявки находится по номеру из 1С (lifts.reg_number). Номера у лифтов бригады
 * появляются постепенно: если по адресу в списках ровно один лифт, номер запоминается сразу,
 * а если лифтов несколько — мастер один раз выбирает нужный, и дальше всё сопоставляется само.
 *
 * Мастер видит все заявки, механик — по своим лифтам.
 */
final class RepairsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{period_from: \DateTimeImmutable, period_to: \DateTimeImmutable, section: string, rows: list<array<string, mixed>>} $report
     * @return array{report_id: int, rows: int, faults: int, matched: int, unmatched: int,
     *   by_mechanic: array<int, list<array{address: string, codes: string, info: string, open: bool}>>}
     *   by_mechanic — новые вызовы (не из прошлой загрузки того же отчёта) по механикам, для уведомлений
     */
    public function import(array $report, ?string $fileName, string $login): array
    {
        $lifts = $this->liftIndex();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO repair_reports (period_from, period_to, section, file_name, uploaded_by) VALUES (?, ?, ?, ?, ?) AS new '
                . 'ON DUPLICATE KEY UPDATE file_name = new.file_name, uploaded_by = new.uploaded_by, id = LAST_INSERT_ID(repair_reports.id)'
            )->execute([
                $report['period_from']->format('Y-m-d H:i:s'),
                $report['period_to']->format('Y-m-d H:i:s'),
                $report['section'],
                $fileName === null ? null : mb_substr($fileName, 0, 190),
                $login,
            ]);
            $reportId = (int) $this->pdo->lastInsertId();

            $insert = $this->pdo->prepare(
                'INSERT INTO repair_calls (report_id, lift_reg, address, floor, started_at, finished_at, stopped, stops_30d, info, codes, kind, lift_id, mechanic_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE '
                . 'report_id = new.report_id, address = new.address, floor = new.floor, finished_at = new.finished_at, stopped = new.stopped, '
                . 'stops_30d = new.stops_30d, info = new.info, codes = new.codes, kind = new.kind, '
                . 'lift_id = COALESCE(repair_calls.lift_id, new.lift_id), mechanic_id = COALESCE(new.mechanic_id, repair_calls.mechanic_id)'
            );
            $learn = $this->pdo->prepare('UPDATE lifts SET reg_number = ? WHERE id = ? AND (reg_number IS NULL OR reg_number = \'\')');

            $summary = ['report_id' => $reportId, 'rows' => 0, 'faults' => 0, 'matched' => 0, 'unmatched' => 0, 'by_mechanic' => []];
            foreach ($report['rows'] as $row) {
                $lift = $this->findLift($lifts, $row['reg'], $row['address']);
                if ($lift !== null && $lift['by'] === 'address') {
                    $learn->execute([self::canonicalReg($row['reg']), $lift['id']]);
                    $lift['reg'] = $row['reg'];
                    $lifts['reg'][self::regKey($row['reg'])] = $lift;
                    foreach ($lifts['address'][self::addressKey($row['address'])] as &$same) {
                        if ($same['id'] === $lift['id']) {
                            $same['reg'] = $row['reg'];
                        }
                    }
                    unset($same);
                }
                $mechanicId = $lift !== null ? $lift['mechanic_id'] : self::addressMechanic($lifts, $row['address']);
                $insert->execute([
                    $reportId, $row['reg'], $row['address'], $row['floor'],
                    $row['started_at']->format('Y-m-d H:i:s'), $row['finished_at']?->format('Y-m-d H:i:s'),
                    (int) $row['stopped'], $row['stops_30d'], $row['info'],
                    $row['codes'] === [] ? null : implode(' ', $row['codes']), $row['kind'], $lift['id'] ?? null, $mechanicId,
                ]);
                // 1 — новая строка, 2 или 0 — заявка уже была в загруженном раньше отчёте
                $isNew = $insert->rowCount() === 1;
                $summary['rows']++;
                $summary[$lift === null ? 'unmatched' : 'matched']++;
                if ($row['kind'] === 'fault') {
                    $summary['faults']++;
                    if ($isNew && $mechanicId !== null) {
                        $summary['by_mechanic'][$mechanicId][] = [
                            'address' => $row['address'],
                            'codes' => implode(' ', $row['codes']),
                            'info' => $row['info'],
                            'open' => $row['finished_at'] === null,
                        ];
                    }
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * Последние отчёты для переключения между днями.
     *
     * @return list<array<string, mixed>>
     */
    public function reports(int $limit = 30): array
    {
        $rows = $this->pdo->query(
            'SELECT r.id, r.period_from, r.period_to, r.section, r.created_at, '
            . "(SELECT COUNT(*) FROM repair_calls c WHERE c.report_id = r.id AND c.kind = 'fault') AS faults "
            . 'FROM repair_reports r ORDER BY r.period_to DESC, r.id DESC LIMIT ' . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'period_from' => self::iso($r['period_from']),
            'period_to' => self::iso($r['period_to']),
            'section' => $r['section'],
            'faults' => (int) $r['faults'],
        ], $rows);
    }

    /**
     * Заявки одного отчёта. Мастер видит все, механик — по своим лифтам, в том числе ещё
     * не определённым точно (по адресу несколько лифтов, но все его): лифт он выбирает сам.
     * С $all механик видит и заявки всего участка — только посмотреть: mine = false.
     *
     * @return list<array<string, mixed>>
     */
    public function calls(Identity $who, bool $asMaster, int $reportId, bool $all = false): array
    {
        return $this->select($who, $asMaster, $all, 'c.report_id = ?', [$reportId]);
    }

    /**
     * Заявки от мастера (не из 1С): открытые и закрытые за последнюю неделю. Права — как у calls().
     *
     * @return list<array<string, mixed>>
     */
    public function manualCalls(Identity $who, bool $asMaster, bool $all = false): array
    {
        return $this->select($who, $asMaster, $all, 'c.report_id IS NULL AND (c.journal_entry_id IS NULL OR c.created_at >= NOW() - INTERVAL 7 DAY)', []);
    }

    /**
     * Мастер оставляет заявку на лифт сам. Механик лифта видит её в «Заявках» и получает уведомление.
     *
     * @param list<string> $codes
     * @return array{item: array<string, mixed>, mechanic_id: int|null}
     */
    public function createManual(Identity $who, int $liftId, string $info, array $codes, bool $stopped, DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare('SELECT id, address, reg_number, mechanic_id FROM lifts WHERE id = ?');
        $stmt->execute([$liftId]);
        $lift = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lift === false) {
            throw new HttpException(400, 'Ошибка в данных', ['lift_id' => 'Такого лифта нет']);
        }
        $this->pdo->prepare(
            'INSERT INTO repair_calls (report_id, lift_reg, address, started_at, stopped, info, codes, kind, lift_id, mechanic_id, created_by) '
            . "VALUES (NULL, ?, ?, ?, ?, ?, ?, 'fault', ?, ?, ?)"
        )->execute([
            $lift['reg_number'] !== null && $lift['reg_number'] !== '' ? $lift['reg_number'] : null,
            mb_substr($lift['address'], 0, 190), $now->format('Y-m-d H:i:s'), (int) $stopped, $info,
            $codes === [] ? null : implode(' ', $codes), $liftId, $lift['mechanic_id'], $who->login,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        return [
            'item' => $this->select($who, true, true, 'c.id = ?', [$id])[0],
            'mechanic_id' => $lift['mechanic_id'] === null ? null : (int) $lift['mechanic_id'],
        ];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(Identity $who, bool $asMaster, bool $all, string $where, array $params): array
    {
        $me = $asMaster ? null : ($who->mechanicId() ?? throw new HttpException(403, 'Войдите как механик'));
        if ($me !== null && !$all) {
            $where .= ' AND COALESCE(l.mechanic_id, c.mechanic_id) = ?';
            $params[] = $me;
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.*, l.address AS lift_address, l.object_name, l.lift_no, l.entrance, COALESCE(l.mechanic_id, c.mechanic_id) AS owner_id, m.name AS mechanic_name '
            . ', j.author_id AS closer_id, j.status AS closed_status, j.occurred_at AS closed_at, cm.name AS closer_name '
            . 'FROM repair_calls c LEFT JOIN lifts l ON l.id = c.lift_id LEFT JOIN mechanics m ON m.id = COALESCE(l.mechanic_id, c.mechanic_id) '
            . 'LEFT JOIN journal_entries j ON j.id = c.journal_entry_id LEFT JOIN mechanics cm ON cm.id = j.author_id '
            . "WHERE $where ORDER BY (c.finished_at IS NULL) DESC, c.started_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = $this->liftIndex();

        return array_map(function (array $r) use ($index, $me): array {
            $mine = $me === null || ($r['owner_id'] !== null && (int) $r['owner_id'] === $me);
            $item = [
                'id' => (int) $r['id'],
                'reg' => $r['lift_reg'],
                'address' => $r['address'],
                'floor' => $r['floor'],
                'started_at' => self::iso($r['started_at']),
                'finished_at' => $r['finished_at'] === null ? null : self::iso($r['finished_at']),
                'stopped' => (bool) $r['stopped'],
                'stops_30d' => (int) $r['stops_30d'],
                'info' => $r['info'],
                'codes' => $r['codes'] === null ? [] : explode(' ', $r['codes']),
                'kind' => $r['kind'],
                // Заявка от мастера лично, не из 1С
                'by_master' => $r['report_id'] === null,
                'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'],
                // Кто закрыл заявку записью в журнал: механик или мастер (запись без автора-механика)
                'closed_by' => $r['journal_entry_id'] === null ? null : ($r['closer_name'] ?? 'Мастер'),
                // Итог записи: resolved — заявка выполнена, остальное (наблюдать, повторный выезд) — ещё висит
                'closed_status' => $r['closed_status'],
                // Когда сделана работа по записи журнала (время из записи, местное)
                'closed_at' => $r['closed_at'] === null ? null : self::iso($r['closed_at']),
                'lift' => $r['lift_id'] === null ? null : [
                    'id' => (int) $r['lift_id'],
                    'address' => $r['lift_address'],
                    'object_name' => $r['object_name'] !== '' ? $r['object_name'] : null,
                    'lift_no' => (int) $r['lift_no'],
                    'entrance' => $r['entrance'],
                    'mechanic' => $r['mechanic_name'],
                ],
                // Чья заявка: механик лифта или (лифт не уточнён) механик всех лифтов дома
                'mechanic' => $r['mechanic_name'],
                'mine' => $mine,
                'candidates' => [],
            ];
            if ($r['lift_id'] === null && $mine) {
                // Механику — только его лифты по адресу
                $lifts = array_filter(
                    $index['address'][self::addressKey((string) $r['address'])] ?? [],
                    static fn (array $l): bool => $me === null || $l['mechanic_id'] === $me,
                );
                $item['candidates'] = array_values(array_map(static fn (array $l): array => [
                    'id' => $l['id'], 'address' => $l['address'], 'object_name' => $l['object_name'],
                    'lift_no' => $l['lift_no'], 'entrance' => $l['entrance'], 'mechanic' => $l['mechanic'], 'reg' => $l['reg'] !== '' ? $l['reg'] : null,
                ], $lifts));
            }

            return $item;
        }, $rows);
    }

    /**
     * Указать лифт заявки: номер из 1С запоминается у лифта, и все заявки с этим номером
     * (прошлые и будущие) сопоставляются сами. Мастер выбирает любой лифт, механик — свой
     * и только в своей заявке.
     */
    public function assignLift(Identity $who, bool $asMaster, int $callId, int $liftId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.lift_reg, COALESCE(l.mechanic_id, c.mechanic_id) AS mechanic_id FROM repair_calls c LEFT JOIN lifts l ON l.id = c.lift_id WHERE c.id = ?'
        );
        $stmt->execute([$callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        $me = $asMaster ? null : ($who->mechanicId() ?? throw new HttpException(403, 'Войдите как механик или мастер'));
        if ($call === false || ($me !== null && (int) $call['mechanic_id'] !== $me)) {
            throw new HttpException(404, 'Заявка не найдена');
        }
        $reg = $call['lift_reg'];
        $stmt = $this->pdo->prepare('SELECT mechanic_id FROM lifts WHERE id = ?');
        $stmt->execute([$liftId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || ($me !== null && (int) $owner !== $me)) {
            throw new HttpException(400, 'Ошибка в данных', ['lift_id' => $owner === false ? 'Такого лифта нет' : 'Это не ваш лифт: чужой лифт указывает мастер']);
        }

        $this->pdo->beginTransaction();
        try {
            // Номер мог быть ошибочно записан у другого лифта — снимаем его там
            $canonical = self::canonicalReg($reg);
            $this->pdo->prepare('UPDATE lifts SET reg_number = NULL WHERE reg_number IN (?, ?) AND id <> ?')->execute([$canonical, $reg, $liftId]);
            $this->pdo->prepare('UPDATE lifts SET reg_number = ? WHERE id = ?')->execute([$canonical, $liftId]);
            $this->pdo->prepare('UPDATE repair_calls SET lift_id = ?, mechanic_id = ? WHERE lift_reg = ?')->execute([$liftId, $owner, $reg]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Заявка закрыта записью в журнал. Закрыть может любой механик (своей записью) или мастер,
     * закрытую — повторно нельзя. Если лифт заявки ещё не был известен, он берётся из записи
     * (по правилам assignLift: механик — только свой лифт).
     *
     * @return array{owner_id: int|null, address: string, reg: string|null, status: string, title: string} для уведомлений
     */
    public function linkJournal(Identity $who, bool $asMaster, int $callId, int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.lift_id, c.lift_reg, c.address, c.journal_entry_id, pj.status AS prev_status, COALESCE(l.mechanic_id, c.mechanic_id) AS mechanic_id '
            . 'FROM repair_calls c LEFT JOIN lifts l ON l.id = c.lift_id LEFT JOIN journal_entries pj ON pj.id = c.journal_entry_id WHERE c.id = ?'
        );
        $stmt->execute([$callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        // Закрыть можно и чужую заявку: механик бригады подменил товарища или был рядом
        if ($call === false) {
            throw new HttpException(404, 'Заявка не найдена');
        }
        if (!$asMaster && $who->mechanicId() === null) {
            throw new HttpException(403, 'Войдите как механик или мастер');
        }
        // Закрытую с итогом «устранено» повторно не закрыть; «наблюдать», «повторный выезд» — можно дописать
        if ($call['journal_entry_id'] !== null && $call['prev_status'] === 'resolved') {
            throw new HttpException(409, 'Заявка уже закрыта записью в журнале');
        }
        $stmt = $this->pdo->prepare('SELECT lift_id, author_id, status, title FROM journal_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        $entryLift = $entry === false ? false : $entry['lift_id'];
        if ($entry === false || ($call['lift_id'] !== null && (int) $entryLift !== (int) $call['lift_id'])) {
            throw new HttpException(400, 'Ошибка в данных', ['entry_id' => 'Запись журнала не найдена или по другому лифту']);
        }
        if (!$asMaster && (int) $entry['author_id'] !== $who->mechanicId()) {
            throw new HttpException(400, 'Ошибка в данных', ['entry_id' => 'Заявку закрывают своей записью в журнале']);
        }
        $this->pdo->prepare('UPDATE repair_calls SET journal_entry_id = ? WHERE id = ?')->execute([$entryId, $callId]);
        if ($call['lift_id'] === null) {
            try {
                $this->assignLift($who, $asMaster, $callId, (int) $entryLift);
            } catch (HttpException) {
                // Запись по чужому лифту: заявка закрыта, лифт укажет мастер
            }
        }

        return [
            'owner_id' => $call['mechanic_id'] === null ? null : (int) $call['mechanic_id'],
            'address' => $call['address'],
            'reg' => $call['lift_reg'],
            'status' => $entry['status'],
            'title' => $entry['title'],
        ];
    }

    /**
     * Ключ адреса для сопоставления: последнее слово улицы и номер дома.
     * «Я.КУПАЛЫ 108/2 п.1» и «Янки Купалы 108/2» дают «купалы 108/2».
     */
    public static function addressKey(string $address): string
    {
        $a = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $address));
        $a = preg_replace('/\([^)]*\)/u', ' ', $a);
        // Подъезд: «п.3», «1п.», «под. 2»
        $a = preg_replace('/(?<=\s|^)(?:п\.|под\.?|подъезд)\s*\d+\b/u', ' ', $a);
        $a = preg_replace('/(?<=\s)\d+\s*п\.?(?![\p{L}])/u', ' ', $a);
        if (!preg_match('/([\p{L}-]+)[\s.,]+(\d+[\p{L}]?(?:\/\d*[\p{L}]?)?)/u', $a, $m)) {
            return trim(preg_replace('/\s+/u', ' ', $a));
        }

        return $m[1] . ' ' . rtrim($m[2], '/');
    }

    /**
     * Номер лифта без пробелов и буквы «н» в конце: в 1С «2508н», в карточках лифтов «2508».
     */
    public static function canonicalReg(string $reg): string
    {
        return mb_strtoupper((string) preg_replace('/[нН]$/u', '', (string) preg_replace('/\s+/u', '', $reg)));
    }

    private static function regKey(string $reg): string
    {
        return self::canonicalReg($reg);
    }

    /**
     * @return array{reg: array<string, array<string, mixed>>, address: array<string, list<array<string, mixed>>>}
     */
    private function liftIndex(): array
    {
        $rows = $this->pdo->query(
            'SELECT l.id, l.address, l.object_name, l.lift_no, l.entrance, l.reg_number, l.mechanic_id, m.name AS mechanic '
            . 'FROM lifts l LEFT JOIN mechanics m ON m.id = l.mechanic_id ORDER BY l.address, l.lift_no'
        )->fetchAll(PDO::FETCH_ASSOC);

        $index = ['reg' => [], 'address' => []];
        foreach ($rows as $r) {
            $lift = [
                'id' => (int) $r['id'], 'address' => $r['address'], 'object_name' => $r['object_name'] !== '' ? $r['object_name'] : null,
                'lift_no' => (int) $r['lift_no'], 'entrance' => $r['entrance'], 'mechanic_id' => $r['mechanic_id'] === null ? null : (int) $r['mechanic_id'],
                'mechanic' => $r['mechanic'], 'reg' => $r['reg_number'] ?? '',
            ];
            if ($r['reg_number'] !== null && $r['reg_number'] !== '') {
                $index['reg'][self::regKey($r['reg_number'])] = $lift;
            }
            $index['address'][self::addressKey($r['address'])][] = $lift;
        }

        return $index;
    }

    /**
     * @param array{reg: array<string, array<string, mixed>>, address: array<string, list<array<string, mixed>>>} $index
     * @return array<string, mixed>|null
     */
    private function findLift(array $index, string $reg, string $address): ?array
    {
        if (isset($index['reg'][self::regKey($reg)])) {
            return $index['reg'][self::regKey($reg)] + ['by' => 'reg'];
        }
        // По адресу — только среди лифтов без номера: лифт с другим номером — это другой лифт
        // (в отчёте бывают разные подъезды одного дома)
        $free = array_values(array_filter(
            $index['address'][self::addressKey($address)] ?? [],
            static fn (array $l): bool => $l['reg'] === '',
        ));

        return count($free) === 1 ? $free[0] + ['by' => 'address'] : null;
    }

    /**
     * Механик по адресу, если все лифты этого дома у него. Иначе null — решает мастер.
     *
     * @param array{reg: array<string, array<string, mixed>>, address: array<string, list<array<string, mixed>>>} $index
     */
    private static function addressMechanic(array $index, string $address): ?int
    {
        $mechanics = array_unique(array_column($index['address'][self::addressKey($address)] ?? [], 'mechanic_id'));

        return count($mechanics) === 1 && $mechanics[array_key_first($mechanics)] !== null ? (int) $mechanics[array_key_first($mechanics)] : null;
    }

    private static function iso(mixed $value): string
    {
        // Время из 1С местное, без пояса: отдаём как есть
        return str_replace(' ', 'T', (string) $value);
    }
}
