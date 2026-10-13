-- Связь в бригаде: общий чат механиков всех участков и заявки на запчасти мастеру.
-- Сообщения чата и переписка по заявке лежат в одной таблице, фото — во вложениях.
-- Сами файлы фото хранятся на диске (UPLOAD_DIR), в базе — только их описание.

CREATE TABLE part_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Механик, которому нужна деталь
    author_id INT UNSIGNED NOT NULL,
    lift_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    quantity VARCHAR(50) NULL,
    urgent TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('new', 'accepted', 'ordered', 'ready', 'issued', 'rejected') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_requests_author (author_id, updated_at),
    KEY idx_requests_status (status, updated_at),
    CONSTRAINT fk_requests_author FOREIGN KEY (author_id) REFERENCES mechanics (id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_lift FOREIGN KEY (lift_id) REFERENCES lifts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NULL — общий чат, иначе переписка по заявке
    request_id INT UNSIGNED NULL,
    author_login VARCHAR(190) NOT NULL,
    author_id INT UNSIGNED NULL,
    -- Написано от имени мастера
    as_master TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NULL,
    -- Смена статуса заявки этим сообщением
    status VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_request (request_id, id),
    CONSTRAINT fk_messages_request FOREIGN KEY (request_id) REFERENCES part_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_author FOREIGN KEY (author_id) REFERENCES mechanics (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Имя файла и часть адреса: случайное, по нему не перебрать чужие фото
    token CHAR(32) NOT NULL,
    uploader_login VARCHAR(190) NOT NULL,
    -- NULL — загружено, но ещё не отправлено в сообщении
    message_id INT UNSIGNED NULL,
    mime VARCHAR(20) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_token (token),
    KEY idx_attachments_message (message_id),
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
