<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;
use App\Http\Request;
use PDO;

/**
 * Ограничение частоты запросов к платным функциям (ИИ). Счётчики в MySQL, окна фиксированные.
 *
 * Два предела: на пользователя в час и общий в сутки. Пользователь — аккаунт входа,
 * а если входа нет (демо) — идентификатор демо-сессии или IP.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $perUserHour,
        private readonly int $perDay,
    ) {
    }

    public static function fromEnv(PDO $pdo): self
    {
        return new self(
            $pdo,
            max(1, (int) Env::get('AI_LIMIT_USER_HOUR', '30')),
            max(1, (int) Env::get('AI_LIMIT_DAY', '1000')),
        );
    }

    /**
     * Засчитывает запрос. Сверх предела — 429 с понятным текстом.
     */
    public function hit(Request $request, string $action): void
    {
        $user = self::who($request);

        if ($this->count("$action:user:$user", 3600) > $this->perUserHour) {
            throw new HttpException(429, 'Слишком много запросов к ИИ: не больше ' . $this->perUserHour . ' в час. Попробуйте позже');
        }
        if ($this->count("$action:all", 86400) > $this->perDay) {
            throw new HttpException(429, 'Дневной лимит запросов к ИИ исчерпан. Попробуйте завтра');
        }

        // Изредка чистим старые окна, чтобы таблица не росла
        if (random_int(1, 50) === 1) {
            $this->pdo->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY');
        }
    }

    /**
     * Кто делает запрос: аккаунт Tailscale, демо-сессия или адрес клиента.
     */
    public static function who(Request $request): string
    {
        $id = $request->header('tailscale-user-login')
            ?? $request->header('x-demo-user')
            ?? $request->header('cf-connecting-ip')
            ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return substr(preg_replace('/[^A-Za-z0-9@._:-]/', '', strtolower((string) $id)) ?: 'unknown', 0, 120);
    }

    private function count(string $bucket, int $window): int
    {
        $start = date('Y-m-d H:i:s', intdiv(time(), $window) * $window);
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) AS new '
            . 'ON DUPLICATE KEY UPDATE hits = rate_limits.hits + 1'
        )->execute([$bucket, $start]);

        $stmt = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$bucket, $start]);

        return (int) $stmt->fetchColumn();
    }
}
