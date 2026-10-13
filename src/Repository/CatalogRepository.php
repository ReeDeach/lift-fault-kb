<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\HttpException;
use App\Support\CodeNormalizer;
use PDO;

final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manufacturers(): array
    {
        $rows = $this->pdo->query(
            'SELECT m.id, m.slug, m.name, m.notes, COUNT(mo.id) AS models_count '
            . 'FROM manufacturers m LEFT JOIN models mo ON mo.manufacturer_id = m.id '
            . 'GROUP BY m.id, m.slug, m.name, m.notes ORDER BY m.name'
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'notes' => $r['notes'],
            'models_count' => (int) $r['models_count'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function models(?string $manufacturerSlug): array
    {
        $sql = 'SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name '
            . 'FROM models mo JOIN manufacturers m ON m.id = mo.manufacturer_id';
        $params = [];

        if ($manufacturerSlug !== null) {
            $this->requireManufacturer($manufacturerSlug);
            $sql .= ' WHERE m.slug = ?';
            $params[] = $manufacturerSlug;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY m.name, mo.name');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $modelIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $controllers = $this->controllersByModel($modelIds);
        $counts = $this->codeCounts($modelIds, $controllers);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'notes' => $r['notes'],
            'manufacturer' => ['slug' => $r['manufacturer_slug'], 'name' => $r['manufacturer_name']],
            'codes_count' => $counts[(int) $r['id']] ?? 0,
            'controllers' => $controllers[(int) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * Число кодов, видимых модели: собственные коды плюс коды её устройств.
     * У кода ровно один владелец, поэтому слагаемые не пересекаются.
     *
     * Раньше это был подзапрос на каждую модель с OR внутри: MySQL на каждой модели
     * читал всю таблицу кодов. Теперь два запроса с GROUP BY по индексам
     * (model_id, code) и (controller_id, code), а сумма считается здесь.
     *
     * @param list<int> $modelIds
     * @param array<int, list<array<string, mixed>>> $controllers устройства по моделям
     * @return array<int, int>
     */
    private function codeCounts(array $modelIds, array $controllers): array
    {
        if ($modelIds === []) {
            return [];
        }

        $in = implode(',', $modelIds);
        $counts = array_map('intval', $this->pdo->query(
            "SELECT model_id, COUNT(*) FROM fault_codes WHERE model_id IN ($in) GROUP BY model_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR));

        $controllerIds = [];
        foreach ($controllers as $list) {
            foreach ($list as $controller) {
                $controllerIds[$controller['id']] = true;
            }
        }
        if ($controllerIds === []) {
            return $counts;
        }

        $perController = array_map('intval', $this->pdo->query(
            'SELECT controller_id, COUNT(*) FROM fault_codes WHERE controller_id IN ('
            . implode(',', array_keys($controllerIds)) . ') GROUP BY controller_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR));

        foreach ($controllers as $modelId => $list) {
            foreach ($list as $controller) {
                $counts[$modelId] = ($counts[$modelId] ?? 0) + ($perController[$controller['id']] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Разбирает параметры model и manufacturer в список id моделей.
     * null — фильтра нет. Неизвестный производитель или модель — 404.
     * Модель можно задать числом (id) или названием, в том числе латиницей вместо кириллицы.
     *
     * @return list<int>|null
     */
    public function resolveModelIds(?string $model, ?string $manufacturerSlug): ?array
    {
        if ($model === null && $manufacturerSlug === null) {
            return null;
        }

        $manufacturerId = $manufacturerSlug !== null ? $this->requireManufacturer($manufacturerSlug) : null;

        $sql = 'SELECT id, name FROM models';
        $params = [];
        if ($manufacturerId !== null) {
            $sql .= ' WHERE manufacturer_id = ?';
            $params[] = $manufacturerId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($model === null) {
            return array_map(static fn (array $r): int => (int) $r['id'], $rows);
        }

        $key = CodeNormalizer::key($model);
        $ids = [];
        foreach ($rows as $row) {
            if (ctype_digit($model) ? (int) $row['id'] === (int) $model : CodeNormalizer::key($row['name']) === $key) {
                $ids[] = (int) $row['id'];
            }
        }

        if ($ids === []) {
            throw new HttpException(404, 'Модель не найдена');
        }

        return $ids;
    }

    /**
     * id устройств управления, установленных на моделях.
     *
     * @param list<int> $modelIds
     * @return list<int>
     */
    public function controllerIds(array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $modelIds));

        return array_map('intval', $this->pdo->query(
            "SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN ($in)"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function requireManufacturer(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM manufacturers WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new HttpException(404, 'Производитель не найден');
        }

        return (int) $id;
    }

    /**
     * @param list<int> $modelIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function controllersByModel(array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }

        $in = implode(',', $modelIds);
        $rows = $this->pdo->query(
            'SELECT mc.model_id, k.id, k.name, k.kind FROM model_controllers mc '
            . "JOIN controllers k ON k.id = mc.controller_id WHERE mc.model_id IN ($in) ORDER BY k.kind, k.name"
        )->fetchAll();

        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['model_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'kind' => $r['kind']];
        }

        return $result;
    }
}
