<?php
require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app-settings.php';   // the Settings page, read by everything
require_once __DIR__ . '/funds.php';   // the one Source of Funds list

if (session_status() === PHP_SESSION_NONE) {
    // v79: the session cookie is never readable by scripts (HttpOnly), is not
    // sent along when another site posts to ProcureEase (SameSite=Lax), and
    // travels only over HTTPS when the site is served over HTTPS
    $__https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (PHP_VERSION_ID >= 70300) {
        $__cp = session_get_cookie_params();
        session_set_cookie_params(['lifetime' => $__cp['lifetime'], 'path' => $__cp['path'] ?: '/', 'domain' => $__cp['domain'],
                                   'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        @ini_set('session.cookie_httponly', '1');
        if ($__https) @ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// v79: a request that changes something must carry this session's token (config/csrf.php)
require_once __DIR__ . '/csrf.php';
csrf_guard();

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT u.*, d.name AS dept_name, d.dept_group
                                 FROM users u
                            LEFT JOIN departments d ON d.code = u.dept_code
                                WHERE u.id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if ($user) $user = au_user_view($user);
    }
    return $user;
}

/**
 * v81 (config/academic-units.php): an account of a school with programs and
 * majors works on one unit at a time. Its dept_code / dept_name become that
 * unit's, so every page and save works on the unit's own documents; the
 * account's school stays in school_code / school_name, and every unit it may
 * work on in scope_units. Nothing changes for an office, or a school without units.
 */
function au_user_view(array $user): array {
    require_once __DIR__ . '/academic-units.php';
    $user['school_code'] = (string)($user['dept_code'] ?? '');
    $user['school_name'] = (string)($user['dept_name'] ?? '');
    $user['scope_units'] = [];
    $__role = (string)($user['role'] ?? '');
    if ($user['school_code'] !== ''
        && ((in_array($__role, ['user', 'dept_admin'], true) && au_has_units($user['school_code']))
            // v83: an office with a 2nd / 3rd set (config/office-sets.php) — the Procurement Unit's and the Budget Unit's too
            || (in_array($__role, ['user', 'dept_admin', 'admin', 'budget'], true) && os_has_sets($user['school_code'])))) {
        $user['scope_units'] = au_scope($user);
        $active = au_active_unit($user);
        $row = au_row($active);
        $user['dept_code'] = $active;
        $user['dept_name'] = (string)($row['name'] ?? $active);
        $user['unit_label'] = au_label($active);
    }
    return $user;
}

/**
 * Load the current user's UI preferences (dark_mode, high_contrast, etc.).
 * Returns an empty array if not logged in or the user_prefs table is missing.
 */
function user_prefs(): array {
    static $prefs = null;
    if ($prefs !== null) return $prefs;
    $prefs = [];
    $u = current_user();
    if (!$u) return $prefs;
    try {
        $stmt = db()->prepare('SELECT key_name, value FROM user_prefs WHERE user_id = ?');
        $stmt->execute([$u['id']]);
        foreach ($stmt->fetchAll() as $r) $prefs[$r['key_name']] = $r['value'];
    } catch (Throwable $t) { /* table not created yet — treat as empty */ }
    return $prefs;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
    // Settings > Security > Session timeout: signed out after that many
    // minutes without using the system. Every page and every save counts.
    $__limit = session_timeout_min() * 60;
    $__last  = (int)($_SESSION['__last_seen'] ?? 0);
    if ($__last > 0 && time() - $__last > $__limit) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_error'] = 'You were signed out after ' . session_timeout_min()
                                 . ' minutes without activity. Please sign in again.';
        if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'You were signed out after a period without activity. Please sign in again.', 'signed_out' => true]);
            exit;
        }
        header('Location: index.php');
        exit;
    }
    $_SESSION['__last_seen'] = time();
    // v81: choose the program / major to work on (?unit=CODE), then back to the same page
    if (isset($_GET['unit']) && is_string($_GET['unit'])) {
        $__cu = current_user();
        foreach (($__cu['scope_units'] ?? []) as $__c) {
            if (strcasecmp($__c, (string)$_GET['unit']) === 0) { $_SESSION['active_unit'][(int)$__cu['id']] = $__c; break; }
        }
        $__back = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        $__keep = array_intersect_key($_GET, ['fy' => 1, 'type' => 1]);
        header('Location: ' . $__back . ($__keep ? '?' . http_build_query($__keep) : ''));
        exit;
    }
    // v81: a document of another of the account's units, opened by its link (a notification,
    // a folder): work on that unit, then the same page again
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $__cu = current_user();
        if ($__cu && count($__cu['scope_units'] ?? []) > 1) {
            $__to = au_doc_unit(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), $_GET);
            if ($__to !== '' && strcasecmp($__to, (string)$__cu['dept_code']) !== 0) {
                foreach ($__cu['scope_units'] as $__c) {
                    if (strcasecmp($__c, $__to) === 0) {
                        $_SESSION['active_unit'][(int)$__cu['id']] = $__c;
                        header('Location: ' . (string)($_SERVER['REQUEST_URI'] ?? ''));
                        exit;
                    }
                }
            }
        }
    }
    // A blocked account loses access straight away, even mid-session
    $u = current_user();
    // v128: an account that was deleted while signed in — signed out, back to sign in
    if (!$u) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_error'] = 'This account no longer exists. Please sign in with another account, or create one.';
        if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'This account no longer exists. Please sign in again.', 'signed_out' => true]);
            exit;
        }
        header('Location: index.php');
        exit;
    }
    if ($u && ($u['status'] ?? 'active') === 'blocked') {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_error'] = 'Your account has been blocked by the Procurement Unit. Please contact them if you believe this is a mistake.';
        header('Location: index.php');
        exit;
    }
}

