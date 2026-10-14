<?php
/**
 * Ensures the three admin-feature tables (activity_logs, settings, backups)
 * exist in the database. Called automatically by the pages that need them,
 * so the admin never has to manually import a SQL patch.
 *
 * Uses CREATE TABLE IF NOT EXISTS + INSERT IGNORE so it's safe to run
 * on every page load — a no-op after the first successful run.
 *
 * Returns true if all three tables now exist; false on failure.
 */
require_once __DIR__ . '/db.php';

/**
 * The approving official stops being a fixed name — cleared once.
 *
 * Every signatory is now read from the account that holds the role. But
 * document formats are pinned per fiscal year on the first save, so on a
 * system where the Procurement Unit has ever opened and saved the Non-PS
 * tab, the name that used to ship with the code was written into that
 * year's format and would keep winning over the live account.
 *
 * These two pinned values are cleared once — and only where they still
 * match the old shipped text word for word, so a name somebody typed in
 * on purpose is never touched. It is marked done in settings afterwards,
 * so it cannot run a second time and undo a later edit. If anything is
 * not ready yet (the format store is created on first use), nothing is
 * marked and it simply runs on a later page load.
 *
 * It stands on its own rather than sitting inside ensure_admin_tables()
 * so that an unrelated statement failing there cannot quietly skip it.
 */
