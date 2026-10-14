<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Auth\Identity;
use App\Auth\IdentityResolver;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Push\Notifier;
use App\Push\PushRepository;
use App\Push\PushSender;
use App\Support\Services;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxFixture;

/**
 * Push-уведомления на настоящем MySQL: подписка устройств и кому какое уведомление уходит.
 * Службы уведомлений подменены записью в память.
 */
final class PushApiTest extends TestCase
{
    private const MASTER = 'boss-push@test.example';
    private const MECH_A = 'push-a@test.example';
    private const MECH_B = 'push-b@test.example';

    private Router $router;
    private Services $services;
    private PDO $pdo;
    private string $tag;
    private string $street;
    /** @var array<string, int> */
    private array $mechanic = [];
    private object $sender;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты пропущены.');
        }
        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ' . bin2hex(random_bytes(3));
        $letters = array_combine(str_split('0123456789abcdef'), mb_str_split('абвгдежзиклмнопр'));
        $this->street = 'Пуш' . strtr(bin2hex(random_bytes(3)), $letters);

        foreach (['a' => self::MECH_A, 'b' => self::MECH_B] as $key => $login) {
            $this->pdo->prepare('INSERT INTO mechanics (name, section, login) VALUES (?, ?, ?)')
                ->execute(["{$this->tag} механик $key", "Участок $key", $login]);
            $this->mechanic[$key] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('INSERT INTO lifts (mechanic_id, address, lift_no) VALUES (?, ?, 1)')
            ->execute([$this->mechanic['a'], "{$this->street} 3"]);

        $this->sender = new class () implements PushSender {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];
            /** @var array<string, int> */
            public array $reply = [];

            public function publicKey(): string
            {
                return 'BTestKey';
            }

            public function send(array $jobs): array
            {
                $result = [];
                foreach ($jobs as $id => $job) {
                    $this->sent[$id] = $job + json_decode($job['payload'], true);
                    $result[$id] = $this->reply[$job['endpoint']] ?? 201;
                }

                return $result;
            }
        };

        $pdo = $this->pdo;
        $sender = $this->sender;
        $this->services = new Services(
            static fn (): PDO => $pdo,
            identityFactory: static fn (PDO $p): IdentityResolver => new IdentityResolver($p, [self::MASTER]),
            pushSenderFactory: static fn (): PushSender => $sender,
        );
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, $this->services);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint LIKE ?')->execute(['%' . bin2hex($this->tag) . '%']);
            $logins = [self::MASTER, self::MECH_A, self::MECH_B];
            $in = implode(',', array_fill(0, count($logins), '?'));
            $this->pdo->prepare("DELETE FROM messages WHERE author_login IN ($in)")->execute($logins);
            $this->pdo->prepare('DELETE FROM repair_reports WHERE section = ?')->execute(["УЧ.{$this->tag}"]);
            $this->pdo->prepare('DELETE FROM repair_calls WHERE created_by = ?')->execute([self::MASTER]);
            $this->pdo->prepare('DELETE FROM lifts WHERE address LIKE ?')->execute([$this->street . '%']);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
    }

    /**
     * Запрос и отправка накопленных уведомлений, как в public/index.php.
     *
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $login, string $method, string $path, array $body = [], array $query = []): array
    {
        $response = $this->router->dispatch(new Request($method, $path, $query, $body, headers: [IdentityResolver::HEADER => $login]));
        $this->services->flushNotifications();

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function endpoint(string $device): string
    {
        return 'https://fcm.googleapis.com/fcm/send/' . bin2hex($this->tag) . '-' . $device;
    }

    /**
     * @param array<string, bool> $prefs
     */
    private function subscribe(string $login, string $role, string $device, array $prefs = []): int
    {
        [$status, $data] = $this->call($login, 'POST', '/api/push/subscribe', [
            'subscription' => [
                'endpoint' => $this->endpoint($device),
                'keys' => ['p256dh' => rtrim(strtr(base64_encode("\x04" . random_bytes(64)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
            ],
            'role' => $role,
            'prefs' => $prefs,
        ]);

        return $status === 204 ? $status : throw new \RuntimeException(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Уведомления, ушедшие с прошлого вызова, по устройствам.
     *
     * @return array<string, array<string, mixed>>
     */
    private function takeSent(): array
    {
        $out = [];
        foreach ($this->sender->sent as $job) {
            if (str_contains($job['endpoint'], bin2hex($this->tag))) {
                $out[substr($job['endpoint'], strrpos($job['endpoint'], '-') + 1)] = $job;
            }
        }
        $this->sender->sent = [];
        ksort($out);

        return $out;
    }

    public function testSubscriptionRules(): void
    {
        $this->assertSame([200, ['enabled' => true, 'public_key' => 'BTestKey']], $this->call(self::MECH_A, 'GET', '/api/push'));

        // Сервер шлёт запросы по адресу подписки: принимаются только службы уведомлений браузеров
        [$status, $error] = $this->call(self::MECH_A, 'POST', '/api/push/subscribe', [
            'subscription' => ['endpoint' => 'https://127.0.0.1/admin', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('A', 22)]],
            'role' => 'mechanic',
        ]);
        $this->assertSame(400, $status);
        $this->assertArrayHasKey('endpoint', $error['error']['fields']);

        $this->expectExceptionMessage('прав мастера');
        $this->subscribe(self::MECH_A, 'master', 'x');
    }

    public function testWhoGetsWhat(): void
    {
        $this->subscribe(self::MASTER, 'master', 'master');
        $this->subscribe(self::MECH_A, 'mechanic', 'a');
        $this->subscribe(self::MECH_B, 'mechanic', 'b', ['requests' => false]);

        // Чат — всем, кроме автора
        $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => 'Кто рядом с депо?']);
        $sent = $this->takeSent();
        $this->assertSame(['b', 'master'], array_keys($sent));
        $this->assertSame("Чат · {$this->tag} механик a, Участок a", $sent['b']['title']);
        $this->assertSame('Кто рядом с депо?', $sent['b']['body']);
        $this->assertSame('#/chat', $sent['b']['url']);

        // Новая заявка — мастеру
        [, $created] = $this->call(self::MECH_A, 'POST', '/api/requests', ['title' => 'Ролик двери', 'quantity' => '2 шт', 'urgent' => true]);
        $id = $created['item']['id'];
        $sent = $this->takeSent();
        $this->assertSame(['master'], array_keys($sent));
        $this->assertSame('Новая заявка · срочно', $sent['master']['title']);
        $this->assertSame("Ролик двери, 2 шт — {$this->tag} механик a", $sent['master']['body']);
        $this->assertSame("#/requests?id=$id", $sent['master']['url']);
        $this->assertTrue($sent['master']['urgent']);

        // Статус от мастера — автору заявки
        $this->call(self::MASTER, 'POST', "/api/requests/$id/messages", ['status' => 'ready', 'body' => 'На складе', 'as' => 'master']);
        $sent = $this->takeSent();
        $this->assertSame(['a'], array_keys($sent));
        $this->assertSame('Деталь пришла, можно забирать', $sent['a']['title']);
        $this->assertSame('Ролик двери: На складе', $sent['a']['body']);

        // Механик отметил «получил» — мастеру. Устройство, которого больше нет (410), забывается
        $this->sender->reply[$this->endpoint('master')] = 410;
        $this->call(self::MECH_A, 'POST', "/api/requests/$id/messages", ['status' => 'issued']);
        $sent = $this->takeSent();
        $this->assertSame("{$this->tag} механик a: деталь получена", $sent['master']['title']);
        $this->assertSame(204, $this->subscribe(self::MASTER, 'master', 'master2'));
        $left = $this->pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?');
        $left->execute([$this->endpoint('master')]);
        $this->assertSame(0, (int) $left->fetchColumn());
    }

    public function testRepairsNotifyOnlyNewCalls(): void
    {
        $this->subscribe(self::MECH_A, 'mechanic', 'a');
        $this->subscribe(self::MECH_B, 'mechanic', 'b');
        $street = mb_strtoupper($this->street);
        $report = ['data' => base64_encode(XlsxFixture::report("УЧ.{$this->tag}", [
            ['9101н', "$street 3 п.1", '5', '31.03.2026 21:20:00', null, 'Да', '3', '44 C6 MLM'],
            ['9101н', "$street 3 п.1", '1', '31.03.2026 9:00:00', '31.03.2026 11:00:00', 'Да', '3', 'ТО2'],
        ]))];

        $this->call(self::MASTER, 'POST', '/api/repairs/import', $report);
        $sent = $this->takeSent();
        $this->assertSame(['a'], array_keys($sent), 'Плановое ТО и чужие лифты не в счёт');
        $this->assertSame('Заявки на ремонт по вашим лифтам: 1 · не закрыто 1', $sent['a']['title']);
        $this->assertSame("$street 3 п.1 — код 44 C6", $sent['a']['body']);
        $this->assertSame('#/repairs', $sent['a']['url']);

        $this->call(self::MASTER, 'POST', '/api/repairs/import', $report);
        $this->assertSame([], $this->takeSent(), 'Повторная загрузка того же отчёта не будит механика');

        // Заявка от мастера лично — механику лифта, «лифт стоит» срочно
        $lift = (int) $this->pdo->query("SELECT id FROM lifts WHERE address = '{$this->street} 3'")->fetchColumn();
        $this->call(self::MASTER, 'POST', '/api/repairs', ['lift_id' => $lift, 'info' => 'Жалоба жильцов: скрежет', 'stopped' => true, 'as' => 'master']);
        $sent = $this->takeSent();
        $this->assertSame(['a'], array_keys($sent));
        $this->assertSame('Заявка от мастера · лифт стоит', $sent['a']['title']);
        $this->assertSame("{$this->street} 3, лифт 1 — Жалоба жильцов: скрежет", $sent['a']['body']);
        $this->assertTrue($sent['a']['urgent']);
    }

    public function testTestNotificationGoesOnlyToOwnDevice(): void
    {
        $this->subscribe(self::MECH_A, 'mechanic', 'a', ['quiet' => true]);
        $this->subscribe(self::MECH_B, 'mechanic', 'b');

        $this->assertSame(404, $this->call(self::MECH_B, 'POST', '/api/push/test', ['endpoint' => $this->endpoint('a')])[0]);
        $this->assertSame([202, ['queued' => true]], $this->call(self::MECH_A, 'POST', '/api/push/test', ['endpoint' => $this->endpoint('a')]));
        $this->assertSame(['a'], array_keys($this->takeSent()));

        $this->assertSame(204, $this->call(self::MECH_A, 'POST', '/api/push/unsubscribe', ['endpoint' => $this->endpoint('a')])[0]);
        $this->assertSame(404, $this->call(self::MECH_A, 'POST', '/api/push/test', ['endpoint' => $this->endpoint('a')])[0]);
    }

    public function testQuietNight(): void
    {
        $this->subscribe(self::MECH_A, 'mechanic', 'a', ['quiet' => true]);
        $this->subscribe(self::MECH_B, 'mechanic', 'b');
        $pdo = $this->pdo;
        $minsk = new DateTimeZone('Europe/Minsk');
        $message = ['author' => ['role' => 'master', 'name' => 'Мастер', 'section' => null], 'body' => 'Планёрка в 8', 'photos' => []];

        foreach (['23:30' => ['b'], '06:59' => ['b'], '07:00' => ['a', 'b']] as $time => $expected) {
            $notifier = new Notifier(
                static fn (): PushRepository => new PushRepository($pdo),
                $this->sender,
                $minsk,
                static fn (): DateTimeImmutable => new DateTimeImmutable("2026-10-13 $time", $minsk),
            );
            $notifier->chat(new Identity(self::MASTER, true, null), $message);
            $notifier->flush();
            $this->assertSame($expected, array_keys($this->takeSent()), $time);
        }
    }
}
