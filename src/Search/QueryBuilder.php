<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\CodeNormalizer;

/**
 * Превращает ввод пользователя в запрос Manticore.
 *
 * Из ввода берутся только слова (буквы и цифры), поэтому операторы языка запросов
 * пользователь подставить не может. Слова с цифрами считаются кодами и приводятся
 * к латинице (Е36 → E36). Служебные слова (не, на, в…) отбрасываются, остальные
 * объединяются кворумом: документ подходит, если в нём есть заданная доля слов
 * (по умолчанию 60 %), а документы с большим числом совпадений ранжируются выше.
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
     * @param float $share какая доля значимых слов должна найтись в документе. Для поиска 0.6,
     *        для диагностики меньше: описание длинное, и в симптоме совпадёт лишь часть слов
     * @return string|null null — в вводе нет ни одного слова
     */
    public static function build(string $input, float $share = 0.6): ?string
    {
        $words = self::words($input);
        if ($words === []) {
            return null;
        }

        // Служебные слова есть почти в каждом тексте: с ними в кворум проходили документы,
        // где совпало только «дверь» и несколько «не». Поэтому кворум считается по значимым словам.
        // Но «не» меняет смысл, и вся фраза целиком («дверь не закрывается») добавляется как
        // альтернатива: она не расширяет выдачу, а поднимает документы, где фраза стоит рядом.
        $content = array_values(array_diff($words, self::STOP_WORDS)) ?: $words;
        $match = count($content) === 1
            ? $content[0]
            : '("' . implode(' ', $content) . '"/' . max(1, (int) ceil(count($content) * $share)) . ')';

        if (count($content) === count($words)) {
            return count($content) === 1 ? $match : trim($match, '()');
        }

        return $match . ' | "' . implode(' ', $words) . '"~2';
    }

    private const STOP_WORDS = [
        'не', 'ни', 'на', 'в', 'во', 'и', 'с', 'со', 'по', 'у', 'к', 'ко', 'о', 'об', 'а', 'но', 'или',
        'из', 'за', 'от', 'до', 'при', 'для', 'же', 'ли', 'бы', 'то', 'это', 'как', 'что', 'так',
    ];
}