function clear_shipped_signatory_defaults(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        $already = $pdo->query("SELECT value FROM settings
                                 WHERE key_name = 'sig_defaults_cleared'")->fetchColumn();
        if ($already) return;

        if (function_exists('ensure_doc_format_table')) ensure_doc_format_table();

        $pdo->prepare("DELETE FROM doc_formats
                        WHERE doc_type = 'nonps' AND key_name = 'sig_head_name'
                          AND value = 'GEORGE WILSON UY'")->execute();
        $pdo->prepare("DELETE FROM doc_formats
                        WHERE doc_type = 'nonps' AND key_name = 'sig_head_position'
                          AND value = 'AO V/Head, Procurement Unit'")->execute();
        $pdo->prepare("INSERT INTO settings (key_name, value)
                       VALUES ('sig_defaults_cleared', '1')")->execute();
    } catch (Throwable $t) { /* not ready yet — runs on a later load */ }
}

/**
 * ppmps.head_custom: 1 when the PPMP's "Approved by" was typed on purpose.
 * Every PPMP before this column starts at 0, so each one shows the Vice
 * President its department answers to — which is what clears the name the
 * old backfill had written into all of them.
 */
function ensure_ppmp_head_custom(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM ppmps")->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('head_custom', $cols, true)) {
            db()->exec("ALTER TABLE ppmps ADD COLUMN head_custom TINYINT(1) NOT NULL DEFAULT 0");
        }
        // Who at the Procurement Unit last edited a PPMP after Budget
        // approved it, and when — shown on the PPMP's review timeline.
        if ($cols && !in_array('pu_edited_at', $cols, true)) {
            db()->exec("ALTER TABLE ppmps ADD COLUMN pu_edited_at DATETIME NULL");
        }
        if ($cols && !in_array('pu_edited_by', $cols, true)) {
            db()->exec("ALTER TABLE ppmps ADD COLUMN pu_edited_by VARCHAR(150) NULL");
        }
        // The budget breakdown under the PPMP table (config/ppmp-expense.php).
        // NULL = the four standard lines, all computed.
        if ($cols && !in_array('expense_summary', $cols, true)) {
            db()->exec("ALTER TABLE ppmps ADD COLUMN expense_summary TEXT NULL");
        }
    } catch (Throwable $t) { /* not ready yet — runs on a later load */ }
}

/**
 * v39: the archive flag on every document that carries its own, and
 * market_scoping.from_form (the document was started from the year's
 * Market Scoping form on the Document Format page). Checked column by
 * column so it is safe on every page load and on any install.
 */
function ensure_v39_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $want = [
        'nonps_submissions' => ['archived' => 'TINYINT(1) DEFAULT 0', 'archived_at' => 'TIMESTAMP NULL', 'archived_by' => 'VARCHAR(100) NULL'],
        'annex_forms'       => ['archived' => 'TINYINT(1) DEFAULT 0', 'archived_at' => 'TIMESTAMP NULL', 'archived_by' => 'VARCHAR(100) NULL'],
        'market_scoping'    => ['archived' => 'TINYINT(1) DEFAULT 0', 'archived_at' => 'TIMESTAMP NULL', 'archived_by' => 'VARCHAR(100) NULL',
                                'from_form' => 'TINYINT(1) NULL',
                                'signatory_image' => 'VARCHAR(255) NULL',
                                'signatory_caption' => 'VARCHAR(500) NULL'],   // v103: the words that go with the picture
        'appcse_header'     => ['archived' => 'TINYINT(1) DEFAULT 0', 'archived_at' => 'TIMESTAMP NULL', 'archived_by' => 'VARCHAR(100) NULL',
                                // v43: the Budget Unit sends the APP-CSE back on its own (config/appcse-review.php)
                                'revision' => 'TINYINT(1) DEFAULT 0', 'revision_note' => 'TEXT NULL',
                                'revision_by' => 'VARCHAR(100) NULL', 'revision_at' => 'TIMESTAMP NULL'],
    ];
    foreach ($want as $table => $cols) {
        try {
            $have = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            if (!$have) continue;
            foreach ($cols as $c => $def) {
                if (!in_array($c, $have, true)) {
                    try { db()->exec("ALTER TABLE `$table` ADD COLUMN `$c` $def"); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $t) { /* table not created yet — a later load adds them */ }
    }
}

function ensure_admin_tables(): bool {
    try {
        $pdo = db();

        // ─── activity_logs ────────────────────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50),
            full_name VARCHAR(100),
            role VARCHAR(20),
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(40),
            entity_ref VARCHAR(100),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_username (username),
            INDEX idx_action (action)
        ) ENGINE=InnoDB");

        // Seed sample activity only if the table is currently empty
        $count = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address, created_at) VALUES
              ('pu-99-001',   'Ramon Cruz',              'admin', 'Signed in',           'session', '',                'Signed in from web browser.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 MINUTE)),
              ('pu-99-001',   'Ramon Cruz',              'admin', 'Requested revision',  'ppmp',    'HRMU · FY 2027',  'Requested revision on the HRMU FY 2027 PPMP.',           '127.0.0.1', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
              ('hrmu-07-001', 'Rico Bautista',           'user',  'Signed in',           'session', '',                'Signed in from web browser.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
              ('ictu-01-001', 'Maria Reyes',             'user',  'Submitted PPMP',      'ppmp',    'ICTU · FY 2027',  'Submitted the FY 2027 PPMP with 4 procurement entries.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
              ('ictu-01-001', 'Maria Reyes',             'user',  'Saved draft',         'ppmp',    'ICTU · FY 2027',  'Autosaved 3 changes to the draft PPMP.',                 '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
              ('soe-02-001',  'Engr. Juan dela Cruz',    'user',  'Submitted PPMP',      'ppmp',    'SOE · FY 2027',   'Submitted the FY 2027 PPMP with 4 procurement entries.', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
              ('pu-99-001',   'Ramon Cruz',              'admin', 'Requested edit',      'ppmp',    'SOAS · FY 2027',  'Requested edit permission for the SOAS FY 2027 PPMP.',   '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
              ('soas-04-001', 'Malou C. Angara',         'user',  'Granted edit access', 'edit_req','SOAS · FY 2027',  'Granted edit access to the Procurement Unit.',           '127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
              ('gsu-06-001',  'Miguel Torres',           'user',  'Submitted PPMP',      'ppmp',    'GSU · FY 2027',   'Submitted the FY 2027 PPMP.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
              ('lib-08-001',  'Elena Ramos',             'user',  'Signed in',           'session', '',                'Signed in from web browser.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
              ('pu-99-001',   'Ramon Cruz',              'admin', 'Generated report',    'report',  'APP',             'Generated FY 2027 Annual Procurement Plan (XLSX).',      '127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
              ('reg-09-001',  'Ligaya Bautista',         'user',  'Submitted PPMP',      'ppmp',    'REG · FY 2027',   'Submitted the FY 2027 PPMP.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 4 DAY)),
              ('soed-05-001', 'Dr. Cristina Villanueva', 'user',  'Signed in',           'session', '',                'Signed in from web browser.',                            '127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 DAY)),
              ('pu-99-001',   'Ramon Cruz',              'admin', 'Backed up database',  'backup',  'procureease-full','Full database backup generated (13 tables, 289 rows).',  '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 DAY))");
        }

        // ─── settings ─────────────────────────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key_name  VARCHAR(80) PRIMARY KEY,
            value     TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $count = (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO settings (key_name, value) VALUES
              ('active_fy',            '2027'),
              ('institution_name',     'Aurora State College of Technology'),
              ('institution_address',  'Zabali, Baler, Aurora, Philippines · 3200'),
              ('governing_law',        'RA 12009 (New Government Procurement Act)'),
              ('session_timeout_min',  '30'),
              ('auto_save_interval',   '800'),
              ('password_min_length',  '6'),
              ('notify_on_submission', '1'),
              ('notify_on_revision',   '1'),
              ('backup_schedule',      'weekly'),
              ('theme_page',           '#CFE4F0'),
              ('theme_primary',        '#4A7AAD'),
              ('theme_accent',         '#CFE4F0')");
        }

        // ─── backups ──────────────────────────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            filename VARCHAR(200) NOT NULL,
            size_bytes BIGINT,
            tables_count INT,
            rows_count INT,
            created_by VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notes TEXT
        ) ENGINE=InnoDB");

        $count = (int)$pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO backups (filename, size_bytes, tables_count, rows_count, created_by, created_at, notes) VALUES
              ('procureease-2026-07-14.sql', 45824, 13, 289, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 7  DAY), 'Weekly automatic backup'),
              ('procureease-2026-07-07.sql', 41892, 13, 264, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 14 DAY), 'Weekly automatic backup'),
              ('procureease-2026-06-30.sql', 38102, 12, 240, 'pu-99-001', DATE_SUB(NOW(), INTERVAL 21 DAY), 'Weekly automatic backup')");
        }

        // ─── email_otps (for registration + password reset verification) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_otps (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(10) NOT NULL,
            purpose VARCHAR(20) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_lookup (email, code, purpose)
        ) ENGINE=InnoDB");

        // v110: expires_at must not move when the row is changed. As the first TIMESTAMP column, MySQL/MariaDB
        // gave it "ON UPDATE CURRENT_TIMESTAMP", so counting a wrong code against the newest code reset that
        // code's expiry to "now" and the right code was refused as expired. DATETIME has no such rule.
        try {
            $__col = $pdo->query("SHOW COLUMNS FROM email_otps LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
            if ($__col && stripos((string)($__col['Type'] ?? ''), 'datetime') === false) {
                $pdo->exec("ALTER TABLE email_otps MODIFY expires_at DATETIME NOT NULL");
            }
        } catch (Throwable $__t) {}

        // ─── archived_ppmps (fully-approved PPMPs moved to archive) ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS archived_ppmps (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ppmp_id INT NOT NULL,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            archived_by VARCHAR(100),
            reason VARCHAR(200),
            UNIQUE KEY unique_ppmp (ppmp_id),
            INDEX idx_dept (dept_code),
            INDEX idx_fy (fy)
        ) ENGINE=InnoDB");

        // ─── announcements (admin-posted messages) ───────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            posted_by VARCHAR(100),
            audience VARCHAR(20) DEFAULT 'all',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active (is_active, created_at)
        ) ENGINE=InnoDB");

        $count = (int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO announcements (title, body, posted_by, audience, is_active, created_at) VALUES
              ('FY 2027 PPMP consolidation deadline extended',
               'Departments now have until July 31 to submit their PPMPs. Please coordinate with your Budget Officer if you need additional revisions before that date.',
               'Ramon Cruz', 'all', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
              ('New workflow: Budget review before final approval',
               'Effective immediately, all PPMPs will pass through the Budget Unit for review before reaching the Procurement Unit for final approval. Please check the Help page for the updated flow.',
               'Ramon Cruz', 'all', 1, DATE_SUB(NOW(), INTERVAL 5 DAY))");
        }

        // ─── user_prefs (per-user preferences like dark mode, contrast) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (
            user_id INT NOT NULL,
            key_name VARCHAR(50) NOT NULL,
            value VARCHAR(200),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, key_name)
        ) ENGINE=InnoDB");

        // ─── notifications (in-app bell + email) ─────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT,
            link VARCHAR(200),
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB");



        // ─── user_reports (issue reports from users) ─────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            username VARCHAR(50),
            full_name VARCHAR(100),
            dept_code VARCHAR(20),
            category VARCHAR(30),
            subject VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            status ENUM('open','in_progress','resolved') DEFAULT 'open',
            admin_reply TEXT,
            replied_by VARCHAR(100),
            replied_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB");

        // ─── dept_budgets (Alloted Budget set by admin/budget per dept-FY) ───
        $pdo->exec("CREATE TABLE IF NOT EXISTS dept_budgets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            alloted_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
            travel_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
            training_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
            supply_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
            equipment_budget DECIMAL(30,2) NOT NULL DEFAULT 0,
            notes TEXT,
            set_by VARCHAR(100),
            set_role VARCHAR(20),
            set_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_dept_fy (dept_code, fy),
            INDEX idx_fy (fy)
        ) ENGINE=InnoDB");

        // Widen budget allocation fields so the Set control has no small fixed cap.
        // This migration is safe for existing installations and preserves current amounts.
        foreach (['alloted_budget', 'travel_budget', 'training_budget', 'supply_budget', 'equipment_budget'] as $budgetCol) {
            try { $pdo->exec("ALTER TABLE dept_budgets MODIFY COLUMN `$budgetCol` DECIMAL(30,2) NOT NULL DEFAULT 0"); } catch (Throwable $t) {}
        }

        // Category-specific allotments and spend classification (safe for existing installs).
        foreach ([
            'travel_budget' => "DECIMAL(30,2) NOT NULL DEFAULT 0",
            'training_budget' => "DECIMAL(30,2) NOT NULL DEFAULT 0",
            'supply_budget' => "DECIMAL(30,2) NOT NULL DEFAULT 0",
            'equipment_budget' => "DECIMAL(30,2) NOT NULL DEFAULT 0",
        ] as $col => $definition) {
            try { $have = $pdo->query('SHOW COLUMNS FROM dept_budgets')->fetchAll(PDO::FETCH_COLUMN); if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE dept_budgets ADD COLUMN `$col` $definition"); } catch (Throwable $t) {}
        }
        try { $ec = $pdo->query('SHOW COLUMNS FROM ppmp_entries')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $t) { $ec = []; }
        try { if ($ec && !in_array('budget_category', $ec, true)) $pdo->exec("ALTER TABLE ppmp_entries ADD COLUMN budget_category ENUM('travel','training','supply','equipment') NOT NULL DEFAULT 'training'"); } catch (Throwable $t) {}

        // ─── submission_deadlines (admin-set cutoff per fiscal year) ───
        $pdo->exec("CREATE TABLE IF NOT EXISTS submission_deadlines (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fy INT NOT NULL,
            deadline_date DATE NOT NULL,
            note TEXT,
            set_by VARCHAR(100),
            reminder_interval_days INT NOT NULL DEFAULT 2,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fy (fy),
            INDEX idx_deadline (deadline_date)
        ) ENGINE=InnoDB");
        // Safe migration for existing installs
        try { $pdo->exec("ALTER TABLE submission_deadlines ADD COLUMN reminder_interval_days INT NOT NULL DEFAULT 2"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN avatar_pos VARCHAR(20) NULL DEFAULT '50% 50%'"); } catch (Throwable $t) {}

        // ─── submission_reminders (tracks who was reminded when) ─────
        $pdo->exec("CREATE TABLE IF NOT EXISTS submission_reminders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            last_reminded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reminder_count INT DEFAULT 1,
            UNIQUE KEY uniq_user_fy (user_id, fy),
            INDEX idx_dept (dept_code)
        ) ENGINE=InnoDB");

        // ─── account_deletion_requests (user asks, admin approves) ───
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_deletion_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            username VARCHAR(50),
            full_name VARCHAR(100),
            dept_code VARCHAR(20),
            reason TEXT,
            status ENUM('pending','approved','denied') DEFAULT 'pending',
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decided_at TIMESTAMP NULL,
            decided_by VARCHAR(100),
            UNIQUE KEY uniq_pending (user_id, status),
            INDEX idx_status (status)
        ) ENGINE=InnoDB");

        // ─── username_change_requests (user asks, the Procurement Unit decides) ─
        // A username is institutional and is quoted verbatim in activity_logs,
        // so it is never self-service. The Procurement Unit sets the final one.
        $pdo->exec("CREATE TABLE IF NOT EXISTS username_change_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            current_username VARCHAR(50),
            requested_username VARCHAR(50),
            approved_username VARCHAR(50) NULL,
            full_name VARCHAR(100),
            dept_code VARCHAR(20),
            reason TEXT,
            status ENUM('pending','approved','denied') DEFAULT 'pending',
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decided_at TIMESTAMP NULL,
            decided_by VARCHAR(100),
            decision_note VARCHAR(255) NULL,
            INDEX idx_status (status),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB");

        // ─── deleted_ppmps (recoverable trash — admin deletes here first) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_ppmps (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ppmp_id INT NOT NULL,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            snapshot LONGTEXT,
            entry_count INT DEFAULT 0,
            total_amount DECIMAL(14,2) DEFAULT 0,
            deleted_by VARCHAR(100),
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dept (dept_code),
            INDEX idx_fy (fy)
        ) ENGINE=InnoDB");

        // ─── nonps_submissions (List of Non-PS Supplies sent to Procurement) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS nonps_submissions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            status ENUM('draft','submitted','revision','approved') DEFAULT 'draft',
            submitted_by VARCHAR(100),
            submitted_at TIMESTAMP NULL,
            reviewed_by VARCHAR(100),
            reviewed_at TIMESTAMP NULL,
            review_note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_dept_fy (dept_code, fy),
            INDEX idx_status (status)
        ) ENGINE=InnoDB");

        // Items for each Non-PS submission
        $pdo->exec("CREATE TABLE IF NOT EXISTS nonps_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            submission_id INT NOT NULL,
            item_no INT DEFAULT 0,
            particulars TEXT,
            unit VARCHAR(50),
            quantity INT DEFAULT 0,
            unit_cost DECIMAL(14,2) DEFAULT 0,
            total_cost DECIMAL(14,2) DEFAULT 0,
            source_category VARCHAR(20) NULL,
            INDEX idx_submission (submission_id)
        ) ENGINE=InnoDB");
        // Safe migration for existing installs
        try { $pdo->exec("ALTER TABLE nonps_items ADD COLUMN source_category VARCHAR(20) NULL"); } catch (Throwable $t) {}

        // ─── nonps_submissions archive support (safe migrations) ─
        // ─── Archive flags for every document type ────────────
        // PPMPs are tracked in archived_ppmps; Non-PS, Market Scoping, Annex
        // and APP-CSE carry their own flag. Nothing is ever moved or copied —
        // the record stays put and the flag only records which fiscal years
        // are finished, so the Archive can group them by year.
        foreach (['market_scoping', 'annex_forms', 'appcse_header'] as $__t) {
            try { $pdo->exec("ALTER TABLE `$__t` ADD COLUMN archived TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `$__t` ADD COLUMN archived_at TIMESTAMP NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `$__t` ADD COLUMN archived_by VARCHAR(100) NULL"); } catch (Throwable $e) {}
        }
        // One-off catch-up of the Archive, and the names as they are written (v103) — see the functions below
        pe_archive_catchup_once($pdo);
        pe_name_case_once($pdo);

        try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived TINYINT(1) DEFAULT 0"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_at TIMESTAMP NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_by VARCHAR(100) NULL"); } catch (Throwable $t) {}

        // ─── nonps_admin_items (Admin's editable consolidated Non-PS per FY per dept) ─
        // This is a per-department, per-FY editable overlay on top of PPMP-sourced Non-PS items.
        // Rows here override the auto-computed rows from PPMPs. Admin can also add manual rows
        // (source_kind = 'manual') that aren't tied to any PPMP entry.
        $pdo->exec("CREATE TABLE IF NOT EXISTS nonps_admin_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fy INT NOT NULL,
            dept_code VARCHAR(20) NOT NULL,
            source_key VARCHAR(255) NOT NULL,
            source_kind ENUM('ppmp','manual') NOT NULL DEFAULT 'ppmp',
            source_category VARCHAR(20) NULL,
            particulars TEXT,
            unit VARCHAR(50),
            quantity INT DEFAULT 0,
            unit_cost DECIMAL(14,2) DEFAULT 0,
            total_cost DECIMAL(14,2) DEFAULT 0,
            display_order INT DEFAULT 0,
            deleted TINYINT(1) DEFAULT 0,
            updated_by VARCHAR(100),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fy_dept_source (fy, dept_code, source_key),
            INDEX idx_fy_dept (fy, dept_code)
        ) ENGINE=InnoDB");

        // Add unit_cost to ppmp_entries if it doesn't exist (safe)
        try { $pdo->exec("ALTER TABLE ppmp_entries ADD COLUMN unit_cost DECIMAL(14,2) DEFAULT 0 AFTER quantity"); } catch (Throwable $t) {}

        // ─── fund_pools (total available money per fund source per FY, set by Budget Unit) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS fund_pools (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fy INT NOT NULL,
            fund_source VARCHAR(60) NOT NULL,
            total_amount DECIMAL(14,2) DEFAULT 0,
            notes TEXT,
            set_by VARCHAR(100),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fy_source (fy, fund_source)
        ) ENGINE=InnoDB");

        // ─── archived_ppmps (soft-archive of completed PPMPs) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS archived_ppmps (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ppmp_id INT NOT NULL,
            dept_code VARCHAR(20),
            fy INT,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            archived_by VARCHAR(50),
            reason TEXT,
            INDEX idx_ppmp (ppmp_id)
        ) ENGINE=InnoDB");

        // ─── announcements (posted by admin, visible to all users) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            posted_by VARCHAR(50),
            audience VARCHAR(20) DEFAULT 'all',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $c = (int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
        if ($c === 0) {
            $pdo->exec("INSERT INTO announcements (title, body, posted_by, audience) VALUES
              ('FY 2027 PPMP submission deadline', 'All departments are reminded to submit their FY 2027 Project Procurement Management Plans on or before August 30, 2026. Late submissions will be endorsed to the Executive Office.', 'pu-99-001', 'all'),
              ('New NGPA (RA 12009) guidelines released', 'The Procurement Unit has released updated guidance based on the New Government Procurement Act. Please review the attached memo before your next PPMP revision.', 'pu-99-001', 'all')");
        }

        // ─── user_prefs (per-user preferences: dark mode, contrast, etc) ─
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (
            user_id INT NOT NULL,
            key_name VARCHAR(50) NOT NULL,
            value VARCHAR(80),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, key_name)
        ) ENGINE=InnoDB");

        // ─── PPMPs workflow extensions ────────────────────────
        // Widen the status enum to support the budget → admin approval chain
        try {
            $pdo->exec("ALTER TABLE ppmps MODIFY status ENUM('draft','submitted','budget_approved','approved') DEFAULT 'draft'");
        } catch (Throwable $t) { /* already updated */ }

        // Add columns to track budget and admin approvals
        $cols = $pdo->query("SHOW COLUMNS FROM ppmps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('budget_reviewed_at', $cols)) {
            $pdo->exec("ALTER TABLE ppmps ADD COLUMN budget_reviewed_at TIMESTAMP NULL,
                        ADD COLUMN budget_reviewed_by VARCHAR(100) NULL,
                        ADD COLUMN budget_note TEXT NULL,
                        ADD COLUMN admin_reviewed_at TIMESTAMP NULL,
                        ADD COLUMN admin_reviewed_by VARCHAR(100) NULL,
                        ADD COLUMN admin_note TEXT NULL");
        }
        // Printable-form fields on the PPMP header
        if (!in_array('ppmp_no', $cols)) {
            $pdo->exec("ALTER TABLE ppmps ADD COLUMN ppmp_no VARCHAR(20) DEFAULT NULL,
                        ADD COLUMN is_final TINYINT(1) DEFAULT 0,
                        ADD COLUMN head_name VARCHAR(120) DEFAULT NULL,
                        ADD COLUMN head_position VARCHAR(120) DEFAULT NULL,
                        ADD COLUMN date_submitted_head VARCHAR(50) DEFAULT NULL");
        }

        // Printable-form fields per entry (quantity, pre-proc conference, docs)
        $entryCols = $pdo->query("SHOW COLUMNS FROM ppmp_entries")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('quantity', $entryCols)) {
            $pdo->exec("ALTER TABLE ppmp_entries ADD COLUMN quantity VARCHAR(120) DEFAULT 'as per schedule',
                        ADD COLUMN pre_conference VARCHAR(3) DEFAULT 'No',
                        ADD COLUMN supporting_docs VARCHAR(200) DEFAULT NULL");

            // Backfill supporting_docs by category on existing entries
            $pdo->exec("UPDATE ppmp_entries SET supporting_docs = 'Training Proposal, RIS'      WHERE category = 'training'   AND (supporting_docs IS NULL OR supporting_docs = '')");
            $pdo->exec("UPDATE ppmp_entries SET supporting_docs = 'TOR, Company Profile'        WHERE category = 'consulting' AND (supporting_docs IS NULL OR supporting_docs = '')");
            $pdo->exec("UPDATE ppmp_entries SET supporting_docs = 'Technical Specifications'    WHERE category = 'nonps'      AND (supporting_docs IS NULL OR supporting_docs = '')");
            $pdo->exec("UPDATE ppmp_entries SET supporting_docs = 'APP-CSE Form'                WHERE category = 'appcse'     AND (supporting_docs IS NULL OR supporting_docs = '')");
        }

        // A new PPMP starts with its date and the FINAL box filled in.
        // This used to write "MAYREEN V. AMAZONA, DIT / Vice President for
        // Academic Affairs" into the "Approved by" of every PPMP that had
        // none — Budget's and Procurement's included. "Approved by" now comes
        // from the Document Format page (ppmp_approved_by()), so no name is
        // written here. Keyed on the date a new PPMP has never had, so it
        // still runs once per new PPMP, as before.
        try {
            $pdo->exec("UPDATE ppmps SET date_submitted_head = date_prepared,
                                          is_final = 1
                         WHERE date_submitted_head IS NULL");
        } catch (Throwable $t) { /* columns may not exist yet on very first run */ }

        // ─── Budget account (role='budget') ───────────────────
        // Add the role to the users.role enum if it's currently a smaller set
        try {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','dept_admin','budget','admin') DEFAULT 'user'");
        } catch (Throwable $t) { /* already ok */ }

        // ─── Extend user_reports: add 'closed' status + resolved_at/by ─
        try {
            $pdo->exec("ALTER TABLE user_reports MODIFY status ENUM('open','in_progress','resolved','closed') DEFAULT 'open'");
        } catch (Throwable $t) { /* already ok */ }
        try { $pdo->exec("ALTER TABLE user_reports ADD COLUMN resolved_at TIMESTAMP NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE user_reports ADD COLUMN resolved_by VARCHAR(100) NULL"); } catch (Throwable $t) {}

        // Ensure the seed BUDGET department exists (so the budget user has a home dept)
        try {
            $pdo->exec("INSERT IGNORE INTO departments (code, name, dept_group, display_order) VALUES
                ('BUDGET-OFC', 'Budget Unit', 'Support Units', 71)");
        } catch (Throwable $t) { /* if the row already exists, fine */ }

        // ─── Department sub-units / programs (e.g. SOED majors, SOE programs) ───
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS dept_units (
                id INT PRIMARY KEY AUTO_INCREMENT,
                dept_code VARCHAR(20) NOT NULL,
                name VARCHAR(150) NOT NULL,
                display_order INT DEFAULT 100,
                INDEX idx_dept (dept_code),
                UNIQUE KEY uniq_dept_unit (dept_code, name)
            ) ENGINE=InnoDB");

            // Seed sub-units only if the table is empty (idempotent)
            $hasUnits = (int)$pdo->query("SELECT COUNT(*) FROM dept_units")->fetchColumn();
            if ($hasUnits === 0) {
                $seedUnits = [
                    // School of Education — majors
                    ['SOED', 'Major in Filipino', 10],
                    ['SOED', 'Major in English', 20],
                    ['SOED', 'Major in Science', 30],
                    ['SOED', 'Major in Mathematics', 40],
                    // School of Engineering — programs
                    ['SOE', 'Civil Engineering', 10],
                    ['SOE', 'Mechanical Engineering', 20],
                    ['SOE', 'Electrical Engineering', 30],
                ];
                $insU = $pdo->prepare("INSERT IGNORE INTO dept_units (dept_code, name, display_order) VALUES (?, ?, ?)");
                foreach ($seedUnits as $su) { $insU->execute($su); }
            }
        } catch (Throwable $t) { /* table exists / seed done */ }

        // Add sub_unit column to users so a member can belong to a specific program
        try { $pdo->exec("ALTER TABLE users ADD COLUMN sub_unit VARCHAR(150) NULL AFTER dept_code"); } catch (Throwable $t) {}

        // ─── APP-CSE consolidated editor (admin-only) ───
        // Fixed PS-DBM catalogue (in config/appcse-catalogue.php); we only store
        // the monthly quantities the admin enters, keyed by FY + item code.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS appcse_qty (
                id INT PRIMARY KEY AUTO_INCREMENT,
                fy INT NOT NULL,
                item_code VARCHAR(60) NOT NULL,
                `jan` DECIMAL(12,2) DEFAULT 0, `feb` DECIMAL(12,2) DEFAULT 0, `mar` DECIMAL(12,2) DEFAULT 0,
                `apr` DECIMAL(12,2) DEFAULT 0, `may` DECIMAL(12,2) DEFAULT 0, `jun` DECIMAL(12,2) DEFAULT 0,
                `jul` DECIMAL(12,2) DEFAULT 0, `aug` DECIMAL(12,2) DEFAULT 0, `sep` DECIMAL(12,2) DEFAULT 0,
                `oct` DECIMAL(12,2) DEFAULT 0, `nov` DECIMAL(12,2) DEFAULT 0, `dec` DECIMAL(12,2) DEFAULT 0,
                unit_price DECIMAL(14,2) DEFAULT 0,
                updated_by VARCHAR(150) DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_fy_code (fy, item_code)
            ) ENGINE=InnoDB");
        } catch (Throwable $t) {}

        // APP-CSE agency header info (typed in the editor, shown on print) — one row per FY
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS appcse_header (
                fy INT NOT NULL,
                dept_code VARCHAR(20) NOT NULL DEFAULT '',
                department VARCHAR(150) DEFAULT '',
                region VARCHAR(120) DEFAULT '',
                address VARCHAR(255) DEFAULT '',
                agency_code VARCHAR(120) DEFAULT '',
                contact_person VARCHAR(150) DEFAULT '',
                position VARCHAR(150) DEFAULT '',
                org_type VARCHAR(150) DEFAULT '',
                email VARCHAR(150) DEFAULT '',
                telephone VARCHAR(120) DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (fy, dept_code)
            ) ENGINE=InnoDB");
        } catch (Throwable $t) {}
        // Existing installs: add the newly-editable agency fields if missing
        try { $pdo->exec("ALTER TABLE appcse_header ADD COLUMN department VARCHAR(150) DEFAULT '' AFTER fy"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE appcse_header ADD COLUMN total_items INT NULL AFTER telephone"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE appcse_header ADD COLUMN region VARCHAR(120) DEFAULT '' AFTER department"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE appcse_header ADD COLUMN address VARCHAR(255) DEFAULT '' AFTER region"); } catch (Throwable $t) {}

        // ─── APP-CSE is per department ────────────────────────
        /* ─── Fund names need room ──────────────────────────────
           ppmp_entries.fund was VARCHAR(20). "Income Generating Project" is
           25 characters, so it would have been truncated on save and then
           matched no pool and no report. Widened once, guarded by the
           column's current length so this is a no-op on every later load. */
        foreach ([['ppmp_entries', 'fund',        "VARCHAR(60) DEFAULT 'GAA'"],
                  ['ppmps',        'primary_fund', "VARCHAR(60) DEFAULT 'GAA'"],
                  ['fund_pools',   'fund_source',  'VARCHAR(60) NOT NULL']] as $__f) {
            [$__tbl, $__col, $__spec] = $__f;
            try {
                $__now = null;
                foreach ($pdo->query("SHOW COLUMNS FROM `$__tbl`")->fetchAll() as $__c) {
                    if (($__c['Field'] ?? '') === $__col) { $__now = (string)($__c['Type'] ?? ''); break; }
                }
                if ($__now !== null && preg_match('/varchar\((\d+)\)/i', $__now, $__m)
                    && (int)$__m[1] < 60) {
                    $pdo->exec("ALTER TABLE `$__tbl` MODIFY `$__col` $__spec");
                }
            } catch (Throwable $t) { /* table not there yet — created below */ }
        }

        // Every department keeps its own APP-CSE on the same form, so both
        // tables are keyed by (fy, dept_code) rather than fy alone. Rows that
        // already exist were the Procurement Unit's, so they are handed to it.
        try {
            $__cols = $pdo->query("SHOW COLUMNS FROM appcse_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('dept_code', $__cols, true)) {
                $pdo->exec("ALTER TABLE appcse_items ADD COLUMN dept_code VARCHAR(20) NOT NULL DEFAULT '' AFTER fy");
                $pdo->exec("CREATE INDEX idx_appcse_items_dept ON appcse_items (fy, dept_code)");
                // Hand the existing institution-wide rows to the Procurement Unit
                $__pu = (string)($pdo->query("SELECT dept_code FROM users WHERE role = 'admin' AND dept_code IS NOT NULL LIMIT 1")->fetchColumn() ?: 'PU');
                $pdo->prepare("UPDATE appcse_items SET dept_code = ? WHERE dept_code = ''")->execute([$__pu]);
            }
        } catch (Throwable $t) { /* table is created below on a fresh install */ }

        try {
            $__cols = $pdo->query("SHOW COLUMNS FROM appcse_header")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('dept_code', $__cols, true)) {
                // fy was the primary key on its own; the pair is the key now
                try { $pdo->exec("ALTER TABLE appcse_header DROP PRIMARY KEY"); } catch (Throwable $e) {}
                $pdo->exec("ALTER TABLE appcse_header ADD COLUMN dept_code VARCHAR(20) NOT NULL DEFAULT '' AFTER fy");
                $__pu = (string)($pdo->query("SELECT dept_code FROM users WHERE role = 'admin' AND dept_code IS NOT NULL LIMIT 1")->fetchColumn() ?: 'PU');
                $pdo->prepare("UPDATE appcse_header SET dept_code = ? WHERE dept_code = ''")->execute([$__pu]);
                try { $pdo->exec("ALTER TABLE appcse_header ADD PRIMARY KEY (fy, dept_code)"); } catch (Throwable $e) {}
            }
        } catch (Throwable $t) {}

        // APP-CSE line items — fully editable per FY (code/desc/unit/price/qty),
        // rows can be added or deleted. Seeded once per FY from the PS-DBM
        // catalogue (config/appcse-catalogue.php); after that the DB is the
        // source of truth. See config/appcse-items.php.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS appcse_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                fy INT NOT NULL,
                dept_code VARCHAR(20) NOT NULL DEFAULT '',
                `part` VARCHAR(5) NOT NULL DEFAULT 'I',
                `category` VARCHAR(150) NOT NULL DEFAULT '',
                code VARCHAR(60) DEFAULT '',
                description VARCHAR(255) DEFAULT '',
                unit VARCHAR(40) DEFAULT '',
                unit_price DECIMAL(14,2) DEFAULT 0,
                `jan` DECIMAL(12,2) DEFAULT 0, `feb` DECIMAL(12,2) DEFAULT 0, `mar` DECIMAL(12,2) DEFAULT 0,
                `apr` DECIMAL(12,2) DEFAULT 0, `may` DECIMAL(12,2) DEFAULT 0, `jun` DECIMAL(12,2) DEFAULT 0,
                `jul` DECIMAL(12,2) DEFAULT 0, `aug` DECIMAL(12,2) DEFAULT 0, `sep` DECIMAL(12,2) DEFAULT 0,
                `oct` DECIMAL(12,2) DEFAULT 0, `nov` DECIMAL(12,2) DEFAULT 0, `dec` DECIMAL(12,2) DEFAULT 0,
                sort_order INT DEFAULT 0,
                updated_by VARCHAR(150) DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fy (fy),
                INDEX idx_appcse_items_dept (fy, dept_code)
            ) ENGINE=InnoDB");
        } catch (Throwable $t) {}
        // Self-heal: if appcse_items already existed from an earlier/partial run
        // and is missing any of the columns above, add them now (no-op once present).
        foreach ([
            "ALTER TABLE appcse_items ADD COLUMN `part` VARCHAR(5) NOT NULL DEFAULT 'I' AFTER fy",
            "ALTER TABLE appcse_items ADD COLUMN `category` VARCHAR(150) NOT NULL DEFAULT '' AFTER `part`",
            "ALTER TABLE appcse_items ADD COLUMN code VARCHAR(60) DEFAULT '' AFTER `category`",
            "ALTER TABLE appcse_items ADD COLUMN description VARCHAR(255) DEFAULT '' AFTER code",
            "ALTER TABLE appcse_items ADD COLUMN unit VARCHAR(40) DEFAULT '' AFTER description",
            "ALTER TABLE appcse_items ADD COLUMN unit_price DECIMAL(14,2) DEFAULT 0 AFTER unit",
            "ALTER TABLE appcse_items ADD COLUMN `jan` DECIMAL(12,2) DEFAULT 0 AFTER unit_price",
            "ALTER TABLE appcse_items ADD COLUMN `feb` DECIMAL(12,2) DEFAULT 0 AFTER `jan`",
            "ALTER TABLE appcse_items ADD COLUMN `mar` DECIMAL(12,2) DEFAULT 0 AFTER `feb`",
            "ALTER TABLE appcse_items ADD COLUMN `apr` DECIMAL(12,2) DEFAULT 0 AFTER `mar`",
            "ALTER TABLE appcse_items ADD COLUMN `may` DECIMAL(12,2) DEFAULT 0 AFTER `apr`",
            "ALTER TABLE appcse_items ADD COLUMN `jun` DECIMAL(12,2) DEFAULT 0 AFTER `may`",
            "ALTER TABLE appcse_items ADD COLUMN `jul` DECIMAL(12,2) DEFAULT 0 AFTER `jun`",
            "ALTER TABLE appcse_items ADD COLUMN `aug` DECIMAL(12,2) DEFAULT 0 AFTER `jul`",
            "ALTER TABLE appcse_items ADD COLUMN `sep` DECIMAL(12,2) DEFAULT 0 AFTER `aug`",
            "ALTER TABLE appcse_items ADD COLUMN `oct` DECIMAL(12,2) DEFAULT 0 AFTER `sep`",
            "ALTER TABLE appcse_items ADD COLUMN `nov` DECIMAL(12,2) DEFAULT 0 AFTER `oct`",
            "ALTER TABLE appcse_items ADD COLUMN `dec` DECIMAL(12,2) DEFAULT 0 AFTER `nov`",
            "ALTER TABLE appcse_items ADD COLUMN sort_order INT DEFAULT 0 AFTER `dec`",
            "ALTER TABLE appcse_items ADD COLUMN updated_by VARCHAR(150) DEFAULT '' AFTER sort_order",
            "ALTER TABLE appcse_items ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER updated_by",
        ] as $alterSql) {
            try { $pdo->exec($alterSql); } catch (Throwable $t) {}
        }

        // ─── Annex A / B / C — project-based procurement annex forms ───
        // One header row per (fy, dept, annex, project_no); line items in annex_items.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS annex_forms (
                id INT PRIMARY KEY AUTO_INCREMENT,
                fy INT NOT NULL,
                dept_code VARCHAR(30) NOT NULL,
                annex VARCHAR(2) NOT NULL DEFAULT 'B',
                project_name VARCHAR(255) DEFAULT '',
                general_description TEXT,
                nep_account_1 VARCHAR(200) DEFAULT '',
                allocated_budget_1 DECIMAL(16,2) DEFAULT 0,
                nep_account_2 VARCHAR(200) DEFAULT '',
                allocated_budget_2 DECIMAL(16,2) DEFAULT 0,
                inclusive_dates VARCHAR(255) DEFAULT '',
                total_estimated DECIMAL(16,2) DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                submitted_by VARCHAR(150) DEFAULT '',
                submitted_at DATETIME NULL,
                reviewed_by VARCHAR(150) DEFAULT '',
                reviewed_at DATETIME NULL,
                review_note TEXT,
                created_by VARCHAR(150) DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fy_dept (fy, dept_code, annex)
            ) ENGINE=InnoDB");
            foreach ([
                "ALTER TABLE annex_forms ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'",
                "ALTER TABLE annex_forms ADD COLUMN submitted_by VARCHAR(150) DEFAULT ''",
                "ALTER TABLE annex_forms ADD COLUMN submitted_at DATETIME NULL",
                "ALTER TABLE annex_forms ADD COLUMN reviewed_by VARCHAR(150) DEFAULT ''",
                "ALTER TABLE annex_forms ADD COLUMN reviewed_at DATETIME NULL",
                "ALTER TABLE annex_forms ADD COLUMN review_note TEXT",
                "ALTER TABLE annex_forms ADD COLUMN subtitle VARCHAR(255) DEFAULT ''",
                "ALTER TABLE annex_forms ADD COLUMN item_note VARCHAR(255) DEFAULT ''",
            ] as $alterSql) {
                try { $pdo->exec($alterSql); } catch (Throwable $t) {}
            }
            try { $pdo->exec("ALTER TABLE annex_forms ADD COLUMN total_estimated DECIMAL(16,2) DEFAULT 0"); } catch (Throwable $t) {}
        } catch (Throwable $t) {}

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS annex_items (
                id INT PRIMARY KEY AUTO_INCREMENT,
                form_id INT NOT NULL,
                item_group VARCHAR(80) DEFAULT '',
                specification VARCHAR(255) DEFAULT '',
                quantity DECIMAL(14,2) DEFAULT 0,
                total_amount DECIMAL(16,2) DEFAULT 0,
                sort_order INT DEFAULT 0,
                INDEX idx_form (form_id)
            ) ENGINE=InnoDB");
        } catch (Throwable $t) {}


        // Ensure a budget user exists — bcrypt hash of 'password123' generated at runtime
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'budget-01-001'")->fetchColumn();
        if ($exists === 0) {
            $budgetHash = password_hash('password123', PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                'budget-01-001', $budgetHash,
                'Sherwin Serrano', 'Budget Officer',
                'budget@ascot.edu.com', '+63 917 555 0107',
                'budget', 'BUDGET-OFC',
            ]);
        }

        // ─── Users status column (pending / active / rejected) ───
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('pending','active','rejected') DEFAULT 'active' AFTER role");
            // All previously-existing accounts are considered already approved
            $pdo->exec("UPDATE users SET status = 'active' WHERE status IS NULL");
        }

        // ─── Non-PS: who prepared the list (editable by the department) ───
        try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN prepared_by VARCHAR(150) DEFAULT ''"); } catch (Throwable $t) {}
        // The designation printed under the preparer's name. Left blank the
        // document falls back to the preparer's position, then the department.
        try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN prepared_designation VARCHAR(150) DEFAULT ''"); } catch (Throwable $t) {}

        // ─── Registration captures the name in parts, plus sex and birthday ───
        foreach ([
            "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN middle_initial VARCHAR(10) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN sex VARCHAR(10) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN birthday DATE NULL",
        ] as $alterSql) {
            try { $pdo->exec($alterSql); } catch (Throwable $t) {}
        }

        // ─── Separate name parts, sex and birthday (collected at registration) ───
        foreach ([
            "ALTER TABLE users ADD COLUMN first_name VARCHAR(80) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN middle_initial VARCHAR(10) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN last_name VARCHAR(80) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN sex VARCHAR(10) DEFAULT ''",
            "ALTER TABLE users ADD COLUMN birthday DATE NULL",
        ] as $alterSql) {
            try { $pdo->exec($alterSql); } catch (Throwable $t) {}
        }

        // ─── Users can also be blocked by the Procurement Unit ───
        try { $pdo->exec("ALTER TABLE users MODIFY status ENUM('pending','active','rejected','blocked') DEFAULT 'active'"); } catch (Throwable $t) {}

        // ─── Market Scoping — rich-text documents submitted per department ───
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS market_scoping (
                id INT PRIMARY KEY AUTO_INCREMENT,
                fy INT NOT NULL,
                dept_code VARCHAR(30) NOT NULL,
                title VARCHAR(255) DEFAULT '',
                content LONGTEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                submitted_by VARCHAR(150) DEFAULT '',
                submitted_at DATETIME NULL,
                reviewed_by VARCHAR(150) DEFAULT '',
                reviewed_at DATETIME NULL,
                review_note TEXT,
                created_by VARCHAR(150) DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fy_dept (fy, dept_code)
            ) ENGINE=InnoDB");
        } catch (Throwable $t) {}

        // ─── Terms and Conditions acceptance (recorded at registration) ───
        try { $pdo->exec("ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL"); } catch (Throwable $t) {}

        // ─── Department lifecycle (the Procurement Unit controls the department list) ───
        // A department is NEVER deleted once it has been used. It is switched to
        // 'inactive' instead, which only stops it appearing on Register Account.
        // Every PPMP, APP, Non-PS list, Annex, Market Scoping document and past
        // record that references the department code is left exactly as it is.
        try { $pdo->exec("ALTER TABLE departments ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE departments ADD COLUMN created_at DATETIME NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE departments ADD COLUMN created_by VARCHAR(150) NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE departments ADD COLUMN deactivated_at DATETIME NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE departments ADD COLUMN deactivated_by VARCHAR(150) NULL"); } catch (Throwable $t) {}
        // Existing rows predate this column — they are all live departments.
        try { $pdo->exec("UPDATE departments SET status = 'active' WHERE status IS NULL OR status = ''"); } catch (Throwable $t) {}
        try { $pdo->exec("CREATE INDEX idx_dept_status ON departments (status)"); } catch (Throwable $t) { /* index exists */ }

        // ─── Repair notification destinations saved by older versions ───
        // Self-limiting: once a row is corrected it no longer matches the
        // WHERE clause, so this is safe to run on every load.
        try {
            // Announcements used to drop people on a dashboard instead of the announcement itself
            $pdo->exec("UPDATE notifications n
                          JOIN users u ON u.id = n.user_id
                           SET n.link = 'user-announcements.php'
                         WHERE n.type = 'announcement'
                           AND u.role IN ('user','budget')
                           AND (n.link IN ('user-dashboard.php','budget-dashboard.php') OR n.link = '' OR n.link IS NULL)");
            $pdo->exec("UPDATE notifications n
                          JOIN users u ON u.id = n.user_id
                           SET n.link = 'announcement.php'
                         WHERE n.type = 'announcement'
                           AND u.role = 'admin'
                           AND (n.link = 'admin-dashboard.php' OR n.link = '' OR n.link IS NULL)");

            // Account approval pointed at the login page; rejection had no destination at all
            $pdo->exec("UPDATE notifications SET link = 'user-dashboard.php'
                         WHERE type = 'approval' AND link = 'index.php'");
            $pdo->exec("UPDATE notifications SET link = 'help.php'
                         WHERE type = 'revision' AND (link = '' OR link IS NULL)
                           AND title LIKE '%account%'");

            // Annex decisions: point at the department's matching annex
            $pdo->exec("UPDATE notifications n
                          JOIN users u ON u.id = n.user_id
                          JOIN annex_forms a ON a.dept_code = u.dept_code
                           SET n.link = CONCAT('annex-edit.php?id=', a.id)
                         WHERE n.link = 'annex.php'
                           AND n.title LIKE CONCAT('%Annex ', a.annex, '%')");

            // Anything still without a destination lands on the person's own dashboard
            $pdo->exec("UPDATE notifications n
                          JOIN users u ON u.id = n.user_id
                           SET n.link = CASE u.role
                                          WHEN 'admin'  THEN 'admin-home.php'
                                          WHEN 'budget' THEN 'budget-dashboard.php'
                                          ELSE 'user-dashboard.php' END
                         WHERE n.link = '' OR n.link IS NULL");
        } catch (Throwable $t) { /* older MySQL may not support multi-table UPDATE */ }

        $__ok = true;
    } catch (Throwable $t) {
        error_log('ensure_admin_tables failed: ' . $t->getMessage());
        $__ok = false;
    }
    // Runs either way: a statement above failing must not quietly skip it.
    clear_shipped_signatory_defaults();
    ensure_ppmp_head_custom();
    ensure_v39_columns();
    // v79: the version history table and the wrong-password columns
    try { require_once __DIR__ . '/doc-versions.php'; doc_versions_ensure(); } catch (Throwable $t) {}
    try { require_once __DIR__ . '/login-guard.php'; login_guard_columns(); } catch (Throwable $t) {}
    // v81: ASCOT's schools, programs and majors (config/academic-units.php)
    try { require_once __DIR__ . '/academic-units.php'; au_migrate(); } catch (Throwable $t) {}
    // v82: ASCOT's administrative units and offices (config/admin-units.php)
    try { require_once __DIR__ . '/admin-units.php'; ao_migrate(); ao_retire(); } catch (Throwable $t) {}
    return $__ok;
}

/** The one-off Archive catch-up (v103: runs once — see the note inside). */
function pe_archive_catchup_once(PDO $pdo): void {
    // One-off catch-up for systems whose fiscal year was rolled over before
    // these flags existed: anything from a year that has already ended is
    // archived now, so the Archive is complete from the first page load.
    // v103: it runs ONCE (settings › archive_catchup_done). It used to run on
    // every page load, so a past year's document restored from the Archive
    // (e.g. FY 2025) was put straight back — only a later year's (FY 2029) stayed restored.
    // Rolling the year over archives the year that ended (api/set-fiscal-year.php).
    try {
        $__fy = (int)($pdo->query("SELECT value FROM settings WHERE key_name = 'active_fy'")->fetchColumn() ?: 0);
        $__caught = (string)($pdo->query("SELECT value FROM settings WHERE key_name = 'archive_catchup_done'")->fetchColumn() ?: '');
        if ($__fy > 0 && $__caught === '') {
            try { $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('archive_catchup_done', ?)")->execute([(string)$__fy]); } catch (Throwable $e) {}
            // every department's APP-CSE of an ended year needs its header row to carry the flag
            try {
                $pdo->prepare("INSERT INTO appcse_header (fy, dept_code)
                               SELECT DISTINCT i.fy, i.dept_code FROM appcse_items i
                                WHERE i.fy < ? AND i.dept_code <> ''
                                  AND NOT EXISTS (SELECT 1 FROM appcse_header h WHERE h.fy = i.fy AND h.dept_code = i.dept_code)")
                    ->execute([$__fy]);
            } catch (Throwable $e) {}
            foreach (['market_scoping', 'annex_forms', 'appcse_header', 'nonps_submissions'] as $__t) {
                try {
                    $pdo->prepare("UPDATE `$__t` SET archived = 1, archived_at = NOW(),
                                          archived_by = COALESCE(archived_by, 'System')
                                    WHERE fy < ? AND (archived IS NULL OR archived = 0)")
                        ->execute([$__fy]);
                } catch (Throwable $e) {}
            }
            try {
                $pdo->prepare("INSERT IGNORE INTO archived_ppmps (ppmp_id, dept_code, fy, archived_by, reason)
                               SELECT p.id, p.dept_code, p.fy, 'System', 'Fiscal year already ended'
                                 FROM ppmps p WHERE p.fy < ?")->execute([$__fy]);
            } catch (Throwable $e) {}
        }
    } catch (Throwable $t) {}
}

/** v103: every account's name as it is written, once. */
function pe_name_case_once(PDO $pdo): void {
    // v103: every account's name as it is written (name_case() in config/auth.php) —
    // once, for the names saved before; new ones are written that way when saved.
    try {
        $__nc = (string)($pdo->query("SELECT value FROM settings WHERE key_name = 'name_case_done'")->fetchColumn() ?: '');
        if ($__nc === '' && function_exists('name_case')) {
            $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('name_case_done', '1')")->execute();
            $__cols = [];
            try { $__cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
            $__parts = in_array('first_name', $__cols, true) && in_array('last_name', $__cols, true) && in_array('middle_initial', $__cols, true);
            $__rows = $pdo->query('SELECT id, full_name' . ($__parts ? ', first_name, middle_initial, last_name' : '') . ' FROM users')->fetchAll();
            $__up = $pdo->prepare('UPDATE users SET full_name = ?' . ($__parts ? ', first_name = ?, middle_initial = ?, last_name = ?' : '') . ' WHERE id = ?');
            foreach ($__rows as $__r) {
                $__full = name_case((string)$__r['full_name']);
                $__args = [$__full];
                if ($__parts) {
                    $__mi = mb_strtoupper(trim((string)$__r['middle_initial']));
                    // a two-letter middle initial ("DC.") stays in capitals inside the full name too
                    if (mb_strlen(rtrim($__mi, '.')) > 1) {
                        $__mi2 = rtrim($__mi, '.') . '.';
                        $__full = preg_replace('/(?<=\s)' . preg_quote(name_case($__mi2), '/') . '(?=\s)/u', $__mi2, $__full);
                        $__args = [$__full];
                    }
                    array_push($__args, name_case((string)$__r['first_name']), $__mi, name_case((string)$__r['last_name']));
                }
                $__args[] = (int)$__r['id'];
                $__same = ($__args[0] === (string)$__r['full_name']) && (!$__parts || ($__args[1] === (string)$__r['first_name'] && $__args[2] === (string)$__r['middle_initial'] && $__args[3] === (string)$__r['last_name']));
                if (!$__same) $__up->execute($__args);
            }
        }
    } catch (Throwable $t) { /* names stay as they are */ }
}
