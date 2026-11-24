-- Borealis 1.7 — Migration 009: profile photos, weekly hours set when inviting, and sprint
-- capacity kept for finished sprints. Only adds columns; it can run again after an interruption
-- (the migrator skips a column that already exists).

ALTER TABLE users ADD COLUMN avatar_photo VARCHAR(40) NULL AFTER avatar_color;
-- @@
ALTER TABLE invitations ADD COLUMN weekly_hours DECIMAL(5,2) NULL AFTER team_id;
-- @@
-- 'manual': set by hand for that sprint. 'history': the hours the sprint had, kept when the
-- person's weekly hours changed after the sprint ended (history is not recalculated).
ALTER TABLE sprint_capacity ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'manual' AFTER hours;
-- @@
-- 1.7: a project is seen only by its members (and its teams). Before, anyone in the workspace could
-- open projects marked «visible to the workspace», so some people have tasks or hours in projects
-- they never joined. They become collaborators of those projects, so nobody loses access to their
-- own work. Only active people of the same workspace; repeating it adds nothing.
INSERT IGNORE INTO project_members (project_id, user_id, role_id, added_at)
SELECT DISTINCT w.project_id, w.user_id, r.id, UTC_TIMESTAMP()
FROM (
  SELECT t.project_id, t.assignee_id AS user_id FROM tasks t WHERE t.assignee_id IS NOT NULL AND t.deleted_at IS NULL
  UNION SELECT t.project_id, ta.user_id FROM task_assignees ta JOIN tasks t ON t.id = ta.task_id WHERE t.deleted_at IS NULL
  UNION SELECT te.project_id, te.user_id FROM time_entries te WHERE te.deleted_at IS NULL
) w
JOIN projects p ON p.id = w.project_id
JOIN workspace_members wm ON wm.workspace_id = p.workspace_id AND wm.user_id = w.user_id AND wm.status = 'active'
JOIN roles r ON r.workspace_id = p.workspace_id AND r.scope = 'project' AND r.role_key = 'contributor'
WHERE NOT EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = w.user_id)
  AND NOT EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = p.team_id AND tm.user_id = w.user_id)
  AND NOT EXISTS (SELECT 1 FROM project_teams pt JOIN team_members tm2 ON tm2.team_id = pt.team_id WHERE pt.project_id = p.id AND tm2.user_id = w.user_id);
