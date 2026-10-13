<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Ai\LanguageModel;
use App\Auth\IdentityResolver;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Личный журнал на настоящем MySQL: вход по заголовку Tailscale, права механика и мастера,
 * проверка записей и черновик из переписки. Тест создаёт своих механиков и лифты с меткой
 * и удаляет их после себя.
 */
final class JournalApiTest extends TestCase
{
    private const MASTER = 'boss@test.example';
    private const MECH_A = 'mech-a@test.example';
    private const MECH_B = 'mech-b@test.example';

    private Router $router;
    private PDO $pdo;
    private string $tag;
    /** @var array<string, int> */
    private array $mechanic = [];
    /** @var array<string, int> */
    private array $lift = [];
    /** @var array<string, mixed>|null ответ подменённой модели для черновика */
    private ?array $modelAnswer = null;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты пропущены.');
        }

        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ' . bin2hex(random_bytes(3));

        foreach (['a' => self::MECH_A, 'b' => self::MECH_B] as $key => $login) {
            $this->pdo->prepare('INSERT INTO mechanics (name, login) VALUES (?, ?)')->execute(["{$this->tag} механик $key", null]);
            $this->mechanic[$key] = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO lifts (mechanic_id, address, lift_no) VALUES (?, ?, 1), (?, ?, 2)')
                ->execute([$this->mechanic[$key], "{$this->tag} улица $key 1", $this->mechanic[$key], "{$this->tag} улица $key 1"]);
            $this->lift[$key] = (int) $this->pdo->lastInsertId();
        }

        $test = $this;
        $model = new class ($test) implements LanguageModel {
            public function __construct(private readonly JournalApiTest $test)
            {
            }

            public function generate(string $system, string $prompt, array $schema): array
            {
                return ['data' => $this->test->modelAnswer(), 'model' => 'fake'];
            }
        };

        $pdo = $this->pdo;
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services(
            static fn (): PDO => $pdo,
            languageModelFactory: fn (): ?LanguageModel => $this->modelAnswer === null ? null : $model,
            identityFactory: static fn (PDO $p): IdentityResolver => new IdentityResolver($p, [self::MASTER]),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM lifts WHERE address LIKE ?')->execute([$this->tag . '%']);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
    }

    /**
     * @internal для подменённой модели
     * @return array<string, mixed>
     */
    public function modelAnswer(): array
    {
        return $this->modelAnswer ?? [];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array{int, array<string, mixed>|null}
     */
    private function call(?string $login, string $method, string $path, array $body = [], array $query = []): array
    {
        $headers = $login === null ? [] : [IdentityResolver::HEADER => $login];
        $response = $this->router->dispatch(new Request($method, $path, $query, $body, headers: $headers));

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function linkBoth(): void
    {
        $this->assertSame(200, $this->call(self::MECH_A, 'POST', '/api/me/link', ['mechanic_id' => $this->mechanic['a']])[0]);
        $this->assertSame(200, $this->call(self::MECH_B, 'POST', '/api/me/link', ['mechanic_id' => $this->mechanic['b']])[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $liftKey, array $extra = []): array
    {
        return $extra + [
            'lift_id' => $this->lift[$liftKey],
            'codes' => 'Е36',
            'title' => 'Код E36: лифт стоит',
            'symptoms' => 'После пуска остановка',
            'status' => 'monitoring',
            'source' => 'ai',
        ];
    }

    public function testWithoutTailscaleThereIsNoAccess(): void
    {
        [$status] = $this->call(null, 'GET', '/api/me');
        [$journal] = $this->call(null, 'GET', '/api/journal');

        $this->assertSame(401, $status);
        $this->assertSame(401, $journal);
    }

    public function testFirstLoginOffersMechanicsAndLinksOnce(): void
    {
        [, $me] = $this->call(self::MECH_A, 'GET', '/api/me');
        $this->assertNull($me['mechanic']);
        $this->assertFalse($me['is_master']);
        $this->assertContains($this->mechanic['a'], array_column($me['mechanics'], 'id'));

        [$status, $linked] = $this->call(self::MECH_A, 'POST', '/api/me/link', ['mechanic_id' => $this->mechanic['a']]);
        $this->assertSame(200, $status);
        $this->assertSame($this->mechanic['a'], $linked['mechanic']['id']);

        [$taken] = $this->call(self::MECH_B, 'POST', '/api/me/link', ['mechanic_id' => $this->mechanic['a']]);
        $this->assertSame(409, $taken, 'Чужого механика взять нельзя');
    }

    public function testMasterIsRecognisedByList(): void
    {
        [, $me] = $this->call('BOSS@test.example', 'GET', '/api/me');

        $this->assertTrue($me['is_master'], 'Сравнение аккаунта без учёта регистра');
    }

    public function testMechanicSeesOnlyOwnLiftsEntriesMasterSeesAll(): void
    {
        $this->linkBoth();

        [$status, $created] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a'));
        $this->assertSame(201, $status);
        $this->assertSame('E36', $created['item']['codes'], 'Кириллица в коде приводится к латинице');
        $this->assertSame("{$this->tag} механик a", $created['item']['author']['name']);

        [, $own] = $this->call(self::MECH_A, 'GET', '/api/journal', query: ['q' => $this->tag]);
        [, $other] = $this->call(self::MECH_B, 'GET', '/api/journal', query: ['q' => $this->tag]);
        [, $master] = $this->call(self::MASTER, 'GET', '/api/journal', query: ['q' => $this->tag]);

        $this->assertSame(1, $own['total']);
        $this->assertSame(0, $other['total'], 'Чужой журнал механику не виден');
        $this->assertSame(1, $master['total']);
        $this->assertSame(1, $own['stats']['lifts'][0]['entries']);
    }

    public function testMechanicMayWriteOnColleaguesLiftAndSeesOwnEntry(): void
    {
        $this->linkBoth();

        [$status] = $this->call(self::MECH_B, 'POST', '/api/journal', $this->entry('a', ['title' => 'Подмена: проверил двери']));
        [, $author] = $this->call(self::MECH_B, 'GET', '/api/journal', query: ['q' => $this->tag]);
        [, $owner] = $this->call(self::MECH_A, 'GET', '/api/journal', query: ['q' => $this->tag]);

        $this->assertSame(201, $status);
        $this->assertSame(1, $author['total'], 'Свою запись автор видит');
        $this->assertSame(1, $owner['total'], 'Хозяин лифта видит запись по своему лифту');
    }

    public function testUnlinkedMechanicCannotWrite(): void
    {
        [$status] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a'));

        $this->assertSame(403, $status);
    }

    public function testEntryValidation(): void
    {
        $this->linkBoth();

        [$s1, $d1] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a', ['title' => '  ']));
        [$s2, $d2] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a', ['work_done' => 'Позвонить +375 29 123-45-67']));
        [$s3, $d3] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a', ['occurred_at' => '2099-01-01T10:00', 'status' => 'done']));
        [$s4] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a', ['lift_id' => 999999999]));
        [$s5, $d5] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a', ['extra' => 1]));

        $this->assertSame(400, $s1);
        $this->assertArrayHasKey('title', $d1['error']['fields']);
        $this->assertSame(400, $s2);
        $this->assertStringContainsString('телефон', $d2['error']['fields']['work_done']);
        $this->assertSame(400, $s3);
        $this->assertArrayHasKey('occurred_at', $d3['error']['fields']);
        $this->assertArrayHasKey('status', $d3['error']['fields']);
        $this->assertSame(400, $s4);
        $this->assertSame(400, $s5);
        $this->assertArrayHasKey('extra', $d5['error']['fields']);
    }

    public function testOnlyAuthorOrMasterDeletes(): void
    {
        $this->linkBoth();
        [, $created] = $this->call(self::MECH_A, 'POST', '/api/journal', $this->entry('a'));
        $id = $created['item']['id'];

        $this->assertSame(403, $this->call(self::MECH_B, 'DELETE', "/api/journal/$id")[0]);
        $this->assertSame(204, $this->call(self::MECH_A, 'DELETE', "/api/journal/$id")[0]);
        $this->assertSame(404, $this->call(self::MASTER, 'DELETE', "/api/journal/$id")[0]);
    }

    public function testLiftsAreGroupedByAddress(): void
    {
        [, $data] = $this->call(self::MASTER, 'GET', '/api/lifts', query: ['mechanic_id' => (string) $this->mechanic['a']]);

        $this->assertCount(1, $data['items']);
        $this->assertSame([1, 2], array_column($data['items'][0]['lifts'], 'lift_no'));
    }

    public function testDraftKeepsOnlyCodesFromChat(): void
    {
        $this->linkBoth();
        $this->modelAnswer = [
            'title' => 'Код 50: не закрываются двери', 'codes' => '50 99', 'symptoms' => 'Двери не закрываются',
            'cause' => 'Мусор на пороге', 'work_done' => 'Почистил порог', 'recommendation' => '', 'status' => 'resolved',
        ];

        [$status, $data] = $this->call(self::MECH_A, 'POST', '/api/journal/draft', [
            'messages' => ['Двери не закрываются, ошибка 50', 'Почистил порог, лифт пущен'],
            'answers' => [['summary' => 'Код 50', 'codes' => ['50'], 'causes' => [['title' => 'Мусор на пороге']]]],
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('ai', $data['source']);
        $this->assertSame('50', $data['draft']['codes'], 'Кода 99 в переписке не было');
        $this->assertSame('resolved', $data['draft']['status']);
        $this->assertNull($data['draft']['recommendation']);
    }

    public function testDraftWithoutAiInventsNoWork(): void
    {
        $this->linkBoth();

        [, $data] = $this->call(self::MECH_A, 'POST', '/api/journal/draft', [
            'messages' => ['Лифт стоит, ошибка 50'],
            'answers' => [['summary' => '', 'codes' => ['50'], 'causes' => [['title' => 'Неисправен контакт ВКЗ', 'first_check' => 'Проверить ВКЗ']]]],
        ]);

        $this->assertSame('template', $data['source']);
        $this->assertNull($data['draft']['work_done']);
        $this->assertSame('monitoring', $data['draft']['status']);
        $this->assertSame('Предположительно: Неисправен контакт ВКЗ', $data['draft']['cause']);
        $this->assertStringStartsWith('Код 50:', $data['draft']['title']);
    }
}
