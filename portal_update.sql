-- ============================================================
--  IRIEBE TOWN SECONDARY SCHOOL (UBE) — RESULT PORTAL UPGRADE
--  File: portal_update.sql   (File 10 of 14)
--
--  Adds the two tables the PDF result system needs:
--    1. portal_students — one row per student: exam number,
--       full name, and the confidential PIN (generated once
--       and reused every term, so a parent keeps ONE PIN).
--    2. result_pdfs     — one row per uploaded result PDF
--       (per student, per term, per session).
--
--  HOW TO RUN (once only):
--    1. Open phpMyAdmin (cPanel → phpMyAdmin on your hosting).
--    2. In the left sidebar, click the SAME database your
--       portal already uses — the one named in
--       portal/config.php as DB_NAME.
--    3. Click the "SQL" tab, paste this whole file, press "Go".
--    4. You should see both tables created (or a note that
--       they already exist — that is fine, nothing is wiped).
-- ============================================================

-- If phpMyAdmin has no database selected, uncomment and edit
-- this line to match the DB_NAME in portal/config.php:
-- USE your_database_name;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Students and their confidential PINs
--    A row is created automatically the first time the admin
--    uploads a result for an exam number the system has not
--    seen before — the PIN is generated at that moment.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portal_students (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_no       VARCHAR(40)  NOT NULL,              -- e.g. ITSS/2026/014
  student_name  VARCHAR(120) NOT NULL,              -- read from the PDF filename
  pin           VARCHAR(20)  NOT NULL,              -- e.g. 4821-7356
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_no (exam_no),
  UNIQUE KEY uq_pin (pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 2. Uploaded result PDFs — one row per student per term.
--    The PDF files themselves live on disk inside
--    portal/results/<class-folder>/ (created by File 11);
--    this table records where each one is and whose it is.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS result_pdfs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_no        VARCHAR(40)  NOT NULL,             -- matches portal_students.exam_no
  class_name     VARCHAR(40)  NOT NULL DEFAULT '',  -- e.g. JSS 2A (the upload folder)
  term           VARCHAR(40)  NOT NULL DEFAULT '',  -- e.g. First Term
  session        VARCHAR(20)  NOT NULL DEFAULT '',  -- e.g. 2025/2026
  stored_file    VARCHAR(200) NOT NULL,             -- e.g. jss-2a/itss-2026-014-first-term.pdf
  original_file  VARCHAR(200) NOT NULL DEFAULT '',  -- the file's name as uploaded
  uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_result (exam_no, term, session),    -- one PDF per student per term
  KEY idx_class (class_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- OPTIONAL demo student for testing — the same exam number and
-- PIN as the Kira preview. Uncomment the two lines if you want
-- it, or add it later from the admin screen and delete after
-- testing:
-- ------------------------------------------------------------
-- INSERT INTO portal_students (exam_no, student_name, pin)
-- VALUES ('ITSS/2026/014', 'Chidera Okonkwo', '4821-7356');

-- Done. Next file: portal/admin_pdfs.php (File 11).
