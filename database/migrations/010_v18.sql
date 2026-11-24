-- Borealis 1.8 — Migration 010: mobile notifications (Web Push), project tools («Todo a mano»)
-- and the Discord and GitHub integrations. Only creates tables and adds columns; it can run again
-- after an interruption (tables use IF NOT EXISTS, the migrator skips existing columns and indexes).

-- Devices where a person allowed notifications (one row per browser/installation).
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) NULL,
  auth VARCHAR(100) NULL,
  device VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  last_ok_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- When a notification was offered to the person's devices (null: not yet, or not for push).
ALTER TABLE notifications ADD COLUMN pushed_at DATETIME NULL AFTER read_at;
-- @@
ALTER TABLE notifications ADD KEY ix_notif_push (pushed_at, created_at);
-- @@
-- «Todo a mano»: direct links of a project (Drive folder, Discord channel, repository, any page).
-- A link is only a link: Borealis does not read those services through it.
CREATE TABLE IF NOT EXISTS project_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  kind ENUM('drive','discord','github','calendar','other') NOT NULL DEFAULT 'other',
  title VARCHAR(120) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  position DOUBLE NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_plink_project (project_id, position),
  CONSTRAINT fk_plink_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Real integrations through each service's API (Discord webhook, GitHub REST). Secrets (webhook
-- address, token) are stored encrypted with storage/app.key, never in clear.
CREATE TABLE IF NOT EXISTS project_integrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  provider ENUM('discord','github') NOT NULL,
  target VARCHAR(255) NULL,
  secret TEXT NULL,
  events VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_ok_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  cache MEDIUMTEXT NULL,
  cached_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_pint (project_id, provider),
  CONSTRAINT fk_pint_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- One delivery per event to an external service (a sprint starting is announced once).
CREATE TABLE IF NOT EXISTS integration_deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  integration_id INT UNSIGNED NOT NULL,
  dedupe_key VARCHAR(120) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_idel (integration_id, dedupe_key),
  CONSTRAINT fk_idel_int FOREIGN KEY (integration_id) REFERENCES project_integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
