<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\CodeNormalizer;

/**
 * Превращает ввод пользователя в запрос Manticore.
 *
 * Из ввода берутся только слова (буквы и цифры), поэтому операторы языка запросов
 * пользователь подставить не может. Слова с цифрами считаются кодами и приводятся
 * к латинице (Е36 → E36). Слова объединяются кворумом: документ подходит, если в нём
 * есть хотя бы половина слов, а документы с большим числом совпадений ранжируются выше.
 * Так «двери не закрываются на этаже» находит симптом, где нет слова «этаж».
 */
final class QueryBuilder
{
    public const MAX_LENGTH = 300;

    private const MAX_WORDS = 16;

    /**
     * @return list<string>
     */
    public static function words(string $input): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(self::code(...), $words);

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * Слово с цифрами, где все буквы — двойники латиницы (е36, а0), считается кодом и пишется
     * латиницей. Обозначения с настоящей кириллицей (мп2, 1дш) остаются как есть.
     */
    private static function code(string $word): string
    {
        if (preg_match('/\d/', $word) !== 1) {
            return $word;
        }
        $latin = strtolower(CodeNormalizer::toLatin($word));

        return preg_match('/\p{Cyrillic}/u', $latin) === 1 ? $word : $latin;
    }

    /**
     * @return string|null null — в вводе нет ни одного слова
     */
    public static function build(string $input): ?string
    {
        $words = self::words($input);
        if ($words === []) {
            return null;
        }
        if (count($words) === 1) {
            return $words[0];
        }

        return '"' . implode(' ', $words) . '"/' . (int) ceil(count($words) / 2);
    }
}