/**
 * Human-readable label for a role, for UI display only.
 * The underlying role value ('admin', 'dept_admin', 'budget', 'user') used
 * throughout auth checks and the database is unchanged — this only controls
 * what gets shown on screen.
 */
function role_label(?string $role): string {
    switch ($role) {
        case 'admin':      return 'Procurement Unit';
        case 'dept_admin': return 'Department Admin';
        case 'budget':     return 'Budget';
        default:           return 'User';
    }
}

function require_admin(): void {
    require_login();
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        header('Location: user-dashboard.php');
        exit;
    }
}

/**
 * Guard for department end-user features (My PPMPs, Edit PPMP, New PPMP, etc.).
 * Budget Unit users also qualify — they're a department themselves (BUDGET-OFC)
 * and are allowed to prepare and submit their own PPMPs.
 * Admins get bounced to their own dashboard.
 */
function require_user_role(): void {
    require_login();
    $u = current_user();
    if (!$u || ($u['role'] !== 'user' && $u['role'] !== 'budget' && $u['role'] !== 'dept_admin')) {
        header('Location: admin-home.php');
        exit;
    }
}

function require_budget(): void {
    require_login();
    $u = current_user();
    if (!$u || $u['role'] !== 'budget') {
        // Bounce to their proper landing page
        $landing = $u && $u['role'] === 'admin' ? 'admin-home.php' : 'user-dashboard.php';
        header('Location: ' . $landing);
        exit;
    }
}

/** Allow budget OR admin (both can see PPMPs going through the workflow) */
function require_reviewer(): void {
    require_login();
    $u = current_user();
    if (!$u || ($u['role'] !== 'budget' && $u['role'] !== 'admin')) {
        header('Location: user-dashboard.php');
        exit;
    }
}

function initials(string $fullName): string {
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts) return 'U';
    if (count($parts) === 1) return strtoupper(mb_substr($parts[0], 0, 2));
    return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The system-wide active fiscal year, set by the admin.
 * This is the single source of truth: users and Budget cannot change it —
 * they always work on whatever year the admin has set.
 * Falls back to 2027 if the setting hasn't been created yet.
 */
function active_fy(): int {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $v = db()->query("SELECT value FROM settings WHERE key_name = 'active_fy'")->fetchColumn();
        $cached = $v ? (int)$v : 2027;
    } catch (Throwable $t) {
        $cached = 2027;
    }
    return $cached;
}

/**
 * The department the Budget Unit account belongs to (usually BUDGET-OFC),
 * or null if no budget account exists yet.
 *
 * Every PPMP is reviewed by the Budget Unit first and the Procurement Unit
 * second. The one exception is the Budget Unit's OWN PPMP: it cannot review
 * itself — budget-dashboard.php deliberately leaves it out of the review queue
 * — so the Procurement Unit is its reviewer and approves it directly. Keeping
 * that rule in one function stops the two endpoints and the file viewer from
 * disagreeing about it.
 */
function budget_dept_code(): ?string {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    try {
        $v = db()->query("SELECT dept_code FROM users WHERE role = 'budget' AND dept_code IS NOT NULL LIMIT 1")
                 ->fetchColumn();
        if ($v) $cached = (string)$v;
    } catch (Throwable $t) { /* fresh install — no budget account yet */ }
    return $cached;
}

