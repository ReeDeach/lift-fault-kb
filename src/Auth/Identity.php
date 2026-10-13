<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Кто сделал запрос: аккаунт Tailscale, есть ли права мастера и какой он механик
 * (null — механик ещё не выбрал себя в списке при первом входе).
 */
final class Identity
{
    /**
     * @param array{id: int, name: string, section: string|null}|null $mechanic
     */
    public function __construct(
        public readonly string $login,
        public readonly bool $isMaster,
        public readonly ?array $mechanic,
    ) {
    }

    public function mechanicId(): ?int
    {
        return $this->mechanic['id'] ?? null;
    }
}
