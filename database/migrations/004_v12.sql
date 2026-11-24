-- Borealis 1.2 — Migration 004: teams as project participants, several people or a team
-- responsible for a task, and "polivalente" (flexible) projects and teams whose people can be
-- moved between tasks from the board.

CREATE TABLE project_teams (
  project_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL,
  PRIMARY KEY (project_id, team_id),
  KEY ix_pt_team (team_id),
  CONSTRAINT fk_pt_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE task_assignees (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL,
  PRIMARY KEY (task_id, user_id),
  KEY ix_ta_user (user_id),
  CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
ALTER TABLE tasks ADD COLUMN team_id INT UNSIGNED NULL AFTER assignee_id;
-- @@
ALTER TABLE tasks ADD CONSTRAINT fk_task_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL;
-- @@
ALTER TABLE projects ADD COLUMN is_flexible TINYINT(1) NOT NULL DEFAULT 0 AFTER cover;
-- @@
ALTER TABLE teams ADD COLUMN is_flexible TINYINT(1) NOT NULL DEFAULT 0 AFTER color;
