<?php

declare(strict_types=1);

namespace App\Comms;

use App\Auth\Identity;
use App\Http\HttpException;
use PDO;

/**
 * Общий чат бригады и заявки на запчасти.
 *
 * Чат видят все, кто вошёл. Заявку видят её автор (механик) и мастер. Переписка по заявке
 * и сообщения чата хранятся в messages, фото — в attachments (файлы — в PhotoStore).
 */
final class CommsRepository
{
    public const STATUSES = ['new', 'accepted', 'ordered', 'ready', 'issued', 'rejected'];
    public const OPEN = ['new', 'accepted', 'ordered', 'ready'];
    public const MAX_PHOTOS = 4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ---------- Фото ----------

    /**
     * @param array{bytes: string, mime: string, width: int, height: int} $photo
     * @return array<string, mixed>
     */
    public function addAttachment(Identity $who, string $token, array $photo): array
    {
        $this->pdo->prepare('INSERT INTO attachments (token, uploader_login, mime, bytes, width, height) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$token, $who->login, $photo['mime'], strlen($photo['bytes']), $photo['width'], $photo['height']]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'url' => '/api/files/' . $token, 'width' => $photo['width'], 'height' => $photo['height']];
    }

    /**
     * Фото, загруженные, но так и не отправленные (больше суток назад): строки удаляются,
     * возвращаются токены файлов, чтобы удалить и их.
     *
     * @return list<string>
     */
    public function takeOrphans(): array
    {
        $tokens = $this->pdo->query('SELECT token FROM attachments WHERE message_id IS NULL AND created_at < NOW() - INTERVAL 1 DAY LIMIT 200')
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($tokens !== []) {
            $in = implode(',', array_fill(0, count($tokens), '?'));
            $this->pdo->prepare("DELETE FROM attachments WHERE token IN ($in)")->execute($tokens);
        }

        return array_map('strval', $tokens);
    }

    /**
     * Фото и кто его может смотреть. null — нет такого.
     *
     * @return array{mime: string, uploader_login: string, message_id: int|null, request_author: int|null, in_request: bool}|null
     */
    public function attachment(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.mime, a.uploader_login, a.message_id, m.request_id, r.author_id '
            . 'FROM attachments a LEFT JOIN messages m ON m.id = a.message_id LEFT JOIN part_requests r ON r.id = m.request_id '
            . 'WHERE a.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'mime' => $row['mime'],
            'uploader_login' => $row['uploader_login'],
            'message_id' => $row['message_id'] === null ? null : (int) $row['message_id'],
            'in_request' => $row['request_id'] !== null,
            'request_author' => $row['author_id'] === null ? null : (int) $row['author_id'],
        ];
    }

    // ---------- Общий чат ----------

    /**
     * Сообщения чата по возрастанию id: новые после after, страница до before или последние.
     *
     * @return list<array<string, mixed>>
     */
    public function chat(Identity $who, ?int $after, ?int $before, int $limit): array
    {
        if ($after !== null) {
            $rows = $this->messages('m.request_id IS NULL AND m.id > ? ORDER BY m.id LIMIT ' . $limit, [$after]);
        } else {
            $where = 'm.request_id IS NULL' . ($before !== null ? ' AND m.id < ?' : '');
            $rows = array_reverse($this->messages($where . ' ORDER BY m.id DESC LIMIT ' . $limit, $before !== null ? [$before] : []));
        }

        return $this->shapeMessages($who, $rows);
    }

    /**
     * @param list<int> $photos
     * @return array<string, mixed>
     */
    public function postChat(Identity $who, bool $asMaster, ?string $body, array $photos): array
    {
        return $this->post($who, null, $asMaster, $body, null, $photos);
    }

    // ---------- Заявки на запчасти ----------

