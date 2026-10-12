<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Ожидаемая ошибка запроса: роутер превращает её в JSON-ответ с нужным статусом.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fields сообщения по полям (для ошибок валидации)
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
