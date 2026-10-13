<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Connection;
use App\Support\PersonalData;
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

        $this->assertSame('Могилёвлифтмаш', $names['mogilevlift'] ?? null);
        $this->assertArrayHasKey('gomellift', $names);
        $this->assertArrayHasKey('doppler', $names);
        $this->assertArrayHasKey('otis', $names);
    }

    public function testModelSeesCodesOfItsControllers(): void
    {
        $codes = $this->pdo->query(
            'SELECT fc.code FROM fault_codes fc '
            . 'JOIN model_controllers mc ON mc.controller_id = fc.controller_id '
            . "JOIN models m ON m.id = mc.model_id WHERE m.name = '0401К'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['43', '44', '45', 'A0', 'b9', 'E36'] as $code) {
            $this->assertContains($code, $codes, "У модели 0401К нет кода $code");
        }
        $this->assertGreaterThanOrEqual(70, count($codes));
    }

    public function testEveryFaultCodeHasExactlyOneOwner(): void
    {
        $bad = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM fault_codes WHERE (model_id IS NULL) = (controller_id IS NULL)'
        )->fetchColumn();

        $this->assertSame(0, $bad, 'Код принадлежит и модели, и устройству, либо ни тому ни другому');
    }

    public function testSameCodeMeansDifferentThingsInDifferentControllers(): void
    {
        $titles = $this->pdo->query(
            "SELECT c.name, fc.title FROM fault_codes fc JOIN controllers c ON c.id = fc.controller_id "
            . "WHERE fc.code = '45' AND c.kind = 'control_unit'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $auto = $titles['УЛ ФБИР.484430.013 (ГОСТ 33984.1, автоматические двери)'] ?? '';
        $manual = $titles['УЛ ФБИР.484440.500 (ГОСТ 33984.1, ручные двери)'] ?? '';

        $this->assertStringContainsString('преобразователя частоты', $auto);
        $this->assertStringContainsString('устройства контроля скорости', $manual);
    }

    public function testControllerCodesAreDescribedAndCaused(): void
    {
        $noDescription = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM fault_codes WHERE controller_id IS NOT NULL AND (description IS NULL OR description = '' OR source IS NULL)"
        )->fetchColumn();
        $this->assertSame(0, $noDescription, 'Код устройства без описания или источника');

        $orphanCauses = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM causes c WHERE (c.source LIKE 'ФБИР%' OR c.source LIKE 'ФАИД%') "
            . 'AND NOT EXISTS (SELECT 1 FROM fault_code_causes x WHERE x.cause_id = c.id)'
        )->fetchColumn();
        $this->assertSame(0, $orphanCauses, 'Причина из РЭ УЛ не привязана ни к одному коду');

        $linked = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM fault_code_causes'
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(120, $linked);
    }

    public function testCasesAreSeededAndLinkedToCodes(): void
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
        $this->assertGreaterThanOrEqual(20, $total);

        $noSource = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cases WHERE source IS NULL OR source = ''"
        )->fetchColumn();
        $this->assertSame(0, $noSource, 'У случая нет источника');

        // Если в сиде опечатка в названии устройства или коде, связь молча пропадает.
        $linked = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cases WHERE fault_code_id IS NOT NULL'
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(12, $linked, 'Случаи не привязались к кодам УЛ');

        $wrongController = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cases c JOIN fault_codes fc ON fc.id = c.fault_code_id '
            . "JOIN controllers k ON k.id = fc.controller_id WHERE k.name NOT LIKE 'УЛ ФБИР.484430.013%'"
        )->fetchColumn();
        $this->assertSame(0, $wrongController);
    }

    public function testCasesContainNoPersonalData(): void
    {
        $texts = $this->pdo->query(
            "SELECT CONCAT_WS(' ', description, notes) FROM cases"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($texts as $text) {
            $found = PersonalData::find((string) $text);
            $this->assertNull($found, "В случае найдено: $found — $text");
        }
    }

    public function testLatinCodesAreStoredConsistently(): void
    {
        $cyrillic = $this->pdo->query(
            "SELECT code FROM fault_codes WHERE code REGEXP '[А-Яа-я]'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $cyrillic, 'Коды должны быть записаны латиницей');
    }

    public function testSeededModelsExist(): void
    {
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
