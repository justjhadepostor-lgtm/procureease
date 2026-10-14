-- ═══════════════════════════════════════════════════════════════
-- ProcureEase — fix the accounts that cannot be opened
--
-- XAMPP › phpMyAdmin › database "procureease" › tab "SQL" › paste all of this › Go.
--
-- What it does (nothing else in the database is touched):
--   1) pu-99-001 (Procurement Unit / Super Admin): password back to password123,
--      active, admin of PU, not locked. Made again if it was deleted.
--   2) Every account still on the old sample password that never worked
--      (e.g. col-01-001, sabm-01-001): password set to password123.
--   3) Every account locked after wrong passwords: unlocked, the count back to 0.
--
-- It does NOT change: anyone's own password, roles, pending / rejected /
-- blocked accounts (those are approved or unblocked on User Management).
--
-- After signing in with password123, change the password right away
-- (Settings › Change password).
-- ═══════════════════════════════════════════════════════════════

-- 1) The Procurement Unit (kept as it is if it is there — it has to exist before the account)
INSERT IGNORE INTO departments (code, name, dept_group, display_order, status)
VALUES ('PU', 'Procurement Unit', 'Support Units', 220, 'active');

UPDATE users
SET password_hash  = '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq',   -- password123
    role           = 'admin',
    dept_code      = 'PU',
    status         = 'active',
    failed_logins  = 0,
    last_failed_at = NULL,
    last_failed_ip = NULL,
    locked_until   = NULL
WHERE username = 'pu-99-001';

-- only if the account no longer exists: make it again
INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code, status)
SELECT 'pu-99-001', '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq', 'George Wilson A. Uy', 'Head, Procurement Unit',
       'procurement@ascot.edu.com', '+63 917 555 0198', 'admin', 'PU', 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'pu-99-001');

-- 2) The old sample password (it matches no password, so these accounts could never sign in)
UPDATE users
SET password_hash  = '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq',   -- password123
    failed_logins  = 0,
    last_failed_at = NULL,
    last_failed_ip = NULL,
    locked_until   = NULL
WHERE password_hash = '$2y$10$0O2LrREnVpXVdBpsjjy.WeaWDIDlnrEIsL/DCyylETyxGSPWWFJ7C';

-- 3) Locked after wrong passwords: unlocked (the same as "🔓 Unlock now" on User Management)
UPDATE users
SET failed_logins  = 0,
    last_failed_at = NULL,
    last_failed_ip = NULL,
    locked_until   = NULL
WHERE failed_logins > 0 OR locked_until IS NOT NULL;

-- The accounts fixed above — they sign in with password123
SELECT username, full_name, role, dept_code, status
FROM users
WHERE password_hash = '$2y$12$rmf/KdCPz3uz.4jIyJnJVusaWsHx7zCLDyBrzloSLqgHsMBsBvFIq'
ORDER BY username;
