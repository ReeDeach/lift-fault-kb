<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Отправка уведомлений в службы браузеров. В тестах подменяется записью в память.
 */
interface PushSender
{
    public function publicKey(): string;

    /**
     * @param array<int, array{endpoint: string, p256dh: string, auth: string, payload: string, urgent: bool}> $jobs
     * @return array<int, int> HTTP-код ответа на каждое уведомление
     */
    public function send(array $jobs): array;
}
