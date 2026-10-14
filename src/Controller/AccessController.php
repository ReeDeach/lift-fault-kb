<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Identity;
use App\Auth\IdentityResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use PDO;

/**
 * Кто может войти из интернета (ngrok, вход через Google). Ведёт мастер.
 *
 *   GET  /api/access          мастера, механики с привязанным аккаунтом, список «Доступ»
 *   POST /api/access          {login, note} — добавить почту
 *   POST /api/access/remove   {login} — убрать почту
 */
final class AccessController
{
    public function __construct(
        private readonly IdentityResolver $auth,
        private readonly PDO $pdo,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->master($request);
        $mechanics = $this->pdo->query('SELECT name, login FROM mechanics WHERE login IS NOT NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $added = $this->pdo->query(
            'SELECT a.login, a.note, a.created_at, m.name AS mechanic FROM access_logins a '
            . 'LEFT JOIN mechanics m ON m.login = a.login ORDER BY a.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return Response::json([
            'masters' => $this->auth->masterLogins(),
            'mechanics' => $mechanics,
            'added' => array_map(static fn (array $r): array => [
                'login' => $r['login'], 'note' => $r['note'], 'mechanic' => $r['mechanic'],
            ], $added),
        ]);
    }

    public function add(Request $request): Response
    {
        $who = $this->master($request);
        $login = $this->login($request->body['login'] ?? null);
        $note = $request->body['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 100)) {
            throw new HttpException(400, 'Ошибка в данных', ['note' => 'Не длиннее 100 символов']);
        }
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        $this->pdo->prepare('INSERT INTO access_logins (login, note, added_by) VALUES (?, ?, ?) AS new ON DUPLICATE KEY UPDATE note = new.note')
            ->execute([$login, $note, $who->login]);

        return new Response(204);
    }

    public function remove(Request $request): Response
    {
        $this->master($request);
        $this->pdo->prepare('DELETE FROM access_logins WHERE login = ?')->execute([$this->login($request->body['login'] ?? null)]);

        return new Response(204);
    }

    private function login(mixed $value): string
    {
        $login = is_string($value) ? strtolower(trim($value)) : '';
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $login) || strlen($login) > 190) {
            throw new HttpException(400, 'Ошибка в данных', ['login' => 'Нужна почта Google, например ivanov@gmail.com']);
        }

        return $login;
    }

    private function master(Request $request): Identity
    {
        $who = $this->auth->require($request);
        if (!$who->isMaster) {
            throw new HttpException(403, 'Доступ настраивает мастер');
        }

        return $who;
    }
}
