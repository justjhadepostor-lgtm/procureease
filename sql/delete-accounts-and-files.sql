-- ═══════════════════════════════════════════════════════════════
-- ProcureEase — keep only the Super Admin (Procurement Unit) and the Budget Unit;
--               delete every other account AND its documents
--
-- FIRST make a backup: ProcureEase › Backup & Restore › Create backup.
-- (The documents are deleted for good; that backup is the way back.)
--
-- Then: XAMPP › phpMyAdmin › database "procureease" › tab "SQL" › paste all of this › Go.
--
-- 1) Keeps only: the Super Admin of the Procurement Unit (role Super Admin, office PU)
--    and the Budget Unit (role Budget). Every other account is deleted — end users,
--    Department Admins, and Super Admin accounts outside the Procurement Unit
--    (e.g. col-01-001, sabm-01-001). A copy is kept in the table users_deleted_accounts.
-- 2) Deletes the documents of every other office: PPMP (with its items, archive entries,
--    revision / edit requests), Non-PS lists, Annexes, APP-CSE, Market Scoping (and photos),
--    and their version history.
--    Kept: the documents of the Procurement Unit and the Budget Unit (with their sets).
--    Not touched: departments (their folders stay, empty), budget allocations,
--    deadlines, settings, the Activity Log.
-- ═══════════════════════════════════════════════════════════════

-- ── 1) the accounts: only the Procurement Unit's Super Admin and the Budget Unit stay ──
CREATE TABLE IF NOT EXISTS users_deleted_accounts LIKE users;
INSERT INTO users_deleted_accounts
  SELECT * FROM users WHERE NOT ((COALESCE(role, '') = 'admin' AND COALESCE(dept_code, '') = 'PU') OR COALESCE(role, '') = 'budget');
DELETE FROM users WHERE NOT ((COALESCE(role, '') = 'admin' AND COALESCE(dept_code, '') = 'PU') OR COALESCE(role, '') = 'budget');

-- ── 2) which offices keep their documents ─────────────────────────
DROP TABLE IF EXISTS pe_keep_depts;
CREATE TABLE pe_keep_depts (code VARCHAR(20) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;   -- the same collation as the other tables
-- the office of every account that is left (the Procurement Unit, the Budget Unit)
INSERT IGNORE INTO pe_keep_depts (code)
  SELECT dept_code FROM users WHERE dept_code IS NOT NULL AND dept_code <> '';
-- and its sets (e.g. PU-S2)
INSERT IGNORE INTO pe_keep_depts (code)
  SELECT d.code FROM departments d JOIN users u ON u.dept_code = d.parent_code;

-- ── 3) delete the documents of every other office ─────────────────
-- PPMP
DELETE FROM ppmp_entries      WHERE ppmp_id IN (SELECT id FROM ppmps WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts));
DELETE FROM revision_requests WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM edit_requests     WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM archived_ppmps    WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM deleted_ppmps     WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM ppmps             WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
-- Non-PS Supplies lists
DELETE FROM nonps_items       WHERE submission_id IN (SELECT id FROM nonps_submissions WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts));
DELETE FROM nonps_submissions WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM nonps_admin_items WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
-- Annex
DELETE FROM annex_items       WHERE form_id IN (SELECT id FROM annex_forms WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts));
DELETE FROM annex_forms       WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
-- Market Scoping (and its photos)
CREATE TABLE IF NOT EXISTS market_scoping_photos (id INT PRIMARY KEY AUTO_INCREMENT, scoping_id INT NOT NULL,
  path VARCHAR(255) NOT NULL, caption VARCHAR(500) NULL, sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_scoping (scoping_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELETE FROM market_scoping_photos WHERE scoping_id IN (SELECT id FROM market_scoping WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts));
DELETE FROM market_scoping    WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
-- APP-CSE
DELETE FROM appcse_items      WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
DELETE FROM appcse_header     WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);
-- version history of those documents
DELETE FROM doc_versions      WHERE dept_code NOT IN (SELECT code FROM pe_keep_depts);

DROP TABLE pe_keep_depts;

-- ── 4) what is left ──────────────────────────────────────────────
SELECT 'Account' AS what, username AS name, role AS detail, dept_code FROM users
UNION ALL SELECT 'PPMP',            CONCAT('FY ', fy), status, dept_code FROM ppmps
UNION ALL SELECT 'Non-PS list',     CONCAT('FY ', fy), status, dept_code FROM nonps_submissions
UNION ALL SELECT 'Annex',           CONCAT('FY ', fy, ' Annex ', annex), status, dept_code FROM annex_forms
UNION ALL SELECT 'Market Scoping',  CONCAT('FY ', fy), status, dept_code FROM market_scoping
UNION ALL SELECT 'APP-CSE',         CONCAT('FY ', fy), COALESCE(status, ''), dept_code FROM appcse_header
ORDER BY dept_code, what, name;

-- ───────────────────────────────────────────────────────────────
-- To bring back the ACCOUNTS only:  INSERT INTO users SELECT * FROM users_deleted_accounts;
-- The documents come back only from the backup made before (Backup & Restore).
-- ───────────────────────────────────────────────────────────────
