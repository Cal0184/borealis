-- Borealis 1.15 · Master Log, navigation, saved views, watchers, decisions and automations.
-- Only adds columns and tables: no existing data is changed or removed.

-- Master Log: the result of what was recorded (NULL = done; 'error' or 'denied' otherwise).
-- Rows written before 1.15 keep NULL, and NULL does not change their hash, so the chain stays valid.
ALTER TABLE activity ADD COLUMN result VARCHAR(10) NULL AFTER source;
-- @@
ALTER TABLE activity ADD INDEX ix_act_time (workspace_id, created_at);
-- @@
ALTER TABLE activity ADD INDEX ix_act_task (task_id, id);
-- @@
-- Retention: when old rows are moved to an archive file (bin/audit-archive.php), the chain keeps
-- going from the last archived row, so «Comprobar integridad» still checks everything that is left.
ALTER TABLE audit_chain ADD COLUMN base_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_id;
-- @@
ALTER TABLE audit_chain ADD COLUMN base_hash CHAR(64) NULL AFTER base_id;
-- @@
-- Favorites and recent items (sidebar and quick search)
CREATE TABLE IF NOT EXISTS user_favorites (
  user_id INT UNSIGNED NOT NULL,
  workspace_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(12) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  position SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, workspace_id, entity_type, entity_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS user_recent (
  user_id INT UNSIGNED NOT NULL,
  workspace_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(12) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  visited_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, workspace_id, entity_type, entity_id),
  KEY ix_recent (user_id, workspace_id, visited_at),
  CONSTRAINT fk_recent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_recent_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Saved filters of the task list (personal, or shared with the workspace)
CREATE TABLE IF NOT EXISTS saved_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  page VARCHAR(20) NOT NULL DEFAULT 'tasks',
  name VARCHAR(80) NOT NULL,
  query VARCHAR(1000) NOT NULL,
  shared TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_views (workspace_id, page, user_id),
  CONSTRAINT fk_view_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_view_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Watchers: people who get notices about a task without being its assignee
CREATE TABLE IF NOT EXISTS task_watchers (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (task_id, user_id),
  KEY ix_watch_user (user_id),
  CONSTRAINT fk_watch_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_watch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- A comment can be marked as a decision (shown in the project's decision log)
ALTER TABLE comments ADD COLUMN is_decision TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
-- @@
-- Automations: «when this happens, if this is true, do this»
CREATE TABLE IF NOT EXISTS automation_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  template VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  params TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_run_at DATETIME NULL,
  runs INT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_auto_ws (workspace_id, enabled),
  CONSTRAINT fk_auto_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_auto_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_auto_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- One row per thing a rule did, so the same event never makes it act twice
CREATE TABLE IF NOT EXISTS automation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id INT UNSIGNED NOT NULL,
  dedupe_key VARCHAR(120) NOT NULL,
  result VARCHAR(10) NOT NULL DEFAULT 'ok',
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_auto_run (rule_id, dedupe_key),
  CONSTRAINT fk_autorun_rule FOREIGN KEY (rule_id) REFERENCES automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
