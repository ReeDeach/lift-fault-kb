-- Вторичный индекс по результатам замеров (php bin/bench.php, отчёт в docs/performance.md).
-- Остальные запросы обслуживают первичные ключи, уникальные ключи и индексы внешних ключей.
-- Главные ускорения дала переделка запросов (CatalogRepository::codeCounts, FaultCodeRepository::search),
-- а не индексы.

-- Поиск кода без модели (/api/codes?code=45): уникальные ключи начинаются с model_id и controller_id,
-- поэтому по одному коду MySQL просматривал всю таблицу.
CREATE INDEX idx_fault_codes_code ON fault_codes (code);
