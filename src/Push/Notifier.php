<?php

declare(strict_types=1);

namespace App\Push;

use App\Auth\Identity;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Кому и что сообщить о событиях в бригаде. Уведомления копятся за запрос и уходят в flush()
 * уже после ответа браузеру (fastcgi_finish_request): отправитель не ждёт служб уведомлений.
 *
 * Автору о его же сообщении не сообщается. Устройство с «тишиной ночью» ничего не получает
 * с 22:00 до 7:00 (по APP_TIMEZONE, по умолчанию Минск).
 */
final class Notifier
{
    private const BODY_MAX = 180;

    private const MECHANIC_TITLES = [
        'accepted' => 'Заявка принята',
        'ordered' => 'Деталь заказана',
        'ready' => 'Деталь пришла, можно забирать',
        'issued' => 'Деталь выдана',
        'rejected' => 'Заявка отклонена',
    ];

    /** @var array<int, array{endpoint: string, p256dh: string, auth: string, payload: string, urgent: bool}> */
    private array $queue = [];

    /**
     * @param (Closure(): PushRepository) $subscriptions
     */
    public function __construct(
        private readonly Closure $subscriptions,
        private readonly ?PushSender $sender,
        private readonly DateTimeZone $timezone,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->sender !== null;
    }

    public function publicKey(): ?string
    {
        return $this->sender?->publicKey();
    }

    /**
     * @param array<string, mixed> $message сообщение чата, как его отдаёт CommsRepository
     */
    public function chat(Identity $who, array $message): void
    {
        if (!$this->enabled()) {
            return;
        }
        $author = $message['author'];
        $name = $author['role'] === 'master' ? 'Мастер' : $author['name'] . ($author['section'] ? ', ' . $author['section'] : '');
        $this->push($this->repo()->forChat($who->login), [
            'title' => 'Чат · ' . $name,
            'body' => self::text($message['body'] ?? null, $message['photos'] ?? []),
            'url' => '#/chat',
            'tag' => 'chat',
        ]);
    }

    /**
     * Новая заявка на запчасти — мастеру.
     *
     * @param array<string, mixed> $request
     */
    public function requestCreated(Identity $who, array $request): void
    {
        if (!$this->enabled()) {
            return;
        }
        $body = $request['title'] . ($request['quantity'] ? ', ' . $request['quantity'] : '') . ' — ' . $request['author']['name'];
        $this->push($this->repo()->forMaster($who->login), [
            'title' => 'Новая заявка' . ($request['urgent'] ? ' · срочно' : ''),
            'body' => self::cut($body),
            'url' => '#/requests?id=' . $request['id'],
            'tag' => 'request-' . $request['id'],
        ], (bool) $request['urgent']);
    }

    /**
     * Ответ или смена статуса по заявке: от мастера — автору заявки, от механика — мастеру.
     *
     * @param array<string, mixed> $request заявка (после сообщения)
     * @param array<string, mixed> $message
     */
    public function requestMessage(Identity $who, bool $asMaster, array $request, array $message): void
    {
        if (!$this->enabled()) {
            return;
        }
        $status = $message['status'];
        $text = self::text($message['body'] ?? null, $message['photos'] ?? [], '');
        $body = self::cut($request['title'] . ($text !== '' ? ': ' . $text : ''));
        $notification = ['body' => $body, 'url' => '#/requests?id=' . $request['id'], 'tag' => 'request-' . $request['id']];

        if ($asMaster) {
            $this->push(
                $this->repo()->forMechanic((int) $request['author']['id'], 'requests', $who->login),
                ['title' => self::MECHANIC_TITLES[$status] ?? 'Мастер ответил по заявке'] + $notification,
                $status === 'ready',
            );

            return;
        }
        $name = $request['author']['name'];
        $this->push($this->repo()->forMaster($who->login), [
            'title' => $status === 'issued' ? $name . ': деталь получена' : $name . ' · по заявке',
        ] + $notification);
    }

