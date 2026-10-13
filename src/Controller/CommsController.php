<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Identity;
use App\Auth\IdentityResolver;
use App\Comms\CommsRepository;
use App\Comms\PhotoStore;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

/**
 * Связь в бригаде. От имени мастера пишет тот, у кого есть права мастера и кто передал
 * "as": "master" (в интерфейсе — роль «мастер»), иначе — от имени своего механика.
 *
 *   POST /api/uploads                     {data: "data:image/jpeg;base64,..."} — фото, до отправки
 *   GET  /api/files/{token}               фото
 *   GET  /api/chat?after=&before=         общий чат
 *   POST /api/chat                        {body, photos: [id], as}
 *   GET  /api/requests?as=master&open=1   заявки на запчасти
 *   POST /api/requests                    {title, quantity, urgent, lift_id, body, photos}
 *   GET  /api/requests/{id}?as=master
 *   POST /api/requests/{id}/messages      {body, photos, status, as}
 *   GET  /api/updates?as=master           для значков «есть новое»
 */
final class CommsController
{
    private const BODY_MAX = 2000;

    public function __construct(
        private readonly IdentityResolver $auth,
        private readonly CommsRepository $comms,
        private readonly PhotoStore $photos,
    ) {
    }

    public function upload(Request $request): Response
    {
        $who = $this->auth->require($request);
        $this->allowedFields($request->body, ['data']);
        $photo = PhotoStore::decode($request->body['data'] ?? null);

        foreach ($this->comms->takeOrphans() as $token) {
            $this->photos->delete($token);
        }
        $token = bin2hex(random_bytes(16));
        $this->photos->save($token, $photo['bytes']);

        return Response::json(['item' => $this->comms->addAttachment($who, $token, $photo)], 201);
    }

