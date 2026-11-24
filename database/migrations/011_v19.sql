-- Borealis 1.9 — Migration 011: hour of delivery for tasks, Borealis → Google Calendar export,
-- e-mail delivery of notifications. Only creates tables and adds columns; nothing is deleted or
-- rewritten, and it can run again after an interruption (IF NOT EXISTS; the migrator skips columns
-- and indexes that already exist).

-- Optional hour of the due date, in the installation's time zone (the same instant for the whole team;
-- each person sees it and is reminded in their own zone). NULL = the whole day, as before.
ALTER TABLE tasks ADD COLUMN due_time TIME NULL AFTER due_date;
-- @@
-- Where each person sends their Borealis work in Google Calendar (one calendar per person) and what.
ALTER TABLE google_accounts ADD COLUMN export_calendar_id VARCHAR(255) NULL AFTER last_sync_at;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_calendar_name VARCHAR(190) NULL AFTER export_calendar_id;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_options VARCHAR(500) NULL AFTER export_calendar_name;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_state ENUM('off','ok','pending','error') NOT NULL DEFAULT 'off' AFTER export_options;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_error VARCHAR(500) NULL AFTER export_state;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_synced_at DATETIME NULL AFTER export_error;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_retry_at DATETIME NULL AFTER export_synced_at;
-- @@
ALTER TABLE google_accounts ADD COLUMN export_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER export_retry_at;
-- @@
-- One row per Google event that Borealis created. Borealis only ever changes or removes events listed
-- here (and Google's copy also carries extendedProperties.private.borealis = 1). Events of anyone else
-- are never read for this, changed or removed.
CREATE TABLE IF NOT EXISTS google_exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  ref_type ENUM('task','sprint','project') NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  google_calendar_id VARCHAR(255) NOT NULL,
  google_event_id VARCHAR(255) NOT NULL,
  content_hash CHAR(40) NOT NULL,
  synced_at DATETIME NOT NULL,
  UNIQUE KEY uq_gexp_ref (user_id, ref_type, ref_id),
  KEY ix_gexp_ref (ref_type, ref_id),
  CONSTRAINT fk_gexp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
-- When a notification was sent by e-mail (null: not yet, or not for e-mail).
ALTER TABLE notifications ADD COLUMN emailed_at DATETIME NULL AFTER pushed_at;
-- @@
ALTER TABLE notifications ADD KEY ix_notif_mail (emailed_at, created_at);
