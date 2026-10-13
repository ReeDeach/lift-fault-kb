<?php

declare(strict_types=1);

namespace Tests\Repairs;

use App\Http\HttpException;
use App\Repairs\RepairsRepository;
use App\Repairs\ReportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxFixture;

final class ReportParserTest extends TestCase
{
    public function testReadsReportAsFrom1C(): void
    {
        $xlsx = XlsxFixture::report('УЧ.ТЕСТОВЫЙ А.А.', [
            ['2508н', 'МОСКОВСКАЯ 253 п.2', '9', '31.03.2026 19:05:43', '31.03.2026 19:50:00', 'Нет', '4', '45 MLM;44 C0 MLM'],
            ['2508н', 'МОСКОВСКАЯ 253 п.2', '1', '31.03.2026 9:00:00', '31.03.2026 11:32:00', 'Да', '4', 'ВнТО'],
            ['6820н', 'Я.КУПАЛЫ 108/2 п.1', '4', '31.03.2026 21:20:00', null, 'Да', '8', '49 MLM;44 С6 MLM'],
            ['5433н', 'МОСКОВСКАЯ 320', '1', '31.03.2026 14:48:23', '31.03.2026 16:30:00', 'Нет', '1', 'Посторон.предмет'],
            ['2391н', 'МОСКОВСКАЯ 265 п.1', '1', '31.03.2026 8:30:00', '31.03.2026 9:30:00', 'Да', '4', 'ВнТО(Замена магнуса на УПДКЛ)'],
            ['', 'итого', null, null, null, null, null, null],
        ]);

        $report = ReportParser::parse($xlsx);

        $this->assertSame('2026-03-31 08:00', $report['period_from']->format('Y-m-d H:i'));
        $this->assertSame('2026-04-01 08:00', $report['period_to']->format('Y-m-d H:i'));
        $this->assertSame('УЧ.ТЕСТОВЫЙ А.А.', $report['section']);
        $this->assertCount(5, $report['rows'], 'Строка без номера лифта пропущена');

        [$fault, $planned, $open, $text, $plannedNote] = $report['rows'];
        $this->assertSame('planned', $plannedNote['kind'], 'ВнТО с пояснением в скобках — тоже плановая работа');
        $this->assertSame(['45', '44', 'C0'], $fault['codes']);
        $this->assertSame('fault', $fault['kind']);
        $this->assertSame('9', $fault['floor']);
        $this->assertSame(4, $fault['stops_30d']);
        $this->assertFalse($fault['stopped']);
        $this->assertSame('planned', $planned['kind']);
        $this->assertTrue($planned['stopped']);
        $this->assertNull($open['finished_at']);
        $this->assertSame(['49', '44', 'C6'], $open['codes'], 'Кириллическая С в подсказке становится латинской');
        $this->assertSame([], $text['codes']);
        $this->assertSame('fault', $text['kind']);
    }

    public function testSharedAndRichTextCells(): void
    {
        $report = ReportParser::parse(XlsxFixture::buildShared([
            ['Период отчета: 31.07.2026 8:00:00 по 03.08.2026 8:00:00'],
            ['УЧ.ТЕСТОВЫЙ А.А.'],
            ['Номер лифта', 'Адрес установки лифта', 'Этаж ост.', 'Дата время заявки', 'Дата время окончания ремона', 'Лифт в ост.', 'Колво ост. за посл. 30 дн.', 'Информация о неисправности'],
            ['6149н', 'ВЫСОКАЯ 34 п.3', '1', '31.07.2026 21:57:00', '01.08.2026 0:20:00', 'Нет', '8', '44 C6 MLM'],
        ]));

        $this->assertSame('УЧ.ТЕСТОВЫЙ А.А.', $report['section']);
        $this->assertSame('ВЫСОКАЯ 34 п.3', $report['rows'][0]['address'], 'Форматированный текст собран из кусков');
        $this->assertSame(['44', 'C6'], $report['rows'][0]['codes']);
    }

    public function testCodesAndNotesFromInfo(): void
    {
        $this->assertSame([['99'], ['АПСиПДЗ сброс']], ReportParser::info('99 MLM;АПСиПДЗ сброс'));
        $this->assertSame([['48'], ['Регулировка замка']], ReportParser::info('48 MLM;Регулировка замка.'));
        $this->assertSame([[], ['Включен режим МП-1']], ReportParser::info('Включен режим МП-1'));
        $this->assertSame([['43'], ['СПК']], ReportParser::info('43 MLM;СПК'));
    }

    public function testNotAReportIsRejected(): void
    {
        $this->expectException(HttpException::class);
        ReportParser::parse(XlsxFixture::build([['Просто таблица'], ['1', '2']]));
    }

    public function testNotAZipIsRejected(): void
    {
        $this->expectException(HttpException::class);
        ReportParser::parse('это не xlsx');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function addresses(): array
    {
        return [
            'подъезд п.N' => ['Я.КУПАЛЫ 108/2 п.1', 'купалы 108/2'],
            'полное имя улицы' => ['Янки Купалы 108/2', 'купалы 108/2'],
            'подъезд Nп.' => ['МОСКОВСКАЯ 293А 1п.', 'московская 293а'],
            'пометка в скобках' => ['МОЛОДОГВАРДЕЙСКАЯ 5 (правый)', 'молодогвардейская 5'],
            'дробь без корпуса' => ['Я. Купалы 13/', 'купалы 13'],
            'ё' => ['Зелёная 30', 'зеленая 30'],
        ];
    }

    #[DataProvider('addresses')]
    public function testAddressKey(string $address, string $key): void
    {
        $this->assertSame($key, RepairsRepository::addressKey($address));
    }
}
