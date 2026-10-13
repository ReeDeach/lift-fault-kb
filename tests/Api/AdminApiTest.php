<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Админ-API на настоящем MySQL. Тест создаёт записи с меткой в названии
 * и удаляет их после себя, сидовые данные не меняются.
 */
final class AdminApiTest extends TestCase
{
    private const TOKEN = 'admin-test-token-0123456789';

    private Router $router;
    private PDO $pdo;
    private string $tag;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты API пропущены.');
        }

        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ-' . bin2hex(random_bytes(4));
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')(
            $this->router,
            new Services(fn (): PDO => $this->pdo, self::TOKEN),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $like = $this->tag . '%';
        $this->pdo->prepare('DELETE FROM cases WHERE description LIKE ?')->execute([$like]);
        $this->pdo->prepare('DELETE FROM fault_codes WHERE title LIKE ?')->execute([$like]);
        foreach (['symptoms', 'causes', 'fixes'] as $table) {
            $this->pdo->prepare("DELETE FROM $table WHERE title LIKE ?")->execute([$like]);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $method, string $path, array $body = [], array $query = []): array
    {
        $response = $this->router->dispatch(new Request(
            $method,
            $path,
            $query,
            $body,
            headers: ['authorization' => 'Bearer ' . self::TOKEN],
        ));

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function controllerId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM controllers WHERE name LIKE '%484430.013%' LIMIT 1")->fetchColumn();
    }

    public function testRequestWithoutTokenIsRejected(): void
    {
        $response = $this->router->dispatch(new Request('POST', '/api/admin/causes', body: ['title' => $this->tag]));

        $this->assertSame(401, $response->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM causes WHERE title LIKE '{$this->tag}%'")->fetchColumn());
    }

    public function testFullKnowledgeChainAppearsInPublicTree(): void
    {
        [$s, $cause] = $this->call('POST', '/api/admin/causes', ['title' => "{$this->tag} Обрыв провода датчика"]);
        $this->assertSame(201, $s);

        [$s, $fix] = $this->call('POST', '/api/admin/fixes', [
            'title' => "{$this->tag} Заменить провод датчика",
            'safety_note' => 'Работы при отключённом питании',
            'steps' => [
                ['instruction' => 'Отключить вводное устройство'],
                ['instruction' => 'Прозвонить провод', 'expected_result' => 'Цепь цела'],
            ],
        ]);
        $this->assertSame(201, $s);
        $this->assertCount(2, $fix['item']['steps']);

        [$s, $symptom] = $this->call('POST', '/api/admin/symptoms', ['title' => "{$this->tag} Лифт стоит с кодом"]);
        $this->assertSame(201, $s);

        [$s, $code] = $this->call('POST', '/api/admin/codes', [
            'code' => 'Т9', 'title' => "{$this->tag} Тестовый код", 'controller_id' => $this->controllerId(),
        ]);
        $this->assertSame(201, $s);
        $this->assertSame('T9', $code['item']['code'], 'Кириллица в коде приводится к латинице');

        $codeId = $code['item']['id'];
        $causeId = $cause['item']['id'];
        $this->assertSame(201, $this->call('PUT', "/api/admin/causes/$causeId/fixes/{$fix['item']['id']}")[0]);
        $this->assertSame(201, $this->call('PUT', "/api/admin/symptoms/{$symptom['item']['id']}/causes/$causeId", ['likelihood' => 4])[0]);
        $this->assertSame(201, $this->call('PUT', "/api/admin/codes/$codeId/symptoms/{$symptom['item']['id']}")[0]);

        [$s, $tree] = $this->call('GET', "/api/codes/$codeId");
        $this->assertSame(200, $s);
        $this->assertSame($causeId, $tree['item']['causes'][0]['id']);
        $this->assertFalse($tree['item']['causes'][0]['direct']);
        $this->assertSame(4, $tree['item']['causes'][0]['likelihood']);
        $this->assertSame('Прозвонить провод', $tree['item']['causes'][0]['fixes'][0]['steps'][1]['instruction']);

        // Прямая связь кода с причиной делает её direct
        $this->assertSame(201, $this->call('PUT', "/api/admin/codes/$codeId/causes/$causeId")[0]);
        [, $tree] = $this->call('GET', "/api/codes/$codeId");
        $this->assertTrue($tree['item']['causes'][0]['direct']);
    }

    public function testPatchChangesOnlyGivenFieldsAndReplacesSteps(): void
    {
        [, $fix] = $this->call('POST', '/api/admin/fixes', [
            'title' => "{$this->tag} Ремонт", 'summary' => 'Кратко',
            'steps' => [['instruction' => 'Шаг А'], ['instruction' => 'Шаг Б']],
        ]);
        $id = $fix['item']['id'];

        [$s, $patched] = $this->call('PATCH', "/api/admin/fixes/$id", ['steps' => [['instruction' => 'Шаг В']]]);

        $this->assertSame(200, $s);
        $this->assertSame('Кратко', $patched['item']['summary']);
        $this->assertSame([['step_no' => 1, 'instruction' => 'Шаг В', 'expected_result' => null]], $patched['item']['steps']);
    }

    public function testDuplicateTitleIsConflict(): void
    {
        $this->call('POST', '/api/admin/symptoms', ['title' => "{$this->tag} Дубль"]);
        [$s, $data] = $this->call('POST', '/api/admin/symptoms', ['title' => "{$this->tag} Дубль"]);

        $this->assertSame(409, $s);
        $this->assertArrayHasKey('title', $data['error']['fields']);
    }

    public function testCodeNeedsExactlyOneOwnerThatExists(): void
    {
        [$none, $noneData] = $this->call('POST', '/api/admin/codes', ['code' => 'X1', 'title' => "{$this->tag} без владельца"]);
        [$both] = $this->call('POST', '/api/admin/codes', [
            'code' => 'X1', 'title' => "{$this->tag} два владельца",
            'controller_id' => $this->controllerId(), 'model_id' => (int) $this->pdo->query('SELECT MIN(id) FROM models')->fetchColumn(),
        ]);
        [$missing, $missingData] = $this->call('POST', '/api/admin/codes', ['code' => 'X1', 'title' => "{$this->tag} нет устройства", 'controller_id' => 999999]);

        $this->assertSame(400, $none);
        $this->assertArrayHasKey('owner', $noneData['error']['fields']);
        $this->assertSame(400, $both);
        $this->assertSame(400, $missing);
        $this->assertArrayHasKey('controller_id', $missingData['error']['fields']);
    }

    public function testPatchCannotBreakCodeOwnership(): void
    {
        [, $code] = $this->call('POST', '/api/admin/codes', ['code' => 'X2', 'title' => "{$this->tag} код", 'controller_id' => $this->controllerId()]);

        [$s] = $this->call('PATCH', "/api/admin/codes/{$code['item']['id']}", ['controller_id' => null]);

        $this->assertSame(400, $s);
    }

    public function testCaseWithPersonalDataIsRejected(): void
    {
        [$s, $data] = $this->call('POST', '/api/admin/cases', [
            'occurred_on' => '2026-10-01',
            'description' => "{$this->tag} Лифт на ул. Ленина стоит",
            'outcome' => 'resolved',
        ]);

        $this->assertSame(400, $s);
        $this->assertStringContainsString('адрес', $data['error']['fields']['description']);
    }

    public function testCaseLifecycle(): void
    {
        [$s, $case] = $this->call('POST', '/api/admin/cases', [
            'occurred_on' => '2026-10-02',
            'description' => "{$this->tag} Двери кабины не закрываются, очищен порог",
            'outcome' => 'resolved',
        ]);
        $this->assertSame(201, $s);
        $id = $case['item']['id'];

        [$s, $patched] = $this->call('PATCH', "/api/admin/cases/$id", ['outcome' => 'partial']);
        $this->assertSame(200, $s);
        $this->assertSame('partial', $patched['item']['outcome']);

        $this->assertSame(204, $this->call('DELETE', "/api/admin/cases/$id")[0]);
        $this->assertSame(404, $this->call('GET', "/api/admin/cases/$id")[0]);
        $this->assertSame(404, $this->call('DELETE', "/api/admin/cases/$id")[0]);
    }

    public function testLinkIsIdempotentAndUnlinkReportsMissing(): void
    {
        [, $cause] = $this->call('POST', '/api/admin/causes', ['title' => "{$this->tag} Причина"]);
        [, $symptom] = $this->call('POST', '/api/admin/symptoms', ['title' => "{$this->tag} Симптом"]);
        $path = "/api/admin/symptoms/{$symptom['item']['id']}/causes/{$cause['item']['id']}";

        $this->assertSame(201, $this->call('PUT', $path, ['likelihood' => 2])[0]);
        $this->assertSame(200, $this->call('PUT', $path, ['likelihood' => 5])[0]);
        $this->assertSame(5, (int) $this->pdo->query(
            "SELECT likelihood FROM symptom_causes WHERE symptom_id = {$symptom['item']['id']}"
        )->fetchColumn());

        $this->assertSame(400, $this->call('PUT', $path, ['likelihood' => 9])[0]);
        $this->assertSame(204, $this->call('DELETE', $path)[0]);
        $this->assertSame(404, $this->call('DELETE', $path)[0]);
        $this->assertSame(404, $this->call('PUT', "/api/admin/symptoms/{$symptom['item']['id']}/causes/999999")[0]);
    }

    public function testUnknownSectionsAre404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/admin/users')[0]);
        $this->assertSame(404, $this->call('PUT', '/api/admin/fixes/1/codes/1')[0]);
        $this->assertSame(404, $this->call('GET', '/api/admin/causes/abc')[0]);
    }

    public function testListSearchesByTitle(): void
    {
        $this->call('POST', '/api/admin/causes', ['title' => "{$this->tag} первая"]);
        $this->call('POST', '/api/admin/causes', ['title' => "{$this->tag} вторая"]);

        [$s, $data] = $this->call('GET', '/api/admin/causes', query: ['q' => $this->tag]);

        $this->assertSame(200, $s);
        $this->assertSame(2, $data['total']);
    }
}
