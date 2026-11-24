-- Borealis 1.11 — Migration 013: «Planificado» state for projects and the due date a task had
-- when it was completed (to tell «terminada a tiempo» from «terminada fuera de plazo»).
-- Only adds: one value at the start of the project state list (existing values and rows are
-- untouched) and one nullable column. It can run again after an interruption: MODIFY with the
-- same list is a no-op, the migrator skips a column that already exists, and the backfill only
-- fills rows that are still empty.
ALTER TABLE projects MODIFY COLUMN state ENUM('planned','active','on_hold','completed') NOT NULL DEFAULT 'active';
-- @@
ALTER TABLE tasks ADD COLUMN completed_due_date DATE NULL AFTER completed_at;
-- @@
UPDATE tasks SET completed_due_date = due_date WHERE completed_at IS NOT NULL AND completed_due_date IS NULL AND due_date IS NOT NULL;
