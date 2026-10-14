-- Push-уведомления: подписки устройств. Устройство подписывается в роли, в которой
-- открыто приложение (мастер или механик), и само выбирает, о чём сообщать.

CREATE TABLE push_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Адрес службы уведомлений браузера (Google, Mozilla, Apple) и ключи шифрования
    endpoint VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(120) NOT NULL,
    auth VARCHAR(40) NOT NULL,
    login VARCHAR(190) NOT NULL,
    role ENUM('mechanic', 'master') NOT NULL,
    mechanic_id INT UNSIGNED NULL,
    -- Чат, заявки на запчасти, заявки на ремонт из 1С, тишина ночью (22:00–7:00)
    chat TINYINT(1) NOT NULL DEFAULT 1,
    requests TINYINT(1) NOT NULL DEFAULT 1,
    repairs TINYINT(1) NOT NULL DEFAULT 1,
    quiet TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_role (role, mechanic_id),
    CONSTRAINT fk_push_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
