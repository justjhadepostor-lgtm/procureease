-- ─────────────────────────────────────────────────────────────
--  ProcureEase — Department management patch
--  Adds the lifecycle columns the Super Admin's Departments page uses.
--
--  YOU DO NOT NORMALLY NEED TO RUN THIS.
--  config/admin-schema.php applies the same changes automatically the
--  first time any admin page is opened. This file is here for anyone who
--  prefers to patch the database by hand, or to re-check an install.
--
--  Safe to run any number of times: every statement checks first, so
--  nothing is duplicated and NO DATA IS TOUCHED. No department row is
--  deleted or altered beyond gaining a status of 'active'.
--
--  How to apply:
--    1. Open phpMyAdmin
--    2. Select the "procureease" database
--    3. Click the "SQL" tab
--    4. Paste this entire file, then click Go
-- ─────────────────────────────────────────────────────────────

-- ─── status: 'active' (offered on Register Account) or 'inactive' ───
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'status') > 0,
  'SELECT ''departments.status already present'' AS note',
  'ALTER TABLE departments ADD COLUMN status ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── who created the department, and when ───
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'created_at') > 0,
  'SELECT ''departments.created_at already present'' AS note',
  'ALTER TABLE departments ADD COLUMN created_at DATETIME NULL'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'created_by') > 0,
  'SELECT ''departments.created_by already present'' AS note',
  'ALTER TABLE departments ADD COLUMN created_by VARCHAR(150) NULL'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── who deactivated it, and when ───
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'deactivated_at') > 0,
  'SELECT ''departments.deactivated_at already present'' AS note',
  'ALTER TABLE departments ADD COLUMN deactivated_at DATETIME NULL'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'deactivated_by') > 0,
  'SELECT ''departments.deactivated_by already present'' AS note',
  'ALTER TABLE departments ADD COLUMN deactivated_by VARCHAR(150) NULL'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── Index for the active-department lookup on Register Account ───
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND INDEX_NAME = 'idx_dept_status') > 0,
  'SELECT ''idx_dept_status already present'' AS note',
  'CREATE INDEX idx_dept_status ON departments (status)'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── Every department that existed before this patch is a live one ───
UPDATE departments SET status = 'active' WHERE status IS NULL OR status = '';

-- ─── Confirmation ───
SELECT COUNT(*) AS total_departments,
       SUM(status = 'active')   AS active,
       SUM(status = 'inactive') AS deactivated
  FROM departments;
