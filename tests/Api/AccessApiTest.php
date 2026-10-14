<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Auth\IdentityResolver;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Вход из интернета (ngrok, Google): пускаем мастеров, механиков с привязанным аккаунтом
 * и почты из списка «Доступ». Через Tailscale — как раньше, всех из сети.
 */
final class AccessApiTest extends TestCase
{
    private const MASTER = 'boss-access@test.example';
    private const LINKED = 'access-linked@test.example';
    private const GUEST = 'access-guest@test.example';

    private Router $router;
    private PDO $pdo;
    private string $tag;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты пропущены.');
        }
        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ' . bin2hex(random_bytes(3));
        $this->pdo->prepare('INSERT INTO mechanics (name, login) VALUES (?, ?)')->execute(["{$this->tag} механик", self::LINKED]);

        $pdo = $this->pdo;
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services(
            static fn (): PDO => $pdo,
            identityFactory: static fn (PDO $p): IdentityResolver => new IdentityResolver($p, [self::MASTER]),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM access_logins WHERE login = ?')->execute([self::GUEST]);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
    }

    /**
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $login, string $method, string $path, array $body = [], bool $internet = true): array
    {
        $headers = [IdentityResolver::HEADER => $login] + ($internet ? [IdentityResolver::CHANNEL => 'internet'] : []);
        $response = $this->router->dispatch(new Request($method, $path, [], $body, headers: $headers));

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testInternetLetsInOnlyKnownPeople(): void
    {
        // Незнакомая почта из интернета — отказ с подсказкой; через Tailscale — как раньше
        [$status, $error] = $this->call(self::GUEST, 'GET', '/api/me');
        $this->assertSame(403, $status);
        $this->assertStringContainsString(self::GUEST, $error['error']['message']);
        $this->assertSame(200, $this->call(self::GUEST, 'GET', '/api/me', internet: false)[0]);

        // Мастер и механик с привязанным аккаунтом проходят
        $this->assertSame(200, $this->call(self::MASTER, 'GET', '/api/me')[0]);
        $this->assertSame(200, $this->call(self::LINKED, 'GET', '/api/me')[0]);

        // Мастер добавляет почту — человек входит; список видит и правит только мастер
        $this->assertSame(403, $this->call(self::LINKED, 'POST', '/api/access', ['login' => self::GUEST])[0]);
        $this->assertSame(400, $this->call(self::MASTER, 'POST', '/api/access', ['login' => 'не почта'])[0]);
        $this->assertSame(204, $this->call(self::MASTER, 'POST', '/api/access', ['login' => ' Access-Guest@test.example ', 'note' => 'новый механик'])[0]);
        $this->assertSame(200, $this->call(self::GUEST, 'GET', '/api/me')[0]);
        [, $list] = $this->call(self::MASTER, 'GET', '/api/access');
        $this->assertContains(self::GUEST, array_column($list['added'], 'login'));
        $this->assertContains(self::LINKED, array_column($list['mechanics'], 'login'));
        $this->assertSame([self::MASTER], $list['masters']);

        // Убрал — снова отказ
        $this->assertSame(204, $this->call(self::MASTER, 'POST', '/api/access/remove', ['login' => self::GUEST])[0]);
        $this->assertSame(403, $this->call(self::GUEST, 'GET', '/api/me')[0]);
    }
}