    /**
     * Новые вызовы из отчёта 1С — механикам по их лифтам.
     *
     * @param array<int, list<array{address: string, codes: string, info: string, open: bool}>> $byMechanic
     */
    public function repairsImported(array $byMechanic): void
    {
        if (!$this->enabled()) {
            return;
        }
        foreach ($byMechanic as $mechanicId => $calls) {
            $open = count(array_filter($calls, static fn (array $c): bool => $c['open']));
            $lines = [];
            foreach (array_slice($calls, 0, 3) as $call) {
                $lines[] = $call['address'] . ' — ' . ($call['codes'] !== '' ? 'код ' . $call['codes'] : $call['info']);
            }
            if (count($calls) > 3) {
                $lines[] = 'и ещё ' . (count($calls) - 3);
            }
            $this->push($this->repo()->forMechanic((int) $mechanicId, 'repairs'), [
                'title' => 'Заявки на ремонт по вашим лифтам: ' . count($calls) . ($open > 0 ? ' · не закрыто ' . $open : ''),
                'body' => self::cut(implode("\n", $lines), 300),
                'url' => '#/repairs',
                'tag' => 'repairs',
            ], $open > 0);
        }
    }

    /**
     * Заявка от мастера лично — механику лифта.
     *
     * @param array<string, mixed> $call заявка, как её отдаёт RepairsRepository
     */
    public function repairCreated(Identity $who, array $call, int $mechanicId): void
    {
        if (!$this->enabled()) {
            return;
        }
        $lift = $call['lift'];
        $place = $lift ? $lift['address'] . ($lift['object_name'] ? ' · ' . $lift['object_name'] : '') . ', лифт ' . $lift['lift_no'] : $call['address'];
        $this->push($this->repo()->forMechanic($mechanicId, 'repairs', $who->login), [
            'title' => 'Заявка от мастера' . ($call['stopped'] ? ' · лифт стоит' : ''),
            'body' => self::cut($place . ' — ' . ($call['codes'] !== [] ? 'код ' . implode(' ', $call['codes']) . ', ' : '') . $call['info'], 300),
            'url' => '#/repairs',
            'tag' => 'repair-' . $call['id'],
        ], (bool) $call['stopped']);
    }

    /**
     * Проверка с экрана настроек: уведомление только на это устройство, без учёта тишины.
     *
     * @param array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool} $subscription
     */
    public function test(array $subscription): void
    {
        $this->queue[$subscription['id']] = self::job($subscription, [
            'title' => 'Уведомления работают',
            'body' => 'Так будут выглядеть сообщения бригады',
            'url' => '#/chat',
            'tag' => 'test',
        ], true);
    }

    /**
     * Отправляет накопленное. Ошибки не мешают ответу: только в журнал сервера.
     */
    public function flush(): void
    {
        if ($this->queue === [] || $this->sender === null) {
            return;
        }
        $jobs = $this->queue;
        $this->queue = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $gone = [];
            foreach ($this->sender->send($jobs) as $id => $status) {
                if ($status === 404 || $status === 410) {
                    $gone[] = $id;
                }
            }
            $this->repo()->forget($gone);
        } catch (\Throwable $e) {
            error_log('push: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array{endpoint: string, p256dh: string, auth: string, payload: string, urgent: bool}>
     */
    public function pending(): array
    {
        return $this->queue;
    }

    /**
     * @param list<array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}> $subscriptions
     * @param array{title: string, body: string, url: string, tag: string} $notification
     */
    private function push(array $subscriptions, array $notification, bool $urgent = false): void
    {
        $night = $this->isNight();
        foreach ($subscriptions as $subscription) {
            if (!($subscription['quiet'] && $night)) {
                $this->queue[$subscription['id']] = self::job($subscription, $notification, $urgent);
            }
        }
    }

    /**
     * @param array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool} $subscription
     * @param array{title: string, body: string, url: string, tag: string} $notification
     * @return array{endpoint: string, p256dh: string, auth: string, payload: string, urgent: bool}
     */
    private static function job(array $subscription, array $notification, bool $urgent): array
    {
        return [
            'endpoint' => $subscription['endpoint'],
            'p256dh' => $subscription['p256dh'],
            'auth' => $subscription['auth'],
            'payload' => (string) json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'urgent' => $urgent,
        ];
    }

    private function isNight(): bool
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
        $hour = (int) $now->setTimezone($this->timezone)->format('G');

        return $hour >= 22 || $hour < 7;
    }

    private function repo(): PushRepository
    {
        return ($this->subscriptions)();
    }

    /**
     * @param list<mixed> $photos
     */
    private static function text(?string $body, array $photos, string $empty = 'Фото'): string
    {
        if ($body !== null && $body !== '') {
            return self::cut(preg_replace('/\s+/u', ' ', $body) ?? $body);
        }

        return $photos !== [] ? (count($photos) > 1 ? 'Фото: ' . count($photos) : 'Фото') : $empty;
    }

    private static function cut(string $text, int $max = self::BODY_MAX): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
