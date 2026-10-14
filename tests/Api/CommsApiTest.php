<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Auth\IdentityResolver;
use App\Comms\PhotoStore;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Services;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Общий чат и заявки на запчасти на настоящем MySQL: кто что видит, кто меняет статус,
 * фото к сообщениям. Тест создаёт своих механиков с меткой, фото кладёт во временную
 * папку и удаляет всё после себя.
 */
final class CommsApiTest extends TestCase
{
    private const MASTER = 'boss-comms@test.example';
    private const MECH_A = 'comms-a@test.example';
    private const MECH_B = 'comms-b@test.example';
    private const STRANGER = 'comms-x@test.example';

    // Наименьший JPEG 1×1
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private Router $router;
    private PDO $pdo;
    private string $tag;
    private string $dir;
    /** @var array<string, int> */
    private array $mechanic = [];
    private int $lift;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты пропущены.');
        }

        $this->pdo = Connection::fromEnv();
        $this->tag = 'ТЕСТ' . bin2hex(random_bytes(3));
        $this->dir = sys_get_temp_dir() . '/lift-comms-' . bin2hex(random_bytes(4));

        foreach (['a' => self::MECH_A, 'b' => self::MECH_B] as $key => $login) {
            $this->pdo->prepare('INSERT INTO mechanics (name, section, login) VALUES (?, ?, ?)')
                ->execute(["{$this->tag} механик $key", "Участок $key", $login]);
            $this->mechanic[$key] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('INSERT INTO lifts (mechanic_id, address, lift_no) VALUES (?, ?, 1)')
            ->execute([$this->mechanic['a'], "{$this->tag} улица 1"]);
        $this->lift = (int) $this->pdo->lastInsertId();

        $pdo = $this->pdo;
        $dir = $this->dir;
        $this->router = new Router();
        (require __DIR__ . '/../../src/routes.php')($this->router, new Services(
            static fn (): PDO => $pdo,
            identityFactory: static fn (PDO $p): IdentityResolver => new IdentityResolver($p, [self::MASTER]),
            photoStoreFactory: static fn (): PhotoStore => new PhotoStore($dir),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $logins = [self::MASTER, self::MECH_A, self::MECH_B, self::STRANGER];
            $in = implode(',', array_fill(0, count($logins), '?'));
            $this->pdo->prepare("DELETE FROM messages WHERE author_login IN ($in)")->execute($logins);
            $this->pdo->prepare("DELETE FROM attachments WHERE uploader_login IN ($in)")->execute($logins);
            $this->pdo->prepare('DELETE FROM lifts WHERE address LIKE ?')->execute([$this->tag . '%']);
            $this->pdo->prepare('DELETE FROM mechanics WHERE name LIKE ?')->execute([$this->tag . '%']);
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    private function send(?string $login, string $method, string $path, array $body = [], array $query = []): Response
    {
        $headers = $login === null ? [] : [IdentityResolver::HEADER => $login];

        return $this->router->dispatch(new Request($method, $path, $query, $body, headers: $headers));
    }

    /**
     * @return array{int, array<string, mixed>|null}
     */
    private function call(?string $login, string $method, string $path, array $body = [], array $query = []): array
    {
        $response = $this->send($login, $method, $path, $body, $query);

        return [$response->status, $response->body === '' ? null : json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * Загружает фото и возвращает его описание из ответа.
     *
     * @return array<string, mixed>
     */
    private function upload(string $login, ?string $jpeg = null): array
    {
        [$status, $data] = $this->call($login, 'POST', '/api/uploads', ['data' => 'data:image/jpeg;base64,' . base64_encode($jpeg ?? base64_decode(self::JPEG))]);
        $this->assertSame(201, $status, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $data['item'];
    }

    private function token(string $url): string
    {
        return substr($url, strlen('/api/files/'));
    }

    public function testChatIsSharedByAllSections(): void
    {
        [$s1, $a] = $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => '  Есть у кого ВКЗ на 0401К?  ']);
        [$s2, $m] = $this->call(self::MASTER, 'POST', '/api/chat', ['body' => 'Есть на складе', 'as' => 'master']);
        $this->assertSame(201, $s1);
        $this->assertSame(201, $s2);
        $this->assertSame('Есть у кого ВКЗ на 0401К?', $a['item']['body']);
        $this->assertSame('Участок a', $a['item']['author']['section']);
        $this->assertSame(['role' => 'master', 'id' => null, 'name' => 'Мастер', 'section' => null], $m['item']['author']);

        [, $seenByB] = $this->call(self::MECH_B, 'GET', '/api/chat', query: ['after' => (string) ($a['item']['id'] - 1)]);
        $mine = array_column($seenByB['items'], 'mine', 'id');
        $this->assertSame([$a['item']['id'] => false, $m['item']['id'] => false], $mine);

        [, $newOnly] = $this->call(self::MECH_A, 'GET', '/api/chat', query: ['after' => (string) $a['item']['id']]);
        $this->assertSame([$m['item']['id']], array_column($newOnly['items'], 'id'));

        [, $updates] = $this->call(self::MECH_B, 'GET', '/api/updates');
        $this->assertGreaterThanOrEqual($m['item']['id'], $updates['chat_last_id']);
    }

    public function testChatLikes(): void
    {
        [, $post] = $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => 'Кто был на Лесной?']);
        $id = $post['item']['id'];
        $this->assertSame(['count' => 0, 'liked' => false, 'names' => []], $post['item']['likes']);

        // Лайк ставит и снимает повторное нажатие; другие видят число и кто поставил
        [$status, $liked] = $this->call(self::MECH_B, 'POST', "/api/chat/$id/like");
        $this->assertSame(200, $status);
        $this->assertSame(['count' => 1, 'liked' => true, 'names' => ["{$this->tag} механик b"]], $liked['likes']);
        $this->call(self::MASTER, 'POST', "/api/chat/$id/like", ['as' => 'master']);
        [, $chat] = $this->call(self::MECH_A, 'GET', '/api/chat', query: ['after' => (string) ($id - 1)]);
        $mine = array_values(array_filter($chat['items'], static fn (array $m): bool => $m['id'] === $id))[0];
        $this->assertSame([2, false], [$mine['likes']['count'], $mine['likes']['liked']]);
        $this->assertEqualsCanonicalizing(["{$this->tag} механик b", 'Мастер'], $mine['likes']['names']);
        // При подгрузке нового — свежие лайки уже показанных сообщений
        [, $poll] = $this->call(self::MECH_A, 'GET', '/api/chat', query: ['after' => (string) $id]);
        $this->assertSame(2, $poll['likes'][(string) $id]['count']);
        [, $unliked] = $this->call(self::MECH_B, 'POST', "/api/chat/$id/like");
        $this->assertSame(1, $unliked['likes']['count']);
        $this->assertFalse($unliked['likes']['liked']);

        $this->assertSame(404, $this->call(self::MECH_A, 'POST', '/api/chat/999999999/like')[0]);
    }

    public function testChatRules(): void
    {
        $this->assertSame(401, $this->call(null, 'GET', '/api/chat')[0]);
        $this->assertSame(403, $this->call(self::STRANGER, 'POST', '/api/chat', ['body' => 'Привет'])[0], 'Не выбрал себя среди механиков');
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => 'Я мастер', 'as' => 'master'])[0]);
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => '   '])[0]);
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => str_repeat('я', 2001)])[0]);
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/chat', ['body' => 'x', 'admin' => true])[0]);
    }

    public function testPhotoIsStrippedOfMetadataAndAttachedOnce(): void
    {
        $plain = base64_decode(self::JPEG);
        // APP1 с EXIF сразу после SOI: в нём телефон пишет координаты
        $exif = "Exif\0\0GPS 52.0976 23.7341";
        $withExif = substr($plain, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($plain, 2);

        $photo = $this->upload(self::MECH_A, $withExif);
        $this->assertSame(1, $photo['width']);
        $stored = file_get_contents($this->dir . '/' . $this->token($photo['url']) . '.img');
        $this->assertStringNotContainsString('GPS', $stored);
        $this->assertSame($plain, $stored);

        $this->assertSame(400, $this->call(self::MECH_B, 'POST', '/api/chat', ['photos' => [$photo['id']]])[0], 'Чужое фото не прикрепить');
        [$status, $message] = $this->call(self::MECH_A, 'POST', '/api/chat', ['photos' => [$photo['id']]]);
        $this->assertSame(201, $status);
        $this->assertSame($photo['url'], $message['item']['photos'][0]['url']);
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/chat', ['photos' => [$photo['id']]])[0], 'Уже отправлено');

        $file = $this->send(self::MECH_B, 'GET', $photo['url']);
        $this->assertSame(200, $file->status);
        $this->assertSame('image/jpeg', $file->headers['Content-Type']);
        $this->assertSame('nosniff', $file->headers['X-Content-Type-Options']);
    }

    public function testNotAPhotoIsRejected(): void
    {
        $fake = ['data' => 'data:image/jpeg;base64,' . base64_encode('<?php echo 1;')];
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/uploads', $fake)[0]);
        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/uploads', ['data' => 'data:image/svg+xml;base64,PHN2Zz4='])[0]);
        $this->assertSame(401, $this->call(null, 'POST', '/api/uploads', ['data' => 'data:image/jpeg;base64,' . self::JPEG])[0]);
        $this->assertSame(404, $this->call(self::MECH_A, 'GET', '/api/files/../../etc/passwd')[0]);
    }

    public function testPartRequestFlow(): void
    {
        $photo = $this->upload(self::MECH_A);
        [$status, $created] = $this->call(self::MECH_A, 'POST', '/api/requests', [
            'title' => 'Контакт ВКЗ', 'quantity' => '2 шт', 'urgent' => true, 'lift_id' => $this->lift,
            'body' => 'Подгорел, лифт стоит', 'photos' => [$photo['id']],
        ]);
        $this->assertSame(201, $status, json_encode($created, JSON_UNESCAPED_UNICODE));
        $request = $created['item'];
        $id = $request['id'];
        $this->assertSame('new', $request['status']);
        $this->assertTrue($request['urgent']);
        $this->assertSame($this->lift, $request['lift']['id']);
        $this->assertSame(1, $request['photos_count']);
        $this->assertSame('Подгорел, лифт стоит', $request['messages'][0]['body']);

        // Другой механик видит её в заявках бригады (не в «моих»), мастер — у себя
        $this->assertSame(200, $this->call(self::MECH_B, 'GET', "/api/requests/$id")[0]);
        $this->assertNotContains($id, array_column($this->call(self::MECH_B, 'GET', '/api/requests')[1]['items'], 'id'));
        $this->assertContains($id, array_column($this->call(self::MECH_B, 'GET', '/api/requests', query: ['scope' => 'all'])[1]['items'], 'id'));
        $this->assertSame(403, $this->call(self::MECH_B, 'GET', '/api/requests', query: ['as' => 'master'])[0]);
        $this->assertContains($id, array_column($this->call(self::MASTER, 'GET', '/api/requests', query: ['as' => 'master', 'open' => '1'])[1]['items'], 'id'));

        // Фото заявки видит бригада, но не посторонний (без привязки к механику)
        $this->assertSame(200, $this->send(self::MECH_B, 'GET', $photo['url'])->status);
        $this->assertSame(404, $this->send(self::STRANGER, 'GET', $photo['url'])->status);
        $this->assertSame(200, $this->send(self::MASTER, 'GET', $photo['url'])->status);

        // Статус меняет мастер
        $this->assertSame(403, $this->call(self::MECH_A, 'POST', "/api/requests/$id/messages", ['status' => 'ordered'])[0]);
        $this->assertSame(403, $this->call(self::MECH_B, 'POST', "/api/requests/$id/messages", ['body' => 'Мне тоже'])[0], 'Писать в чужую заявку нельзя');
        [$s, $ordered] = $this->call(self::MASTER, 'POST', "/api/requests/$id/messages", ['as' => 'master', 'status' => 'ordered', 'body' => 'Заказал, будет в четверг']);
        $this->assertSame(201, $s);
        $this->assertSame('ordered', $ordered['item']['status']);
        $this->assertSame(201, $this->call(self::MECH_A, 'POST', "/api/requests/$id/messages", ['body' => 'Спасибо'])[0]);
        $this->assertSame(201, $this->call(self::MASTER, 'POST', "/api/requests/$id/messages", ['as' => 'master', 'status' => 'ready'])[0]);

        // Пришла — механик отмечает «получил»
        $this->assertSame(201, $this->call(self::MECH_A, 'POST', "/api/requests/$id/messages", ['status' => 'issued'])[0]);
        [, $done] = $this->call(self::MECH_A, 'GET', "/api/requests/$id");
        $this->assertSame('issued', $done['item']['status']);
        $this->assertSame(['new', 'ordered', null, 'ready', 'issued'], array_column($done['item']['messages'], 'status'));
        $this->assertNotContains($id, array_column($this->call(self::MASTER, 'GET', '/api/requests', query: ['as' => 'master', 'open' => '1'])[1]['items'], 'id'));
    }

    public function testRequestValidation(): void
    {
        [$status, $data] = $this->call(self::MECH_A, 'POST', '/api/requests', ['title' => ' ', 'urgent' => 'да', 'lift_id' => 'x']);
        $this->assertSame(400, $status);
        $this->assertSame(['title', 'urgent', 'lift_id'], array_keys($data['error']['fields']));

        $this->assertSame(400, $this->call(self::MECH_A, 'POST', '/api/requests', ['title' => 'Ролик', 'lift_id' => 999999999])[0]);
        $this->assertSame(403, $this->call(self::STRANGER, 'POST', '/api/requests', ['title' => 'Ролик'])[0]);
        $this->assertSame(400, $this->call(self::MASTER, 'POST', '/api/requests/1/messages', ['as' => 'master', 'status' => 'lost'])[0]);
    }
}
