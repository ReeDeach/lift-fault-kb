-- Доступ из интернета (через ngrok с входом через Google): кого пускать кроме мастеров
-- и механиков, чей аккаунт уже привязан. Список ведёт мастер на экране «Доступ».

CREATE TABLE access_logins (
    login VARCHAR(190) NOT NULL,
    -- Кто это, для мастера: «Вишневецкий, новый механик»
    note VARCHAR(100) NULL,
    added_by VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
