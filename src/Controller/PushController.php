<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\IdentityResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Push\Notifier;
use App\Push\PushRepository;

/**
 * Push-уведомления на устройство.
 *
 *   GET  /api/push               {enabled, public_key} — включены ли уведомления на сервере
 *   POST /api/push/subscribe     {subscription: {endpoint, keys: {p256dh, auth}}, role, prefs: {chat, requests, repairs, quiet}}
 *   POST /api/push/unsubscribe   {endpoint}
 *   POST /api/push/test          {endpoint} — пробное уведомление на это устройство
 */
final class PushController
{
    /**
     * Службы уведомлений браузеров. Сервер отправляет запрос по адресу из подписки,
     * поэтому чужие адреса (в том числе внутренние) не принимаются.
     */
    private const SERVICES = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public function __construct(
        private readonly IdentityResolver $auth,
        private readonly PushRepository $subscriptions,
        private readonly Notifier $notifier,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->auth->require($request);

        return Response::json(['enabled' => $this->notifier->enabled(), 'public_key' => $this->notifier->publicKey()]);
    }

    public function subscribe(Request $request): Response
    {
        $who = $this->auth->require($request);
        $this->enabled();
        $body = $request->body;
        $this->allowedFields($body, ['subscription', 'role', 'prefs']);

        $sub = $body['subscription'] ?? null;
        $endpoint = $this->endpoint(is_array($sub) ? ($sub['endpoint'] ?? null) : null);
        $keys = is_array($sub) && is_array($sub['keys'] ?? null) ? $sub['keys'] : [];
        $p256dh = $keys['p256dh'] ?? null;
        $authKey = $keys['auth'] ?? null;
        if (!is_string($p256dh) || !preg_match('/^[A-Za-z0-9_-]{80,100}={0,2}$/', $p256dh)
            || !is_string($authKey) || !preg_match('/^[A-Za-z0-9_-]{16,30}={0,2}$/', $authKey)) {
            throw new HttpException(400, 'Ошибка в данных', ['subscription' => 'Нет ключей подписки']);
        }

        $role = $body['role'] ?? null;
        if ($role === 'master') {
            if (!$who->isMaster) {
                throw new HttpException(403, 'У этого аккаунта нет прав мастера');
            }
        } elseif ($role === 'mechanic') {
            if ($who->mechanicId() === null) {
                throw new HttpException(403, 'Сначала выберите себя в списке механиков');
            }
        } else {
            throw new HttpException(400, 'Ошибка в данных', ['role' => 'Допустимо: master, mechanic']);
        }

        $prefs = $body['prefs'] ?? [];
        if (!is_array($prefs)) {
            throw new HttpException(400, 'Ошибка в данных', ['prefs' => 'Ожидается объект']);
        }
        $this->allowedFields($prefs, PushRepository::TOPICS);
        $clean = [];
        foreach (PushRepository::TOPICS as $topic) {
            $value = $prefs[$topic] ?? $topic !== 'quiet';
            if (!is_bool($value)) {
                throw new HttpException(400, 'Ошибка в данных', ["prefs.$topic" => 'Ожидается true или false']);
            }
            $clean[$topic] = $value;
        }

        $this->subscriptions->save(
            ['endpoint' => $endpoint, 'p256dh' => rtrim($p256dh, '='), 'auth' => rtrim($authKey, '=')],
            $who->login,
            $role,
            $role === 'mechanic' ? $who->mechanicId() : null,
            $clean,
        );

        return new Response(204);
    }

    public function unsubscribe(Request $request): Response
    {
        $who = $this->auth->require($request);
        $this->allowedFields($request->body, ['endpoint']);
        $this->subscriptions->delete($this->endpoint($request->body['endpoint'] ?? null), $who->login);

        return new Response(204);
    }

    public function test(Request $request): Response
    {
        $who = $this->auth->require($request);
        $this->enabled();
        $this->allowedFields($request->body, ['endpoint']);
        $subscription = $this->subscriptions->find($this->endpoint($request->body['endpoint'] ?? null), $who->login)
            ?? throw new HttpException(404, 'Это устройство не подписано: включите уведомления заново');
        $this->notifier->test($subscription);

        // Тело обязательно: пустой ответ 202 интерфейс (jQuery, dataType json) счёл бы обрывом связи
        return Response::json(['queued' => true], 202);
    }

    private function enabled(): void
    {
        if (!$this->notifier->enabled()) {
            throw new HttpException(503, 'Уведомления на сервере не настроены: нет ключей VAPID');
        }
    }

    private function endpoint(mixed $endpoint): string
    {
        $host = is_string($endpoint) && strlen($endpoint) <= 1000 && str_starts_with($endpoint, 'https://')
            ? strtolower((string) parse_url($endpoint, PHP_URL_HOST))
            : '';
        foreach (self::SERVICES as $service) {
            if ($host !== '' && ($host === $service || str_ends_with($host, '.' . $service))) {
                return $endpoint;
            }
        }

        throw new HttpException(400, 'Ошибка в данных', ['endpoint' => 'Этот браузер не поддерживается для уведомлений']);
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
