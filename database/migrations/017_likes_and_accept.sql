-- Лайки сообщений чата (один на человека) и «Принял» у заявки на ремонт: кто взял заявку.

CREATE TABLE message_likes (
    message_id INT UNSIGNED NOT NULL,
    login VARCHAR(190) NOT NULL,
    -- Имя для подсказки «кто лайкнул»: механик или мастер
    mechanic_id INT UNSIGNED NULL,
    as_master TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, login),
    CONSTRAINT fk_likes_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT fk_likes_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE repair_calls
    -- Кто принял заявку в работу и когда (местное время, как в отчёте 1С)
    ADD COLUMN accepted_login VARCHAR(190) NULL AFTER created_by,
    ADD COLUMN accepted_by INT UNSIGNED NULL AFTER accepted_login,
    ADD COLUMN accepted_at DATETIME NULL AFTER accepted_by,
    ADD CONSTRAINT fk_repair_calls_accepted FOREIGN KEY (accepted_by) REFERENCES mechanics (id) ON DELETE SET NULL;
