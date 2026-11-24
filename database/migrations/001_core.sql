-- Borealis 1.0 — Migration 001: core schema
-- Engine: MySQL 8 / MariaDB 10.4+, utf8mb4.
-- Each statement is separated by a line containing only "-- @@".

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(120) NOT NULL,
  handle VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  title VARCHAR(120) NULL,
  avatar_color CHAR(7) NOT NULL DEFAULT '#10B981',
  prefs TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_instance_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_workspace_id INT UNSIGNED NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_handle (handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_login_email (email, created_at),
  KEY ix_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE workspaces (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  settings TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  scope ENUM('workspace','project') NOT NULL,
  role_key VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  rank_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_roles_key (workspace_id, scope, role_key),
  CONSTRAINT fk_roles_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission VARCHAR(60) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE workspace_members (
  workspace_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  joined_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id, user_id),
  KEY ix_wm_user (user_id),
  CONSTRAINT fk_wm_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_wm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wm_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  invited_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_inv_token (token_hash),
  KEY ix_inv_ws (workspace_id),
  CONSTRAINT fk_inv_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE priorities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  color CHAR(7) NOT NULL,
  weight SMALLINT NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_prio_ws (workspace_id, weight),
  CONSTRAINT fk_prio_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  color CHAR(7) NOT NULL DEFAULT '#10B981',
  archived_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_teams_ws (workspace_id),
  CONSTRAINT fk_teams_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE team_members (
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  team_role ENUM('coordinator','member') NOT NULL DEFAULT 'member',
  responsibility VARCHAR(160) NULL,
  joined_at DATETIME NOT NULL,
  PRIMARY KEY (team_id, user_id),
  KEY ix_tm_user (user_id),
  CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  team_role_id INT UNSIGNED NULL,
  project_key VARCHAR(10) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  color CHAR(7) NOT NULL DEFAULT '#10B981',
  visibility ENUM('workspace','private') NOT NULL DEFAULT 'workspace',
  state ENUM('active','on_hold','completed') NOT NULL DEFAULT 'active',
  lead_user_id INT UNSIGNED NULL,
  start_date DATE NULL,
  due_date DATE NULL,
  task_seq INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  archived_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_project_key (workspace_id, project_key),
  KEY ix_projects_team (team_id),
  CONSTRAINT fk_projects_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_projects_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_lead FOREIGN KEY (lead_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE project_members (
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL,
  PRIMARY KEY (project_id, user_id),
  KEY ix_pm_user (user_id),
  CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE project_modules (
  project_id INT UNSIGNED NOT NULL,
  module_key VARCHAR(30) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (project_id, module_key),
  CONSTRAINT fk_mod_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE statuses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  category ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
  color CHAR(7) NOT NULL DEFAULT '#94A3B8',
  position INT NOT NULL DEFAULT 0,
  wip_limit SMALLINT UNSIGNED NULL,
  KEY ix_status_project (project_id, position),
  CONSTRAINT fk_status_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE labels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#0EA5E9',
  UNIQUE KEY uq_label (project_id, name),
  CONSTRAINT fk_label_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE epics (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  color CHAR(7) NOT NULL DEFAULT '#8B5CF6',
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_epic_project (project_id),
  CONSTRAINT fk_epic_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE sprints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  goal TEXT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  state ENUM('planned','active','completed') NOT NULL DEFAULT 'planned',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  committed_count INT NULL,
  completed_count INT NULL,
  committed_points DECIMAL(8,2) NULL,
  completed_points DECIMAL(8,2) NULL,
  result_notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_sprint_project (project_id, state),
  CONSTRAINT fk_sprint_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  number INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NULL,
  status_id INT UNSIGNED NOT NULL,
  priority_id INT UNSIGNED NULL,
  assignee_id INT UNSIGNED NULL,
  reporter_id INT UNSIGNED NULL,
  epic_id INT UNSIGNED NULL,
  sprint_id INT UNSIGNED NULL,
  estimate DECIMAL(6,2) NULL,
  start_date DATE NULL,
  due_date DATE NULL,
  position DOUBLE NOT NULL DEFAULT 0,
  backlog_position DOUBLE NOT NULL DEFAULT 0,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  blocked_reason VARCHAR(255) NULL,
  completed_at DATETIME NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_task_number (project_id, number),
  KEY ix_task_status (project_id, status_id, position),
  KEY ix_task_assignee (assignee_id, deleted_at),
  KEY ix_task_due (due_date),
  KEY ix_task_parent (parent_id),
  KEY ix_task_sprint (sprint_id),
  CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_status FOREIGN KEY (status_id) REFERENCES statuses(id),
  CONSTRAINT fk_task_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_prio FOREIGN KEY (priority_id) REFERENCES priorities(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_epic FOREIGN KEY (epic_id) REFERENCES epics(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE task_labels (
  task_id INT UNSIGNED NOT NULL,
  label_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (task_id, label_id),
  CONSTRAINT fk_tl_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_label FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE task_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_task_id INT UNSIGNED NOT NULL,
  target_task_id INT UNSIGNED NOT NULL,
  link_type ENUM('relates','blocks','duplicates') NOT NULL DEFAULT 'relates',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_link (source_task_id, target_task_id, link_type),
  CONSTRAINT fk_link_src FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_link_tgt FOREIGN KEY (target_task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  edited_at DATETIME NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_comment_task (task_id, created_at),
  CONSTRAINT fk_comment_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_folder_project (project_id),
  CONSTRAINT fk_folder_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_folder_parent FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NULL,
  folder_id INT UNSIGNED NULL,
  uploader_id INT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(40) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_files_project (project_id, folder_id),
  KEY ix_files_task (task_id),
  CONSTRAINT fk_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_files_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
  CONSTRAINT fk_files_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_doc_project (project_id),
  CONSTRAINT fk_doc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  workspace_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  actor_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  message VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  dedupe_key VARCHAR(80) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_notif_user (user_id, read_at, created_at),
  UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  entity_type VARCHAR(30) NOT NULL,
  entity_id INT UNSIGNED NULL,
  summary VARCHAR(255) NOT NULL,
  data TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_act_ws (workspace_id, created_at),
  KEY ix_act_project (project_id, created_at),
  KEY ix_act_task (task_id, created_at),
  CONSTRAINT fk_act_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
