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

    public function testSeededModelsAndCodesExist(): void
    {
        $codes = $this->pdo->query(
            "SELECT fc.code FROM fault_codes fc JOIN models m ON m.id = fc.model_id WHERE m.name = '0401К'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('45', $codes);
        $this->assertContains('E36', $codes);

        $models = $this->pdo->query(
            "SELECT m.name FROM models m JOIN manufacturers f ON f.id = m.manufacturer_id WHERE f.slug = 'mogilevlift'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('ПВБ1010Г', $models);
    }

    /**
     * Опечатка в названии при связывании в сиде молча теряет связку, поэтому проверяем «сирот»
     * среди записей с источником РЭ: запись без связей не попадёт в диагностику.
     */
    public function testSeededKnowledgeHasNoOrphans(): void
    {
        $checks = [
            'симптом без причины' => 'SELECT COUNT(*) FROM symptoms s WHERE s.source LIKE \'РЭ%\' '
                . 'AND NOT EXISTS (SELECT 1 FROM symptom_causes x WHERE x.symptom_id = s.id)',
            'причина без симптома' => 'SELECT COUNT(*) FROM causes c WHERE c.source LIKE \'РЭ%\' '
                . 'AND NOT EXISTS (SELECT 1 FROM symptom_causes x WHERE x.cause_id = c.id)',
            'причина без ремонта' => 'SELECT COUNT(*) FROM causes c WHERE c.source LIKE \'РЭ%\' '
                . 'AND NOT EXISTS (SELECT 1 FROM cause_fixes x WHERE x.cause_id = c.id)',
            'ремонт без причины' => 'SELECT COUNT(*) FROM fixes f WHERE f.source LIKE \'РЭ%\' '
                . 'AND NOT EXISTS (SELECT 1 FROM cause_fixes x WHERE x.fix_id = f.id)',
            'ремонт без шагов' => 'SELECT COUNT(*) FROM fixes f WHERE f.source LIKE \'РЭ%\' '
                . 'AND NOT EXISTS (SELECT 1 FROM fix_steps x WHERE x.fix_id = f.id)',
        ];

        foreach ($checks as $label => $sql) {
            $this->assertSame(0, (int) $this->pdo->query($sql)->fetchColumn(), "Найдено: $label");
        }

        $this->assertGreaterThanOrEqual(14, (int) $this->pdo->query('SELECT COUNT(*) FROM symptoms')->fetchColumn());
        $this->assertGreaterThanOrEqual(19, (int) $this->pdo->query('SELECT COUNT(*) FROM fixes')->fetchColumn());
    }

    public function testBrakeRelatedFixesCarrySafetyNote(): void
    {
        $missing = $this->pdo->query(
            "SELECT title FROM fixes WHERE (title LIKE '%тормоз%' OR title LIKE '%ограничитель скорости%' "
            . "OR title LIKE '%замок%') AND (safety_note IS NULL OR safety_note = '')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $missing, 'У ремонтов узлов безопасности нет пометки по технике безопасности');
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
