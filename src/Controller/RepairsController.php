<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Identity;
use App\Auth\IdentityResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Push\Notifier;
use App\Repairs\RepairsRepository;
use App\Repairs\ReportParser;
use App\Support\CodeNormalizer;
use App\Support\Env;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Заявки на ремонт из ежедневного отчёта 1С.
 *
 *   POST /api/repairs/import            {name, data: base64 XLSX} — загружает мастер
 *   POST /api/repairs                   {lift_id, info, codes, stopped} — мастер оставляет заявку на лифт сам
 *   GET  /api/repairs?as=master&report=&scope=all заявки отчёта (по умолчанию последнего) и список отчётов;
 *                                       механику scope=all — весь участок (свои помечены mine)
 *   POST /api/repairs/{id}/lift         {lift_id, as} — указать лифт (мастер — любой, механик — свой), номер из 1С запоминается
 *   POST /api/repairs/{id}/journal      {entry_id, as} — заявка закрыта записью в журнал
 */
final class RepairsController
{
    public function __construct(
        private readonly IdentityResolver $auth,
        private readonly RepairsRepository $repairs,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    public function import(Request $request): Response
    {
        $who = $this->master($request);
        $data = $request->body['data'] ?? null;
        $name = $request->body['name'] ?? null;
        if (!is_string($data) || $data === '') {
            throw new HttpException(400, 'Ошибка в данных', ['data' => 'Нужен файл отчёта']);
        }
        $data = preg_replace('#^data:[^;,]*;base64,#', '', $data);
        if (strlen($data) > intdiv(ReportParser::MAX_BYTES * 4, 3) + 4) {
            throw new HttpException(413, 'Файл слишком большой: отчёт 1С занимает килобайты');
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            throw new HttpException(400, 'Ошибка в данных', ['data' => 'Файл передан не целиком']);
        }

        $report = ReportParser::parse($bytes);
        $summary = $this->repairs->import($report, is_string($name) ? $name : null, $who->login);
        // Механикам — о новых вызовах по их лифтам (при повторной загрузке отчёта не повторяется)
        $this->notifier?->repairsImported($summary['by_mechanic']);

        return Response::json([
            'report_id' => $summary['report_id'],
            'rows' => $summary['rows'],
            'faults' => $summary['faults'],
            'matched' => $summary['matched'],
            'unmatched' => $summary['unmatched'],
        ], 201);
    }

    public function create(Request $request): Response
    {
        $who = $this->master($request);
        $body = $request->body;
        $unknown = array_diff(array_map('strval', array_keys($body)), ['lift_id', 'info', 'codes', 'stopped', 'as']);
        if ($unknown !== []) {
            throw new HttpException(400, 'Ошибка в данных', array_fill_keys($unknown, 'Неизвестное поле'));
        }
        $errors = [];
        $lift = $body['lift_id'] ?? null;
        if (!is_int($lift) || $lift <= 0) {
            $errors['lift_id'] = 'Выберите лифт';
        }
        $info = is_string($body['info'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $body['info'])) : '';
        if ($info === '' || mb_strlen($info) > 500) {
            $errors['info'] = $info === '' ? 'Напишите, что случилось' : 'Не длиннее 500 символов';
        }
        $codes = $body['codes'] ?? null;
        if ($codes !== null && (!is_string($codes) || mb_strlen($codes) > 100)) {
            $errors['codes'] = 'Коды через пробел, не длиннее 100 символов';
        }
        $stopped = $body['stopped'] ?? false;
        if (!is_bool($stopped)) {
            $errors['stopped'] = 'Ожидается true или false';
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }
        $list = [];
        foreach (preg_split('/[\s,;]+/u', (string) $codes, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            $code = strtoupper(CodeNormalizer::toLatin($code));
            $list[$code] = $code;
        }

        // Время 1С местное, без пояса: заявку мастера пишем так же
        $now = new DateTimeImmutable('now', new DateTimeZone(Env::get('APP_TIMEZONE', 'Europe/Minsk')));
        $created = $this->repairs->createManual($who, $lift, $info, array_values($list), $stopped, $now);
        if ($created['mechanic_id'] !== null) {
            $this->notifier?->repairCreated($who, $created['item'], $created['mechanic_id']);
        }

        return Response::json(['item' => $created['item']], 201);
    }

    public function index(Request $request): Response
    {
        $who = $this->auth->require($request);
        $asMaster = $this->asMaster($who, $request->queryString('as'));
        if (!$asMaster && $who->mechanicId() === null) {
            throw new HttpException(403, 'Войдите как механик или мастер');
        }
        $reports = $this->repairs->reports();
        $reportId = $request->queryString('report');
        if ($reportId !== null && !ctype_digit($reportId)) {
            throw new HttpException(400, 'Ошибка в данных', ['report' => 'Ожидается число']);
        }
        $report = null;
        foreach ($reports as $r) {
            if ($reportId === null || $r['id'] === (int) $reportId) {
                $report = $r;
                break;
            }
        }
        if ($reportId !== null && $report === null) {
            throw new HttpException(404, 'Отчёт не найден');
        }

        $all = $request->queryString('scope') === 'all';

        return Response::json([
            'manual' => $this->repairs->manualCalls($who, $asMaster, $all),
            'reports' => $reports,
            'report' => $report,
            'items' => $report === null ? [] : $this->repairs->calls($who, $asMaster, $report['id'], $all),
        ]);
    }

    public function assignLift(Request $request): Response
    {
        $who = $this->auth->require($request);
        $lift = $request->body['lift_id'] ?? null;
        if (!is_int($lift) || $lift <= 0) {
            throw new HttpException(400, 'Ошибка в данных', ['lift_id' => 'Выберите лифт']);
        }
        $this->repairs->assignLift($who, $this->asMaster($who, $request->body['as'] ?? null), $this->id($request), $lift);

        return new Response(204);
    }

    public function linkJournal(Request $request): Response
    {
        $who = $this->auth->require($request);
        $entry = $request->body['entry_id'] ?? null;
        if (!is_int($entry) || $entry <= 0) {
            throw new HttpException(400, 'Ошибка в данных', ['entry_id' => 'Ожидается id записи журнала']);
        }
        $asMaster = $this->asMaster($who, $request->body['as'] ?? null);
        $closed = $this->repairs->linkJournal($who, $asMaster, $this->id($request), $entry);
        // Закрыл не тот, чей лифт: сообщить механику лифта
        if ($closed['owner_id'] !== null && $closed['owner_id'] !== ($asMaster ? null : $who->mechanicId())) {
            $this->notifier?->repairClosedByOther($who, $asMaster, $closed, $closed['owner_id']);
        }
        // И мастеру — что заявка закрыта и с каким итогом (если закрыл не он сам)
        if (!$asMaster) {
            $this->notifier?->repairClosedToMaster($who, $closed);
        }

        return new Response(204);
    }

    private function master(Request $request): Identity
    {
        $who = $this->auth->require($request);
        if (!$who->isMaster) {
            throw new HttpException(403, 'Отчёты 1С загружает и сопоставляет мастер');
        }

        return $who;
    }

    private function asMaster(Identity $who, mixed $as): bool
    {
        if ($as === null || $as === 'mechanic') {
            return false;
        }
        if ($as !== 'master') {
            throw new HttpException(400, 'Ошибка в данных', ['as' => 'Допустимо: master, mechanic']);
        }
        if (!$who->isMaster) {
            throw new HttpException(403, 'У этого аккаунта нет прав мастера');
        }

        return true;
    }

    private function id(Request $request): int
    {
        $id = (string) $request->param('id');

        return ctype_digit($id) && $id !== '0' ? (int) $id : throw new HttpException(404, 'Заявка не найдена');
    }
}
