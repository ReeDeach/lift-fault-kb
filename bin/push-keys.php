<?php

declare(strict_types=1);

/**
 * Ключи VAPID для push-уведомлений. Создаются один раз и вписываются в .env:
 *
 *   docker compose exec php php bin/push-keys.php
 *
 * Если ключи сменить, все устройства придётся подписать заново.
 */

require __DIR__ . '/../vendor/autoload.php';

$keys = App\Push\WebPush::generateKeys();
echo 'VAPID_PUBLIC_KEY=', $keys['public'], PHP_EOL;
echo 'VAPID_PRIVATE_KEY=', $keys['private'], PHP_EOL;
