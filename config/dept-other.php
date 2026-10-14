<?php
/* ═══════════════════════════════════════════════════════════════
   v99: Registration — "Others" for Department.

   Someone whose department is not on the list picks "Others" and types
   it. The account is kept pending with no department (users.dept_other
   holds what was typed); the Procurement Unit sees it on Pending Users
   and, when approving, puts the account in a department on record
   (one it may first add on Departments).
   =============================================================== */
require_once __DIR__ . '/db.php';

const DEPT_OTHER = '__OTHER__';

/** Adds users.dept_other once (what was typed under "Others"). */
function dept_other_column(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('dept_other', $have, true)) db()->exec('ALTER TABLE users ADD COLUMN dept_other VARCHAR(150) NULL');
    } catch (Throwable $t) {}
}

/** The departments an account can be put in: schools and offices on record, active (as Registration lists them). */
function dept_other_choices(): array {
    try {
        return db()->query("SELECT code, name FROM departments
                             WHERE COALESCE(status, 'active') = 'active' AND (parent_code IS NULL OR parent_code = '')
                          ORDER BY display_order, name")->fetchAll();
    } catch (Throwable $t) {
        return db()->query('SELECT code, name FROM departments ORDER BY display_order, name')->fetchAll();
    }
}
