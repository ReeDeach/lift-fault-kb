-- Знания: симптомы, причины, ремонты (с пошаговыми проверками) и связи между ними.

CREATE TABLE symptoms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_symptoms_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE causes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_causes_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fixes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    safety_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fixes_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fix_steps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fix_id INT UNSIGNED NOT NULL,
    step_no SMALLINT UNSIGNED NOT NULL,
    instruction TEXT NOT NULL,
    expected_result TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fix_steps_fix_step (fix_id, step_no),
    CONSTRAINT fk_fix_steps_fix FOREIGN KEY (fix_id)
        REFERENCES fixes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fault_code_symptoms (
    fault_code_id INT UNSIGNED NOT NULL,
    symptom_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (fault_code_id, symptom_id),
    CONSTRAINT fk_fcs_fault_code FOREIGN KEY (fault_code_id)
        REFERENCES fault_codes (id) ON DELETE CASCADE,
    CONSTRAINT fk_fcs_symptom FOREIGN KEY (symptom_id)
        REFERENCES symptoms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE symptom_causes (
    symptom_id INT UNSIGNED NOT NULL,
    cause_id INT UNSIGNED NOT NULL,
    likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3,
    PRIMARY KEY (symptom_id, cause_id),
    CONSTRAINT chk_symptom_causes_likelihood CHECK (likelihood BETWEEN 1 AND 5),
    CONSTRAINT fk_sc_symptom FOREIGN KEY (symptom_id)
        REFERENCES symptoms (id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_cause FOREIGN KEY (cause_id)
        REFERENCES causes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cause_fixes (
    cause_id INT UNSIGNED NOT NULL,
    fix_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (cause_id, fix_id),
    CONSTRAINT fk_cf_cause FOREIGN KEY (cause_id)
        REFERENCES causes (id) ON DELETE CASCADE,
    CONSTRAINT fk_cf_fix FOREIGN KEY (fix_id)
        REFERENCES fixes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
