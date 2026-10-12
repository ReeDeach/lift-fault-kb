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
}
