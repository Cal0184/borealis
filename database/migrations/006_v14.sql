-- Borealis 1.4 — Migration 006: the guided tour starts by itself only for people who are new.
-- Everyone who already used Borealis before this update is marked as having seen it
-- (they can still open it from the account menu or Perfil › Ayuda).

UPDATE users SET prefs = JSON_SET(CASE WHEN prefs IS NULL OR prefs = '' OR JSON_VALID(prefs) = 0 THEN '{}' ELSE prefs END, '$.tour', 'skipped');
