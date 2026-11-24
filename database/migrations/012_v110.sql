-- Borealis 1.10 — Migration 012: links of a task to its Google Drive folder and its GitHub repository.
-- Only adds columns; nothing is deleted or rewritten, and it can run again after an interruption
-- (the migrator skips columns that already exist). NULL = no link, as before.
ALTER TABLE tasks ADD COLUMN drive_url VARCHAR(500) NULL AFTER blocked_reason;
-- @@
ALTER TABLE tasks ADD COLUMN github_url VARCHAR(500) NULL AFTER drive_url;
