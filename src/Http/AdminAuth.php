<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Проверка токена админ-API: заголовок Authorization: Bearer <ADMIN_TOKEN>.
 * Пустой ADMIN_TOKEN выключает админ-API целиком.
 */
final class AdminAuth
{
    private const MIN_TOKEN_LENGTH = 16;

    /**
     * @param callable(Request): Response $handler
     * @return callable(Request): Response
     */
    public static function guard(string $token, callable $handler): callable
    {
        return static function (Request $request) use ($token, $handler): Response {
            if (strlen($token) < self::MIN_TOKEN_LENGTH) {
                throw new HttpException(403, 'Админ-API выключено: задайте ADMIN_TOKEN не короче ' . self::MIN_TOKEN_LENGTH . ' символов');
            }

            $header = $request->header('authorization') ?? '';
            $given = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : '';

            if ($given === '' || !hash_equals($token, $given)) {
                $response = Response::error('Нужен токен администратора', 401);

                return new Response($response->status, $response->body, $response->headers + ['WWW-Authenticate' => 'Bearer']);
            }

            return $handler($request);
        };
    }
}
