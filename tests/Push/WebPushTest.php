<?php

declare(strict_types=1);

namespace Tests\Push;

use App\Push\WebPush;
use PHPUnit\Framework\TestCase;

final class WebPushTest extends TestCase
{
    /**
     * Пример из RFC 8291, приложение A: те же ключи и соль дают ровно то же тело.
     */
    public function testEncryptionMatchesRfcExample(): void
    {
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            WebPush::b64decode('DGv6ra1nlYgDCS1FRnbzlw'),
            [
                'private' => WebPush::b64decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
                'public' => WebPush::b64decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            ],
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_y'
            . 'l95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64($body),
        );
    }

    public function testVapidSignatureVerifiesWithPublicKey(): void
    {
        $keys = WebPush::generateKeys();
        $push = new WebPush($keys['public'], $keys['private'], 'mailto:master@example.com');

        $header = $push->authorization('https://fcm.googleapis.com/fcm/send/abc', 1_800_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=' . preg_quote($keys['public'], '/') . '$/', $header);

        [$jwt] = explode(', ', substr($header, 8));
        [$head, $claims, $signature] = explode('.', $jwt);
        $this->assertSame(
            ['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 12 * 3600, 'sub' => 'mailto:master@example.com'],
            json_decode(WebPush::b64decode($claims), true),
        );

        // r‖s обратно в DER, чтобы проверить подпись средствами openssl
        $raw = WebPush::b64decode($signature);
        $this->assertSame(64, strlen($raw));
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\0" . $bytes;
            }

            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . WebPush::b64decode($keys['public'])), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $this->assertSame(1, openssl_verify($head . '.' . $claims, $der, $pem, OPENSSL_ALGO_SHA256));
    }

    public function testBrokenKeysAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new WebPush('abc', 'def', 'mailto:x@example.com');
    }
}
