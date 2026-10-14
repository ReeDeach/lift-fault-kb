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

/**
 * Заявки на ремонт из ежедневного отчёта 1С.
 *
 *   POST /api/repairs/import            {name, data: base64 XLSX} — загружает мастер
 *   GET  /api/repairs?as=master&report= заявки отчёта (по умолчанию последнего) и список отчётов
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

        return Response::json([
            'reports' => $reports,
            'report' => $report,
            'items' => $report === null ? [] : $this->repairs->calls($who, $asMaster, $report['id']),
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
        $this->repairs->linkJournal($who, $this->asMaster($who, $request->body['as'] ?? null), $this->id($request), $entry);

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
