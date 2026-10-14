<?php

declare(strict_types=1);

namespace App\Push;

use PDO;

/**
 * Подписки устройств на уведомления.
 */
final class PushRepository
{
    public const TOPICS = ['chat', 'requests', 'repairs', 'quiet'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Подписывает устройство или обновляет его настройки. Адрес подписки уникален:
     * если браузером теперь пользуется другой человек, подписка переходит к нему.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param array{chat: bool, requests: bool, repairs: bool, quiet: bool} $prefs
     */
    public function save(array $subscription, string $login, string $role, ?int $mechanicId, array $prefs): void
    {
        $this->pdo->prepare(
            'INSERT INTO push_subscriptions (endpoint, endpoint_hash, p256dh, auth, login, role, mechanic_id, chat, requests, repairs, quiet) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE '
            . 'p256dh = new.p256dh, auth = new.auth, login = new.login, role = new.role, mechanic_id = new.mechanic_id, '
            . 'chat = new.chat, requests = new.requests, repairs = new.repairs, quiet = new.quiet, updated_at = NOW()'
        )->execute([
            $subscription['endpoint'], hash('sha256', $subscription['endpoint']), $subscription['p256dh'], $subscription['auth'],
            $login, $role, $mechanicId, (int) $prefs['chat'], (int) $prefs['requests'], (int) $prefs['repairs'], (int) $prefs['quiet'],
        ]);
    }

    /**
     * Отписка: удалить можно только свою подписку.
     */
    public function delete(string $endpoint, string $login): void
    {
        $this->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND login = ?')
            ->execute([hash('sha256', $endpoint), $login]);
    }

    /**
     * @return array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}|null
     */
    public function find(string $endpoint, string $login): ?array
    {
        $rows = $this->select('endpoint_hash = ? AND login = ?', [hash('sha256', $endpoint), $login]);

        return $rows[0] ?? null;
    }

    /**
     * Удаляет подписки, которых больше нет у службы уведомлений (браузер отписался или удалён).
     *
     * @param list<int> $ids
     */
    public function forget(array $ids): void
    {
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($in)")->execute($ids);
        }
    }

    /**
     * Кому сообщить о сообщении в общем чате: всем, кроме автора.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}>
     */
    public function forChat(string $exceptLogin): array
    {
        return $this->select('chat = 1 AND login <> ?', [$exceptLogin]);
    }

    /**
     * Устройства мастера по теме: requests — заявки на запчасти, repairs — закрытие заявок на ремонт.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}>
     */
    public function forMaster(string $exceptLogin, string $topic = 'requests'): array
    {
        $column = $topic === 'repairs' ? 'repairs' : 'requests';

        return $this->select("role = 'master' AND $column = 1 AND login <> ?", [$exceptLogin]);
    }

    /**
     * Устройства механика по теме: requests — его заявки на запчасти, repairs — заявки на ремонт.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}>
     */
    public function forMechanic(int $mechanicId, string $topic, string $exceptLogin = ''): array
    {
        $column = $topic === 'repairs' ? 'repairs' : 'requests';

        return $this->select("role = 'mechanic' AND mechanic_id = ? AND $column = 1 AND login <> ?", [$mechanicId, $exceptLogin]);
    }

    /**
     * @param list<mixed> $params
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, quiet: bool}>
     */
    private function select(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare("SELECT id, endpoint, p256dh, auth, quiet FROM push_subscriptions WHERE $where");
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'endpoint' => $r['endpoint'],
            'p256dh' => $r['p256dh'],
            'auth' => $r['auth'],
            'quiet' => (bool) $r['quiet'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
