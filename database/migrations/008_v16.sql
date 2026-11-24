-- Borealis 1.6 — Migration 008: personal tasks, calendars, events and meetings, Google connections.
-- Only new tables; nothing existing changes. Every statement can run twice (IF NOT EXISTS),
-- so an interrupted update can simply be repeated.

-- Personal tasks («Mis tareas»): private to their owner, independent of workspaces and projects.
CREATE TABLE IF NOT EXISTS personal_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  position DOUBLE NOT NULL DEFAULT 0,
  google_account_id INT UNSIGNED NULL,
  google_list_id VARCHAR(190) NULL,
  google_etag VARCHAR(190) NULL,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_plist_user (user_id, deleted_at),
  UNIQUE KEY uq_plist_google (user_id, google_list_id),
  CONSTRAINT fk_plist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS personal_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  priority ENUM('none','low','medium','high') NOT NULL DEFAULT 'none',
  due_date DATE NULL,
  completed_at DATETIME NULL,
  position DOUBLE NOT NULL DEFAULT 0,
  google_task_id VARCHAR(190) NULL,
  google_etag VARCHAR(190) NULL,
  google_updated DATETIME NULL,
  pushed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_ptask_list (list_id, deleted_at, position),
  KEY ix_ptask_user_due (user_id, due_date),
  UNIQUE KEY uq_ptask_google (list_id, google_task_id),
  CONSTRAINT fk_ptask_list FOREIGN KEY (list_id) REFERENCES personal_lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptask_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptask_parent FOREIGN KEY (parent_id) REFERENCES personal_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Google accounts linked by a person. Tokens are encrypted by the application (never sent to the browser).
CREATE TABLE IF NOT EXISTS google_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  google_email VARCHAR(190) NULL,
  scopes VARCHAR(1000) NOT NULL DEFAULT '',
  access_token TEXT NULL,
  refresh_token TEXT NULL,
  expires_at DATETIME NULL,
  status ENUM('active','error','revoked') NOT NULL DEFAULT 'active',
  last_error VARCHAR(500) NULL,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_gacc_user (user_id),
  CONSTRAINT fk_gacc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Calendars of a workspace. «personal»: only its owner. «shared»: workspace members with default_access,
-- plus explicit calendar_members. source «google» mirrors a calendar of the owner's Google account.
CREATE TABLE IF NOT EXISTS calendars (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  owner_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#10B981',
  kind ENUM('personal','shared') NOT NULL DEFAULT 'shared',
  default_access ENUM('none','read','edit') NOT NULL DEFAULT 'read',
  purpose VARCHAR(30) NULL,
  project_id INT UNSIGNED NULL,
  team_id INT UNSIGNED NULL,
  source ENUM('internal','google') NOT NULL DEFAULT 'internal',
  google_account_id INT UNSIGNED NULL,
  google_calendar_id VARCHAR(255) NULL,
  google_access VARCHAR(20) NULL,
  sync_token VARCHAR(500) NULL,
  synced_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_cal_ws (workspace_id, deleted_at),
  UNIQUE KEY uq_cal_google (workspace_id, owner_id, google_calendar_id),
  CONSTRAINT fk_cal_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_cal_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS calendar_members (
  calendar_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  access ENUM('read','edit','manage') NOT NULL DEFAULT 'read',
  PRIMARY KEY (calendar_id, user_id),
  CONSTRAINT fk_calm_cal FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
  CONSTRAINT fk_calm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- Events and meetings. Times are stored in UTC; all-day events use the dates only.
CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendar_id INT UNSIGNED NOT NULL,
  kind ENUM('event','meeting') NOT NULL DEFAULT 'event',
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  location VARCHAR(255) NULL,
  video_url VARCHAR(500) NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  recurrence ENUM('none','daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'none',
  recurrence_until DATE NULL,
  reminder_minutes INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  team_id INT UNSIGNED NULL,
  status ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  created_by INT UNSIGNED NULL,
  google_event_id VARCHAR(255) NULL,
  google_etag VARCHAR(190) NULL,
  google_updated DATETIME NULL,
  google_recurring TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_event_cal_time (calendar_id, deleted_at, starts_at),
  KEY ix_event_project (project_id),
  UNIQUE KEY uq_event_google (calendar_id, google_event_id),
  CONSTRAINT fk_event_cal FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS event_participants (
  event_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, user_id),
  KEY ix_evp_user (user_id),
  CONSTRAINT fk_evp_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_evp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
