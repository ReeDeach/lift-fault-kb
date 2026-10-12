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
        $sql = 'SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name, '
            . '(SELECT COUNT(*) FROM fault_codes fc WHERE fc.model_id = mo.id OR fc.controller_id IN '
            . '(SELECT mc.controller_id FROM model_controllers mc WHERE mc.model_id = mo.id)) AS codes_count '
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

        $controllers = $this->controllersByModel(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'notes' => $r['notes'],
            'manufacturer' => ['slug' => $r['manufacturer_slug'], 'name' => $r['manufacturer_name']],
            'codes_count' => (int) $r['codes_count'],
            'controllers' => $controllers[(int) $r['id']] ?? [],
        ], $rows);
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
