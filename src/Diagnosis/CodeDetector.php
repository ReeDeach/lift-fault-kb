<?php

declare(strict_types=1);

namespace App\Diagnosis;

use App\Support\CodeNormalizer;

/**
 * Находит коды в тексте обращения, когда поле кода не заполнено:
 * «постоянно появляется ошибка 49, 50», «на индикаторе 44 с подсказкой C6», «код Е36».
 *
 * Кодом считается только то, что стоит сразу после слов «код», «ошибка», «индикатор»,
 * «табло» или «подсказка» и содержит цифру. Поэтому «застряли на 5 этаже» кодом не станет.
 * Коды других систем («Er 55», «Err 30») не берутся: в базе они с кодами УЛ не связаны.
 * Есть ли такой код на самом деле, проверяет уже поиск по базе.
 */
final class CodeDetector
{
    private const TOKEN = '(?:[A-Za-zА-Яа-яЁё]{1,2}\d{1,3}|\d{1,3}[A-Za-zА-Яа-яЁё]?)(?![\p{L}\d])';

    /**
     * @return list<string> коды латиницей, не больше CodeNormalizer::MAX_CODES
     */
    public static function detect(string $text): array
    {
        $token = self::TOKEN;
        $pattern = '/(?<![\p{L}])(?:код\p{L}*|ошибк\p{L}*|индикатор\p{L}*|табло|подсказк\p{L}*)'
            . '[\s:№#]*'
            . "($token(?:\\s*(?:,|\\/|;|\\s|\\bи\\b|\\bили\\b)\\s*$token)*)/iu";

        if (preg_match_all($pattern, $text, $matches) === 0) {
            return [];
        }

        $codes = [];
        foreach ($matches[1] as $list) {
            preg_match_all('/' . $token . '/u', $list, $tokens);
            foreach ($tokens[0] as $raw) {
                $code = CodeNormalizer::toLatin($raw);
                if (CodeNormalizer::isValidCode($code)) {
                    $codes[strtoupper($code)] ??= $code;
                }
            }
        }

        return array_slice(array_values($codes), 0, CodeNormalizer::MAX_CODES);
    }
}
