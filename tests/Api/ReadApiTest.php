<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Router;
use App\Support\Disclaimer;
use App\Support\Services;
use PHPUnit\Framework\TestCase;

/**
 * API чтения целиком, от роутера до MySQL, без HTTP-сервера.
 * Запускается, если задан DB_HOST и миграции с сидами применены (в CI это делают шаги до phpunit).
 */
final class ReadApiTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты API пропущены.');
        }

        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services());
    }

    /**
     * @param array<string, string> $query
     * @return array{int, array<string, mixed>}
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->router->dispatch(new Request('GET', $path, $query));

        return [$response->status, json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testManufacturersListHasAllFourWithModelCounts(): void
    {
        [$status, $data] = $this->get('/api/manufacturers');

        $this->assertSame(200, $status);
        $bySlug = array_column($data['items'], null, 'slug');
        foreach (['mogilevlift', 'gomellift', 'doppler', 'otis'] as $slug) {
            $this->assertArrayHasKey($slug, $bySlug);
        }
        $this->assertSame('Могилёвлифт', $bySlug['mogilevlift']['name']);
        $this->assertGreaterThanOrEqual(2, $bySlug['mogilevlift']['models_count']);
        $this->assertSame(Disclaimer::TEXT, $data['disclaimer']);
    }

    public function testModelsShowControllersAndCodeCounts(): void
    {
        [$status, $data] = $this->get('/api/models', ['manufacturer' => 'mogilevlift']);

        $this->assertSame(200, $status);
        $byName = array_column($data['items'], null, 'name');
        $this->assertArrayHasKey('0401К', $byName);
        $this->assertGreaterThanOrEqual(70, $byName['0401К']['codes_count']);

        $controllerNames = array_column($byName['0401К']['controllers'], 'name');
        $this->assertContains('ПЧ Hpmont HD5L', $controllerNames);
    }

    public function testUnknownManufacturerIs404(): void
    {
        [$status, $data] = $this->get('/api/models', ['manufacturer' => 'nope']);

        $this->assertSame(404, $status);
        $this->assertSame(404, $data['error']['status']);
    }

    public function testCodeLookupAcceptsCyrillicLookalikesInCodeAndModel(): void
    {
        [$statusLatin, $latin] = $this->get('/api/codes', ['model' => '0401K', 'code' => 'A0']);
        [$statusCyr, $cyr] = $this->get('/api/codes', ['model' => '0401К', 'code' => 'А0']);

        $this->assertSame(200, $statusLatin);
        $this->assertSame(200, $statusCyr);
        $this->assertGreaterThanOrEqual(1, $latin['total']);
        $this->assertSame($latin['items'], $cyr['items']);
        $this->assertSame('A0', $cyr['items'][0]['code']);
        $this->assertSame('controller', $cyr['items'][0]['owner']['type']);
    }

    public function testSameCodeForModelResolvesToItsOwnController(): void
    {
        [, $data] = $this->get('/api/codes', ['model' => '0401К', 'code' => '45']);

        $this->assertSame(1, $data['total']);
        $this->assertStringContainsString('ФБИР.484430.013', $data['items'][0]['owner']['name']);
        $this->assertStringContainsString('преобразователя частоты', $data['items'][0]['title']);
    }

    public function testCodeWithoutModelReturnsEveryVariant(): void
    {
        [, $data] = $this->get('/api/codes', ['code' => '45']);

        $owners = array_unique(array_column(array_column($data['items'], 'owner'), 'name'));
        $this->assertGreaterThanOrEqual(3, count($owners), 'Код 45 должен вернуться для всех вариантов УЛ');
    }

    public function testSeveralCodesAsShownOnIndicator(): void
    {
        [$status, $data] = $this->get('/api/codes', ['model' => '0401К', 'code' => '44 С6']);

        $this->assertSame(200, $status);
        $this->assertSame(['44', 'C6'], array_column($data['items'], 'code'));

        [$tooMany] = $this->get('/api/codes', ['code' => '1 2 3 4 5 6']);
        $this->assertSame(400, $tooMany);
    }

    public function testDopplerEtypeCodeFoundWithOrWithoutLeadingZero(): void
    {
        [$status, $data] = $this->get('/api/codes', ['model' => 'Traction MRL ECO II VVVF', 'code' => '5', 'expand' => 'tree']);

        $this->assertSame(200, $status);
        $this->assertSame(['05'], array_column($data['items'], 'code'));
        $this->assertSame('Контроллер E-type (Doppler)', $data['items'][0]['owner']['name']);
        $this->assertGreaterThanOrEqual(3, count($data['items'][0]['causes']));
    }

    public function testUnknownCodeIsEmptyListNotError(): void
    {
        [$status, $data] = $this->get('/api/codes', ['model' => '0401К', 'code' => 'ZZ99']);

        $this->assertSame(200, $status);
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['total']);
    }

    public function testUnknownModelIs404(): void
    {
        [$status] = $this->get('/api/codes', ['model' => 'не-существует']);

        $this->assertSame(404, $status);
    }

    public function testBadParametersAreRejectedWithFieldMessages(): void
    {
        [$status, $data] = $this->get('/api/codes', ['code' => "1' OR '1'='1", 'expand' => 'all']);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('code', $data['error']['fields']);
        $this->assertArrayHasKey('expand', $data['error']['fields']);

        [$statusLimit] = $this->get('/api/codes', ['limit' => 'abc']);
        $this->assertSame(400, $statusLimit);
    }

    public function testPagination(): void
    {
        [, $page1] = $this->get('/api/codes', ['model' => '0401К', 'limit' => '5']);
        [, $page2] = $this->get('/api/codes', ['model' => '0401К', 'limit' => '5', 'offset' => '5']);
        [, $beyond] = $this->get('/api/codes', ['model' => '0401К', 'offset' => '100000']);

        $this->assertCount(5, $page1['items']);
        $this->assertCount(5, $page2['items']);
        $this->assertGreaterThanOrEqual(70, $page1['total']);
        $this->assertSame($page1['total'], $page2['total']);
        $this->assertSame([], array_intersect(array_column($page1['items'], 'id'), array_column($page2['items'], 'id')));
        $this->assertSame([], $beyond['items']);
    }

    public function testListIsFlatUnlessTreeRequested(): void
    {
        [, $flat] = $this->get('/api/codes', ['model' => '0401К', 'code' => '43']);
        [, $tree] = $this->get('/api/codes', ['model' => '0401К', 'code' => '43', 'expand' => 'tree']);

        $this->assertArrayNotHasKey('causes', $flat['items'][0]);
        $this->assertArrayHasKey('causes', $tree['items'][0]);
        $this->assertArrayHasKey('symptoms', $tree['items'][0]);
        $this->assertArrayHasKey('cases', $tree['items'][0]);
    }

    public function testTreeListsManualCausesFirst(): void
    {
        [, $data] = $this->get('/api/codes', ['model' => '0401К', 'code' => '43', 'expand' => 'tree']);

        $causes = $data['items'][0]['causes'];
        $this->assertGreaterThanOrEqual(4, count($causes));
        $this->assertTrue($causes[0]['direct']);

        $seenIndirect = false;
        foreach ($causes as $cause) {
            if (!$cause['direct']) {
                $seenIndirect = true;
            }
            $this->assertFalse($seenIndirect && $cause['direct'], 'Прямые причины должны идти перед косвенными');
        }
    }

    public function testTreeCarriesFixesWithStepsAndSafetyNotes(): void
    {
        [, $data] = $this->get('/api/codes', ['model' => '0401К', 'limit' => '100', 'expand' => 'tree']);

        $fixes = [];
        foreach ($data['items'] as $item) {
            foreach ($item['causes'] as $cause) {
                foreach ($cause['fixes'] as $fix) {
                    $fixes[$fix['id']] = $fix;
                }
            }
        }

        $this->assertNotSame([], $fixes, 'В деревьях кодов нет ни одного ремонта');
        $this->assertNotSame([], array_filter($fixes, static fn (array $f): bool => $f['steps'] !== []));

        foreach ($fixes as $fix) {
            if (preg_match('/тормоз|ограничитель скорости|замок/ui', $fix['title'])) {
                $this->assertNotEmpty($fix['safety_note'], 'Нет пометки по технике безопасности: ' . $fix['title']);
            }
        }
    }

    public function testTreeIncludesRealCasesForCode(): void
    {
        [, $data] = $this->get('/api/codes', ['model' => '0401К', 'code' => 'C6', 'expand' => 'tree']);

        $cases = $data['items'][0]['cases'];
        $this->assertNotSame([], $cases);
        $this->assertSame('resolved', $cases[0]['outcome']);
        $this->assertStringContainsString('ПТЗ', $cases[0]['description']);
    }

    public function testShowReturnsFullTree(): void
    {
        [, $list] = $this->get('/api/codes', ['model' => '0401К', 'code' => 'E36']);
        $id = (string) $list['items'][0]['id'];

        [$status, $data] = $this->get('/api/codes/' . $id);

        $this->assertSame(200, $status);
        $this->assertSame('E36', $data['item']['code']);
        $this->assertGreaterThanOrEqual(2, count($data['item']['causes']));
        $this->assertGreaterThanOrEqual(1, count($data['item']['symptoms']));
        $this->assertSame(Disclaimer::TEXT, $data['disclaimer']);
    }

    public function testShowUnknownOrMalformedIdIs404(): void
    {
        [$missing] = $this->get('/api/codes/99999999');
        [$malformed] = $this->get('/api/codes/abc');

        $this->assertSame(404, $missing);
        $this->assertSame(404, $malformed);
    }
}