/** True when this department's PPMP is reviewed by the Procurement Unit alone. */
function ppmp_skips_budget(?string $deptCode): bool {
    $bd = budget_dept_code();
    if ($bd !== null && $deptCode !== null && $deptCode !== $bd) {
        // v83: the Budget Unit's own 2nd / 3rd set goes to the Procurement Unit too
        require_once __DIR__ . '/office-sets.php';
        if (os_is_set($deptCode) && au_school_of($deptCode) === $bd) return true;
    }
    return $bd !== null && $deptCode !== null && $deptCode === $bd;
}

/**
 * True while a PPMP is with the Procurement Unit for final approval: the
 * Budget Unit has approved it (or, for the Budget Unit's own PPMP, it has
 * been submitted). This is the window in which the Procurement Unit may edit
 * it; once it is given final approval it is locked.
 */
function ppmp_with_pu(array $p): bool {
    $st = (string)($p['status'] ?? '');
    return $st === 'budget_approved'
        || ($st === 'submitted' && ppmp_skips_budget($p['dept_code'] ?? null));
}

/**
 * v90: who sees a department's PPMP, and when — only once it is with them.
 *   the department      always (its own)
 *   the Budget Unit     once it is submitted (a draft is the department's own)
 *   the Procurement Unit once the Budget Unit has approved it (or, for the
 *                       Budget Unit's own PPMP, once it is submitted), and
 *                       after final approval — never while it is with Budget
 * Each office always sees its own (and its sets').
 */
function ppmp_visible_to(array $p, ?array $u = null): bool {
    $u = $u ?? current_user();
    if (!$u) return false;
    require_once __DIR__ . '/office-sets.php';
    // the account's school / office, and the document's (a program's school, a set's office)
    $mine = au_school_of((string)($u['school_code'] ?? $u['dept_code'] ?? ''));
    if ($mine !== '' && strcasecmp(au_school_of((string)($p['dept_code'] ?? '')), $mine) === 0) return true;
    $role = (string)($u['role'] ?? '');
    $st   = (string)($p['status'] ?? 'draft');
    if ($role === 'admin')  return $st === 'approved' || ppmp_with_pu($p);
    if ($role === 'budget') return $st !== 'draft';
    return false;
}

/** When it reached the Procurement Unit, for putting the newest first; null when it is not there. */
function ppmp_with_pu_since(array $p): ?string {
    if (!ppmp_with_pu($p)) return null;
    $t = ($p['status'] ?? '') === 'budget_approved' ? ($p['budget_reviewed_at'] ?? '') : '';
    if ($t === '' || $t === null) $t = $p['submitted_at'] ?? '';
    return ($t !== '' && $t !== null) ? (string)$t : '1970-01-01 00:00:00';
}

/**
 * A document of a fiscal year before the active one. Those are archived when
 * the year changes; one restored from the Archive is for viewing only — it
 * cannot be edited, submitted or reviewed, and it is not counted in the
 * active year or in any consolidation (config/past-years.php).
 */
function fy_is_past(int $fy): bool {
    return $fy > 0 && $fy < active_fy();
}

/** What to say when a past-year document is asked to change. */
function fy_past_msg(string $label, int $fy): string {
    return "This FY $fy $label is from a past fiscal year. It can be viewed, not changed.";
}

/** Stop a change to a past year's document: say why and go back to $back. */
function fy_past_stop(int $fy, string $label, string $back): void {
    if (!fy_is_past($fy)) return;
    flash('error', fy_past_msg($label, $fy));
    header('Location: ' . $back);
    exit;
}

/** The fiscal year of a PPMP (0 when there is none). */
function ppmp_fy(int $ppmpId): int {
    try {
        $s = db()->prepare('SELECT fy FROM ppmps WHERE id = ?');
        $s->execute([$ppmpId]);
        return (int)$s->fetchColumn();
    } catch (Throwable $t) { return 0; }
}

/**
 * The Department Admin of a department, or null when none is assigned.
 * There is at most one per department (api/set-user-role.php enforces it).
 */
