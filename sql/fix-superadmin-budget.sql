-- ═══════════════════════════════════════════════════════════════
-- ProcureEase — open the Super Admin and the Budget Office accounts again
--
-- XAMPP › phpMyAdmin › database "procureease" › tab "SQL" › paste all of this › Go.
-- Then sign in:   pu-99-001      /  password123   (Super Admin, Procurement Unit)
--                 budget-01-001  /  password123   (Budget Office)
-- and change the passwords right away (Settings › Change password).
--
-- Only these two accounts are touched: the password, active, not locked,
-- the right role and office. If one of them is gone, it is made again.
-- ═══════════════════════════════════════════════════════════════

-- the two offices (kept as they are if they are there)
INSERT IGNORE INTO departments (code, name, dept_group, display_order, status) VALUES ('PU', 'Procurement Unit', 'Support Units', 220, 'active');
INSERT IGNORE INTO departments (code, name, dept_group, display_order, status) VALUES ('BUDGET-OFC', 'Budget Office', 'Support Units', 210, 'active');

-- Super Admin (Procurement Unit)
UPDATE users
SET password_hash = '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq',   -- password123
    role = 'admin', dept_code = 'PU', status = 'active',
    failed_logins = 0, last_failed_at = NULL, last_failed_ip = NULL, locked_until = NULL
WHERE username = 'pu-99-001';
INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code, status)
SELECT 'pu-99-001', '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq', 'George Wilson A. Uy', 'Head, Procurement Unit',
       'procurement@ascot.edu.com', '+63 917 555 0198', 'admin', 'PU', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'pu-99-001');

-- Budget Office
UPDATE users
SET password_hash = '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq',   -- password123
    role = 'budget', dept_code = 'BUDGET-OFC', status = 'active',
    failed_logins = 0, last_failed_at = NULL, last_failed_ip = NULL, locked_until = NULL
WHERE username = 'budget-01-001';
INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code, status)
SELECT 'budget-01-001', '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq', 'Sherwin Serrano', 'Budget Officer',
       'budget@ascot.edu.com', '', 'budget', 'BUDGET-OFC', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'budget-01-001');

-- what is there now
SELECT username, full_name, role, dept_code, status FROM users ORDER BY username;
