<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminRepository;
use App\Admin\Schema;
use App\Admin\Validator;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Search\SearchIndex;

/**
 * Админ-API: ввод и правка базы знаний. Доступ проверяет AdminAuth до вызова методов.
 *
 *   GET    /api/admin/{entity}?q=&limit=&offset=   список
 *   GET    /api/admin/{entity}/{id}                запись
 *   POST   /api/admin/{entity}                     создать (201)
 *   PATCH  /api/admin/{entity}/{id}                изменить переданные поля
 *   DELETE /api/admin/{entity}/{id}                удалить (204)
 *   PUT    /api/admin/{owner}/{id}/{target}/{targetId}  связать (201 или 200)
 *   DELETE /api/admin/{owner}/{id}/{target}/{targetId}  убрать связь (204)
 */
final class AdminController
{
    public function __construct(
        private readonly AdminRepository $repository,
        private readonly ?SearchIndex $index = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $entity = $this->entity($request);
        $limit = $request->queryInt('limit', 50, 1, 200);
        $offset = $request->queryInt('offset', 0, 0, 1_000_000);
        $result = $this->repository->list($entity, $request->queryString('q'), $limit, $offset);

        return Response::json($result + ['limit' => $limit, 'offset' => $offset]);
    }

    public function show(Request $request): Response
    {
        $entity = $this->entity($request);
        $item = $this->repository->find($entity, $this->id($request, 'id'));
        if ($item === null) {
            throw new HttpException(404, Schema::ENTITIES[$entity]['label'] . ' не найден(а)');
        }

        return Response::json(['item' => $item]);
    }

    public function create(Request $request): Response
    {
        $entity = $this->entity($request);
        $data = Validator::validate($request->body, Schema::ENTITIES[$entity]['fields'], partial: false);
        $item = $this->repository->create($entity, $data);
        $warning = $this->reindex($entity, $item['id']);

        return Response::json(['item' => $item] + $warning, 201);
    }

    public function update(Request $request): Response
    {
        $entity = $this->entity($request);
        $id = $this->id($request, 'id');
        $data = Validator::validate($request->body, Schema::ENTITIES[$entity]['fields'], partial: true);
        $item = $this->repository->update($entity, $id, $data);
        $warning = $this->reindex($entity, $id);

        return Response::json(['item' => $item] + $warning);
    }

    public function delete(Request $request): Response
    {
        $entity = $this->entity($request);
        $id = $this->id($request, 'id');
        $this->repository->delete($entity, $id);
        $this->reindex($entity, $id, deleted: true);

        return new Response(204);
    }

    public function link(Request $request): Response
    {
        $link = $this->linkName($request);
        $extra = $this->linkExtra($link, $request->body);
        $created = $this->repository->link($link, $this->id($request, 'id'), $this->id($request, 'targetId'), $extra);

        return Response::json(['linked' => true], $created ? 201 : 200);
    }

    public function unlink(Request $request): Response
    {
        $link = $this->linkName($request);
        $this->repository->unlink($link, $this->id($request, 'id'), $this->id($request, 'targetId'));

        return new Response(204);
    }

    private function entity(Request $request): string
    {
        $entity = (string) $request->param('entity');
        if (!isset(Schema::ENTITIES[$entity])) {
            throw new HttpException(404, 'Неизвестный раздел: ' . $entity);
        }

        return $entity;
    }

    private function linkName(Request $request): string
    {
        $link = $request->param('entity') . '/' . $request->param('target');
        if (!isset(Schema::LINKS[$link])) {
            throw new HttpException(404, 'Такой связи нет: ' . $link);
        }

        return $link;
    }

    private function id(Request $request, string $name): int
    {
        $id = (string) $request->param($name);
        if (!ctype_digit($id) || (int) $id === 0) {
            throw new HttpException(404, 'Запись не найдена');
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, int>
     */
    private function linkExtra(string $link, array $body): array
    {
        $spec = Schema::LINKS[$link]['extra'] ?? [];
        $errors = [];
        $extra = [];

        foreach (array_keys($body) as $name) {
            if (!isset($spec[$name])) {
                $errors[(string) $name] = 'Неизвестное поле';
            }
        }
        foreach ($spec as $name => $rule) {
            $value = $body[$name] ?? $rule['default'];
            if (!is_int($value) || $value < $rule['min'] || $value > $rule['max']) {
                $errors[$name] = "Целое число от {$rule['min']} до {$rule['max']}";
                continue;
            }
            $extra[$name] = $value;
        }

        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }

        return $extra;
    }

    /**
     * Обновляет поисковый индекс после записи. База уже изменена, поэтому сбой индекса
     * не превращается в ошибку запроса: ответ получает предупреждение, а индекс
     * восстанавливается командой php bin/search.php rebuild.
     *
     * @return array<string, string>
     */
    private function reindex(string $entity, int $id, bool $deleted = false): array
    {
        if ($this->index === null) {
            return [];
        }

        try {
            $deleted ? $this->index->remove($entity, $id) : $this->index->sync($entity, $id);
        } catch (\Throwable $e) {
            error_log('search index: ' . $e->getMessage());

            return ['warning' => 'Запись сохранена, но поисковый индекс не обновлён. Выполните php bin/search.php rebuild'];
        }

        return [];
    }
}
