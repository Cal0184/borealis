-- Borealis 1.0 — Migration 002: record of imports from Borealis v0.2.x (file-based storage).
-- Prevents importing the same data twice into a workspace.

CREATE TABLE legacy_imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  source_hash CHAR(64) NOT NULL,
  source_version VARCHAR(20) NOT NULL,
  summary TEXT NOT NULL,
  imported_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_legacy_source (workspace_id, source_hash),
  CONSTRAINT fk_legacy_ws FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
  CONSTRAINT fk_legacy_user FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
