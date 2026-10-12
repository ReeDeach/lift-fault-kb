<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Search\Documents;
use App\Search\ManticoreIndex;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Полнотекстовый поиск на настоящих MySQL и Manticore. Индекс пересобирается перед тестами.
 */
final class SearchApiTest extends TestCase
{
    private const TOKEN = 'search-test-token-0123456789';

    private Router $router;
    private PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        if (Connection::isConfigured() && ManticoreIndex::isConfigured()) {
            (new ManticoreIndex(ManticoreIndex::connect(), new Documents(Connection::fromEnv())))->rebuild();
        }
    }

    protected function setUp(): void
    {
        if (!Connection::isConfigured() || !ManticoreIndex::isConfigured()) {
            $this->markTestSkipped('DB_HOST или MANTICORE_HOST не заданы: тесты поиска пропущены.');
        }

        $this->pdo = Connection::fromEnv();
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services(fn (): PDO => $this->pdo, self::TOKEN));
    }

    /**
     * @param array<string, string> $query
     * @return array{int, array<string, mixed>|null}
     */
    private function call(string $method, string $path, array $query = [], array $body = []): array
    {
        $response = $this->router->dispatch(new Request(
            $method, $path, $query, $body, headers: ['authorization' => 'Bearer ' . self::TOKEN],
        ));

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testFindsSymptomInOtherWordForms(): void
    {
        [$status, $data] = $this->call('GET', '/api/search', ['q' => 'дверь не закрывается']);

        $this->assertSame(200, $status);
        $this->assertSame('symptoms', $data['items'][0]['type']);
        $this->assertStringContainsString('Двери не закрываются', $data['items'][0]['title']);
        $this->assertStringContainsString('<mark>', $data['items'][0]['title_html']);
        $this->assertArrayHasKey('disclaimer', $data);
    }

    public function testYoAndYeAreTheSame(): void
    {
        [, $data] = $this->call('GET', '/api/search', ['q' => 'лебедка гудит']);

        $this->assertStringContainsString('лебёдка', $data['items'][0]['title']);
    }

    public function testCyrillicCodeFindsLatinCodeFirst(): void
    {
        [, $data] = $this->call('GET', '/api/search', ['q' => 'Е36']);

        $this->assertSame('codes', $data['items'][0]['type']);
        $this->assertSame('E36', $data['items'][0]['code']);
        $this->assertSame('controller', $data['items'][0]['owner']['type']);
    }

    public function testTypeFilter(): void
    {
        [, $data] = $this->call('GET', '/api/search', ['q' => 'пассажиры застряли', 'type' => 'cases']);

        $this->assertGreaterThan(0, $data['total']);
        $this->assertSame(['cases'], array_values(array_unique(array_column($data['items'], 'type'))));
    }

    public function testModelFilterKeepsOnlyCodesOfThatModel(): void
    {
        $allowed = $this->pdo->query(
            "SELECT mc.controller_id FROM model_controllers mc JOIN models m ON m.id = mc.model_id WHERE m.name = '0401К'"
        )->fetchAll(PDO::FETCH_COLUMN);

        [, $all] = $this->call('GET', '/api/search', ['q' => 'дверь', 'type' => 'codes', 'limit' => '50']);
        [, $filtered] = $this->call('GET', '/api/search', ['q' => 'дверь', 'type' => 'codes', 'model' => '0401К', 'limit' => '50']);

        $this->assertLessThan($all['total'], $filtered['total'], 'Коды других вариантов УЛ должны отсеяться');
        foreach ($filtered['items'] as $item) {
            $this->assertContains($item['owner']['id'], array_map('intval', $allowed));
        }
    }

    public function testPagination(): void
    {
        [, $page1] = $this->call('GET', '/api/search', ['q' => 'дверь', 'limit' => '3']);
        [, $page2] = $this->call('GET', '/api/search', ['q' => 'дверь', 'limit' => '3', 'offset' => '3']);

        $this->assertCount(3, $page1['items']);
        $this->assertSame($page1['total'], $page2['total']);
        $key = static fn (array $i): string => $i['type'] . $i['id'];
        $this->assertSame([], array_intersect(array_map($key, $page1['items']), array_map($key, $page2['items'])));
    }

    public function testBadParameters(): void
    {
        [$empty, $emptyData] = $this->call('GET', '/api/search');
        [$noWords] = $this->call('GET', '/api/search', ['q' => '?!']);
        [$long] = $this->call('GET', '/api/search', ['q' => str_repeat('а', 301)]);
        [$type, $typeData] = $this->call('GET', '/api/search', ['q' => 'дверь', 'type' => 'users']);
        [$model] = $this->call('GET', '/api/search', ['q' => 'дверь', 'model' => 'нет-такой']);

        $this->assertSame(400, $empty);
        $this->assertArrayHasKey('q', $emptyData['error']['fields']);
        $this->assertSame(400, $noWords);
        $this->assertSame(400, $long);
        $this->assertSame(400, $type);
        $this->assertArrayHasKey('type', $typeData['error']['fields']);
        $this->assertSame(404, $model);
    }

    public function testAdminWritesAreSearchableImmediately(): void
    {
        $word = 'тестслово' . bin2hex(random_bytes(3));
        [$status, $cause] = $this->call('POST', '/api/admin/causes', [], ['title' => "ТЕСТ причина $word"]);
        $this->assertSame(201, $status);
        $this->assertArrayNotHasKey('warning', $cause);
        $id = $cause['item']['id'];

        try {
            [, $found] = $this->call('GET', '/api/search', ['q' => $word]);
            $this->assertSame([['causes', $id]], array_map(static fn (array $i): array => [$i['type'], $i['id']], $found['items']));

            $this->call('PATCH', "/api/admin/causes/$id", [], ['title' => 'ТЕСТ причина переименована ' . $id]);
            [, $renamed] = $this->call('GET', '/api/search', ['q' => $word]);
            $this->assertSame(0, $renamed['total']);
        } finally {
            $this->assertSame(204, $this->call('DELETE', "/api/admin/causes/$id")[0]);
        }

        [, $gone] = $this->call('GET', '/api/search', ['q' => 'переименована ' . $id]);
        $this->assertSame([], array_filter($gone['items'], static fn (array $i): bool => $i['type'] === 'causes' && $i['id'] === $id));
    }

    public function testStaleIndexEntriesAreHidden(): void
    {
        $word = 'устаревшее' . bin2hex(random_bytes(3));
        $this->pdo->prepare('INSERT INTO symptoms (title) VALUES (?)')->execute(["ТЕСТ $word"]);
        $id = (int) $this->pdo->lastInsertId();
        $index = new ManticoreIndex(ManticoreIndex::connect(), new Documents($this->pdo));
        $index->sync('symptoms', $id);

        // Запись удалена мимо API: в индексе она осталась, но в ответ попасть не должна
        $this->pdo->prepare('DELETE FROM symptoms WHERE id = ?')->execute([$id]);
        [, $data] = $this->call('GET', '/api/search', ['q' => $word]);
        $index->remove('symptoms', $id);

        $this->assertSame([], $data['items']);
    }
}
