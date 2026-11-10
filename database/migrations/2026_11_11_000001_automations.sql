-- Automations: visual flows (trigger → emails, waits, conditions, actions)
-- that every enrolled contact walks step by step.

CREATE TABLE IF NOT EXISTS automations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    trigger_type VARCHAR(10) NOT NULL DEFAULT '',
    trigger_config TEXT NULL,
    flow MEDIUMTEXT NOT NULL,
    sender_id INT UNSIGNED NULL,
    reply_to VARCHAR(190) NULL,
    scan_at DATETIME NULL,
    scan_day DATE NULL,
    activated_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY automations_project (project_id, status),
    KEY automations_trigger (status, trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per contact enrolled in an automation ("cycle" lets yearly date
-- triggers enrol the same contact again the next year).
CREATE TABLE IF NOT EXISTS automation_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    automation_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    cycle VARCHAR(10) NOT NULL DEFAULT '',
    step_id VARCHAR(40) NULL,
    waiting TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    next_at DATETIME NULL,
    exit_reason VARCHAR(190) NULL,
    source VARCHAR(60) NULL,
    entered_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    UNIQUE KEY automation_runs_unique (automation_id, contact_id, cycle),
    KEY automation_runs_due (status, next_at),
    KEY automation_runs_step (automation_id, status, step_id),
    KEY automation_runs_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    automation_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    step_id VARCHAR(40) NULL,
    type VARCHAR(10) NOT NULL,
    detail VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    KEY automation_log_automation (automation_id, id),
    KEY automation_log_step (automation_id, step_id, type),
    KEY automation_log_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emails of an automation are campaigns with status "auto" and their automation_id.
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND column_name = 'automation_id');
SET @s := IF(@c = 0, 'ALTER TABLE campaigns ADD COLUMN automation_id INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- A message of an automation email belongs to one run (0 for campaigns), so the
-- same person can get it again in a later cycle.
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'messages' AND column_name = 'run_id');
SET @s := IF(@c = 0, 'ALTER TABLE messages ADD COLUMN run_id INT UNSIGNED NOT NULL DEFAULT 0', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'messages_campaign_run');
SET @s := IF(@c = 0, 'ALTER TABLE messages ADD UNIQUE KEY messages_campaign_run (campaign_id, email, run_id)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'messages_campaign_contact');
SET @s := IF(@c > 0, 'ALTER TABLE messages DROP INDEX messages_campaign_contact', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
