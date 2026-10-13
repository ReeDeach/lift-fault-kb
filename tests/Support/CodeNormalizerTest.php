<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\CodeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lookalikes(): array
    {
        return [
            'кириллические А0' => ['А0', 'A0'],
            'кириллические Е36' => ['Е36', 'E36'],
            'кириллическая С в C6' => ['С6', 'C6'],
            'строчные' => ['а5', 'a5'],
            'латиница не меняется' => ['b9', 'b9'],
            'пробелы по краям' => ['  45 ', '45'],
        ];
    }

    #[DataProvider('lookalikes')]
    public function testCyrillicLookalikesBecomeLatin(string $input, string $expected): void
    {
        $this->assertSame($expected, CodeNormalizer::toLatin($input));
    }

    public function testModelNamesMatchRegardlessOfAlphabetAndCase(): void
    {
        $this->assertSame(CodeNormalizer::key('0401К'), CodeNormalizer::key('0401K'));
        $this->assertSame(CodeNormalizer::key('ПВБ1010Г'), CodeNormalizer::key('ПВБ1010Г'));
        $this->assertNotSame(CodeNormalizer::key('0401К'), CodeNormalizer::key('0402К'));
    }

    public function testCodeValidation(): void
    {
        $this->assertTrue(CodeNormalizer::isValidCode('E36'));
        $this->assertTrue(CodeNormalizer::isValidCode('b9'));
        $this->assertFalse(CodeNormalizer::isValidCode(''));
        $this->assertFalse(CodeNormalizer::isValidCode('ОШ 44'));
        $this->assertFalse(CodeNormalizer::isValidCode(str_repeat('1', 33)));
        $this->assertFalse(CodeNormalizer::isValidCode("1' OR '1'='1"));
    }

    public function testCodeListAsWrittenFromIndicator(): void
    {
        $this->assertSame(['44', 'C6'], CodeNormalizer::parseList('44 С6'));
        $this->assertSame(['44', 'C6'], CodeNormalizer::parseList(' 44, C6 '));
        $this->assertSame(['44', 'C6'], CodeNormalizer::parseList('44/C6'));
        $this->assertSame(['E36'], CodeNormalizer::parseList('Е36 e36'));
        $this->assertSame(['45'], CodeNormalizer::parseList('45'));
    }

    public function testBadCodeListIsRejected(): void
    {
        $this->assertNull(CodeNormalizer::parseList(''));
        $this->assertNull(CodeNormalizer::parseList('   '));
        $this->assertNull(CodeNormalizer::parseList("1' OR '1'='1"));
        $this->assertNull(CodeNormalizer::parseList('ОШ 44'));
        $this->assertNull(CodeNormalizer::parseList('1 2 3 4 5 6'));
    }
}
