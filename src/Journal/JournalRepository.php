<?php

declare(strict_types=1);

namespace App\Journal;

use App\Auth\Identity;
use App\Http\HttpException;
use PDO;

/**
 * Механики, их лифты и записи журнала.
 *
 * Кто что видит:
 *   мастер — все лифты и все записи;
 *   механик — все лифты бригады (чтобы записать и на чужой лифт при подмене),
 *             но записи только по своим лифтам и свои собственные.
 */
final class JournalRepository
{
    public const STATUSES = ['resolved', 'monitoring', 'repeat_visit', 'unresolved'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mechanics(): array
    {
        $rows = $this->pdo->query(
            'SELECT m.id, m.name, m.section, m.login IS NOT NULL AS linked, COUNT(l.id) AS lifts '
            . 'FROM mechanics m LEFT JOIN lifts l ON l.mechanic_id = m.id '
            . 'GROUP BY m.id, m.name, m.section, m.login ORDER BY m.section, m.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'section' => $r['section'],
            'linked' => (bool) $r['linked'],
            'lifts' => (int) $r['lifts'],
        ], $rows);
    }

    /**
     * Привязывает аккаунт к механику. Чужого механика (уже привязанного к другому аккаунту) взять нельзя.
     */
    public function link(string $login, int $mechanicId): void
    {
        $stmt = $this->pdo->prepare('SELECT login FROM mechanics WHERE id = ?');
        $stmt->execute([$mechanicId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            throw new HttpException(404, 'Механик не найден');
        }
        if ($current !== null && $current !== $login) {
            throw new HttpException(409, 'Этот механик уже привязан к другому аккаунту. Обратитесь к мастеру');
        }

        $this->pdo->prepare('UPDATE mechanics SET login = NULL WHERE login = ?')->execute([$login]);
        $this->pdo->prepare('UPDATE mechanics SET login = ? WHERE id = ?')->execute([$login, $mechanicId]);
    }

    /**
     * Лифты, сгруппированные по механику и адресу: так их удобно выбирать при сохранении записи.
     *
     * @return list<array<string, mixed>>
     */
    public function lifts(?int $mechanicId): array
    {
        $sql = 'SELECT l.id, l.address, l.object_name, l.lift_no, l.reg_number, l.notes, l.mechanic_id, '
            . 'mo.id AS model_id, mo.name AS model_name, '
            . '(SELECT COUNT(*) FROM journal_entries j WHERE j.lift_id = l.id) AS entries, '
            . '(SELECT MAX(j.occurred_at) FROM journal_entries j WHERE j.lift_id = l.id) AS last_entry '
            . 'FROM lifts l LEFT JOIN models mo ON mo.id = l.model_id';
        $params = [];
        if ($mechanicId !== null) {
            $sql .= ' WHERE l.mechanic_id = ?';
            $params[] = $mechanicId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY l.mechanic_id, l.address, l.object_name, l.lift_no');
        $stmt->execute($params);

        $places = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['mechanic_id'] . '|' . $r['address'] . '|' . $r['object_name'];
            $places[$key] ??= [
                'mechanic_id' => $r['mechanic_id'] === null ? null : (int) $r['mechanic_id'],
                'address' => $r['address'],
                'object_name' => $r['object_name'] !== '' ? $r['object_name'] : null,
                'lifts' => [],
            ];
            $places[$key]['lifts'][] = [
                'id' => (int) $r['id'],
                'lift_no' => (int) $r['lift_no'],
                'reg_number' => $r['reg_number'],
                'notes' => $r['notes'],
                'model' => $r['model_id'] === null ? null : ['id' => (int) $r['model_id'], 'name' => $r['model_name']],
                'entries' => (int) $r['entries'],
                'last_entry' => $r['last_entry'],
            ];
        }

        return array_values($places);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lift(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.address, l.object_name, l.lift_no, l.mechanic_id, l.model_id, m.name AS mechanic_name '
            . 'FROM lifts l LEFT JOIN mechanics m ON m.id = l.mechanic_id WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : $r;
    }

    /**
     * @param array<string, mixed> $data проверенные поля записи
     * @return array<string, mixed>
     */
    public function create(Identity $who, array $data): array
    {
        $lift = $this->lift((int) $data['lift_id']) ?? throw new HttpException(400, 'Ошибка в данных', ['lift_id' => 'Лифт не найден']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO journal_entries (lift_id, author_id, occurred_at, codes, title, symptoms, cause, work_done, '
            . 'recommendation, status, source, chat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $lift['id'], $who->mechanicId(), $data['occurred_at'], $data['codes'], $data['title'], $data['symptoms'],
            $data['cause'], $data['work_done'], $data['recommendation'], $data['status'], $data['source'], $data['chat'],
        ]);

        return $this->entries($who, ['id' => (int) $this->pdo->lastInsertId()], 1, 0)['items'][0];
    }

    public function delete(Identity $who, int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT author_id FROM journal_entries WHERE id = ?');
        $stmt->execute([$id]);
        $author = $stmt->fetchColumn();
        if ($author === false) {
            throw new HttpException(404, 'Запись не найдена');
        }
        if (!$who->isMaster && (int) $author !== $who->mechanicId()) {
            throw new HttpException(403, 'Удалить запись может её автор или мастер');
        }
        $this->pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
    }

    /**
     * Записи журнала с учётом прав.
     *
     * @param array{id?: int, lift_id?: int, mechanic_id?: int, q?: string} $filter
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function entries(Identity $who, array $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->scope($who, $filter);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries j JOIN lifts l ON l.id = j.lift_id WHERE $where");
        $count->execute($params);

        $stmt = $this->pdo->prepare(
            'SELECT j.*, l.address, l.object_name, l.lift_no, l.mechanic_id AS lift_mechanic_id, '
            . 'lm.name AS lift_mechanic_name, a.name AS author_name '
            . 'FROM journal_entries j JOIN lifts l ON l.id = j.lift_id '
            . 'LEFT JOIN mechanics lm ON lm.id = l.mechanic_id LEFT JOIN mechanics a ON a.id = j.author_id '
            . "WHERE $where ORDER BY j.occurred_at DESC, j.id DESC LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'items' => array_map([$this, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /**
     * Повторы по лифтам и кодам за последние 90 дней — то, на что механик и мастер смотрят в первую очередь.
     *
     * @param array{lift_id?: int, mechanic_id?: int} $filter
     * @return array<string, mixed>
     */
    public function stats(Identity $who, array $filter): array
    {
        [$where, $params] = $this->scope($who, $filter);
        $where .= ' AND j.occurred_at >= NOW() - INTERVAL 90 DAY';

        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.address, l.object_name, l.lift_no, COUNT(*) AS n, MAX(j.occurred_at) AS last_at, '
            . "SUM(j.status IN ('repeat_visit', 'unresolved')) AS open_n "
            . "FROM journal_entries j JOIN lifts l ON l.id = j.lift_id WHERE $where "
            . 'GROUP BY l.id, l.address, l.object_name, l.lift_no ORDER BY n DESC, last_at DESC LIMIT 10'
        );
        $stmt->execute($params);
        $lifts = array_map(static fn (array $r): array => [
            'lift_id' => (int) $r['id'],
            'address' => $r['address'],
            'object_name' => $r['object_name'] !== '' ? $r['object_name'] : null,
            'lift_no' => (int) $r['lift_no'],
            'entries' => (int) $r['n'],
            'open' => (int) $r['open_n'],
            'last_at' => $r['last_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $this->pdo->prepare("SELECT j.codes FROM journal_entries j JOIN lifts l ON l.id = j.lift_id WHERE $where AND j.codes IS NOT NULL");
        $stmt->execute($params);
        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $list) {
            foreach (preg_split('/[\s,]+/u', (string) $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }
        arsort($codes);

        return [
            'lifts' => $lifts,
            'codes' => array_map(static fn (string $c, int $n): array => ['code' => $c, 'entries' => $n], array_keys($codes), $codes),
        ];
    }

    /**
     * Краткая техническая история лифта для ИИ: даты, коды, что было и что сделано.
     * Без адреса, номера лифта и фамилий — это уходит во внешний сервис.
     *
     * @return list<array<string, string|null>>
     */
    public function historyForAi(int $liftId, int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DATE(occurred_at) AS d, codes, title, cause, work_done, status FROM journal_entries '
            . "WHERE lift_id = ? ORDER BY occurred_at DESC LIMIT $limit"
        );
        $stmt->execute([$liftId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{string, list<mixed>}
     */
    private function scope(Identity $who, array $filter): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!$who->isMaster) {
            $me = $who->mechanicId() ?? throw new HttpException(403, 'Выберите себя в списке механиков');
            $where[] = '(l.mechanic_id = ? OR j.author_id = ?)';
            array_push($params, $me, $me);
        }
        foreach (['id' => 'j.id', 'lift_id' => 'j.lift_id', 'mechanic_id' => 'l.mechanic_id'] as $key => $column) {
            if (isset($filter[$key])) {
                $where[] = "$column = ?";
                $params[] = (int) $filter[$key];
            }
        }
        if (($filter['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $filter['q'], '%_\\') . '%';
            $where[] = '(l.address LIKE ? OR l.object_name LIKE ? OR j.codes LIKE ? OR j.title LIKE ? OR j.symptoms LIKE ? OR j.work_done LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'occurred_at' => $r['occurred_at'],
            'lift' => [
                'id' => (int) $r['lift_id'],
                'address' => $r['address'],
                'object_name' => $r['object_name'] !== '' ? $r['object_name'] : null,
                'lift_no' => (int) $r['lift_no'],
                'mechanic' => $r['lift_mechanic_id'] === null ? null : ['id' => (int) $r['lift_mechanic_id'], 'name' => $r['lift_mechanic_name']],
            ],
            'author' => $r['author_id'] === null ? null : ['id' => (int) $r['author_id'], 'name' => $r['author_name']],
            'codes' => $r['codes'],
            'title' => $r['title'],
            'symptoms' => $r['symptoms'],
            'cause' => $r['cause'],
            'work_done' => $r['work_done'],
            'recommendation' => $r['recommendation'],
            'status' => $r['status'],
            'source' => $r['source'],
            'created_at' => $r['created_at'],
        ];
    }
}
