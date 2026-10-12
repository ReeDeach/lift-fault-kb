<?php

declare(strict_types=1);

namespace App\Diagnosis;

use App\Repository\FaultCodeRepository;
use App\Search\SearchService;
use Closure;
use PDO;
use Throwable;

/**
 * Собирает из базы причины-кандидаты для диагностики. ИИ выбирает только из них.
 *
 * Источники и вес (чем больше, тем выше в списке без ИИ):
 *   причина, названная для кода прямо в руководстве          10
 *   причина через симптомы кода                               likelihood × 1.5
 *   причина симптома, найденного поиском по описанию          likelihood × k
 *   причина, найденная поиском по описанию напрямую           4 × k
 * где k от 1 до 0.3 убывает с местом в выдаче поиска. Коды, которые нашёл поиск,
 * а не назвал пользователь, весят вдвое меньше.
 */
final class CandidateFinder
{
    public const MAX_CAUSES = 15;
    private const MAX_CASES = 5;
    private const MAX_EVIDENCE = 4;
    private const SEARCH_HITS = 15;
    private const SEARCH_CODES = 5;

    /** Описание длинное, и с симптомом совпадает лишь часть слов: хватает трети */
    private const SEARCH_SHARE = 0.34;

    /** @var array<int, array<string, mixed>> */
    private array $causes = [];

    /**
     * @param (Closure(): SearchService)|null $search null — поиск не настроен
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly FaultCodeRepository $codes,
        private readonly ?Closure $search,
    ) {
    }

    /**
     * @param list<int>|null $modelIds
     * @param list<string>|null $codes коды с индикатора (например 44 и подсказка C6)
     * @param array{models: list<int>, controllers: list<int>}|null $owner
     * @return array{causes: list<array<string, mixed>>, codes: list<array<string, mixed>>, cases: list<array<string, mixed>>, search: string}
     */
    public function find(string $description, ?array $modelIds, ?array $owner, ?array $codes): array
    {
        $this->causes = [];

        $named = $codes !== null ? $this->codes->search($modelIds, $codes, 10, 0)['items'] : [];
        [$hits, $searchStatus] = $this->searchHits($description, $owner);

        // Коды из поиска по тексту нужны, только если пользователь код не назвал: иначе число
        // из описания («на индикаторе 45») находит чужие коды, где это число просто упомянуто
        $found = [];
        $namedIds = array_column($named, 'id');
        foreach ($named === [] ? $hits : [] as $hit) {
            if ($hit['type'] === 'codes' && count($found) < self::SEARCH_CODES) {
                $found[] = $hit['id'];
            }
        }

        $codes = $this->codes->withTree([...$named, ...$this->codes->findMany($found)]);
        $cases = [];
        foreach ($codes as $item) {
            $weight = in_array($item['id'], $namedIds, true) ? 1.0 : 0.5;
            $label = 'Код ' . $item['code'] . ' (' . $item['owner']['name'] . ')';
            foreach ($item['causes'] as $cause) {
                $cause['direct']
                    ? $this->add($cause, 10 * $weight, "$label: причина названа в руководстве")
                    : $this->add($cause, $cause['likelihood'] * 1.5 * $weight, "$label: связана через симптомы, вероятность {$cause['likelihood']} из 5");
            }
            foreach ($item['cases'] as $case) {
                $cases[$case['id']] ??= $case + ['code' => $item['code']];
            }
        }

        $this->addFromSearch($hits);

        $causes = array_values($this->causes);
        usort($causes, static fn (array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $causes = array_slice($causes, 0, self::MAX_CAUSES);

        $fixes = $this->codes->fixesForCauses(array_column($causes, 'id'));
        foreach ($causes as &$cause) {
            $cause['score'] = round($cause['score'], 2);
            $cause['evidence'] = array_slice(array_values(array_unique($cause['evidence'])), 0, self::MAX_EVIDENCE);
            $cause['fixes'] = $fixes[$cause['id']] ?? [];
        }
        unset($cause);

        return [
            'causes' => $causes,
            'codes' => array_map(
                static fn (array $c): array => array_diff_key($c, ['symptoms' => true, 'causes' => true, 'cases' => true]),
                $codes,
            ),
            'cases' => array_slice(array_values($cases), 0, self::MAX_CASES),
            'search' => $searchStatus,
        ];
    }

    /**
     * @return array{list<array<string, mixed>>, string}
     */
    private function searchHits(string $description, ?array $owner): array
    {
        if ($this->search === null) {
            return [[], 'disabled'];
        }

        try {
            return [
                ($this->search)()->search($description, ['codes', 'symptoms', 'causes'], $owner, self::SEARCH_HITS, 0, self::SEARCH_SHARE)['items'],
                'ok',
            ];
        } catch (Throwable $e) {
            error_log('diagnose search: ' . $e->getMessage());

            return [[], 'unavailable'];
        }
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function addFromSearch(array $hits): void
    {
        $rank = [];
        foreach ($hits as $i => $hit) {
            $rank[$hit['type']][$hit['id']] = max(0.3, 1 - $i / self::SEARCH_HITS);
        }

        if (isset($rank['symptoms'])) {
            $in = implode(',', array_keys($rank['symptoms']));
            foreach ($this->pdo->query(
                'SELECT sc.symptom_id, sc.likelihood, s.title AS symptom, c.id, c.title, c.source FROM symptom_causes sc '
                . "JOIN symptoms s ON s.id = sc.symptom_id JOIN causes c ON c.id = sc.cause_id WHERE sc.symptom_id IN ($in)"
            )->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = $rank['symptoms'][(int) $r['symptom_id']];
                $this->add(
                    ['id' => (int) $r['id'], 'title' => $r['title'], 'source' => $r['source']],
                    (int) $r['likelihood'] * $k,
                    "Описание похоже на симптом «{$r['symptom']}», вероятность {$r['likelihood']} из 5",
                );
            }
        }

        if (isset($rank['causes'])) {
            $in = implode(',', array_keys($rank['causes']));
            foreach ($this->pdo->query("SELECT id, title, source FROM causes WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this->add(
                    ['id' => (int) $r['id'], 'title' => $r['title'], 'source' => $r['source']],
                    4 * $rank['causes'][(int) $r['id']],
                    'Описание совпадает с формулировкой причины',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $cause
     */
    private function add(array $cause, float $score, string $evidence): void
    {
        $id = (int) $cause['id'];
        $this->causes[$id] ??= [
            'id' => $id,
            'title' => $cause['title'],
            'source' => $cause['source'] ?? null,
            'score' => 0.0,
            'evidence' => [],
        ];
        $this->causes[$id]['score'] += $score;
        $this->causes[$id]['evidence'][] = $evidence;
    }
}
