<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Запись в базу для админ-API. Имена таблиц и столбцов берутся только из Schema,
 * значения всегда передаются параметрами.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(string $entity, ?string $q, int $limit, int $offset): array
    {
        $def = Schema::ENTITIES[$entity];
        $where = '';
        $params = [];

        if ($q !== null) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = ' WHERE ' . implode(' OR ', array_map(static fn (string $c): string => "$c LIKE ?", $def['search']));
            $params = array_fill(0, count($def['search']), $like);
        }

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$def['table']}$where");
        $count->execute($params);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$def['table']}$where ORDER BY {$def['order']} LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'items' => array_map(fn (array $row): array => $this->shape($entity, $row), $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $entity, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . Schema::ENTITIES[$entity]['table'] . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $item = $this->shape($entity, $row);
        if ($entity === 'fixes') {
            $item['steps'] = $this->steps($id);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $data проверенные Validator значения
     * @return array<string, mixed>
     */
    public function create(string $entity, array $data): array
    {
        $this->checkRules($entity, $data);

        return $this->transaction(function () use ($entity, $data): array {
            $steps = $data['steps'] ?? null;
            unset($data['steps']);

            $columns = array_keys($data);
            $stmt = $this->pdo->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                Schema::ENTITIES[$entity]['table'],
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            ));
            $this->execute($entity, $stmt, array_values($data));
            $id = (int) $this->pdo->lastInsertId();

            if ($steps !== null) {
                $this->replaceSteps($id, $steps);
            }

            return $this->find($entity, $id);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $entity, int $id, array $data): array
    {
        $current = $this->require($entity, $id);
        $this->checkRules($entity, $data + $current);

        return $this->transaction(function () use ($entity, $id, $data): array {
            $steps = $data['steps'] ?? null;
            unset($data['steps']);

            if ($data !== []) {
                $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($data)));
                $stmt = $this->pdo->prepare(
                    'UPDATE ' . Schema::ENTITIES[$entity]['table'] . " SET $set WHERE id = ?"
                );
                $this->execute($entity, $stmt, [...array_values($data), $id]);
            }
            if ($steps !== null) {
                $this->replaceSteps($id, $steps);
            }

            return $this->find($entity, $id);
        });
    }

    public function delete(string $entity, int $id): void
    {
        $this->require($entity, $id);
        $stmt = $this->pdo->prepare('DELETE FROM ' . Schema::ENTITIES[$entity]['table'] . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Создаёт или обновляет связь. Возвращает true, если связь новая.
     *
     * @param array<string, int> $extra
     */
    public function link(string $link, int $ownerId, int $targetId, array $extra): bool
    {
        $def = Schema::LINKS[$link];
        [$ownerEntity, $targetEntity] = explode('/', $link);
        $this->require($ownerEntity, $ownerId);
        $this->require($targetEntity, $targetId);

        $exists = $this->linkExists($def, $ownerId, $targetId);
        $columns = [$def['owner'], $def['target'], ...array_keys($extra)];
        $values = [$ownerId, $targetId, ...array_values($extra)];
        $update = $extra === []
            ? "{$def['owner']} = new.{$def['owner']}"
            : implode(', ', array_map(static fn (string $c): string => "$c = new.$c", array_keys($extra)));

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s) AS new ON DUPLICATE KEY UPDATE %s',
            $def['table'],
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
            $update,
        ));
        $stmt->execute($values);

        return !$exists;
    }

    public function unlink(string $link, int $ownerId, int $targetId): void
    {
        $def = Schema::LINKS[$link];
        $stmt = $this->pdo->prepare("DELETE FROM {$def['table']} WHERE {$def['owner']} = ? AND {$def['target']} = ?");
        $stmt->execute([$ownerId, $targetId]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'Связь не найдена');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function require(string $entity, int $id): array
    {
        $item = $this->find($entity, $id);
        if ($item === null) {
            throw new HttpException(404, Schema::ENTITIES[$entity]['label'] . ' не найден(а): id ' . $id);
        }

        return $item;
    }

    /**
     * Правила, которые зависят от нескольких полей или от базы:
     * существование связанных записей и владелец кода (ровно одно из model_id и controller_id).
     *
     * @param array<string, mixed> $data
     */
    private function checkRules(string $entity, array $data): void
    {
        $errors = [];

        foreach (Schema::ENTITIES[$entity]['fields'] as $name => $spec) {
            if ($spec['type'] !== 'fk' || !isset($data[$name])) {
                continue;
            }
            $stmt = $this->pdo->prepare("SELECT 1 FROM {$spec['table']} WHERE id = ?");
            $stmt->execute([$data[$name]]);
            if ($stmt->fetchColumn() === false) {
                $errors[$name] = 'Запись не найдена: id ' . $data[$name];
            }
        }

        if ($entity === 'codes' && (($data['model_id'] ?? null) === null) === (($data['controller_id'] ?? null) === null)) {
            $errors['owner'] = 'Код принадлежит ровно одному владельцу: укажите model_id или controller_id';
        }

        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function execute(string $entity, \PDOStatement $stmt, array $params): void
    {
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                $field = 'id';
                foreach (Schema::ENTITIES[$entity]['unique'] as $key => $column) {
                    if (str_contains($e->getMessage(), $key)) {
                        $field = $column;
                    }
                }
                throw new HttpException(409, 'Такая запись уже есть', [$field => 'Значение уже занято']);
            }
            throw $e;
        }
    }

    /**
     * @param list<array{instruction: string, expected_result: string|null}> $steps
     */
    private function replaceSteps(int $fixId, array $steps): void
    {
        $this->pdo->prepare('DELETE FROM fix_steps WHERE fix_id = ?')->execute([$fixId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO fix_steps (fix_id, step_no, instruction, expected_result) VALUES (?, ?, ?, ?)'
        );
        foreach ($steps as $i => $step) {
            $stmt->execute([$fixId, $i + 1, $step['instruction'], $step['expected_result']]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(int $fixId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT step_no, instruction, expected_result FROM fix_steps WHERE fix_id = ? ORDER BY step_no'
        );
        $stmt->execute([$fixId]);

        return array_map(static fn (array $r): array => [
            'step_no' => (int) $r['step_no'],
            'instruction' => $r['instruction'],
            'expected_result' => $r['expected_result'],
        ], $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $def
     */
    private function linkExists(array $def, int $ownerId, int $targetId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$def['table']} WHERE {$def['owner']} = ? AND {$def['target']} = ?");
        $stmt->execute([$ownerId, $targetId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Приводит строку из базы к типам полей: id и внешние ключи — числа, флаги — bool.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(string $entity, array $row): array
    {
        $item = ['id' => (int) $row['id']];

        foreach (Schema::ENTITIES[$entity]['fields'] as $name => $spec) {
            if (!array_key_exists($name, $row)) {
                continue;
            }
            $value = $row[$name];
            $item[$name] = match (true) {
                $value === null => null,
                $spec['type'] === 'fk' => (int) $value,
                $spec['type'] === 'bool' => (bool) $value,
                default => $value,
            };
        }
        $item['created_at'] = $row['created_at'] ?? null;

        return $item;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
