<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты раннера на SQLite в памяти: проверяется логика (порядок, повторный запуск,
 * ошибки), а не MySQL-диалект. Схему на настоящем MySQL проверяет SchemaTest.
 */
final class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->dir = sys_get_temp_dir() . '/migrator_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    public function testSplitStatementsIgnoresCommentsAndBlankParts(): void
    {
        $sql = "-- комментарий\nCREATE TABLE a (id INTEGER);\n\nINSERT INTO a VALUES (1);\n";

        $this->assertSame(
            ['CREATE TABLE a (id INTEGER)', 'INSERT INTO a VALUES (1)'],
            Migrator::splitStatements($sql),
        );
    }

    public function testSplitStatementsDoesNotBreakCyrillicText(): void
    {
        // «х» в UTF-8 содержит байт 0x85, который \R без /u принимает за перевод строки.
        $sql = "-- только техническое описание\n"
            . "INSERT INTO a (name) VALUES ('хлеб, хмель, чехол');\n";

        $this->assertSame(
            ["INSERT INTO a (name) VALUES ('хлеб, хмель, чехол')"],
            Migrator::splitStatements($sql),
        );
    }

    public function testAppliesPendingInNameOrder(): void
    {
        $this->write('002_insert.sql', "INSERT INTO t VALUES (1);\n");
        $this->write('001_create.sql', "CREATE TABLE t (id INTEGER);\n");

        $applied = (new Migrator($this->pdo, $this->dir))->migrate();

        $this->assertSame(['001_create.sql', '002_insert.sql'], $applied);
        $this->assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testSecondRunAppliesNothing(): void
    {
        $this->write('001_create.sql', "CREATE TABLE t (id INTEGER);\n");
        $migrator = new Migrator($this->pdo, $this->dir);

        $migrator->migrate();

        $this->assertSame([], $migrator->migrate());
        $this->assertSame([], $migrator->pending());
    }

    public function testNewFileIsPickedUpLater(): void
    {
        $this->write('001_create.sql', "CREATE TABLE t (id INTEGER);\n");
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();

        $this->write('002_more.sql', "CREATE TABLE u (id INTEGER);\n");

        $this->assertSame(['002_more.sql'], $migrator->pending());
        $this->assertSame(['002_more.sql'], $migrator->migrate());
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $this->write('001_ok.sql', "CREATE TABLE t (id INTEGER);\n");
        $this->write('002_bad.sql', "INSERT INTO missing_table VALUES (1);\n");
        $migrator = new Migrator($this->pdo, $this->dir);

        try {
            $migrator->migrate();
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('002_bad.sql', $e->getMessage());
        }

        $this->assertSame(['001_ok.sql'], $migrator->applied());
        $this->assertSame(['002_bad.sql'], $migrator->pending());
    }

    public function testSeedRunsEveryTimeAndIsNotTracked(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->write('001_seed.sql', "INSERT OR IGNORE INTO t VALUES (1);\n");
        $migrator = new Migrator($this->pdo, $this->dir);

        $this->assertSame(['001_seed.sql'], $migrator->seed($this->dir));
        $this->assertSame(['001_seed.sql'], $migrator->seed($this->dir));
        $this->assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new Migrator($this->pdo, $this->dir . '/nope'))->pending();
    }
}
