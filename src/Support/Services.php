<?php

declare(strict_types=1);

namespace App\Support;

use App\Ai\GeminiClient;
use App\Ai\LanguageModel;
use App\Auth\IdentityResolver;
use App\Database\Connection;
use App\Http\HttpException;
use App\Search\Documents;
use App\Search\ManticoreIndex;
use App\Search\SearchIndex;
use Closure;
use PDO;
use PDOException;

/**
 * Простейший контейнер: подключение к БД создаётся лениво, при первом обращении,
 * поэтому маршруты, которым база не нужна (например /api/health), работают без неё.
 */
final class Services
{
    private ?PDO $pdo = null;

    private ?PDO $manticore = null;

    /**
     * @param (Closure(): PDO)|null $pdoFactory
     * @param (Closure(self): ?SearchIndex)|null $searchIndexFactory
     * @param (Closure(): ?LanguageModel)|null $languageModelFactory
     * @param (Closure(PDO): IdentityResolver)|null $identityFactory
     */
    public function __construct(
        private readonly ?Closure $pdoFactory = null,
        private readonly ?string $adminToken = null,
        private readonly ?Closure $searchIndexFactory = null,
        private readonly ?Closure $languageModelFactory = null,
        private readonly ?Closure $identityFactory = null,
    ) {
    }

    /**
     * Кто сделал запрос (аккаунт Tailscale). В тестах подменяется.
     */
    public function identity(): IdentityResolver
    {
        return $this->identityFactory !== null ? ($this->identityFactory)($this->pdo()) : IdentityResolver::fromEnv($this->pdo());
    }

    /**
     * Языковая модель для диагностики. null — AI_API_KEY не задан, диагностика работает без ИИ.
     */
    public function languageModel(): ?LanguageModel
    {
        return $this->languageModelFactory !== null ? ($this->languageModelFactory)() : GeminiClient::fromEnv();
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->pdoFactory !== null
                ? ($this->pdoFactory)()
                : Connection::fromEnv();
        }

        return $this->pdo;
    }

    public function adminToken(): string
    {
        return $this->adminToken ?? Env::get('ADMIN_TOKEN');
    }

    /**
     * Подключение к Manticore. Если он недоступен — 503, остальное API продолжает работать.
     */
    public function manticore(): PDO
    {
        if ($this->manticore === null) {
            try {
                $this->manticore = ManticoreIndex::connect();
            } catch (PDOException $e) {
                error_log('manticore: ' . $e->getMessage());
                throw new HttpException(503, 'Поиск временно недоступен');
            }
        }

        return $this->manticore;
    }

    /**
     * Индекс, который обновляется после записи через админ-API. null — поиск не настроен.
     */
    public function searchIndex(): ?SearchIndex
    {
        if ($this->searchIndexFactory !== null) {
            return ($this->searchIndexFactory)($this);
        }

        return ManticoreIndex::isConfigured()
            ? new ManticoreIndex(fn (): PDO => $this->manticore(), new Documents($this->pdo()))
            : null;
    }
}