    /**
     * Фото отдаётся тому, кто может видеть сообщение: чат — всем, заявка — автору и мастеру.
     * В демо картинка грузится обычным <img> без демо-заголовков, поэтому там хватает
     * случайного токена в адресе (данные демо вымышлены).
     */
    public function file(Request $request): Response
    {
        $token = (string) $request->param('token');
        $info = preg_match('/^[0-9a-f]{32}$/', $token) ? $this->comms->attachment($token) : null;
        if ($info === null) {
            throw new HttpException(404, 'Фото не найдено');
        }

        $who = $this->auth->resolve($request);
        if ($who === null && !$this->auth->isDemo()) {
            throw new HttpException(401, 'Вход только через Tailscale: откройте сайт по адресу *.ts.net');
        }
        if ($who !== null && !$this->auth->isDemo()) {
            $allowed = $info['uploader_login'] === $who->login
                || ($info['message_id'] !== null && (!$info['in_request'] || $who->isMaster || $info['request_author'] === $who->mechanicId()));
            if (!$allowed) {
                throw new HttpException(404, 'Фото не найдено');
            }
        }

        $bytes = $this->photos->read($token) ?? throw new HttpException(404, 'Фото не найдено');

        return new Response(200, $bytes, [
            'Content-Type' => $info['mime'],
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function chat(Request $request): Response
    {
        $who = $this->auth->require($request);
        $after = $this->optionalId($request, 'after');
        $before = $this->optionalId($request, 'before');

        return Response::json(['items' => $this->comms->chat($who, $after, $before, $after !== null ? 100 : 50)]);
    }

    public function postChat(Request $request): Response
    {
        $who = $this->auth->require($request);
        $body = $request->body;
        $this->allowedFields($body, ['body', 'photos', 'as']);

        return Response::json(['item' => $this->comms->postChat(
            $who,
            $this->asMaster($who, $body['as'] ?? null),
            $this->text($body['body'] ?? null, 'body', self::BODY_MAX),
            $this->photoIds($body['photos'] ?? []),
        )], 201);
    }

    public function requests(Request $request): Response
    {
        $who = $this->auth->require($request);

        return Response::json(['items' => $this->comms->requests(
            $who,
            $this->asMaster($who, $request->queryString('as')),
            $request->queryString('open') === '1',
        )]);
    }

    public function showRequest(Request $request): Response
    {
        $who = $this->auth->require($request);

        return Response::json(['item' => $this->comms->request($who, $this->id($request), $this->asMaster($who, $request->queryString('as')))]);
    }

    public function createRequest(Request $request): Response
    {
        $who = $this->auth->require($request);
        $body = $request->body;
        $this->allowedFields($body, ['title', 'quantity', 'urgent', 'lift_id', 'body', 'photos']);

        $errors = [];
        $data = [];
        foreach (['title' => 255, 'quantity' => 50, 'body' => self::BODY_MAX] as $field => $max) {
            try {
                $data[$field] = $this->text($body[$field] ?? null, $field, $max);
            } catch (HttpException $e) {
                $errors += $e->fields;
            }
        }
        if (($data['title'] ?? null) === null && !isset($errors['title'])) {
            $errors['title'] = 'Напишите, что нужно';
        }
        $urgent = $body['urgent'] ?? false;
        if (!is_bool($urgent)) {
            $errors['urgent'] = 'Ожидается true или false';
        }
        $lift = $body['lift_id'] ?? null;
        if ($lift !== null && (!is_int($lift) || $lift <= 0)) {
            $errors['lift_id'] = 'Ожидается id лифта';
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }

        return Response::json(['item' => $this->comms->createRequest($who, [
            'title' => (string) $data['title'],
            'quantity' => $data['quantity'],
            'urgent' => $urgent,
            'lift_id' => $lift,
            'body' => $data['body'],
        ], $this->photoIds($body['photos'] ?? []))], 201);
    }

    public function postToRequest(Request $request): Response
    {
        $who = $this->auth->require($request);
        $body = $request->body;
        $this->allowedFields($body, ['body', 'photos', 'status', 'as']);

        $status = $body['status'] ?? null;
        if ($status !== null && !in_array($status, CommsRepository::STATUSES, true)) {
            throw new HttpException(400, 'Ошибка в данных', ['status' => 'Допустимо: ' . implode(', ', CommsRepository::STATUSES)]);
        }

        return Response::json(['item' => $this->comms->postToRequest(
            $who,
            $this->id($request),
            $this->asMaster($who, $body['as'] ?? null),
            $this->text($body['body'] ?? null, 'body', self::BODY_MAX),
            $status,
            $this->photoIds($body['photos'] ?? []),
        )], 201);
    }

    public function updates(Request $request): Response
    {
        $who = $this->auth->require($request);
        $asMaster = $this->asMaster($who, $request->queryString('as'));
        if (!$asMaster && $who->mechanicId() === null) {
            return Response::json(['chat_last_id' => 0, 'requests_updated_at' => null, 'requests_open' => 0]);
        }

        return Response::json($this->comms->updates($who, $asMaster));
    }

    private function asMaster(Identity $who, mixed $as): bool
    {
        if ($as === null || $as === 'mechanic') {
            return false;
        }
        if ($as !== 'master') {
            throw new HttpException(400, 'Ошибка в данных', ['as' => 'Допустимо: master, mechanic']);
        }
        if (!$who->isMaster) {
            throw new HttpException(403, 'У этого аккаунта нет прав мастера');
        }

        return true;
    }

    private function text(mixed $value, string $field, int $max): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new HttpException(400, 'Ошибка в данных', [$field => 'Ожидается строка']);
        }
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value) > $max) {
            throw new HttpException(400, 'Ошибка в данных', [$field => "Не длиннее $max символов"]);
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<int>
     */
    private function photoIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > CommsRepository::MAX_PHOTOS) {
            throw new HttpException(400, 'Ошибка в данных', ['photos' => 'До ' . CommsRepository::MAX_PHOTOS . ' фото']);
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new HttpException(400, 'Ошибка в данных', ['photos' => 'Ожидаются id загруженных фото']);
            }
        }

        return array_values(array_unique($value));
    }

    private function id(Request $request): int
    {
        $id = (string) $request->param('id');

        return ctype_digit($id) && $id !== '0' ? (int) $id : throw new HttpException(404, 'Заявка не найдена');
    }

    private function optionalId(Request $request, string $name): ?int
    {
        $value = $request->queryString($name);
        if ($value !== null && !ctype_digit($value)) {
            throw new HttpException(400, 'Ошибка в данных', [$name => 'Ожидается число']);
        }

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    private function allowedFields(array $body, array $allowed): void
    {
        $unknown = array_diff(array_map('strval', array_keys($body)), $allowed);
        if ($unknown !== []) {
            throw new HttpException(400, 'Ошибка в данных', array_fill_keys($unknown, 'Неизвестное поле'));
        }
    }
}
