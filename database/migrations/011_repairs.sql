-- Заявки на ремонт из ежедневного отчёта 1С (XLSX, приходит мастеру на почту).
-- Отчёт загружает мастер. Лифт сопоставляется по номеру из 1С (lifts.reg_number),
-- а если номера ещё нет — мастер выбирает лифт один раз, и номер запоминается.

CREATE TABLE repair_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_from DATETIME NOT NULL,
    period_to DATETIME NOT NULL,
    -- Участок из шапки отчёта («УЧ.…»)
    section VARCHAR(190) NOT NULL DEFAULT '',
    file_name VARCHAR(190) NULL,
    uploaded_by VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_repair_reports_period (section, period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE repair_calls (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id INT UNSIGNED NOT NULL,
    -- Номер лифта из 1С, например 6820н
    lift_reg VARCHAR(32) NOT NULL,
    address VARCHAR(190) NOT NULL,
    floor VARCHAR(16) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    -- «Лифт в ост.»: лифт в этот момент стоит
    stopped TINYINT(1) NOT NULL DEFAULT 0,
    -- Остановок за последние 30 дней
    stops_30d SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    info VARCHAR(500) NOT NULL DEFAULT '',
    -- Коды из «Информации о неисправности», через пробел: «44 C0»
    codes VARCHAR(100) NULL,
    kind ENUM('fault', 'planned') NOT NULL DEFAULT 'fault',
    lift_id INT UNSIGNED NULL,
    journal_entry_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_repair_calls_call (lift_reg, started_at),
    KEY idx_repair_calls_report (report_id),
    KEY idx_repair_calls_lift (lift_id, started_at),
    CONSTRAINT fk_repair_calls_report FOREIGN KEY (report_id) REFERENCES repair_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_repair_calls_lift FOREIGN KEY (lift_id) REFERENCES lifts (id) ON DELETE SET NULL,
    CONSTRAINT fk_repair_calls_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
