-- Кто сейчас в приложении: время последнего запроса (приложение спрашивает сервер раз в 30 секунд,
-- в чате — чаще). «В сети» — кто был за последние полторы минуты.

CREATE TABLE presence (
    login VARCHAR(190) NOT NULL,
    mechanic_id INT UNSIGNED NULL,
    as_master TINYINT(1) NOT NULL DEFAULT 0,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (login),
    KEY idx_presence_seen (last_seen),
    CONSTRAINT fk_presence_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
