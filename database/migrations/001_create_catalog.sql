-- Каталог оборудования: производители -> модели -> коды неисправностей.
-- Вторичные индексы здесь сознательно не добавлены: они появятся на этапе 5
-- вместе с замерами EXPLAIN до и после.

CREATE TABLE manufacturers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_manufacturers_slug (slug),
    UNIQUE KEY uq_manufacturers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE models (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    manufacturer_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    controller VARCHAR(100) NULL,
    year_from SMALLINT UNSIGNED NULL,
    year_to SMALLINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_models_manufacturer_name (manufacturer_id, name),
    CONSTRAINT fk_models_manufacturer FOREIGN KEY (manufacturer_id)
        REFERENCES manufacturers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fault_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    model_id INT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_safety_related TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fault_codes_model_code (model_id, code),
    CONSTRAINT fk_fault_codes_model FOREIGN KEY (model_id)
        REFERENCES models (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
