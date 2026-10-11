<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные проверки на настоящем MySQL 8. Запускаются только если задан DB_HOST
 * и миграции с сидами уже применены (в CI это делают предыдущие шаги).
 */
final class SchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB_HOST не задан: интеграционные тесты MySQL пропущены.');
        }

        $this->pdo = Connection::fromEnv();
    }

    public function testAllTablesExist(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ([
            'manufacturers', 'models', 'fault_codes', 'symptoms', 'causes', 'fixes',
            'fix_steps', 'fault_code_symptoms', 'symptom_causes', 'cause_fixes', 'cases',
            'schema_migrations',
        ] as $table) {
            $this->assertContains($table, $tables, "Нет таблицы $table");
        }
    }

    public function testManufacturersAreSeededWithCyrillicIntact(): void
    {
        $names = $this->pdo
            ->query('SELECT slug, name FROM manufacturers')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('Могилёвлифт', $names['mogilevlift'] ?? null);
        $this->assertArrayHasKey('gomellift', $names);
        $this->assertArrayHasKey('doppler', $names);
        $this->assertArrayHasKey('otis', $names);
    }

    public function testForeignKeyRejectsUnknownModel(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO fault_codes (model_id, code, title) VALUES (999999, 'X1', 'тест')"
        );
    }

    public function testFaultCodeIsUniquePerModel(): void
    {
        $this->pdo->beginTransaction();

        try {
            $manufacturerId = (int) $this->pdo
                ->query("SELECT id FROM manufacturers WHERE slug = 'otis'")
                ->fetchColumn();

            $this->pdo->prepare('INSERT INTO models (manufacturer_id, name) VALUES (?, ?)')
                ->execute([$manufacturerId, 'schema-test-model']);
            $modelId = (int) $this->pdo->lastInsertId();

            $insert = $this->pdo->prepare(
                'INSERT INTO fault_codes (model_id, code, title) VALUES (?, ?, ?)'
            );
            $insert->execute([$modelId, 'E05', 'первая запись']);

            $this->expectException(PDOException::class);
            $insert->execute([$modelId, 'E05', 'дубликат']);
        } finally {
            $this->pdo->rollBack();
        }
    }
}
