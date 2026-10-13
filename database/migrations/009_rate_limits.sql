-- Счётчики запросов для ограничения частоты (ИИ-диагностика и черновики журнала платные).
-- Одна строка — одно «ведро» в одном окне времени. Старые окна чистит RateLimiter.

CREATE TABLE rate_limits (
    bucket VARCHAR(190) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
