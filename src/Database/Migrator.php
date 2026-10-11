<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Применяет SQL-файлы из каталога по порядку имён и запоминает применённые
 * в таблице schema_migrations.
 *
 * Ограничения формата: каждая команда заканчивается ';' в конце строки,
 * строки-комментарии начинаются с '--'. Внутри строковых значений
 * не должно быть ';' перед переводом строки.
 *
 * DDL в MySQL подтверждается неявно, поэтому транзакции вокруг миграций нет:
 * если миграция упала посередине, её нужно доделать или откатить вручную.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'filename VARCHAR(190) NOT NULL PRIMARY KEY, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        );
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo
            ->query('SELECT filename FROM schema_migrations ORDER BY filename')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files($this->migrationsDir), $this->applied()));
    }

    /**
     * @return list<string> имена применённых миграций
     */
    public function migrate(): array
    {
        $done = [];

        foreach ($this->pending() as $file) {
            try {
                $this->runFile($this->migrationsDir . '/' . $file);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf('Миграция %s не применена: %s', $file, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $stmt->execute([$file]);
            $done[] = $file;
        }

        return $done;
    }

    /**
     * Сиды не отслеживаются: файлы должны быть идемпотентными.
     *
     * @return list<string>
     */
    public function seed(string $seedsDir): array
    {
        $done = [];

        foreach ($this->files($seedsDir) as $file) {
            try {
                $this->runFile($seedsDir . '/' . $file);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf('Сид %s не применён: %s', $file, $e->getMessage()),
                    0,
                    $e,
                );
            }
            $done[] = $file;
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        // Переводы строк перечислены явно, а не через \R: без флага /u класс \R
        // считает переводом строки байт 0x85, который входит в UTF-8 букву «х»
        // и ряд других кириллических букв, и рвёт строки посреди слова.
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $parts = preg_split('/;[ \t]*(?:\r\n|\n|\r|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $s): bool => $s !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('Каталог не найден: ' . $dir);
        }

        $paths = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        $names = array_map('basename', $paths);
        sort($names, SORT_STRING);

        return $names;
    }

    private function runFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Не удалось прочитать файл ' . $path);
        }

        foreach (self::splitStatements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
