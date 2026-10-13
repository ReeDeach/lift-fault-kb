-- Личный журнал механика: механики, их лифты (адрес и номер лифта на адресе) и записи журнала.
-- Адреса и фамилии — рабочие данные бригады: в репозиторий они не попадают, загружаются
-- командой bin/import-lifts.php из private/. Фамилии и телефоны жильцов в записи не пишутся.

CREATE TABLE mechanics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    section VARCHAR(100) NULL,
    -- Аккаунт Tailscale, под которым механик входит. Привязывается при первом входе.
    login VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mechanics_name (name),
    UNIQUE KEY uq_mechanics_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lifts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mechanic_id INT UNSIGNED NULL,
    address VARCHAR(190) NOT NULL,
    -- Название объекта на адресе (БЧК, Склады): на одном адресе бывает несколько объектов
    object_name VARCHAR(100) NOT NULL DEFAULT '',
    lift_no TINYINT UNSIGNED NOT NULL,
    model_id INT UNSIGNED NULL,
    reg_number VARCHAR(32) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lifts_place (address, object_name, lift_no),
    KEY idx_lifts_mechanic (mechanic_id),
    CONSTRAINT fk_lifts_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE SET NULL,
    CONSTRAINT fk_lifts_model FOREIGN KEY (model_id) REFERENCES models (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lift_id INT UNSIGNED NOT NULL,
    -- Кто записал: механик может записать и на чужой лифт (подмена, отпуск)
    author_id INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    codes VARCHAR(100) NULL,
    title VARCHAR(255) NOT NULL,
    symptoms TEXT NULL,
    cause TEXT NULL,
    work_done TEXT NULL,
    recommendation TEXT NULL,
    status ENUM('resolved', 'monitoring', 'repeat_visit', 'unresolved') NOT NULL DEFAULT 'resolved',
    source ENUM('ai', 'manual') NOT NULL DEFAULT 'manual',
    -- Переписка с ИИ, из которой собрана запись: только текст механика и выводы, без адреса
    chat TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_journal_lift_time (lift_id, occurred_at),
    KEY idx_journal_author (author_id),
    CONSTRAINT fk_journal_lift FOREIGN KEY (lift_id) REFERENCES lifts (id) ON DELETE CASCADE,
    CONSTRAINT fk_journal_author FOREIGN KEY (author_id) REFERENCES mechanics (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
