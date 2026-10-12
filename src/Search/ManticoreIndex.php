<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Env;
use PDO;

/**
 * Real-time таблица Manticore с документами базы знаний.
 *
 * Морфология: lemmatize_ru_all приводит русские слова к словарной форме
 * («дверей», «двери» → «дверь»), stem_en — английские. index_exact_words хранит
 * и исходные формы, поэтому точное совпадение ранжируется выше. non_cjk склеивает ё и е.
 */
final class ManticoreIndex implements SearchIndex
{
    public const TABLE = 'kb';

    private const SCHEMA = 'CREATE TABLE ' . self::TABLE . ' ('
        . 'kind string, entity_id bigint, code text, title text, body text, model_id int, controller_id int'
        . ") morphology='lemmatize_ru_all, stem_en' charset_table='non_cjk' min_word_len='1' index_exact_words='1'";

    private const COLUMNS = ['id', 'kind', 'entity_id', 'code', 'title', 'body', 'model_id', 'controller_id'];

    private ?PDO $connection = null;

    /**
     * @param PDO|\Closure(): PDO $manticore подключение или фабрика: с фабрикой соединение
     *        открывается при первой записи, и недоступный Manticore не мешает создать объект
     */
    public function __construct(
        private readonly PDO|\Closure $manticore,
        private readonly Documents $documents,
    ) {
    }

    private function db(): PDO
    {
        return $this->connection ??= $this->manticore instanceof PDO ? $this->manticore : ($this->manticore)();
    }

    /**
     * Подключение к Manticore по MySQL-протоколу. Подготовленные выражения эмулируются:
     * Manticore их не поддерживает, PDO сам экранирует значения.
     */
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            Env::get('MANTICORE_HOST', '127.0.0.1'),
            (int) Env::get('MANTICORE_PORT', '9306'),
        );

        return new PDO($dsn, '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    }

    public static function isConfigured(): bool
    {
        return Env::get('MANTICORE_HOST') !== '';
    }

    public function sync(string $entity, int $id): void
    {
        $doc = $this->documents->one($entity, $id);
        if ($doc === null) {
            $this->remove($entity, $id);

            return;
        }

        $this->replace([$doc]);
    }

    public function remove(string $entity, int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        self::execute($stmt, [Documents::docId($entity, $id)]);
    }

    /**
     * Пересоздаёт таблицу и загружает все документы заново.
     *
     * @return array<string, int> число документов по разделам
     */
    public function rebuild(): array
    {
        $this->db()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db()->exec(self::SCHEMA);

        $counts = [];
        foreach (array_keys(Documents::KINDS) as $kind) {
            $counts[$kind] = 0;
            foreach ($this->documents->batches($kind) as $batch) {
                $this->replace($batch);
                $counts[$kind] += count($batch);
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> число документов по разделам в индексе
     */
    public function status(): array
    {
        $rows = $this->db()->query('SELECT kind, COUNT(*) AS n FROM ' . self::TABLE . ' GROUP BY kind')->fetchAll();

        return array_column(array_map(static fn (array $r): array => [$r['kind'], (int) $r['n']], $rows), 1, 0);
    }

    /**
     * @param list<array<string, mixed>> $docs
     */
    private function replace(array $docs): void
    {
        $row = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
        $stmt = $this->db()->prepare(
            'REPLACE INTO ' . self::TABLE . ' (' . implode(', ', self::COLUMNS) . ') VALUES '
            . implode(', ', array_fill(0, count($docs), $row))
        );

        $values = [];
        foreach ($docs as $doc) {
            foreach (self::COLUMNS as $column) {
                $values[] = $doc[$column];
            }
        }
        self::execute($stmt, $values);
    }

    /**
     * Числа передаются как PARAM_INT: при эмуляции PDO подставляет их без кавычек,
     * а Manticore не приводит строку к числу для целочисленных атрибутов.
     *
     * @param list<mixed> $values
     */
    public static function execute(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
    }
}
