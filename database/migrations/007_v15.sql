-- Borealis 1.5 — Migration 007: «Capacidad agotada — Trabajo pendiente».
-- Only new tables; nothing existing changes. Every statement can run twice (IF NOT EXISTS),
-- so an interrupted update can simply be repeated.

CREATE TABLE IF NOT EXISTS capacity_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  capacity_minutes INT UNSIGNED NOT NULL,
  logged_minutes INT UNSIGNED NOT NULL,
  pending_tasks INT UNSIGNED NOT NULL,
  detected_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  resolution VARCHAR(20) NULL,
  resolution_note VARCHAR(500) NULL,
  UNIQUE KEY uq_cap_alert (workspace_id, user_id, week_start),
  KEY ix_cap_alert_open (workspace_id, status),
  CONSTRAINT fk_cap_alert_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_alert_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS capacity_alert_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id INT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NULL,
  detail VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_cap_ev_alert (alert_id, id),
  CONSTRAINT fk_cap_ev_alert FOREIGN KEY (alert_id) REFERENCES capacity_alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS capacity_extras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  minutes INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL,
  authorized_by INT UNSIGNED NULL,
  alert_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_cap_extra (workspace_id, user_id, week_start),
  CONSTRAINT fk_cap_extra_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_extra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_extra_by FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cap_extra_alert FOREIGN KEY (alert_id) REFERENCES capacity_alerts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
