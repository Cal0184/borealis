-- Borealis 1.3 — Migration 005: hours each person has available in each sprint.
-- Without a row, a person's availability is their weekly hours × the sprint's length.

CREATE TABLE sprint_capacity (
  sprint_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  hours DECIMAL(7,2) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (sprint_id, user_id),
  KEY ix_sc_user (user_id),
  CONSTRAINT fk_sc_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
