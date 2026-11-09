-- Public signup pages with a form that adds people to lists.

CREATE TABLE IF NOT EXISTS forms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    intro TEXT NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'ca',
    list_ids VARCHAR(500) NOT NULL DEFAULT '[]',
    choose_lists TINYINT(1) NOT NULL DEFAULT 0,
    fields TEXT NULL,
    button_text VARCHAR(80) NOT NULL DEFAULT '',
    success_text VARCHAR(1000) NULL,
    consent_text VARCHAR(1000) NULL,
    privacy_url VARCHAR(500) NULL,
    redirect_url VARCHAR(500) NULL,
    double_optin TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY forms_slug (slug),
    KEY forms_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_signups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    data TEXT NOT NULL,
    token CHAR(40) NOT NULL,
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    contact_id INT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY form_signups_token (token),
    KEY form_signups_form (form_id, confirmed_at),
    KEY form_signups_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
