<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Ai\LanguageModel;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Disclaimer;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * /api/diagnose на настоящей базе. Модель подменена: она выбирает переданные ей
 * причины по правилу из теста и записывает, что получила. Настоящий Gemini в тестах не вызывается.
 */
final class DiagnoseApiTest extends TestCase
{
    private Router $router;

    /** @var list<array{system: string, prompt: string}> */
    private array $calls = [];

    /** @var callable(list<int>): array<string, mixed> ответ модели по id кандидатов */
    private $answer;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты API пропущены.');
        }

        $this->answer = static fn (array $ids): array => [
            'summary' => 'Проверьте первую причину',
            'causes' => [['cause_id' => $ids[0], 'confidence' => 'high', 'reasoning' => 'Первая в списке']],
            'questions' => [],
        ];

        $model = new class ($this) implements LanguageModel {
            public function __construct(private readonly DiagnoseApiTest $test)
            {
            }

            public function generate(string $system, string $prompt, array $schema): array
            {
                return ['data' => $this->test->answerFor($system, $prompt), 'model' => 'fake'];
            }
        };

        $pdo = Connection::fromEnv();
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')(
            $this->router,
            new Services(static fn (): PDO => $pdo, languageModelFactory: static fn (): LanguageModel => $model),
        );
    }

    /**
     * @internal вызывается подменённой моделью
     * @return array<string, mixed>
     */
    public function answerFor(string $system, string $prompt): array
    {
        $this->calls[] = ['system' => $system, 'prompt' => $prompt];
        $data = json_decode(substr($prompt, strpos($prompt, '{')), true, flags: JSON_THROW_ON_ERROR);

        return ($this->answer)(array_column($data['кандидаты'], 'cause_id'));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     */
    private function post(array $body): array
    {
        $response = $this->router->dispatch(new Request('POST', '/api/diagnose', body: $body));

        return [$response->status, json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testCodeOfModelGivesCausesFromItsTree(): void
    {
        [$status, $data] = $this->post([
            'description' => 'Лифт стоит, на индикаторе 45, пускатель щёлкает',
            'model' => '0401К',
            'code' => '45',
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('ok', $data['ai']['status']);
        $this->assertSame(['45'], array_column($data['codes'], 'code'));
        $this->assertStringContainsString('484430.013', $data['codes'][0]['owner']['name']);
        $this->assertCount(1, $data['causes']);
        $this->assertSame('Первая в списке', $data['causes'][0]['reasoning']);
        $this->assertNotEmpty($data['causes'][0]['evidence']);
        $this->assertSame(Disclaimer::TEXT, $data['disclaimer']);

        $prompt = json_decode(substr($this->calls[0]['prompt'], strpos($this->calls[0]['prompt'], '{')), true);
        $this->assertSame('45', $prompt['код_на_индикаторе']);
        $this->assertSame('Нет готовности преобразователя частоты', $prompt['коды'][0]['значение']);
        $this->assertLessThanOrEqual(15, count($prompt['кандидаты']));
    }

    public function testCodeIsTakenInCyrillic(): void
    {
        [, $data] = $this->post(['description' => 'Ошибка в преобразователе частоты', 'model' => '0401К', 'code' => 'Е36']);

        $this->assertSame(['E36'], array_column($data['codes'], 'code'));
    }

    public function testCodeWithHintTakesBoth(): void
    {
        [$status, $data] = $this->post(['description' => 'Пассажиры застряли у этажа', 'model' => '0401К', 'code' => '44 С6']);

        $this->assertSame(200, $status);
        $this->assertSame(['44', 'C6'], array_column($data['codes'], 'code'));
        $prompt = json_decode(substr($this->calls[0]['prompt'], strpos($this->calls[0]['prompt'], '{')), true);
        $this->assertSame('44 C6', $prompt['код_на_индикаторе']);
    }

    public function testCodesWrittenInDescriptionAreUsed(): void
    {
        [$status, $data] = $this->post(['description' => 'Постоянно появляется ошибка 49, 50']);

        $this->assertSame(200, $status);
        $this->assertSame(['49', '50'], $data['detected_codes']);
        $this->assertSame('ok', $data['ai']['status']);
        $this->assertContains('50', array_column($data['codes'], 'code'));
        $this->assertNotEmpty($data['causes']);
    }

    public function testCodeFieldWinsOverText(): void
    {
        [, $data] = $this->post(['description' => 'Раньше была ошибка 49, теперь другая', 'code' => '50']);

        $this->assertSame([], $data['detected_codes']);
        $this->assertSame(['50'], array_column($data['codes'], 'code'));
    }

    public function testCausesOfOtherManufacturersAreNotOffered(): void
    {
        [, $data] = $this->post([
            'description' => 'На табло ошибка 5, дверь кабины не закрывается, привод дверей гудит',
            'model' => 'Traction MRL ECO II VVVF',
        ]);

        $this->assertSame(['05'], array_column($data['codes'], 'code'));
        foreach ($data['causes'] as $cause) {
            $this->assertStringNotContainsString('ДК.00.00.001', (string) $cause['source'], 'Причина из руководства Могилёвлифтмаша для Doppler');
            $this->assertStringNotContainsString('ФБИР', (string) $cause['source']);
        }
    }

    public function testDescriptionAloneFindsCausesThroughSymptoms(): void
    {
        [, $data] = $this->post(['description' => 'При пуске лебёдка гудит, свет в кабине тускнеет']);

        $this->assertSame('ok', $data['ai']['status']);
        $this->assertNotEmpty($data['causes']);
        $evidence = implode(' ', $data['causes'][0]['evidence']);
        $this->assertStringContainsString('симптом', $evidence);
    }

    public function testInventedCauseFromModelNeverReachesClient(): void
    {
        $this->answer = static fn (array $ids): array => [
            'summary' => 'Гномы',
            'causes' => [['cause_id' => 99999999, 'confidence' => 'high', 'reasoning' => 'В лифте поселились гномы']],
            'questions' => [],
        ];

        [, $data] = $this->post(['description' => 'Двери не закрываются, код 50', 'code' => '50']);

        $this->assertSame('rejected', $data['ai']['status']);
        $this->assertSame(1, $data['ai']['discarded']);
        $this->assertStringNotContainsString('гном', json_encode($data['causes'], JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($data['causes'], 'Вместо выдумки — причины из базы');
    }

    public function testNothingInDatabaseSkipsModel(): void
    {
        [$status, $data] = $this->post(['description' => 'щщщщ ыыыыы ъъъъъ жжжжжж']);

        $this->assertSame(200, $status);
        $this->assertSame('skipped', $data['ai']['status']);
        $this->assertSame([], $data['causes']);
        $this->assertSame([], $this->calls);
    }

    public function testBadInput(): void
    {
        [$short, $shortData] = $this->post(['description' => 'стоит']);
        [$unknown, $unknownData] = $this->post(['description' => 'Лифт стоит между этажами', 'floor' => 3]);
        [$codeType] = $this->post(['description' => 'Лифт стоит между этажами', 'code' => 45]);
        [$badCode] = $this->post(['description' => 'Лифт стоит между этажами', 'code' => "45' OR 1"]);
        [$noModel] = $this->post(['description' => 'Лифт стоит между этажами', 'model' => 'нет-такой']);

        $this->assertSame(400, $short);
        $this->assertArrayHasKey('description', $shortData['error']['fields']);
        $this->assertSame(400, $unknown);
        $this->assertArrayHasKey('floor', $unknownData['error']['fields']);
        $this->assertSame(400, $codeType);
        $this->assertSame(400, $badCode);
        $this->assertSame(404, $noModel);
        $this->assertSame([], $this->calls, 'При ошибке во входных данных модель не вызывается');
    }

    public function testGetIsNotAllowed(): void
    {
        $response = $this->router->dispatch(new Request('GET', '/api/diagnose'));

        $this->assertSame(405, $response->status);
    }
}
