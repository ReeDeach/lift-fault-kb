<?php

declare(strict_types=1);

namespace Tests\Support;

use ZipArchive;

/**
 * Минимальный XLSX для тестов: один лист, ячейки строками (inlineStr), как в отчёте 1С.
 */
final class XlsxFixture
{
    /**
     * @param list<list<string|null>> $rows
     */
    public static function build(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $row) {
            $xml .= '<row r="' . ($i + 1) . '">';
            foreach ($row as $j => $value) {
                if ($value === null) {
                    continue;
                }
                $xml .= '<c r="' . chr(65 + $j) . ($i + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /**
     * Тот же лист, но строки в общей таблице sharedStrings, часть — форматированным текстом
     * (<r><t>…</t></r>), как сохраняют Excel и другие программы.
     *
     * @param list<list<string|null>> $rows
     */
    public static function buildShared(array $rows): string
    {
        $strings = [];
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $row) {
            $sheet .= '<row r="' . ($i + 1) . '">';
            foreach ($row as $j => $value) {
                if ($value === null) {
                    continue;
                }
                $strings[] = $value;
                $sheet .= '<c r="' . chr(65 + $j) . ($i + 1) . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        foreach ($strings as $k => $s) {
            $half = intdiv(mb_strlen($s), 2);
            $shared .= $k % 2 === 0
                ? '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>'
                : '<si><r><t>' . htmlspecialchars(mb_substr($s, 0, $half), ENT_XML1) . '</t></r><r><rPr><b/></rPr><t>' . htmlspecialchars(mb_substr($s, $half), ENT_XML1) . '</t></r></si>';
        }
        $shared .= '</sst>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $shared);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /**
     * Отчёт в формате 1С: период, участок, заголовок и строки заявок.
     *
     * @param list<list<string|null>> $calls [номер, адрес, этаж, начало, конец, в ост., за 30 дн., информация]
     */
    public static function report(string $section, array $calls, string $from = '31.03.2026 8:00:00', string $to = '01.04.2026 8:00:00'): string
    {
        return self::build([
            ["Период отчета: $from по $to"],
            [$section],
            ['Номер лифта', 'Адрес установки лифта', "Этаж\nост.", 'Дата время заявки', 'Дата время окончания ремона', 'Лифт в ост.', "Колво\nост. за\nпосл.\n30 дн.", 'Информация о неисправности'],
            ...$calls,
        ]);
    }
}
