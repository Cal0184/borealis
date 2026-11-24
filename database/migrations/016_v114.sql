-- Borealis 1.14 — Migration 016: notificaciones sin sesión, estimaciones del trabajador, Gantt,
-- trabajos rápidos y auditoría.
-- Only adds columns and tables. The only rows written are copies into the new columns
-- (admin_estimate_hours ← estimate_hours, baseline_* ← the first known dates), so existing data keeps
-- its meaning. It can run again after an interruption: the migrator skips columns and indexes that
-- already exist, the tables use IF NOT EXISTS and the copies only fill NULLs.

-- Each run of the scheduled job (bin/notify.php, /cron/run or the fallback started by a visit):
-- when it started and finished, whether it worked and what it did. Borealis shows it in
-- Administración so a missing cron job is visible instead of silent.
CREATE TABLE IF NOT EXISTS system_jobs (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_ok_at DATETIME NULL,
  last_source VARCHAR(10) NULL,
  last_summary VARCHAR(255) NULL,
  last_error VARCHAR(500) NULL,
  runs INT UNSIGNED NOT NULL DEFAULT 0,
  cli_ok_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- One row per notice and device: sent, waiting for a retry (with the next attempt), failed for good
-- or gone (the browser unsubscribed). The UNIQUE key is what keeps a notice from reaching the same
-- device twice, even when the cron and a visit send at the same moment. subscription_id has no
-- foreign key on purpose: the row stays (as «gone») after the browser drops its subscription.
CREATE TABLE IF NOT EXISTS notification_deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  state ENUM('sending','sent','retry','failed','gone') NOT NULL DEFAULT 'sending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_code SMALLINT NULL,
  last_error VARCHAR(255) NULL,
  next_attempt_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_delivery (notification_id, subscription_id),
  KEY ix_delivery_retry (state, next_attempt_at),
  KEY ix_delivery_user (user_id, created_at),
  KEY ix_delivery_sub (subscription_id),
  CONSTRAINT fk_delivery_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Estimates: the administrator's, the worker's, and the agreed one. estimate_hours stays the
-- agreed (planned) hours that capacity, monitoring, analysis and the Gantt already use.
ALTER TABLE tasks ADD COLUMN admin_estimate_hours DECIMAL(7,2) NULL AFTER estimate_hours;
-- @@
ALTER TABLE tasks ADD COLUMN worker_estimate_hours DECIMAL(7,2) NULL AFTER admin_estimate_hours;
-- @@
ALTER TABLE tasks ADD COLUMN estimate_review ENUM('none','pending','agreed') NOT NULL DEFAULT 'none' AFTER worker_estimate_hours;
-- @@
UPDATE tasks SET admin_estimate_hours = estimate_hours WHERE admin_estimate_hours IS NULL AND estimate_hours IS NOT NULL;
-- @@
-- Every estimate change with its reason (who, which kind, before and after).
CREATE TABLE IF NOT EXISTS task_estimates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind ENUM('admin','worker','agreed') NOT NULL,
  old_hours DECIMAL(7,2) NULL,
  new_hours DECIMAL(7,2) NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY ix_test_task (task_id, created_at),
  CONSTRAINT fk_test_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_test_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Gantt: milestones, and the original plan (baseline) to compare with the current one.
ALTER TABLE tasks ADD COLUMN is_milestone TINYINT(1) NOT NULL DEFAULT 0 AFTER is_blocked;
-- @@
ALTER TABLE tasks ADD COLUMN baseline_start DATE NULL AFTER start_date;
-- @@
ALTER TABLE tasks ADD COLUMN baseline_due DATE NULL AFTER due_date;
-- @@
UPDATE tasks t SET t.baseline_due = COALESCE((SELECT d.old_due FROM task_due_changes d WHERE d.task_id = t.id AND d.old_due IS NOT NULL ORDER BY d.changed_at, d.id LIMIT 1), t.due_date)
  WHERE t.baseline_due IS NULL AND (t.due_date IS NOT NULL OR EXISTS (SELECT 1 FROM task_due_changes d2 WHERE d2.task_id = t.id AND d2.old_due IS NOT NULL));
-- @@
UPDATE tasks SET baseline_start = start_date WHERE baseline_start IS NULL AND start_date IS NOT NULL;
-- @@
-- Quick work: unplanned work (imprevistos, urgencias) and its kind; one «Trabajos rápidos» project
-- per workspace holds the work that belongs to no project.
ALTER TABLE tasks ADD COLUMN unplanned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_milestone;
-- @@
ALTER TABLE tasks ADD COLUMN work_type VARCHAR(20) NULL AFTER unplanned;
-- @@
ALTER TABLE projects ADD COLUMN kind ENUM('standard','quick') NOT NULL DEFAULT 'standard' AFTER state;
-- @@
-- Audit (Activity log): category, before and after, reason, origin and a hash chain that makes any
-- later change of a stored row detectable. Old rows keep NULL (not covered by the chain).
ALTER TABLE activity ADD COLUMN category VARCHAR(20) NULL AFTER entity_id;
-- @@
ALTER TABLE activity ADD COLUMN before_data TEXT NULL AFTER data;
-- @@
ALTER TABLE activity ADD COLUMN after_data TEXT NULL AFTER before_data;
-- @@
ALTER TABLE activity ADD COLUMN reason VARCHAR(500) NULL AFTER after_data;
-- @@
ALTER TABLE activity ADD COLUMN source VARCHAR(12) NULL AFTER reason;
-- @@
ALTER TABLE activity ADD COLUMN prev_hash CHAR(64) NULL AFTER created_at;
-- @@
ALTER TABLE activity ADD COLUMN row_hash CHAR(64) NULL AFTER prev_hash;
-- @@
ALTER TABLE activity ADD INDEX ix_act_cat (workspace_id, category, created_at);
-- @@
ALTER TABLE activity ADD INDEX ix_act_user (workspace_id, user_id, created_at);
-- @@
-- Head of each workspace's audit chain (locked while a row is appended, so the chain never forks).
CREATE TABLE IF NOT EXISTS audit_chain (
  workspace_id INT UNSIGNED NOT NULL PRIMARY KEY,
  head CHAR(64) NOT NULL,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_achain_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Notifications: a person who activated a device but whose hidden «push» preference stayed off (the
-- profile form was saved before pressing «Activar», or its submit failed) had every notice dropped
-- silently. Having a subscribed device means wanting notifications on it: turn the flag on for them.
UPDATE users u SET u.prefs = JSON_SET(u.prefs, '$.notify.push', true)
  WHERE u.prefs IS NOT NULL AND JSON_VALID(u.prefs) AND JSON_EXTRACT(u.prefs, '$.notify') IS NOT NULL
    AND JSON_EXTRACT(u.prefs, '$.notify.push') = false AND EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.user_id = u.id);
