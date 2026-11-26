      REFERENCES subjects (id),
  CONSTRAINT fk_res_term    FOREIGN KEY (term_id)
      REFERENCES terms (id),
  CONSTRAINT fk_res_teacher FOREIGN KEY (teacher_id)
      REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- SETTINGS (editable from the admin dashboard, no code)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  k  VARCHAR(50) PRIMARY KEY,
  v  VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (k, v) VALUES
  ('school_name_1', 'Iriebe Town'),
  ('school_name_2', 'Secondary School (UBE)'),
  ('current_session', '2026/2027'),
  ('ca1_max',  '20'),
  ('ca2_max',  '20'),
  ('exam_max', '60'),
  ('portal_open', '1');   -- '0' hides ALL results from students

-- ------------------------------------------------------------
-- AUDIT LOG (who did what, when — accountability)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS audit_log;
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  user_name   VARCHAR(120) DEFAULT NULL,
  action      VARCHAR(80)  NOT NULL,
  detail      TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE. Next: portal/config.php holds your database password.
-- Default staff login (auto-created on first run):
--     username: admin     password: admin123   (change it!)
-- ============================================================
