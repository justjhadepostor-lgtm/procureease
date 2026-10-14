-- ═══════════════════════════════════════════════════════════════
-- ProcureEase — new names for the Super Admin and the Budget Office
--
-- XAMPP › phpMyAdmin › database "procureease" › tab "SQL" › paste all of this › Go.
--
--   pu-99-001      (Super Admin, Procurement Unit)  →  George Wilson A. Uy
--   budget-01-001  (Budget Office)                  →  Sherwin Serrano
--
-- Only the name is changed (first name, middle initial, last name, and the
-- full name shown in the system). The username, password, email, role and
-- office stay as they are. Both can still change their name later on
-- My Profile, like every other account.
-- ═══════════════════════════════════════════════════════════════

UPDATE users
SET first_name = 'George Wilson', middle_initial = 'A.', last_name = 'Uy',
    full_name  = 'George Wilson A. Uy'
WHERE username = 'pu-99-001';

UPDATE users
SET first_name = 'Sherwin', middle_initial = '', last_name = 'Serrano',
    full_name  = 'Sherwin Serrano'
WHERE username = 'budget-01-001';

-- the two accounts now
SELECT username, full_name, first_name, middle_initial, last_name, role, dept_code
FROM users WHERE username IN ('pu-99-001', 'budget-01-001');
