<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Identity;
use App\Auth\IdentityResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Journal\JournalDrafter;
use App\Journal\JournalRepository;
use App\Support\CodeNormalizer;
use App\Support\PersonalData;
use DateTimeImmutable;

/**
 *   GET    /api/me                  кто вошёл, механик ли, мастер ли
 *   POST   /api/me/link             {mechanic_id} — «это я» при первом входе
 *   GET    /api/mechanics           механики бригады
 *   GET    /api/lifts?mechanic_id=  лифты по адресам
 *   POST   /api/journal/draft       черновик записи из переписки с ИИ
 *   POST   /api/journal             сохранить запись
 *   GET    /api/journal?lift_id=&mechanic_id=&q=&limit=&offset=
 *   DELETE /api/journal/{id}
 */
final class JournalController
{
    private const TEXT_FIELDS = ['title' => 255, 'symptoms' => 2000, 'cause' => 1000, 'work_done' => 2000, 'recommendation' => 1000];

    public function __construct(
        private readonly IdentityResolver $auth,
        private readonly JournalRepository $journal,
        private readonly JournalDrafter $drafter,
    ) {
    }

    public function me(Request $request): Response
    {
        $who = $this->auth->require($request);

        $demo = $this->auth->isDemo();

        return Response::json([
            'login' => $who->login,
            'is_master' => $who->isMaster,
            'mechanic' => $who->mechanic,
            'mechanics' => $who->mechanic === null || $demo ? $this->journal->mechanics() : null,
            'demo' => $demo,
        ]);
    }

    public function link(Request $request): Response
    {
        $who = $this->auth->require($request);
        if ($this->auth->isDemo()) {
            throw new HttpException(400, 'В демо механик выбирается без привязки аккаунта');
        }
        $id = $request->body['mechanic_id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            throw new HttpException(400, 'Ошибка в данных', ['mechanic_id' => 'Выберите механика']);
        }
        $this->journal->link($who->login, $id);

        return $this->me($request);
    }

    public function mechanics(Request $request): Response
    {
        $this->auth->require($request);

        return Response::json(['items' => $this->journal->mechanics()]);
    }

    public function lifts(Request $request): Response
    {
        $this->auth->require($request);
        $mechanic = $request->queryString('mechanic_id');
        if ($mechanic !== null && !ctype_digit($mechanic)) {
            throw new HttpException(400, 'Ошибка в данных', ['mechanic_id' => 'Ожидается id механика']);
        }

        return Response::json(['items' => $this->journal->lifts($mechanic === null ? null : (int) $mechanic)]);
    }

    public function draft(Request $request): Response
    {
        $this->requireWriter($request);
        $body = $request->body;

        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || count($messages) > 20) {
            throw new HttpException(400, 'Ошибка в данных', ['messages' => 'Нужна переписка: от 1 до 20 сообщений']);
        }
        $messages = array_values(array_filter(array_map(
            static fn ($m): string => is_string($m) ? mb_substr(trim($m), 0, 2000) : '',
            $messages,
        )));
        $answers = is_array($body['answers'] ?? null) ? array_slice(array_values(array_filter($body['answers'], 'is_array')), 0, 20) : [];

        return Response::json($this->drafter->draft($messages, $answers));
    }

    public function create(Request $request): Response
    {
        $who = $this->requireWriter($request);
        $data = $this->validate($request->body);

        return Response::json(['item' => $this->journal->create($who, $data)], 201);
    }

    public function index(Request $request): Response
    {
        $who = $this->auth->require($request);
        $filter = [];
        foreach (['lift_id', 'mechanic_id'] as $key) {
            $value = $request->queryString($key);
            if ($value !== null) {
                if (!ctype_digit($value)) {
                    throw new HttpException(400, 'Ошибка в данных', [$key => 'Ожидается число']);
                }
                $filter[$key] = (int) $value;
            }
        }
        if (($q = $request->queryString('q')) !== null) {
            $filter['q'] = mb_substr($q, 0, 100);
        }
        $limit = $request->queryInt('limit', 50, 1, 200);
        $offset = $request->queryInt('offset', 0, 0, 100000);

        return Response::json($this->journal->entries($who, $filter, $limit, $offset) + [
            'stats' => $this->journal->stats($who, $filter),
        ]);
    }

    public function delete(Request $request): Response
    {
        $who = $this->auth->require($request);
        $id = (string) $request->param('id');
        if (!ctype_digit($id)) {
            throw new HttpException(404, 'Запись не найдена');
        }
        $this->journal->delete($who, (int) $id);

        return new Response(204);
    }

    private function requireWriter(Request $request): Identity
    {
        $who = $this->auth->require($request);
        if ($who->mechanic === null && !$who->isMaster) {
            throw new HttpException(403, 'Сначала выберите себя в списке механиков');
        }

        return $who;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function validate(array $body): array
    {
        $allowed = ['lift_id', 'occurred_at', 'codes', 'status', 'source', 'chat', ...array_keys(self::TEXT_FIELDS)];
        $errors = [];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[(string) $key] = 'Неизвестное поле';
            }
        }

        $data = [];
        $data['lift_id'] = $body['lift_id'] ?? null;
        if (!is_int($data['lift_id']) || $data['lift_id'] <= 0) {
            $errors['lift_id'] = 'Выберите лифт';
        }

        foreach (self::TEXT_FIELDS as $field => $max) {
            $value = $body[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                $errors[$field] = 'Ожидается строка';
                continue;
            }
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;
            if ($value !== null && mb_strlen($value) > $max) {
                $errors[$field] = "Не длиннее $max символов";
            } elseif ($value !== null && ($found = PersonalData::find($value)) !== null && $found !== 'адрес') {
                // Адрес уже есть у лифта; в тексте записи не должно быть фамилий и телефонов
                $errors[$field] = "Похоже на персональные данные ($found)";
            }
            $data[$field] = $value;
        }
        if ($data['title'] === null) {
            $errors['title'] = 'Нужна суть одной строкой';
        }

        $codes = $body['codes'] ?? null;
        if ($codes !== null && $codes !== '') {
            $list = is_string($codes) ? CodeNormalizer::parseList($codes) : null;
            if ($list === null) {
                $errors['codes'] = CodeNormalizer::LIST_RULE;
            }
            $data['codes'] = $list === null ? null : implode(' ', $list);
        } else {
            $data['codes'] = null;
        }

        $data['status'] = $body['status'] ?? 'resolved';
        if (!in_array($data['status'], JournalRepository::STATUSES, true)) {
            $errors['status'] = 'Допустимо: ' . implode(', ', JournalRepository::STATUSES);
        }
        $data['source'] = ($body['source'] ?? 'manual') === 'ai' ? 'ai' : 'manual';

        $data['occurred_at'] = date('Y-m-d H:i:s');
        if (isset($body['occurred_at'])) {
            $date = is_string($body['occurred_at']) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($body['occurred_at'], 0, 16)) : false;
            if ($date === false || $date > new DateTimeImmutable('+1 day')) {
                $errors['occurred_at'] = 'Дата и время в формате ГГГГ-ММ-ДДTчч:мм, не в будущем';
            } else {
                $data['occurred_at'] = $date->format('Y-m-d H:i:s');
            }
        }

        $chat = $body['chat'] ?? null;
        $data['chat'] = is_string($chat) && trim($chat) !== '' ? mb_substr(trim($chat), 0, 20000) : null;

        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }

        return $data;
    }
}
