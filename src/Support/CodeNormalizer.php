<?php

declare(strict_types=1);

namespace App\Support;

/**
 * В руководствах и при наборе с клавиатуры коды часто пишут кириллицей, внешне неотличимой
 * от латиницы (А0, Е36, С6). В базе все коды хранятся латиницей, поэтому ввод приводится к ней.
 */
final class CodeNormalizer
{
    private const LOOKALIKES = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X',
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x',
    ];

    public static function toLatin(string $value): string
    {
        return strtr(trim($value), self::LOOKALIKES);
    }

    /**
     * Ключ для сравнения названий моделей: латиница без различий регистра.
     */
    public static function key(string $value): string
    {
        return mb_strtolower(self::toLatin($value));
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,32}$/', $code) === 1;
    }

    public const MAX_CODES = 5;

    public const LIST_RULE = 'Код: до 32 символов, буквы, цифры, точка, дефис и подчёркивание. '
        . 'Несколько кодов (например, 44 C6) — через пробел или запятую, не больше ' . self::MAX_CODES;

    /**
     * Разбирает ввод с одним или несколькими кодами: «44 C6», «44, С6», «44/C6».
     * На индикаторе УЛ код 44 часто идёт вместе с подсказкой C0–C9, и пишут их вместе.
     *
     * @return list<string>|null коды латиницей без повторов; null — есть некорректный код или их слишком много
     */
    public static function parseList(string $input): ?array
    {
        $parts = preg_split('/[\s,;\/+]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $codes = [];

        foreach ($parts as $part) {
            $code = self::toLatin($part);
            if (!self::isValidCode($code)) {
                return null;
            }
            $codes[strtoupper($code)] ??= $code;
        }

        return $codes === [] || count($codes) > self::MAX_CODES ? null : array_values($codes);
    }
}
