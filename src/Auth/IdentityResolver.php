<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use App\Http\Request;
use App\Support\Env;
use PDO;

/**
 * Определяет пользователя по аккаунту Tailscale.
 *
 * tailscale serve добавляет к каждому запросу из сети Tailscale заголовок Tailscale-User-Login.
 * Доверять ему можно, только если до сайта нельзя достучаться в обход Tailscale: поэтому
 * nginx слушает лишь 127.0.0.1 (docker-compose.yml), а наружу сайт отдаёт tailscale serve.
 *
 * Права мастера — у аккаунтов из MASTER_LOGINS. Механиком может войти любой аккаунт сети:
 * при первом входе он выбирает себя в списке механиков, и аккаунт привязывается.
 *
 * Демо-режим (DEMO_MODE=1, только у демо-копии с вымышленными данными): входа нет, каждый
 * браузер присылает свой случайный X-Demo-User, права мастера у всех, механик выбирается
 * заголовком X-Demo-Mechanic без привязки. В рабочей копии эти заголовки игнорируются.
 */
final class IdentityResolver
{
    public const HEADER = 'tailscale-user-login';
    public const DEMO_USER = 'x-demo-user';
    public const DEMO_MECHANIC = 'x-demo-mechanic';
    /** Ставит только nginx для входа из интернета (docker/nginx/internet.conf) */
    public const CHANNEL = 'x-lft-channel';

    /**
     * @param list<string> $masterLogins
     * @param string|null $devLogin вход без Tailscale для локальной разработки (APP_ENV=local)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $masterLogins,
        private readonly ?string $devLogin = null,
        private readonly bool $demo = false,
    ) {
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    public static function fromEnv(PDO $pdo): self
    {
        $masters = array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', Env::get('MASTER_LOGINS')),
        )));
        $dev = Env::get('APP_ENV') === 'local' ? Env::get('AUTH_DEV_LOGIN') : '';

        return new self($pdo, $masters, $dev !== '' ? $dev : null, Env::get('DEMO_MODE') === '1');
    }

    public function resolve(Request $request): ?Identity
    {
        if ($this->demo) {
            return $this->resolveDemo($request);
        }

        $login = strtolower(trim((string) ($request->header(self::HEADER) ?? $this->devLogin ?? '')));
        if ($login === '' || strlen($login) > 190) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name, section FROM mechanics WHERE login = ?');
        $stmt->execute([$login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isMaster = in_array($login, $this->masterLogins, true);

        // Из интернета (ngrok, вход через Google) пускаем не всех: мастеров, механиков с привязанным
        // аккаунтом и тех, кого мастер добавил в «Доступ». Пометку ставит только nginx для ngrok.
        if ($request->header(self::CHANNEL) === 'internet' && !$isMaster && $row === false && !$this->allowed($login)) {
            throw new HttpException(403, "Нет доступа для $login. Попросите мастера добавить эту почту в «Доступ»");
        }

        return new Identity(
            $login,
            $isMaster,
            $row === false ? null : ['id' => (int) $row['id'], 'name' => $row['name'], 'section' => $row['section']],
        );
    }

    /**
     * Почта в списке «Доступ» (мастер добавил человека для входа из интернета).
     */
    public function allowed(string $login): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM access_logins WHERE login = ?');
        $stmt->execute([strtolower(trim($login))]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    public function masterLogins(): array
    {
        return $this->masterLogins;
    }

    private function resolveDemo(Request $request): ?Identity
    {
        $session = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $request->header(self::DEMO_USER)));
        if ($session === '' || $session === null) {
            return null;
        }

        $mechanic = null;
        $id = (string) $request->header(self::DEMO_MECHANIC);
        if (ctype_digit($id)) {
            $stmt = $this->pdo->prepare('SELECT id, name, section FROM mechanics WHERE id = ?');
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $mechanic = $row === false ? null : ['id' => (int) $row['id'], 'name' => $row['name'], 'section' => $row['section']];
        }

        return new Identity('demo:' . substr($session, 0, 40), true, $mechanic);
    }

    public function require(Request $request): Identity
    {
        return $this->resolve($request)
            ?? throw new HttpException(401, $request->header(self::CHANNEL) === 'internet'
                ? 'Войдите через Google: откройте ссылку на сайт заново'
                : 'Вход только через Tailscale: откройте сайт по адресу *.ts.net');
    }
}
