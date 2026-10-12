-- Устройства управления и приводы (УЛ, ПЧ): коды ошибок принадлежат им, а не модели лифта.
-- Один и тот же код в разных вариантах УЛ может означать разное (например, код 45),
-- а одна модель лифта работает с несколькими устройствами (УЛ и частотный преобразователь).

CREATE TABLE controllers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    kind ENUM('control_unit', 'drive') NOT NULL,
    vendor VARCHAR(100) NULL,
    doc_ref VARCHAR(150) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_controllers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE model_controllers (
    model_id INT UNSIGNED NOT NULL,
    controller_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (model_id, controller_id),
    CONSTRAINT fk_mc_model FOREIGN KEY (model_id)
        REFERENCES models (id) ON DELETE CASCADE,
    CONSTRAINT fk_mc_controller FOREIGN KEY (controller_id)
        REFERENCES controllers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Код неисправности принадлежит либо модели лифта, либо устройству (ровно одно из двух).
-- CHECK здесь не используется: MySQL запрещает CHECK по столбцам с каскадными внешними ключами.
-- Правило проверяется тестами и валидацией API.
ALTER TABLE fault_codes MODIFY model_id INT UNSIGNED NULL;

ALTER TABLE fault_codes ADD COLUMN controller_id INT UNSIGNED NULL AFTER model_id;

ALTER TABLE fault_codes ADD UNIQUE KEY uq_fault_codes_controller_code (controller_id, code);

ALTER TABLE fault_codes ADD CONSTRAINT fk_fault_codes_controller FOREIGN KEY (controller_id)
    REFERENCES controllers (id) ON DELETE CASCADE;

-- Причины, которые руководство называет прямо для кода (без посредника-симптома).
CREATE TABLE fault_code_causes (
    fault_code_id INT UNSIGNED NOT NULL,
    cause_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (fault_code_id, cause_id),
    CONSTRAINT fk_fcc_fault_code FOREIGN KEY (fault_code_id)
        REFERENCES fault_codes (id) ON DELETE CASCADE,
    CONSTRAINT fk_fcc_cause FOREIGN KEY (cause_id)
        REFERENCES causes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
