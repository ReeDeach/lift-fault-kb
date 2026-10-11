-- Реальные случаи из практики.
-- Не записывайте сюда адреса объектов, фамилии и телефоны: только техническое описание.

CREATE TABLE cases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    model_id INT UNSIGNED NULL,
    fault_code_id INT UNSIGNED NULL,
    occurred_on DATE NOT NULL,
    description TEXT NOT NULL,
    cause_id INT UNSIGNED NULL,
    fix_id INT UNSIGNED NULL,
    outcome ENUM('resolved', 'partial', 'unresolved') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cases_model FOREIGN KEY (model_id)
        REFERENCES models (id) ON DELETE SET NULL,
    CONSTRAINT fk_cases_fault_code FOREIGN KEY (fault_code_id)
        REFERENCES fault_codes (id) ON DELETE SET NULL,
    CONSTRAINT fk_cases_cause FOREIGN KEY (cause_id)
        REFERENCES causes (id) ON DELETE SET NULL,
    CONSTRAINT fk_cases_fix FOREIGN KEY (fix_id)
        REFERENCES fixes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
