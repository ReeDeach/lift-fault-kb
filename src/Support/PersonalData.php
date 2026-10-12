<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Признаки персональных данных в описаниях случаев. В базе хранится только техническое
 * описание: без фамилий, адресов, телефонов и регистрационных номеров лифтов.
 */
final class PersonalData
{
    public const PATTERNS = [
        'фамилия с инициалами' => '/[А-ЯЁ][а-яё]+\s+[А-ЯЁ]\.\s?[А-ЯЁ]\./u',
        'регистрационный номер лифта' => '/\b\d{4}\s?н\b/u',
        'телефон' => '/\+?\d[\d\s\-()]{8,}\d/u',
        'адрес' => '/\b(ул|пр|пер|д|кв)\.\s?\S|\bулица\b|\bпроспект\b/u',
    ];

    /**
     * @return string|null что найдено (название признака) или null, если текст чистый
     */
    public static function find(string $text): ?string
    {
        foreach (self::PATTERNS as $label => $regex) {
            if (preg_match($regex, $text) === 1) {
                return $label;
            }
        }

        return null;
    }
}
