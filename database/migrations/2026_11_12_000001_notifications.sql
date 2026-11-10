-- Notices for users (bell and push) and the devices subscribed to Web Push.

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body VARCHAR(500) NOT NULL DEFAULT '',
    url VARCHAR(500) NOT NULL DEFAULT '/',
    tag VARCHAR(60) NOT NULL DEFAULT '',
    pushed TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY notifications_user (user_id, read_at),
    KEY notifications_push (pushed, created_at),
    KEY notifications_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(200) NOT NULL,
    auth VARCHAR(64) NOT NULL,
    device VARCHAR(120) NOT NULL DEFAULT '',
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_ok_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY push_subscriptions_endpoint (endpoint_hash),
    KEY push_subscriptions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
