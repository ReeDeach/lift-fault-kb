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
            $this->pdo->prepare('DELETE FROM lifts WHERE address LIKE ?')->execute([$this->street . '%']);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
    }

    /**
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $login, string $method, string $path, array $body = [], array $query = []): array
    {
        $response = $this->router->dispatch(new Request($method, $path, $query, $body, headers: [IdentityResolver::HEADER => $login]));

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
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
        $this->assertSame('9001н', $reg->fetchColumn(), 'Единственный лифт по адресу — номер запомнен');

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

        // Механик видит только свои; заявки без лифта видит только мастер
        [, $a] = $this->call(self::MECH_A, 'GET', '/api/repairs');
        $this->assertSame(['9001н'], array_column($a['items'], 'reg'));
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $this->assertSame([], $b['items']);

        // Мастер указывает лифт — все заявки с этим номером сопоставлены, механик их видит
        $this->assertSame(204, $this->call(self::MASTER, 'POST', "/api/repairs/{$byReg['9002н fault']['id']}/lift", ['lift_id' => $this->lift['b2']])[0]);
        [, $b] = $this->call(self::MECH_B, 'GET', '/api/repairs');
        $this->assertCount(2, $b['items']);

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

        // Чужую заявку механик закрыть не может
        $this->assertSame(404, $this->call(self::MECH_B, 'POST', "/api/repairs/$callId/journal", ['entry_id' => $entry['item']['id']])[0]);
    }

    public function testOnlyMasterImportsAndAssigns(): void
    {
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/repairs/import', ['data' => $this->report()])[0]);
        $this->assertSame(400, $this->call(self::MASTER, 'POST', '/api/repairs/import', ['data' => base64_encode('не отчёт')])[0]);
        $this->assertSame(403, $this->call(self::MECH_A, 'GET', '/api/repairs', query: ['as' => 'master'])[0]);
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/repairs/1/lift', ['lift_id' => $this->lift['a']])[0]);
    }
}
