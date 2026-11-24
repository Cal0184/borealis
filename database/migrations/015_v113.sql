-- Borealis 1.13 — Migration 015: análisis (Fase C).
-- Only adds tables and indexes; nothing is deleted or rewritten, no existing row changes.
-- It can run again: tables use IF NOT EXISTS and the migrator skips indexes that already exist.

-- Results of the analysis kept between visits. Each row carries a fingerprint of the data it was
-- computed from; when tasks, hours, sprints or capacity change the fingerprint no longer matches
-- and the result is recalculated (it is never served stale). Safe to empty at any time.
CREATE TABLE IF NOT EXISTS analysis_cache (
  workspace_id INT UNSIGNED NOT NULL,
  cache_key CHAR(64) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  computed_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id, cache_key),
  KEY ix_acache_time (computed_at),
  CONSTRAINT fk_acache_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Scenarios applied for real from «Análisis › Escenarios»: who, when, and every value before and
-- after, so they can be audited and reverted.
CREATE TABLE IF NOT EXISTS scenario_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  sprint_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  changes MEDIUMTEXT NOT NULL,
  state ENUM('applied','reverted') NOT NULL DEFAULT 'applied',
  created_at DATETIME NOT NULL,
  reverted_at DATETIME NULL,
  reverted_by INT UNSIGNED NULL,
  KEY ix_scen_project (project_id, created_at),
  CONSTRAINT fk_scen_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_scen_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_scen_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE SET NULL,
  CONSTRAINT fk_scen_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_scen_rev FOREIGN KEY (reverted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Indexes for the historical queries (finished tasks per project and period, due-date history per
-- period, block events per project).
ALTER TABLE tasks ADD INDEX ix_task_completed (project_id, completed_at);
-- @@
ALTER TABLE task_due_changes ADD INDEX ix_tdc_changed (changed_at);
-- @@
ALTER TABLE activity ADD INDEX ix_act_entity (entity_type, entity_id, action);
