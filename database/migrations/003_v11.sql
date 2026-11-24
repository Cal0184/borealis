-- Borealis 1.1 — Migration 003: weekly capacity, time tracking, sprint capacity,
-- project initials history, project backgrounds and test ("ghost") users.

ALTER TABLE workspace_members ADD COLUMN weekly_hours DECIMAL(5,2) NULL AFTER status;
-- @@
ALTER TABLE users ADD COLUMN is_ghost TINYINT(1) NOT NULL DEFAULT 0 AFTER is_instance_admin;
-- @@
ALTER TABLE projects ADD COLUMN cover VARCHAR(20) NOT NULL DEFAULT 'forest' AFTER color;
-- @@
ALTER TABLE tasks ADD COLUMN estimate_hours DECIMAL(7,2) NULL AFTER estimate;
-- @@
ALTER TABLE sprints ADD COLUMN capacity_hours DECIMAL(7,2) NULL AFTER end_date;
-- @@
CREATE TABLE time_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  minutes INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_time_task (task_id, deleted_at),
  KEY ix_time_user (user_id, work_date),
  KEY ix_time_project (project_id, work_date),
  CONSTRAINT fk_time_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE project_key_aliases (
  workspace_id INT UNSIGNED NOT NULL,
  old_key VARCHAR(10) NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id, old_key),
  CONSTRAINT fk_alias_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_alias_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
