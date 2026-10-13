<?php

declare(strict_types=1);

namespace App\Repairs;

use App\Http\HttpException;
use App\Support\CodeNormalizer;
use DateTimeImmutable;
use SimpleXMLElement;
use ZipArchive;

/**
 * Разбор ежедневного отчёта 1С о заявках на ремонт (XLSX).
 *
 * Строка «Период отчета: 31.03.2026 8:00:00 по 01.04.2026 8:00:00», затем участок («УЧ.…»),
 * затем таблица: номер лифта, адрес, этаж, время заявки и окончания ремонта, «Лифт в ост.»,
 * остановок за 30 дней и «Информация о неисправности». Столбцы ищутся по заголовкам,
 * а не по номеру, — так переживём, если 1С добавит или переставит столбец.
 *
 * Из «Информации» берутся коды: «45 MLM;44 C0 MLM» → 45, 44, C0. «MLM» — приписка
 * диспетчеров без значения для нас, отбрасывается. Остальное (например «АПСиПДЗ сброс»,
 * «Посторон.предмет») остаётся текстом. Строки «ТО2», «ВнТО» — плановые работы, не вызовы.
 */
final class ReportParser
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const COLUMNS = [
        'reg' => 'номер лифта',
        'address' => 'адрес',
        'floor' => 'этаж',
        'started' => 'время заявки',
        'finished' => 'окончания',
        'stopped' => 'в ост',
        'stops' => '30',
        'info' => 'информация',
    ];

    /** Плановые работы (в отчёте вместе с вызовами), в том числе с пояснением: «ВнТО(Замена магнуса на УПДКЛ)» */
    private const PLANNED = '/^(?:ВНТО|ТО-?\d?|ПТО|ЕТО)(?![\p{L}])/u';

    /**
     * @return array{period_from: DateTimeImmutable, period_to: DateTimeImmutable, section: string,
     *   rows: list<array{reg: string, address: string, floor: ?string, started_at: DateTimeImmutable,
     *   finished_at: ?DateTimeImmutable, stopped: bool, stops_30d: int, info: string, codes: list<string>,
     *   kind: string}>}
     */
    public static function parse(string $xlsx): array
    {
        if (strlen($xlsx) > self::MAX_BYTES) {
            throw new HttpException(413, 'Файл слишком большой: отчёт 1С занимает килобайты');
        }
        $grid = self::grid($xlsx);

        $period = null;
        $section = '';
        $header = null;
        foreach ($grid as $i => $row) {
            $line = trim(implode(' ', $row));
            if ($period === null && preg_match('/(\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?)\s+по\s+(\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?)/u', $line, $m)) {
                $period = [self::date($m[1]), self::date($m[2])];
                continue;
            }
            if ($section === '' && preg_match('/^УЧ\b/ui', $line)) {
                $section = mb_substr($line, 0, 190);
                continue;
            }
            if (mb_stripos($line, 'номер лифта') !== false) {
                $header = $i;
                break;
            }
        }
        if ($period === null || $period[0] === null || $period[1] === null || $header === null) {
            throw new HttpException(400, 'Это не похоже на отчёт 1С о заявках: нет строки «Период отчета» или заголовка «Номер лифта»');
        }

        $cols = [];
        foreach ($grid[$header] as $col => $title) {
            $title = mb_strtolower(preg_replace('/\s+/u', ' ', $title));
            foreach (self::COLUMNS as $key => $needle) {
                if (!isset($cols[$key]) && str_contains($title, $needle)) {
                    $cols[$key] = $col;
                    break;
                }
            }
        }
        foreach (['reg', 'address', 'started', 'info'] as $required) {
            if (!isset($cols[$required])) {
                throw new HttpException(400, 'В отчёте не найден столбец «' . self::COLUMNS[$required] . '»');
            }
        }

        $rows = [];
        foreach (array_slice($grid, $header + 1, null, true) as $row) {
            $get = static fn (string $key): string => isset($cols[$key]) ? trim($row[$cols[$key]] ?? '') : '';
            $reg = mb_substr($get('reg'), 0, 32);
            $started = self::date($get('started'));
            if ($reg === '' || $started === null) {
                continue;
            }
            $info = mb_substr(preg_replace('/\s+/u', ' ', $get('info')), 0, 500);
            [$codes, $notes] = self::info($info);
            $planned = $codes === [] && $notes !== [] && preg_match(self::PLANNED, mb_strtoupper(str_replace(['.', ' '], '', $notes[0])));
            $stops = $get('stops');
            $rows[] = [
                'reg' => $reg,
                'address' => mb_substr($get('address'), 0, 190),
                'floor' => $get('floor') !== '' ? mb_substr($get('floor'), 0, 16) : null,
                'started_at' => $started,
                'finished_at' => self::date($get('finished')),
                'stopped' => mb_strtolower($get('stopped')) === 'да',
                'stops_30d' => ctype_digit($stops) ? (int) $stops : 0,
                'info' => $info,
                'codes' => $codes,
                'kind' => $planned ? 'planned' : 'fault',
            ];
        }

        return ['period_from' => $period[0], 'period_to' => $period[1], 'section' => $section, 'rows' => $rows];
    }

    /**
     * Коды и текстовые пометки из «Информации о неисправности».
     *
     * @return array{list<string>, list<string>}
     */
    public static function info(string $info): array
    {
        $codes = [];
        $notes = [];
        foreach (preg_split('/\s*;\s*/u', trim($info)) ?: [] as $part) {
            $part = trim($part, " \t.");
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,3})(?:\s+([CСcс]\d{1,2}))?(?:\s+MLM)?$/u', $part, $m)) {
                foreach (array_filter([$m[1], $m[2] ?? '']) as $code) {
                    $code = strtoupper(CodeNormalizer::toLatin($code));
                    $codes[$code] = $code;
                }
                continue;
            }
            $notes[] = $part;
        }

        return [array_values($codes), $notes];
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Число — дата Excel (дни с 30.12.1899): на случай, если 1С выгрузит даты не текстом
        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value - 25569) * 86400);

            return $seconds > 0 ? (new DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null;
        }
        foreach (['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Первый лист книги как строки текстовых ячеек: [номер строки => [номер столбца => текст]].
     *
     * @return array<int, array<int, string>>
     */
    private static function grid(string $xlsx): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $xlsx);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
            @unlink($tmp);
            throw new HttpException(400, 'Файл не открылся как XLSX. Загрузите отчёт в формате Excel (.xlsx)');
        }
        try {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                        $sheet = $zip->getFromName($name);
                        break;
                    }
                }
            }
            if ($sheet === false) {
                throw new HttpException(400, 'В файле нет листа с таблицей');
            }
            $shared = [];
            $strings = $zip->getFromName('xl/sharedStrings.xml');
            if ($strings !== false) {
                foreach (self::xml($strings)->si as $si) {
                    $shared[] = self::text($si);
                }
            }
        } finally {
            $zip->close();
            @unlink($tmp);
        }

        $grid = [];
        foreach (self::xml($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::column($ref);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    default => (string) $c->v,
                };
                $cells[$col] = $value;
            }
            if ($cells !== []) {
                ksort($cells);
                $grid[(int) $row['r'] ?: count($grid) + 1] = $cells;
            }
        }
        ksort($grid);

        return array_values($grid);
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        $doc = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if ($doc === false) {
            throw new HttpException(400, 'Файл XLSX повреждён');
        }

        return $doc;
    }

    /**
     * Текст ячейки: простой <t> или куски форматированного текста <r><t>. Узла может не быть.
     */
    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = '';
        foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $t) {
            $text .= (string) $t;
        }

        return $text;
    }

    private static function column(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref)) ?: 'A';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
