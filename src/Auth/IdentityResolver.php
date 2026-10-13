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
 */
final class IdentityResolver
{
    public const HEADER = 'tailscale-user-login';

    /**
     * @param list<string> $masterLogins
     * @param string|null $devLogin вход без Tailscale для локальной разработки (APP_ENV=local)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $masterLogins,
        private readonly ?string $devLogin = null,
    ) {
    }

    public static function fromEnv(PDO $pdo): self
    {
        $masters = array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', Env::get('MASTER_LOGINS')),
        )));
        $dev = Env::get('APP_ENV') === 'local' ? Env::get('AUTH_DEV_LOGIN') : '';

        return new self($pdo, $masters, $dev !== '' ? $dev : null);
    }

    public function resolve(Request $request): ?Identity
    {
        $login = strtolower(trim((string) ($request->header(self::HEADER) ?? $this->devLogin ?? '')));
        if ($login === '' || strlen($login) > 190) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name, section FROM mechanics WHERE login = ?');
        $stmt->execute([$login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return new Identity(
            $login,
            in_array($login, $this->masterLogins, true),
            $row === false ? null : ['id' => (int) $row['id'], 'name' => $row['name'], 'section' => $row['section']],
        );
    }

    public function require(Request $request): Identity
    {
        return $this->resolve($request)
            ?? throw new HttpException(401, 'Вход только через Tailscale: откройте сайт по адресу *.ts.net');
    }
}
