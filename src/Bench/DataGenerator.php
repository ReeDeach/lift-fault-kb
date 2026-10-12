<?php

declare(strict_types=1);

namespace App\Bench;

use PDO;

/**
 * Синтетические данные для замеров производительности. Объёмы подобраны так, чтобы
 * таблицы были на порядки больше настоящих и планы запросов стали заметны.
 * Генератор детерминирован (фиксированный seed), замеры воспроизводимы.
 */
final class DataGenerator
{
    private const BATCH = 1000;

    private const WORDS = [
        'дверь', 'кабина', 'шахта', 'замок', 'контакт', 'привод', 'тормоз', 'лебёдка', 'канат', 'ловители',
        'ограничитель', 'скорость', 'датчик', 'плата', 'реле', 'пускатель', 'цепь', 'питание', 'фаза', 'этаж',
        'остановка', 'кнопка', 'вызов', 'индикатор', 'преобразователь', 'двигатель', 'обрыв', 'износ', 'регулировка', 'порог',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly PDO $pdo, private readonly int $scale = 1)
    {
    }

    /**
     * @return array<string, int> число строк по таблицам
     */
    public function run(): array
    {
        mt_srand(42);
        $s = $this->scale;

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0');

        $manufacturers = 20;
        $models = 2000 * $s;
        $controllers = 400 * $s;
        $symptoms = 3000 * $s;
        $causes = 8000 * $s;
        $fixes = 4000 * $s;

        $this->insert('manufacturers', ['id', 'name', 'slug'], $manufacturers,
            static fn (int $i): array => [$i, "Производитель $i", "maker-$i"]);
        $this->insert('models', ['id', 'manufacturer_id', 'name'], $models,
            static fn (int $i): array => [$i, ($i % $manufacturers) + 1, sprintf('%04dК', $i)]);
        $this->insert('controllers', ['id', 'name', 'kind'], $controllers,
            static fn (int $i): array => [$i, "УЛ вариант $i", $i % 4 === 0 ? 'drive' : 'control_unit']);
        // У каждой модели два устройства: УЛ и привод
        $this->insert('model_controllers', ['model_id', 'controller_id'], $models * 2,
            static fn (int $i): array => [intdiv($i - 1, 2) + 1, (($i * 7) % $controllers) + 1]);

        // Коды устройств: по 100 на устройство, номера 1..100 — одни и те же номера у разных устройств
        $perController = 100;
        $this->insert('fault_codes', ['id', 'model_id', 'controller_id', 'code', 'title', 'description', 'is_safety_related'],
            $controllers * $perController,
            fn (int $i): array => [
                $i, null, intdiv($i - 1, $perController) + 1, (string) ((($i - 1) % $perController) + 1),
                $this->phrase(5) . " #$i", $this->phrase(25), (int) ($i % 10 === 0),
            ]);
        // Коды моделей (старые лифты без отдельного устройства): по 10 на модель, вида E01..E10
        $offset = $controllers * $perController;
        $this->insert('fault_codes', ['id', 'model_id', 'controller_id', 'code', 'title', 'description', 'is_safety_related'],
            $models * 10,
            fn (int $i): array => [
                $offset + $i, intdiv($i - 1, 10) + 1, null, sprintf('E%02d', (($i - 1) % 10) + 1),
                $this->phrase(5) . ' #' . ($offset + $i), $this->phrase(25), 0,
            ]);
        $codes = $offset + $models * 10;

        $this->insert('symptoms', ['id', 'title'], $symptoms, fn (int $i): array => [$i, $this->phrase(6) . " #$i"]);
        $this->insert('causes', ['id', 'title'], $causes, fn (int $i): array => [$i, $this->phrase(6) . " #$i"]);
        $this->insert('fixes', ['id', 'title', 'summary', 'safety_note'], $fixes,
            fn (int $i): array => [$i, $this->phrase(5) . " #$i", $this->phrase(20), $i % 5 === 0 ? $this->phrase(8) : null]);
        $this->insert('fix_steps', ['fix_id', 'step_no', 'instruction', 'expected_result'], $fixes * 4,
            fn (int $i): array => [intdiv($i - 1, 4) + 1, (($i - 1) % 4) + 1, $this->phrase(12), $this->phrase(4)]);

        // Связи: у кода 2 симптома и 2 прямые причины, у симптома 4 причины, у причины 2 ремонта
        $this->insert('fault_code_symptoms', ['fault_code_id', 'symptom_id'], $codes * 2,
            static fn (int $i): array => [intdiv($i - 1, 2) + 1, (($i * 13) % $symptoms) + 1]);
        $this->insert('fault_code_causes', ['fault_code_id', 'cause_id'], $codes * 2,
            static fn (int $i): array => [intdiv($i - 1, 2) + 1, (($i * 17) % $causes) + 1]);
        $this->insert('symptom_causes', ['symptom_id', 'cause_id', 'likelihood'], $symptoms * 4,
            static fn (int $i): array => [intdiv($i - 1, 4) + 1, (($i * 31) % $causes) + 1, mt_rand(1, 5)]);
        $this->insert('cause_fixes', ['cause_id', 'fix_id'], $causes * 2,
            static fn (int $i): array => [intdiv($i - 1, 2) + 1, (($i * 11) % $fixes) + 1]);

        // Случаи: 50 тысяч за 5 лет, у трети нет кода
        $this->insert('cases', ['fault_code_id', 'occurred_on', 'description', 'outcome'], 50000 * $s,
            fn (int $i): array => [
                $i % 3 === 0 ? null : mt_rand(1, $codes),
                date('Y-m-d', strtotime('2021-01-01') + mt_rand(0, 5 * 365) * 86400),
                $this->phrase(30),
                ['resolved', 'partial', 'unresolved'][mt_rand(0, 2)],
            ]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1, UNIQUE_CHECKS = 1');

        return $this->counts;
    }

    /**
     * @param list<string> $columns
     * @param callable(int): list<mixed> $row строка по номеру, начиная с 1
     */
    private function insert(string $table, array $columns, int $total, callable $row): void
    {
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sqlFor = static fn (int $n): string => sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, $n, $placeholders)),
        );

        for ($start = 1; $start <= $total; $start += self::BATCH) {
            $n = min(self::BATCH, $total - $start + 1);
            $values = [];
            for ($i = $start; $i < $start + $n; $i++) {
                array_push($values, ...$row($i));
            }
            $this->pdo->prepare($sqlFor($n))->execute($values);
        }

        $this->counts[$table] = (int) $this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    private function phrase(int $words): string
    {
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = self::WORDS[mt_rand(0, count(self::WORDS) - 1)];
        }

        return implode(' ', $out);
    }
}
