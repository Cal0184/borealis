-- Borealis 1.12 — Migration 014: monitoring (Fase B).
-- Only adds columns and tables; nothing is deleted or rewritten. It can run again after an
-- interruption: the migrator skips columns and indexes that already exist and the tables use
-- IF NOT EXISTS. Existing rows keep NULL in the new columns (= «not recorded», never invented).

-- Real start of the work (first time the task entered an «in progress» column), who blocked it and
-- when, and the remaining work the responsible person may write down.
ALTER TABLE tasks ADD COLUMN started_at DATETIME NULL AFTER start_date;
-- @@
ALTER TABLE tasks ADD COLUMN blocked_at DATETIME NULL AFTER blocked_reason;
-- @@
ALTER TABLE tasks ADD COLUMN blocked_by INT UNSIGNED NULL AFTER blocked_at;
-- @@
ALTER TABLE tasks ADD COLUMN remaining_hours DECIMAL(7,2) NULL AFTER estimate_hours;
-- @@
-- Every change of a task's due date (reprogramming history).
CREATE TABLE IF NOT EXISTS task_due_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  old_due DATE NULL,
  new_due DATE NULL,
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NOT NULL,
  KEY ix_tdc_task (task_id, changed_at),
  CONSTRAINT fk_tdc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tdc_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Explanations of a delay or of an estimate deviation, with the corrective action decided.
CREATE TABLE IF NOT EXISTS task_explanations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  kind ENUM('delay','deviation') NOT NULL DEFAULT 'delay',
  reason VARCHAR(30) NOT NULL,
  comment VARCHAR(1000) NULL,
  corrective_action VARCHAR(1000) NULL,
  due_date DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_texp_task (task_id, created_at),
  CONSTRAINT fk_texp_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_texp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- What a sprint committed to when it started (planned = 1) and what was added later (planned = 0).
-- planned NULL = sprint started before 1.12 (unknown). outcome is filled when the sprint closes.
CREATE TABLE IF NOT EXISTS sprint_commitments (
  sprint_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  planned TINYINT(1) NULL,
  estimate_hours DECIMAL(7,2) NULL,
  added_at DATETIME NOT NULL,
  outcome ENUM('done','moved','pending') NULL,
  PRIMARY KEY (sprint_id, task_id),
  KEY ix_scommit_task (task_id),
  CONSTRAINT fk_scommit_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
  CONSTRAINT fk_scommit_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Rule-based monitoring alerts: one row per rule and subject (dedupe_key), reopened instead of
-- duplicated, resolved automatically when the condition disappears.
CREATE TABLE IF NOT EXISTS monitor_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  rule VARCHAR(30) NOT NULL,
  dedupe_key VARCHAR(120) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  project_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  sprint_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  message VARCHAR(255) NOT NULL,
  explanation VARCHAR(1000) NULL,
  state ENUM('open','ack','resolved') NOT NULL DEFAULT 'open',
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  ack_by INT UNSIGNED NULL,
  ack_at DATETIME NULL,
  ack_note VARCHAR(500) NULL,
  resolved_at DATETIME NULL,
  escalated_at DATETIME NULL,
  UNIQUE KEY uq_monitor_key (workspace_id, dedupe_key),
  KEY ix_monitor_state (workspace_id, state, severity),
  KEY ix_monitor_project (project_id),
  CONSTRAINT fk_monitor_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_monitor_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_monitor_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_monitor_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
  CONSTRAINT fk_monitor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Last run of the rules per workspace (from cron or from a visit), with its error if it failed.
CREATE TABLE IF NOT EXISTS monitor_runs (
  workspace_id INT UNSIGNED NOT NULL PRIMARY KEY,
  ran_at DATETIME NOT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(500) NULL,
  CONSTRAINT fk_mrun_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Tasks already in an active sprint: recorded with planned = NULL (unknown whether committed at the start).
INSERT IGNORE INTO sprint_commitments (sprint_id, task_id, planned, estimate_hours, added_at)
  SELECT t.sprint_id, t.id, NULL, t.estimate_hours, NOW() FROM tasks t JOIN sprints s ON s.id = t.sprint_id
  WHERE s.state = 'active' AND t.deleted_at IS NULL AND t.parent_id IS NULL;
