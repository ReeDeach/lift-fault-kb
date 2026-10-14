<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Auth\IdentityResolver;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxFixture;

/**
 * Заявки на ремонт из отчёта 1С на настоящем MySQL: загрузка, сопоставление лифтов
 * по адресу и номеру, права мастера и механика, закрытие записью в журнал.
 * Тест создаёт свои улицы, механиков и лифты и удаляет их после себя.
 */
final class RepairsApiTest extends TestCase
{
    private const MASTER = 'boss-repairs@test.example';
    private const MECH_A = 'repairs-a@test.example';
    private const MECH_B = 'repairs-b@test.example';

    private Router $router;
    private ?int $reportId = null;
    private PDO $pdo;
    private string $tag;
    private string $street;
    /** @var array<string, int> */
    private array $mechanic = [];
    /** @var array<string, int> */
    private array $lift = [];

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты пропущены.');
        }
        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ' . bin2hex(random_bytes(3));
        // Улица только из букв: ключ адреса берёт последнее слово перед номером дома
        $letters = array_combine(str_split('0123456789abcdef'), mb_str_split('абвгдежзиклмнопр'));
        $this->street = 'Тестовая' . strtr(bin2hex(random_bytes(3)), $letters);

        foreach (['a' => self::MECH_A, 'b' => self::MECH_B] as $key => $login) {
            $this->pdo->prepare('INSERT INTO mechanics (name, login) VALUES (?, ?)')->execute(["{$this->tag} механик $key", $login]);
            $this->mechanic[$key] = (int) $this->pdo->lastInsertId();
        }
        // У механика A по адресу один лифт, у B — два: тут нужен выбор мастера
        $add = $this->pdo->prepare('INSERT INTO lifts (mechanic_id, address, lift_no) VALUES (?, ?, ?)');
        $add->execute([$this->mechanic['a'], "{$this->street} 12", 1]);
        $this->lift['a'] = (int) $this->pdo->lastInsertId();
        $add->execute([$this->mechanic['b'], "{$this->street} 7", 1]);
        $this->lift['b1'] = (int) $this->pdo->lastInsertId();
        $add->execute([$this->mechanic['b'], "{$this->street} 7", 2]);
        $this->lift['b2'] = (int) $this->pdo->lastInsertId();

        $pdo = $this->pdo;
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services(
            static fn (): PDO => $pdo,
            identityFactory: static fn (PDO $p): IdentityResolver => new IdentityResolver($p, [self::MASTER]),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM repair_reports WHERE section = ?')->execute(["УЧ.{$this->tag}"]);
            $this->pdo->prepare('DELETE FROM repair_calls WHERE created_by = ?')->execute([self::MASTER]);
            $this->pdo->prepare('DELETE FROM lifts WHERE address LIKE ?')->execute([$this->street . '%']);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
    }

    /**
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $login, string $method, string $path, array $body = [], array $query = []): array
    {
        // В базе могут быть отчёты новее тестового: список заявок — именно тестового отчёта
        if ($method === 'GET' && $path === '/api/repairs' && $this->reportId !== null && !isset($query['report'])) {
            $query['report'] = (string) $this->reportId;
        }
        $response = $this->router->dispatch(new Request($method, $path, $query, $body, headers: [IdentityResolver::HEADER => $login]));
        $data = $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        if ($path === '/api/repairs/import' && $response->status === 201) {
            $this->reportId = $data['report_id'];
        }

        return [$response->status, $data];
    }

    private function report(): string
    {
        $street = mb_strtoupper($this->street);

        return base64_encode(XlsxFixture::report("УЧ.{$this->tag}", [
            ['9001н', "$street 12 п.1", '3', '31.03.2026 18:29:01', '31.03.2026 18:55:00', 'Нет', '8', '49 MLM'],
            ['9002н', "$street 7 п.2", '1', '31.03.2026 21:20:00', null, 'Да', '5', '44 C6 MLM'],
            ['9002н', "$street 7 п.2", '1', '31.03.2026 9:00:00', '31.03.2026 16:30:00', 'Да', '5', 'ТО2'],
        ]));
    }

    public function testImportMatchesLiftsAndRemembersNumbers(): void
    {
        [$status, $summary] = $this->call(self::MASTER, 'POST', '/api/repairs/import', ['name' => 'отчёт.xlsx', 'data' => $this->report()]);
        $this->assertSame(201, $status, json_encode($summary, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['rows' => 3, 'faults' => 2, 'matched' => 1, 'unmatched' => 2], array_intersect_key($summary, array_flip(['rows', 'faults', 'matched', 'unmatched'])));

        $reg = $this->pdo->prepare('SELECT reg_number FROM lifts WHERE id = ?');
        $reg->execute([$this->lift['a']]);
        $this->assertSame('9001', $reg->fetchColumn(), 'Единственный лифт по адресу — номер запомнен (без «н», как в карточках лифтов)');

        // Мастер видит все заявки; у несопоставленной — лифты по адресу на выбор
        [, $master] = $this->call(self::MASTER, 'GET', '/api/repairs', query: ['as' => 'master']);
        $this->assertSame($summary['report_id'], $master['report']['id']);
        $byReg = [];
        foreach ($master['items'] as $item) {
            $byReg[$item['reg'] . ' ' . $item['kind']] = $item;
        }
        $this->assertSame($this->lift['a'], $byReg['9001н fault']['lift']['id']);
        $this->assertNull($byReg['9002н fault']['lift']);
        $this->assertNull($byReg['9002н fault']['finished_at']);
        $this->assertSame(['44', 'C6'], $byReg['9002н fault']['codes']);
        $this->assertEqualsCanonicalizing([$this->lift['b1'], $this->lift['b2']], array_column($byReg['9002н fault']['candidates'], 'id'));

        // Механик видит только свои. Лифт по адресу не определён, но оба лифта дома у механика B —
        // заявки его: он видит их сразу, с выбором из своих лифтов
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame(['9001н'], array_column($a['items'], 'reg'));

        // Весь участок: механик видит и чужие заявки — только посмотреть, без выбора лифта
        [, $all] = $this->call(self::MECH_A, 'GET', '/api/repairs', query: ['scope' => 'all']);
        $this->assertCount(3, $all['items']);
        foreach ($all['items'] as $item) {
            $this->assertSame($item['reg'] === '9001н', $item['mine']);
            if (!$item['mine']) {
                $this->assertSame([], $item['candidates']);
                $this->assertSame("{$this->tag} механик b", $item['mechanic'], 'Чья заявка — видно, даже если лифт не уточнён');
            }
        }

        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $this->assertCount(2, $b['items']);
        $this->assertNull($b['items'][0]['lift']);
        $this->assertEqualsCanonicalizing([$this->lift['b1'], $this->lift['b2']], array_column($b['items'][0]['candidates'], 'id'));

        // Чужую заявку механик не трогает, чужой лифт не указывает
        $faultId = $byReg['9002н fault']['id'];
        $this->assertSame(404, $this->call(self::MECH_A, 'POST', "/api/repairs/$faultId/lift", ['lift_id' => $this->lift['a']])[0]);
        $this->assertSame(400, $this->call(self::MECH_B, 'POST', "/api/repairs/$faultId/lift", ['lift_id' => $this->lift['a']])[0]);

        // Механик выбирает свой лифт (ошибся), мастер поправляет — номер переходит к нужному лифту
        $this->assertSame(204, $this->call(self::MECH_B, 'POST', "/api/repairs/$faultId/lift", ['lift_id' => $this->lift['b1']])[0]);
        $this->assertSame(204, $this->call(self::MASTER, 'POST', "/api/repairs/$faultId/lift", ['lift_id' => $this->lift['b2'], 'as' => 'master'])[0]);
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $this->assertCount(2, $b['items']);
        $this->assertSame([$this->lift['b2'], $this->lift['b2']], array_map(static fn (array $i): int => $i['lift']['id'], $b['items']));
        $reg = $this->pdo->prepare('SELECT reg_number FROM lifts WHERE id = ?');
        $reg->execute([$this->lift['b1']]);
        $this->assertNull($reg->fetchColumn(), 'Ошибочно записанный номер снят');

        // Повторная загрузка того же отчёта ничего не дублирует, сопоставление сохраняется
        [, $again] = $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => $this->report()]);
        $this->assertSame($summary['report_id'], $again['report_id']);
        $this->assertSame(['matched' => 3, 'unmatched' => 0], array_intersect_key($again, array_flip(['matched', 'unmatched'])));
        $this->assertCount(3, $this->call(self::MASTER, 'GET', '/api/repairs', query: ['as' => 'master'])[1]['items']);
    }

    public function testClosingWithJournalEntry(): void
    {
        $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => $this->report()]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $callId = $a['items'][0]['id'];

        [, $entry] = $this->call(self::MECH_A, 'POST', '/api/journal', ['lift_id' => $this->lift['a'], 'codes' => '49', 'title' => 'Заявка: код 49', 'status' => 'resolved']);
        $this->assertSame(204, $this->call(self::MECH_A, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entry['item']['id']])[0]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame($entry['item']['id'], $a['items'][0]['journal_entry_id']);

        // В журнале у записи видно, какую заявку она закрыла
        [, $journal] = $this->call(self::MECH_A, 'GET', '/api/journal', query: ['lift' => (string) $this->lift['a']]);
        $this->assertSame('9001н', $journal['items'][0]['repair']['reg']);
        $this->assertSame('2026-03-31T18:29:01', $journal['items'][0]['repair']['started_at']);

        // Уже закрытую заявку повторно не закрыть; видно, кто закрыл
        $this->assertSame(409, $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entry['item']['id']])[0]);
        $this->assertSame("{$this->tag} механик a", $a['items'][0]['closed_by']);

        // Заявка без точного лифта: механик закрывает её записью по своему лифту — лифт запоминается
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $fault = array_values(array_filter($b['items'], static fn (array $i): bool => $i['kind'] === 'fault'))[0];
        $this->assertNull($fault['lift']);
        [, $entryB] = $this->call(self::MECH_B, 'POST', '/api/journal', ['lift_id' => $this->lift['b2'], 'codes' => '44 C6', 'title' => 'Заявка: код 44 C6', 'status' => 'resolved']);
        $this->assertSame(204, $this->call(self::MECH_B, 'POST', "/api/repairs/{$fault['id']}/journal", ['entry_id' => $entryB['item']['id']])[0]);
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        foreach ($b['items'] as $item) {
            $this->assertSame($this->lift['b2'], $item['lift']['id'], 'Номер 1С запомнен у лифта — обе заявки по нему сопоставлены');
        }
    }

    public function testOtherMechanicClosesCall(): void
    {
        $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => $this->report()]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $callId = $a['items'][0]['id'];

        // Итог «наблюдать»: заявка ещё висит, её можно закрыть ещё раз
        [, $watch] = $this->call(self::MECH_A, 'POST', '/api/journal', ['lift_id' => $this->lift['a'], 'title' => 'Наблюдать', 'status' => 'monitoring']);
        $this->assertSame(204, $this->call(self::MECH_A, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $watch['item']['id']])[0]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame('monitoring', $a['items'][0]['closed_status']);

        // Механик B подменил A: закрывает его заявку своей записью по лифту A
        [, $entryA] = $this->call(self::MECH_A, 'POST', '/api/journal', ['lift_id' => $this->lift['a'], 'title' => 'Запись A', 'status' => 'resolved']);
        [, $entryB] = $this->call(self::MECH_B, 'POST', '/api/journal', ['lift_id' => $this->lift['a'], 'codes' => '49', 'title' => 'Подмена: код 49', 'status' => 'resolved']);
        [$status, $error] = $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entryA['item']['id']]);
        $this->assertSame(400, $status, 'Чужой записью заявку не закрыть');
        $this->assertArrayHasKey('entry_id', $error['error']['fields']);
        $this->assertSame(204, $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entryB['item']['id']])[0]);

        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame($entryB['item']['id'], $a['items'][0]['journal_entry_id']);
        $this->assertSame("{$this->tag} механик b", $a['items'][0]['closed_by']);
        $this->assertSame('resolved', $a['items'][0]['closed_status']);
        $this->assertSame(409, $this->call(self::MECH_A, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entryA['item']['id']])[0]);
    }

    public function testAcceptCall(): void
    {
        $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => $this->report()]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $callId = $a['items'][0]['id'];
        $this->assertNull($a['items'][0]['accepted']);

        // Принял механик B — все видят кто; второй не перехватит; повторное нажатие — отмена
        $this->assertSame([200, ['accepted' => true]], $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/accept"));
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame("{$this->tag} механик b", $a['items'][0]['accepted']['name']);
        $this->assertFalse($a['items'][0]['accepted']['mine']);
        [$status, $error] = $this->call(self::MECH_A, 'POST', "/api/repairs/$callId/accept");
        $this->assertSame(409, $status);
        $this->assertStringContainsString('механик b', $error['error']['message']);
        $this->assertSame(403, $this->call(self::MASTER, 'POST', "/api/repairs/$callId/accept")[0], 'Принимает механик, не мастер');
        $this->assertSame([200, ['accepted' => false]], $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/accept"));
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertNull($a['items'][0]['accepted']);
    }

    public function testMasterCreatesCallForMechanic(): void
    {
        [$status, $created] = $this->call(self::MASTER, 'POST', '/api/repairs', [
            'lift_id' => $this->lift['a'], 'info' => 'Жалоба: двери  закрываются с ударом', 'codes' => '44 с6', 'stopped' => true, 'as' => 'master',
        ]);
        $this->assertSame(201, $status, json_encode($created, JSON_UNESCAPED_UNICODE));
        $item = $created['item'];
        $this->assertTrue($item['by_master']);
        $this->assertSame(['44', 'C6'], $item['codes']);
        $this->assertSame('Жалоба: двери закрываются с ударом', $item['info']);
        $this->assertSame("{$this->tag} механик a", $item['mechanic']);
        $this->assertNull($item['reg'], 'У лифта нет номера 1С');

        // Механик лифта видит заявку отдельно от отчёта, другой механик — только во «всём участке»
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame([$item['id']], array_column($a['manual'], 'id'));
        $this->assertTrue($a['manual'][0]['mine']);
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $this->assertSame([], $b['manual']);
        [, $bAll] = $this->call(self::MECH_B, 'GET', '/api/repairs', query: ['scope' => 'all']);
        $this->assertContains($item['id'], array_column($bAll['manual'], 'id'));

        // Для точки «новое» на вкладке
        [, $updates] = $this->call(self::MECH_A, 'GET', '/api/updates');
        $this->assertGreaterThanOrEqual($item['id'], $updates['repairs_last_id']);

        // Оставляет заявку только мастер, без лифта и текста нельзя
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/repairs', ['lift_id' => $this->lift['a'], 'info' => 'x'])[0]);
        [$status, $error] = $this->call(self::MASTER, 'POST', '/api/repairs', ['info' => '', 'as' => 'master']);
        $this->assertSame(400, $status);
        $this->assertEqualsCanonicalizing(['lift_id', 'info'], array_keys($error['error']['fields']));

        // Механик закрывает заявку мастера записью в журнал
        [, $entry] = $this->call(self::MECH_A, 'POST', '/api/journal', ['lift_id' => $this->lift['a'], 'title' => 'Заявка мастера', 'status' => 'resolved']);
        $this->assertSame(204, $this->call(self::MECH_A, 'POST', "/api/repairs/{$item['id']}/journal", ['entry_id' => $entry['item']['id']])[0]);
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame($entry['item']['id'], $a['manual'][0]['journal_entry_id'], 'Закрытая заявка остаётся в списке неделю');
    }

    public function testOnlyMasterImportsAndAssigns(): void
    {
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/repairs/import', ['data' => $this->report()])[0]);
        $this->assertSame(400, $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => base64_encode('не отчёт')])[0]);
        $this->assertSame(403, $this->call(self::MECH_A, 'GET', '/api/repairs', query: ['as' => 'master'])[0]);
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/repairs/1/lift', ['lift_id' => $this->lift['a'], 'as' => 'master'])[0]);
    }
}
