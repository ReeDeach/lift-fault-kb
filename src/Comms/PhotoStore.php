<?php

declare(strict_types=1);

namespace App\Comms;

use App\Http\HttpException;
use App\Support\Env;

/**
 * Фото к сообщениям: проверка и хранение на диске.
 *
 * Телефон сам уменьшает снимок и присылает его JPEG в data URL (обычно 200–600 КБ).
 * Сервер принимает только JPEG и PNG, сверяет тип по содержимому, а не по заголовку,
 * и вырезает метаданные: в EXIF снимка с телефона бывают координаты места съёмки.
 * Файлы лежат вне public/ и отдаются через /api/files/{token} с проверкой прав.
 */
final class PhotoStore
{
    public const MAX_BYTES = 3 * 1024 * 1024;
    public const MAX_SIDE = 4096;

    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png'];

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromEnv(): self
    {
        return new self(Env::get('UPLOAD_DIR', '/var/www/uploads'));
    }

    /**
     * Разбирает data URL снимка. Ошибка — 400 с понятным текстом.
     *
     * @return array{bytes: string, mime: string, width: int, height: int}
     */
    public static function decode(mixed $dataUrl): array
    {
        if (!is_string($dataUrl) || !preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new HttpException(400, 'Ошибка в данных', ['data' => 'Нужно фото JPEG или PNG']);
        }
        if (strlen($m[1]) > intdiv(self::MAX_BYTES * 4, 3) + 4) {
            throw new HttpException(413, 'Фото слишком большое: не больше 3 МБ');
        }
        $bytes = base64_decode($m[1], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new HttpException(400, 'Ошибка в данных', ['data' => 'Файл не похож на фото JPEG или PNG']);
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new HttpException(400, 'Ошибка в данных', ['data' => 'Размер фото — до ' . self::MAX_SIDE . ' точек по стороне']);
        }

        $mime = self::TYPES[$info[2]];
        $bytes = $mime === 'image/jpeg' ? self::stripJpeg($bytes) : self::stripPng($bytes);

        return ['bytes' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }

    public function save(string $token, string $bytes): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new HttpException(503, 'Хранилище фото недоступно');
        }
        if (@file_put_contents($this->path($token), $bytes, LOCK_EX) === false) {
            throw new HttpException(503, 'Не удалось сохранить фото');
        }
    }

    public function read(string $token): ?string
    {
        $bytes = @file_get_contents($this->path($token));

        return $bytes === false ? null : $bytes;
    }

    public function delete(string $token): void
    {
        @unlink($this->path($token));
    }

    /**
     * Удаляет все файлы (сброс демо).
     */
    public function clear(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.img') ?: [] as $file) {
            $n += (int) @unlink($file);
        }

        return $n;
    }

    private function path(string $token): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            throw new HttpException(404, 'Фото не найдено');
        }

        return $this->dir . '/' . $token . '.img';
    }

    /**
     * JPEG без метаданных: убираются сегменты APP1 (EXIF, XMP), APP13 (IPTC) и комментарии.
     * APP0 (JFIF) и APP14 (Adobe, цветовая схема) остаются, сжатые данные не трогаются.
     */
    public static function stripJpeg(string $jpeg): string
    {
        $out = substr($jpeg, 0, 2);
        $pos = 2;
        $len = strlen($jpeg);
        while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xDA) {
                // Начало сжатых данных: дальше копируется всё как есть
                break;
            }
            $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($size < 2 || $pos + 2 + $size > $len) {
                break;
            }
            if ($marker !== 0xE1 && $marker !== 0xED && $marker !== 0xFE) {
                $out .= substr($jpeg, $pos, 2 + $size);
            }
            $pos += 2 + $size;
        }

        return $out . substr($jpeg, $pos);
    }

    /**
     * PNG без текстовых чанков и EXIF.
     */
    public static function stripPng(string $png): string
    {
        $out = substr($png, 0, 8);
        $pos = 8;
        $len = strlen($png);
        while ($pos + 12 <= $len) {
            $size = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            if ($pos + 12 + $size > $len) {
                break;
            }
            if (!in_array($type, ['eXIf', 'tEXt', 'iTXt', 'zTXt'], true)) {
                $out .= substr($png, $pos, 12 + $size);
            }
            $pos += 12 + $size;
        }

        return $out . substr($png, $pos);
    }
}
