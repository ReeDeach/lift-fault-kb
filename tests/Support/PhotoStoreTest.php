<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Comms\PhotoStore;
use App\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class PhotoStoreTest extends TestCase
{
    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function png(string $extra = ''): string
    {
        $ihdr = pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr) . $extra
            . self::chunk('IDAT', (string) gzcompress("\0\0")) . self::chunk('IEND', '');
    }

    public function testPngTextAndExifAreRemoved(): void
    {
        $dirty = self::png(self::chunk('tEXt', "Comment\0GPS 52.09") . self::chunk('eXIf', 'MM'));

        $photo = PhotoStore::decode('data:image/png;base64,' . base64_encode($dirty));

        $this->assertSame('image/png', $photo['mime']);
        $this->assertSame(self::png(), $photo['bytes']);
    }

    public function testTypeIsCheckedByContentNotByLabel(): void
    {
        // PNG под видом JPEG принимается как PNG
        $photo = PhotoStore::decode('data:image/jpeg;base64,' . base64_encode(self::png()));
        $this->assertSame('image/png', $photo['mime']);

        $this->expectException(HttpException::class);
        PhotoStore::decode('data:image/png;base64,' . base64_encode('GIF89a' . str_repeat("\0", 20)));
    }

    public function testTooBigIsRejectedBeforeDecoding(): void
    {
        try {
            PhotoStore::decode('data:image/jpeg;base64,' . str_repeat('A', 5 * 1024 * 1024));
            $this->fail('Ожидалась ошибка');
        } catch (HttpException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function testBrokenJpegIsKeptAsIs(): void
    {
        $this->assertSame("\xFF\xD8\xFF", PhotoStore::stripJpeg("\xFF\xD8\xFF"));
    }
}
