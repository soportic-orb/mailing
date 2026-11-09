-- Plataforma de Mailing: esquema inicial.
-- Migracions additives i idempotents (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(10) NOT NULL DEFAULT 'editor',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(100) NOT NULL PRIMARY KEY,
    v MEDIUMTEXT NULL,
    secret TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#ac1966',
    logo_src VARCHAR(255) NULL,
    website VARCHAR(255) NULL,
    company VARCHAR(190) NULL,
    postal_address VARCHAR(500) NULL,
    default_language VARCHAR(5) NOT NULL DEFAULT 'ca',
    mailjet_key TEXT NULL,
    mailjet_secret TEXT NULL,
    default_sender_id INT UNSIGNED NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY projects_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_projects (
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS senders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    reply_to VARCHAR(190) NULL,
    mailjet_id BIGINT UNSIGNED NULL,
    mailjet_status VARCHAR(20) NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY senders_project_email (project_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    `key` VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'text',
    created_at DATETIME NOT NULL,
    UNIQUE KEY fields_project_key (project_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    first_name VARCHAR(120) NOT NULL DEFAULT '',
    last_name VARCHAR(190) NOT NULL DEFAULT '',
    language VARCHAR(5) NOT NULL DEFAULT '',
    status VARCHAR(12) NOT NULL DEFAULT 'subscribed',
    fields MEDIUMTEXT NULL,
    source VARCHAR(60) NULL,
    external_id VARCHAR(120) NULL,
    consent_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    last_open_at DATETIME NULL,
    last_click_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY contacts_project_email (project_id, email),
    KEY contacts_project_status (project_id, status),
    KEY contacts_external (project_id, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    public TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    KEY lists_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS list_contacts (
    list_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'subscribed',
    source VARCHAR(60) NULL,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (list_id, contact_id),
    KEY list_contacts_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    rules MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY segments_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connectors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(10) NOT NULL,
    config MEDIUMTEXT NULL,
    list_id INT UNSIGNED NULL,
    mode VARCHAR(10) NOT NULL DEFAULT 'add',
    interval_minutes INT UNSIGNED NOT NULL DEFAULT 360,
    token_hash VARCHAR(64) NULL,
    token_hint VARCHAR(12) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_status VARCHAR(10) NULL,
    last_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    KEY connectors_project (project_id),
    KEY connectors_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    list_id INT UNSIGNED NULL,
    connector_id INT UNSIGNED NULL,
    filename VARCHAR(255) NOT NULL DEFAULT '',
    total INT UNSIGNED NOT NULL DEFAULT 0,
    created INT UNSIGNED NOT NULL DEFAULT 0,
    updated INT UNSIGNED NOT NULL DEFAULT 0,
    skipped INT UNSIGNED NOT NULL DEFAULT 0,
    removed INT UNSIGNED NOT NULL DEFAULT 0,
    errors MEDIUMTEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY imports_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    doc MEDIUMTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY templates_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    sender_id INT UNSIGNED NULL,
    reply_to VARCHAR(190) NULL,
    doc MEDIUMTEXT NOT NULL,
    html LONGTEXT NULL,
    audience MEDIUMTEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    test_sent_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    recipients INT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(1000) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY campaigns_project_status (project_id, status),
    KEY campaigns_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type VARCHAR(10) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    doc MEDIUMTEXT NOT NULL,
    saved_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY doc_versions_unique (owner_type, owner_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NULL,
    email VARCHAR(190) NOT NULL,
    language VARCHAR(5) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    mailjet_id BIGINT UNSIGNED NULL,
    error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    opened_at DATETIME NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    clicked_at DATETIME NULL,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,
    bounced_at DATETIME NULL,
    bounce_hard TINYINT(1) NOT NULL DEFAULT 0,
    spam_at DATETIME NULL,
    blocked_at DATETIME NULL,
    unsub_at DATETIME NULL,
    UNIQUE KEY messages_campaign_contact (campaign_id, email),
    KEY messages_status (campaign_id, status),
    KEY messages_mailjet (mailjet_id),
    KEY messages_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    type VARCHAR(10) NOT NULL,
    url VARCHAR(1000) NULL,
    detail VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    KEY events_campaign (campaign_id, type),
    KEY events_contact (contact_id),
    KEY events_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(40) NOT NULL,
    project_id INT UNSIGNED NULL,
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    original_name VARCHAR(255) NULL,
    width INT UNSIGNED NOT NULL DEFAULT 0,
    height INT UNSIGNED NOT NULL DEFAULT 0,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY images_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    project_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    detail VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    KEY activity_project (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
