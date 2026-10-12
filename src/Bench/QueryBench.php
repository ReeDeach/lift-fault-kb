<?php

declare(strict_types=1);

namespace App\Bench;

use App\Repository\CatalogRepository;
use App\Repository\FaultCodeRepository;
use PDO;

/**
 * Сценарии замеров: те же вызовы репозиториев, что делают эндпоинты API.
 * Для каждого сценария — медиана времени и EXPLAIN всех SELECT, которые он выполнил.
 */
final class QueryBench
{
    /** Модель и производитель из синтетических данных DataGenerator */
    public const MODEL = '1000К';
    public const MANUFACTURER = 'maker-5';

    public function __construct(private readonly RecordingPdo $pdo)
    {
    }

    /**
     * @return array<string, callable(): mixed>
     */
    public function scenarios(): array
    {
        $codes = new FaultCodeRepository($this->pdo);
        $catalog = new CatalogRepository($this->pdo);

        return [
            'GET /api/codes?code=45 — код во всех устройствах' =>
                static fn () => $codes->search(null, ['45'], 20, 0),
            'GET /api/codes?model=1000К&code=45 — код в модели' =>
                static fn () => $codes->search($catalog->resolveModelIds(self::MODEL, null), ['45'], 20, 0),
            'GET /api/codes?model=1000К — первая страница кодов модели' =>
                static fn () => $codes->search($catalog->resolveModelIds(self::MODEL, null), null, 20, 0),
            'GET /api/codes?model=1000К&expand=tree — дерево для 20 кодов' =>
                static fn () => $codes->withTree($codes->search($catalog->resolveModelIds(self::MODEL, null), null, 20, 0)['items']),
            'GET /api/models?manufacturer=maker-5 — модели производителя' =>
                static fn () => $catalog->models(self::MANUFACTURER),
            'GET /api/models — все модели' =>
                static fn () => $catalog->models(null),
        ];
    }

    /**
     * @return array<string, array{ms: float, runs: int, plans: list<array{sql: string, rows: list<array<string, mixed>>}>}>
     */
    public function run(int $runs = 15): array
    {
        $results = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $this->pdo->log = [];
            $first = $this->time($scenario);
            $queries = $this->pdo->log;

            // Медленные сценарии не гоняем по 15 раз: хватает трёх замеров
            $times = [$first];
            $n = $first > 1000 ? 2 : $runs - 1;
            for ($i = 0; $i < $n; $i++) {
                $times[] = $this->time($scenario);
            }
            sort($times);

            $results[$name] = [
                'ms' => $times[intdiv(count($times), 2)],
                'runs' => count($times),
                'plans' => $this->explain($queries),
            ];
        }

        return $results;
    }

    private function time(callable $scenario): float
    {
        $start = hrtime(true);
        $scenario();

        return (hrtime(true) - $start) / 1e6;
    }

    /**
     * @param list<array{sql: string, params: list<mixed>}> $queries
     * @return list<array{sql: string, rows: list<array<string, mixed>>}>
     */
    private function explain(array $queries): array
    {
        $plans = [];
        $seen = [];

        foreach ($queries as $query) {
            if (isset($seen[$query['sql']])) {
                continue;
            }
            $seen[$query['sql']] = true;

            $stmt = $this->pdo->prepare('EXPLAIN ' . $query['sql']);
            $stmt->execute($query['params']);
            $plans[] = ['sql' => $query['sql'], 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }
        $this->pdo->log = [];

        return $plans;
    }
}
