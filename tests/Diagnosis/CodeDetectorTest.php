<?php

declare(strict_types=1);

namespace Tests\Diagnosis;

use App\Diagnosis\CodeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function texts(): array
    {
        return [
            'список через запятую' => ['Постоянно появляется ошибка 49, 50', ['49', '50']],
            'код с подсказкой' => ['Пассажиры застряли, на индикаторе 44 с подсказкой С6', ['44', 'C6']],
            'код и подсказка подряд' => ['код 44 C6, лифт стоит', ['44', 'C6']],
            'кириллица в коде' => ['В ПЧ ошибка Е36', ['E36']],
            'через двоеточие и «или»' => ['Ошибки: 46 или 48', ['46', '48']],
            'на табло' => ['на табло A2, двери открыты', ['A2']],
            'регистр не важен' => ['ОШИБКА 50', ['50']],
            'ответ на уточнение одним кодом' => ['Лифт стоит, ошибка 99. 44', ['99', '44']],
            'ответ кодом с подсказкой' => ['Двери не закрываются. 44 С6', ['44', 'C6']],
        ];
    }

    #[DataProvider('texts')]
    public function testFindsCodesAfterKeywords(string $text, array $expected): void
    {
        $this->assertSame($expected, CodeDetector::detect($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noCodes(): array
    {
        return [
            'этаж — не код' => ['Пассажиры застряли на 5 этаже'],
            'ошибка без номера' => ['Ошибка на панели, лифт стоит'],
            'слово после ошибки' => ['Ошибка появляется постоянно, 3 раза за день'],
            'просто числа' => ['Лифт 1990 года, 12 этажей'],
            'ответ одной цифрой' => ['Сколько раз останавливался. 3'],
            'количество со словом' => ['Застряли. 2 человека'],
        ];
    }

    #[DataProvider('noCodes')]
    public function testIgnoresNumbersWithoutCodeKeyword(string $text): void
    {
        $this->assertSame([], CodeDetector::detect($text));
    }

    public function testNoMoreThanFiveCodes(): void
    {
        $this->assertCount(5, CodeDetector::detect('коды 1, 2, 3, 4, 5, 6, 7'));
    }
}
