<?php

declare(strict_types=1);

namespace App\Push;

use App\Support\Env;
use RuntimeException;

/**
 * Отправка push-уведомлений по стандарту Web Push без сторонних библиотек: только openssl и curl.
 *
 * Текст шифруется ключами браузера (RFC 8291, aes128gcm), а сервер подписывается своими
 * ключами VAPID (RFC 8292) — по ним служба уведомлений (Google, Mozilla, Apple) узнаёт,
 * что подписку оформил именно этот сайт. Ключи VAPID создаёт bin/push-keys.php.
 */
final class WebPush implements PushSender
{
    private const TIMEOUT = 8;

    private const EC_PRIVATE_PREFIX = '30770201010420';
    private const EC_PRIVATE_MIDDLE = 'a00a06082a8648ce3d030107a144034200';
    private const EC_PUBLIC_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var \OpenSSLAsymmetricKey */
    private readonly mixed $vapidKey;

    /**
     * @param string $publicKey открытый ключ VAPID: точка P-256 без сжатия (65 байт), base64url
     * @param string $privateKey закрытый ключ VAPID: 32 байта, base64url
     * @param string $subject контакт для служб уведомлений: mailto: или https:
     */
    public function __construct(
        private readonly string $publicKey,
        string $privateKey,
        private readonly string $subject,
    ) {
        $public = self::b64decode($publicKey);
        $private = self::b64decode($privateKey);
        if (strlen($public) !== 65 || $public[0] !== "\x04" || strlen($private) !== 32) {
            throw new RuntimeException('Ключи VAPID повреждены: создайте заново через bin/push-keys.php');
        }
        $this->vapidKey = self::privateKey($private, $public);
    }

    /**
     * null — VAPID_PUBLIC_KEY не задан, уведомления выключены.
     */
    public static function fromEnv(): ?self
    {
        $public = Env::get('VAPID_PUBLIC_KEY');
        $private = Env::get('VAPID_PRIVATE_KEY');
        if ($public === '' || $private === '') {
            return null;
        }

        return new self($public, $private, Env::get('VAPID_SUBJECT', 'mailto:admin@example.com'));
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Создаёт пару ключей VAPID.
     *
     * @return array{public: string, private: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])
            ?: throw new RuntimeException('openssl не создал ключ');
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public' => self::b64("\x04" . self::pad32($ec['x']) . self::pad32($ec['y'])),
            'private' => self::b64(self::pad32($ec['d'])),
        ];
    }

    /**
     * Шифрует текст для браузера (RFC 8291, aes128gcm): тело запроса к службе уведомлений.
     *
     * @param string|null $salt и $ephemeral — только для проверки по примеру из RFC
     * @param array{private: string, public: string}|null $ephemeral
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?string $salt = null, ?array $ephemeral = null): string
    {
        $uaPublic = self::b64decode($p256dh);
        $authSecret = self::b64decode($auth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Подписка браузера повреждена');
        }

        if ($ephemeral === null) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])
                ?: throw new RuntimeException('openssl не создал ключ');
            $ec = openssl_pkey_get_details($key)['ec'];
            $asPublic = "\x04" . self::pad32($ec['x']) . self::pad32($ec['y']);
        } else {
            $asPublic = $ephemeral['public'];
            $key = self::privateKey($ephemeral['private'], $asPublic);
        }
        $salt ??= random_bytes(16);

        $shared = openssl_pkey_derive(self::publicKeyPem($uaPublic), $key, 32)
            ?: throw new RuntimeException('Не удалось согласовать ключ с браузером');
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Одна запись: текст и разделитель последней записи 0x02, без дополнения
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag)
            ?: throw new RuntimeException('Не удалось зашифровать уведомление');

        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /**
     * Заголовок Authorization с подписью VAPID для службы уведомлений по адресу $endpoint.
     */
    public function authorization(string $endpoint, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $input = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($input, $der, $this->vapidKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Не удалось подписать уведомление');
        }

        return 'vapid t=' . $input . '.' . self::b64(self::derToRaw($der)) . ', k=' . $this->publicKey;
    }

    /**
     * Рассылает уведомления параллельно. Возвращает HTTP-код ответа службы для каждого
     * (0 — нет связи): 404 и 410 значат, что подписки больше нет.
     *
     * @param array<int, array{endpoint: string, p256dh: string, auth: string, payload: string, urgent: bool}> $jobs
     * @return array<int, int>
     */
    public function send(array $jobs): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $result = [];
        foreach ($jobs as $id => $job) {
            try {
                $body = self::encrypt($job['payload'], $job['p256dh'], $job['auth']);
            } catch (RuntimeException $e) {
                error_log('push: ' . $e->getMessage());
                $result[$id] = 410;
                continue;
            }
            $ch = curl_init($job['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $this->authorization($job['endpoint']),
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: 86400',
                    'Urgency: ' . ($job['urgent'] ? 'high' : 'normal'),
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $id => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 400 && $code !== 404 && $code !== 410) {
                error_log('push: служба уведомлений ответила ' . $code . ': ' . mb_substr((string) curl_multi_getcontent($ch), 0, 200));
            }
            $result[$id] = $code;
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return $result;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private static function privateKey(string $d, string $public): mixed
    {
        $der = hex2bin(self::EC_PRIVATE_PREFIX) . $d . hex2bin(self::EC_PRIVATE_MIDDLE) . $public;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('Ключ не читается openssl');
    }

    private static function publicKeyPem(string $point): string
    {
        $der = hex2bin(self::EC_PUBLIC_PREFIX) . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function pad32(string $bytes): string
    {
        return str_pad($bytes, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Подпись ECDSA из DER (как отдаёт openssl) в 64 байта r‖s (как требует JWT).
     */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= self::pad32($int);
            $pos += 2 + $len;
        }

        return $out;
    }
}
