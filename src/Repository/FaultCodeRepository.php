<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FaultCodeRepository
{
    private const BASE_SELECT = 'SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, '
        . 'fc.model_id, fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name '
        . 'FROM fault_codes fc '
        . 'LEFT JOIN controllers k ON k.id = fc.controller_id '
        . 'LEFT JOIN models mo ON mo.id = fc.model_id';

    private const CASES_PER_CODE = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Коды, видимые модели: собственные и коды её устройств управления.
     * $modelIds === null — без фильтра по модели.
     *
     * @param list<int>|null $modelIds
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?array $modelIds, ?string $code, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if ($modelIds !== null) {
            if ($modelIds === []) {
                return ['items' => [], 'total' => 0];
            }
            $in = implode(',', array_map('intval', $modelIds));
            $where[] = "(fc.model_id IN ($in) OR fc.controller_id IN "
                . "(SELECT mc.controller_id FROM model_controllers mc WHERE mc.model_id IN ($in)))";
        }

        if ($code !== null) {
            $where[] = 'fc.code = ?';
            $params[] = $code;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM fault_codes fc' . $clause);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            self::BASE_SELECT . $clause . " ORDER BY k.name, mo.name, fc.code LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return ['items' => array_map([$this, 'shape'], $stmt->fetchAll()), 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::BASE_SELECT . ' WHERE fc.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->shape($row);
    }

    /**
     * Дополняет коды деревом: симптомы, причины, ремонты с шагами и случаи из практики.
     * Запросы идут пачками по всем кодам сразу, а не по одному на код.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function withTree(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_map(static fn (array $i): int => (int) $i['id'], $items);
        $in = implode(',', $ids);

        $symptoms = [];
        foreach ($this->pdo->query(
            'SELECT fcs.fault_code_id, s.id, s.title FROM fault_code_symptoms fcs '
            . "JOIN symptoms s ON s.id = fcs.symptom_id WHERE fcs.fault_code_id IN ($in) ORDER BY s.title"
        )->fetchAll() as $r) {
            $symptoms[(int) $r['fault_code_id']][] = ['id' => (int) $r['id'], 'title' => $r['title']];
        }

        $causes = $this->causesByCode($in);
        $fixes = $this->fixesByCause($causes);

        $cases = [];
        foreach ($this->pdo->query(
            'SELECT id, fault_code_id, occurred_on, description, outcome, notes FROM cases '
            . "WHERE fault_code_id IN ($in) ORDER BY occurred_on DESC, id DESC"
        )->fetchAll() as $r) {
            $codeId = (int) $r['fault_code_id'];
            if (count($cases[$codeId] ?? []) < self::CASES_PER_CODE) {
                $cases[$codeId][] = [
                    'id' => (int) $r['id'],
                    'occurred_on' => $r['occurred_on'],
                    'description' => $r['description'],
                    'outcome' => $r['outcome'],
                    'notes' => $r['notes'],
                ];
            }
        }

        foreach ($items as &$item) {
            $codeId = (int) $item['id'];
            $item['symptoms'] = $symptoms[$codeId] ?? [];
            $item['causes'] = array_map(function (array $cause) use ($fixes): array {
                $cause['fixes'] = $fixes[$cause['id']] ?? [];

                return $cause;
            }, $causes[$codeId] ?? []);
            $item['cases'] = $cases[$codeId] ?? [];
        }
        unset($item);

        return $items;
    }

    /**
     * Причины кода: названные в руководстве прямо (direct) и пришедшие через симптомы
     * (с оценкой вероятности 1–5). Прямые идут первыми.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function causesByCode(string $in): array
    {
        $byCode = [];

        foreach ($this->pdo->query(
            'SELECT fcc.fault_code_id, c.id, c.title, c.source FROM fault_code_causes fcc '
            . "JOIN causes c ON c.id = fcc.cause_id WHERE fcc.fault_code_id IN ($in)"
        )->fetchAll() as $r) {
            $byCode[(int) $r['fault_code_id']][(int) $r['id']] = [
                'id' => (int) $r['id'], 'title' => $r['title'], 'source' => $r['source'],
                'direct' => true, 'likelihood' => null,
            ];
        }

        foreach ($this->pdo->query(
            'SELECT fcs.fault_code_id, c.id, c.title, c.source, sc.likelihood FROM fault_code_symptoms fcs '
            . 'JOIN symptom_causes sc ON sc.symptom_id = fcs.symptom_id '
            . "JOIN causes c ON c.id = sc.cause_id WHERE fcs.fault_code_id IN ($in)"
        )->fetchAll() as $r) {
            $codeId = (int) $r['fault_code_id'];
            $causeId = (int) $r['id'];
            $likelihood = (int) $r['likelihood'];
            $existing = $byCode[$codeId][$causeId] ?? null;

            if ($existing === null) {
                $byCode[$codeId][$causeId] = [
                    'id' => $causeId, 'title' => $r['title'], 'source' => $r['source'],
                    'direct' => false, 'likelihood' => $likelihood,
                ];
            } else {
                $byCode[$codeId][$causeId]['likelihood'] = max($existing['likelihood'] ?? 0, $likelihood);
            }
        }

        $result = [];
        foreach ($byCode as $codeId => $list) {
            $list = array_values($list);
            usort($list, static fn (array $a, array $b): int =>
                [$b['direct'], $b['likelihood'] ?? 0, $a['title']] <=> [$a['direct'], $a['likelihood'] ?? 0, $b['title']]);
            $result[$codeId] = $list;
        }

        return $result;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $causes
     * @return array<int, list<array<string, mixed>>> ремонты по id причины
     */
    private function fixesByCause(array $causes): array
    {
        $causeIds = [];
        foreach ($causes as $list) {
            foreach ($list as $cause) {
                $causeIds[$cause['id']] = true;
            }
        }
        if ($causeIds === []) {
            return [];
        }

        $in = implode(',', array_keys($causeIds));
        $rows = $this->pdo->query(
            'SELECT cf.cause_id, f.id, f.title, f.summary, f.safety_note FROM cause_fixes cf '
            . "JOIN fixes f ON f.id = cf.fix_id WHERE cf.cause_id IN ($in) ORDER BY f.title"
        )->fetchAll();

        $fixIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id'], $rows)));
        $steps = [];
        if ($fixIds !== []) {
            $fixIn = implode(',', $fixIds);
            foreach ($this->pdo->query(
                "SELECT fix_id, step_no, instruction, expected_result FROM fix_steps WHERE fix_id IN ($fixIn) "
                . 'ORDER BY fix_id, step_no'
            )->fetchAll() as $s) {
                $steps[(int) $s['fix_id']][] = [
                    'step_no' => (int) $s['step_no'],
                    'instruction' => $s['instruction'],
                    'expected_result' => $s['expected_result'],
                ];
            }
        }

        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['cause_id']][] = [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'summary' => $r['summary'],
                'safety_note' => $r['safety_note'],
                'steps' => $steps[(int) $r['id']] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $owner = $row['controller_id'] !== null
            ? ['type' => 'controller', 'id' => (int) $row['controller_id'], 'name' => $row['controller_name'], 'kind' => $row['controller_kind']]
            : ['type' => 'model', 'id' => (int) $row['model_id'], 'name' => $row['model_name']];

        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'title' => $row['title'],
            'description' => $row['description'],
            'is_safety_related' => (bool) $row['is_safety_related'],
            'source' => $row['source'],
            'owner' => $owner,
        ];
    }
}