    /**
     * Список заявок: мастер видит все, механик — свои.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(Identity $who, bool $all, bool $openOnly): array
    {
        [$where, $params] = $this->requestScope($who, $all);
        if ($openOnly) {
            $where .= " AND r.status IN ('" . implode("','", self::OPEN) . "')";
        }
        $stmt = $this->pdo->prepare($this->requestSelect() . " WHERE $where ORDER BY r.updated_at DESC, r.id DESC LIMIT 200");
        $stmt->execute($params);

        return array_map([$this, 'shapeRequest'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Заявка с перепиской. Чужая для механика — 404, как и несуществующая.
     *
     * @return array<string, mixed>
     */
    public function request(Identity $who, int $id, bool $all): array
    {
        [$where, $params] = $this->requestScope($who, $all);
        $stmt = $this->pdo->prepare($this->requestSelect() . " WHERE $where AND r.id = ?");
        $stmt->execute([...$params, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new HttpException(404, 'Заявка не найдена');
        }

        return $this->shapeRequest($row) + [
            'messages' => $this->shapeMessages($who, $this->messages('m.request_id = ? ORDER BY m.id', [$id])),
        ];
    }

    /**
     * @param array{title: string, quantity: ?string, urgent: bool, lift_id: ?int, body: ?string} $data
     * @param list<int> $photos
     * @return array<string, mixed>
     */
    public function createRequest(Identity $who, array $data, array $photos): array
    {
        $mechanic = $who->mechanicId() ?? throw new HttpException(403, 'Заявку подаёт механик: войдите как механик');
        if ($data['lift_id'] !== null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM lifts WHERE id = ?');
            $stmt->execute([$data['lift_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new HttpException(400, 'Ошибка в данных', ['lift_id' => 'Такого лифта нет']);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO part_requests (author_id, lift_id, title, quantity, urgent) VALUES (?, ?, ?, ?, ?)')
                ->execute([$mechanic, $data['lift_id'], $data['title'], $data['quantity'], (int) $data['urgent']]);
            $id = (int) $this->pdo->lastInsertId();
            $this->post($who, $id, false, $data['body'], 'new', $photos, false);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->request($who, $id, false);
    }

    /**
     * Сообщение в переписке по заявке, при необходимости со сменой статуса.
     * Статус меняет мастер. Механик только отмечает «получил», когда деталь пришла.
     *
     * @param list<int> $photos
     * @return array<string, mixed>
     */
    public function postToRequest(Identity $who, int $id, bool $asMaster, ?string $body, ?string $status, array $photos): array
    {
        $request = $this->request($who, $id, $asMaster);
        if (!$asMaster && $request['author']['id'] !== $who->mechanicId()) {
            throw new HttpException(403, 'Писать в заявку могут её автор и мастер');
        }
        if ($status !== null) {
            if ($status === $request['status']) {
                $status = null;
            } elseif (!$asMaster && !($status === 'issued' && $request['status'] === 'ready')) {
                throw new HttpException(403, 'Статус заявки меняет мастер. Механик отмечает только «получил», когда деталь пришла');
            }
        }
        if ($status === null && $body === null && $photos === []) {
            throw new HttpException(400, 'Ошибка в данных', ['body' => 'Напишите сообщение или приложите фото']);
        }

        $this->pdo->beginTransaction();
        try {
            $message = $this->post($who, $id, $asMaster, $body, $status, $photos, false);
            $this->pdo->prepare('UPDATE part_requests SET updated_at = NOW()' . ($status !== null ? ', status = ?' : '') . ' WHERE id = ?')
                ->execute($status !== null ? [$status, $id] : [$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $message;
    }

    /**
     * Для значков «есть новое» на вкладках: последний id чата и время последнего изменения заявок.
     *
     * @return array{chat_last_id: int, requests_updated_at: string|null, requests_open: int}
     */
    public function updates(Identity $who, bool $all): array
    {
        [$where, $params] = $this->requestScope($who, $all);
        $stmt = $this->pdo->prepare(
            "SELECT MAX(r.updated_at), SUM(r.status IN ('" . implode("','", self::OPEN) . "')) FROM part_requests r WHERE $where"
        );
        $stmt->execute($params);
        [$updated, $open] = $stmt->fetch(PDO::FETCH_NUM);

        return [
            'chat_last_id' => (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM messages WHERE request_id IS NULL')->fetchColumn(),
            'requests_updated_at' => $updated === null ? null : self::iso($updated),
            'requests_open' => (int) $open,
        ];
    }

    // ---------- Внутреннее ----------

    /**
     * Время из MySQL (сервер и база работают в UTC) в ISO 8601 с Z: браузер покажет местное.
     */
    private static function iso(mixed $value): string
    {
        return str_replace(' ', 'T', (string) $value) . 'Z';
    }

    /**
     * @param list<int> $photos id загруженных этим пользователем и ещё не отправленных фото
     * @return array<string, mixed>
     */
    private function post(Identity $who, ?int $requestId, bool $asMaster, ?string $body, ?string $status, array $photos, bool $own = true): array
    {
        if ($body === null && $photos === [] && $status === null) {
            throw new HttpException(400, 'Ошибка в данных', ['body' => 'Напишите сообщение или приложите фото']);
        }
        if (!$asMaster && $who->mechanicId() === null) {
            throw new HttpException(403, 'Войдите как механик или как мастер');
        }

        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('INSERT INTO messages (request_id, author_login, author_id, as_master, body, status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$requestId, $who->login, $asMaster ? null : $who->mechanicId(), (int) $asMaster, $body, $status]);
            $id = (int) $this->pdo->lastInsertId();

            if ($photos !== []) {
                $in = implode(',', array_fill(0, count($photos), '?'));
                $stmt = $this->pdo->prepare("UPDATE attachments SET message_id = ? WHERE message_id IS NULL AND uploader_login = ? AND id IN ($in)");
                $stmt->execute([$id, $who->login, ...$photos]);
                if ($stmt->rowCount() !== count($photos)) {
                    throw new HttpException(400, 'Ошибка в данных', ['photos' => 'Фото не найдено или уже отправлено: загрузите заново']);
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->shapeMessages($who, $this->messages('m.id = ?', [$id]))[0];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function messages(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.request_id, m.author_login, m.author_id, m.as_master, m.body, m.status, m.created_at, me.name, me.section '
            . "FROM messages m LEFT JOIN mechanics me ON me.id = m.author_id WHERE $where"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function shapeMessages(Identity $who, array $rows): array
    {
        $photos = [];
        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT message_id, token, width, height FROM attachments WHERE message_id IN ($in) ORDER BY id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $photos[(int) $a['message_id']][] = ['url' => '/api/files/' . $a['token'], 'width' => (int) $a['width'], 'height' => (int) $a['height']];
            }
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'at' => self::iso($r['created_at']),
            'author' => (bool) $r['as_master']
                ? ['role' => 'master', 'id' => null, 'name' => 'Мастер', 'section' => null]
                : ['role' => 'mechanic', 'id' => $r['author_id'] === null ? null : (int) $r['author_id'], 'name' => $r['name'] ?? 'Механик', 'section' => $r['section']],
            'mine' => $r['author_login'] === $who->login,
            'body' => $r['body'],
            'status' => $r['status'],
            'photos' => $photos[(int) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * @return array{string, list<int>}
     */
    private function requestScope(Identity $who, bool $all): array
    {
        if ($all) {
            if (!$who->isMaster) {
                throw new HttpException(403, 'Все заявки видит только мастер');
            }

            return ['1 = 1', []];
        }
        $me = $who->mechanicId() ?? throw new HttpException(403, 'Войдите как механик');

        return ['r.author_id = ?', [$me]];
    }

    private function requestSelect(): string
    {
        return 'SELECT r.id, r.title, r.quantity, r.urgent, r.status, r.created_at, r.updated_at, '
            . 'me.id AS author_id, me.name AS author_name, me.section AS author_section, '
            . 'l.id AS lift_id, l.address, l.object_name, l.lift_no, '
            . '(SELECT COUNT(*) FROM messages x WHERE x.request_id = r.id) AS messages_count, '
            . '(SELECT COUNT(*) FROM attachments a JOIN messages x ON x.id = a.message_id WHERE x.request_id = r.id) AS photos_count, '
            . '(SELECT x.body FROM messages x WHERE x.request_id = r.id AND x.body IS NOT NULL ORDER BY x.id DESC LIMIT 1) AS last_body, '
            . '(SELECT x.as_master FROM messages x WHERE x.request_id = r.id ORDER BY x.id DESC LIMIT 1) AS last_by_master '
            . 'FROM part_requests r JOIN mechanics me ON me.id = r.author_id LEFT JOIN lifts l ON l.id = r.lift_id';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shapeRequest(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'quantity' => $r['quantity'],
            'urgent' => (bool) $r['urgent'],
            'status' => $r['status'],
            'created_at' => self::iso($r['created_at']),
            'updated_at' => self::iso($r['updated_at']),
            'author' => ['id' => (int) $r['author_id'], 'name' => $r['author_name'], 'section' => $r['author_section']],
            'lift' => $r['lift_id'] === null ? null : [
                'id' => (int) $r['lift_id'], 'address' => $r['address'], 'object_name' => $r['object_name'], 'lift_no' => (int) $r['lift_no'],
            ],
            'messages_count' => (int) $r['messages_count'],
            'photos_count' => (int) $r['photos_count'],
            'last_body' => $r['last_body'],
            'last_by_master' => (bool) $r['last_by_master'],
        ];
    }
}