function dept_admin_of(?string $deptCode): ?array {
    if ($deptCode === null || $deptCode === '') return null;
    // v81: a program / major answers to its school's Department Admin
    require_once __DIR__ . '/academic-units.php';
    $deptCode = au_school_of($deptCode);
    static $cache = [];
    if (array_key_exists($deptCode, $cache)) return $cache[$deptCode];
    $cache[$deptCode] = null;
    try {
        $s = db()->prepare("SELECT id, full_name FROM users
                             WHERE dept_code = ? AND role = 'dept_admin'
                               AND COALESCE(status, 'active') = 'active'
                          ORDER BY id LIMIT 1");
        $s->execute([$deptCode]);
        $cache[$deptCode] = $s->fetch() ?: null;
    } catch (Throwable $t) { /* leave as null */ }
    return $cache[$deptCode];
}

/**
 * May this account send its department's work to the Budget Unit?
 *
 * Everyone may prepare and save; only the Department Admin submits. If the
 * department has no Department Admin yet, anyone in it may submit, so a
 * department is never left unable to file while the role is being assigned.
 *
 * The Procurement Unit and the Budget Unit submit their own department's
 * documents like any other unit.
 */
function can_submit_for_dept(?array $u = null): bool {
    $u = $u ?? current_user();
    if (!$u) return false;
    $head = dept_admin_of($u['dept_code'] ?? null);
    if ($head === null) return true;                  // none assigned yet
    return (int)$head['id'] === (int)$u['id'];
}

/** Who to name when someone else has to do the submitting. */
function dept_submitter_name(?array $u = null): string {
    $u = $u ?? current_user();
    $head = dept_admin_of($u['dept_code'] ?? null);
    return $head ? (string)$head['full_name'] : '';
}

/**
 * v103: a person's name as it is written — each part capitalised, the rest small
 * ("jhade postor", "JHADE POSTOR" → "Jhade Postor"; "dela cruz" → "Dela Cruz";
 * "ana-marie" → "Ana-Marie"; "o'neil" → "O'Neil"). A single-letter initial
 * ("d." → "D.") and II / III / IV stay in capitals. The middle initial field is
 * kept in capitals on its own.
 */
function name_case(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '') return '';
    return preg_replace_callback('/\S+/u', function ($m) {
        $w = $m[0];
        if (preg_match('/^\p{L}\.?$/u', $w)) return mb_strtoupper($w);                       // an initial: D / D.
        if (preg_match('/^(ii|iii|iv)[.,]?$/iu', $w)) return mb_strtoupper($w);                // II, III, IV
        return preg_replace_callback("/(^|[-'’])(\p{L})([^-'’]*)/u",
            fn($p) => $p[1] . mb_strtoupper($p[2]) . mb_strtolower($p[3]), mb_strtolower($w));
    }, $s);
}

/** Flash messages via session */
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function get_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
/**
 * v97: the "Submitted!" check mark. Called where a document has really been
 * submitted; the next page (includes/head.php) plays it once. $what names what
 * went ("FY 2027 PPMP"), $to where it went ("Procurement Unit").
 */
function celebrate_submitted(string $what = '', string $to = ''): void {
    $_SESSION['__submitted'] = ['what' => $what, 'to' => $to, 't' => time()];
}
/** The check mark waiting to be played, once; one more than a minute old is dropped. */
function take_submitted(): ?array {
    $s = $_SESSION['__submitted'] ?? null;
    unset($_SESSION['__submitted']);
    return (is_array($s) && time() - (int)($s['t'] ?? 0) <= 60) ? $s : null;
}
function render_flashes(): void {
    foreach (get_flashes() as $f) {
        echo '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

/** Record an event in the activity_logs table. Silent-fails if table missing. */
function log_activity(string $action, string $entity_type = '', string $entity_ref = '', string $details = ''): void {
    try {
        $u = current_user();
        // Saving on its own happens every few seconds while someone types. A
        // "Saved …" entry is written once per person and document every 30
        // minutes, so the log does not fill with the same line.
        if (strncmp($action, 'Saved', 5) === 0) {
            $q = db()->prepare('SELECT MAX(created_at) FROM activity_logs WHERE username = ? AND action = ? AND entity_ref = ?');
            $q->execute([$u['username'] ?? '(system)', $action, $entity_ref]);
            $last = $q->fetchColumn();
            if ($last) {
                // both times from the database's own clock, so they are compared alike
                $now = (string)db()->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
                $age = strtotime($now) - strtotime((string)$last);
                if ($age >= 0 && $age < 1800) return;
            }
        }
        $stmt = db()->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $u['username'] ?? '(system)',
            $u['full_name'] ?? '',
            $u['role']     ?? '',
            $action, $entity_type, $entity_ref, $details,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $t) { /* silent */ }
}

/** Classify a department into the admin folder group: 'Academic' or 'Administrative'. */
function dept_group_folder(string $deptCode, ?string $deptGroup = null): string {
    // The College/Office of the Registrar (REG) is an administrative unit.
    if (strtoupper(trim($deptCode)) === 'REG') return 'Administrative';
    if ($deptGroup === 'Schools & Colleges') return 'Academic';
    // Support offices are administrative, even if older rows carry stale grouping.
    if (in_array(strtoupper(trim($deptCode)), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) return 'Administrative';
    return 'Administrative';
}
