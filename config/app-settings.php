<?php
/* ═══════════════════════════════════════════════════════════════
   The Settings page, read by the rest of the system.

   The values below take effect across the system. Most are set on
   Settings > "Save all settings"; auto_save_interval, session_timeout_min
   and password_min_length were taken off that page (v43) and keep the value
   they have (or the default), and the theme colours are no longer used:
     auto_save_interval   how long the editors wait after the last change
                          before they save on their own (milliseconds)
     session_timeout_min  signed out after this many minutes without using
                          the system (config/auth.php, includes/head.php)
     password_min_length  the shortest password accepted anywhere a password
                          is set (never below 8, the system's own floor)
     notify_on_submission email the Procurement Unit when something is
                          submitted to it (the bell always shows it)
     notify_on_revision   email the Procurement Unit when a department sends
                          back what it was asked to revise
     backup_schedule      automatic backups: daily / weekly / monthly / manual
     theme_custom + theme_page / theme_primary / theme_accent
                          the light-mode colours (dark mode keeps its own)
     support_ict_email / support_procurement_email   on the Help page
     institution_name / institution_address / governing_law
   =============================================================== */
require_once __DIR__ . '/db.php';

const APP_SETTING_DEFAULTS = [
    'auto_save_interval'        => '1500',
    'session_timeout_min'       => '30',
    'password_min_length'       => '8',
    'notify_on_submission'      => '1',
    'notify_on_revision'        => '1',
    'backup_schedule'           => 'weekly',
    'theme_custom'              => '0',
    // What the interface actually uses when no custom colours are set
    'theme_page'                => '#eef4fa',
    'theme_primary'             => '#1e5285',
    'theme_accent'              => '#1e5285',
    'support_ict_email'         => 'ictu@ascot.edu.com',
    'support_procurement_email' => 'procurement@ascot.edu.com',
    'institution_name'          => 'Aurora State College of Technology',
    'institution_address'       => 'Zabali, Baler, Aurora, Philippines · 3200',
    'governing_law'             => 'RA 12009 (New Government Procurement Act)',
];

/** The password floor the system never goes below. */
const PASSWORD_FLOOR = 8;

function app_settings_all(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT key_name, value FROM settings')->fetchAll() as $r) {
            $cache[(string)$r['key_name']] = (string)$r['value'];
        }
    } catch (Throwable $t) {}
    return $cache;
}

function app_setting(string $key, ?string $default = null): string {
    $all = app_settings_all();
    if (array_key_exists($key, $all) && $all[$key] !== '') return $all[$key];
    return $default ?? (APP_SETTING_DEFAULTS[$key] ?? '');
}

function app_int_setting(string $key, int $min, int $max): int {
    $v = (int)app_setting($key);
    if ($v <= 0) $v = (int)(APP_SETTING_DEFAULTS[$key] ?? $min);
    return max($min, min($max, $v));
}

/** Milliseconds the editors wait after the last change before saving. */
function autosave_ms(): int { return app_int_setting('auto_save_interval', 200, 5000); }

/** Minutes without activity before the session ends. */
function session_timeout_min(): int { return app_int_setting('session_timeout_min', 5, 480); }

/** The shortest password accepted. */
function password_min_length(): int { return max(PASSWORD_FLOOR, app_int_setting('password_min_length', PASSWORD_FLOOR, 32)); }

/**
 * Whether a password meets the rules. '' when it does, else the reason —
 * the same wording everywhere a password is set.
 */
function password_problem(string $pw): string {
    $n = password_min_length();
    if (mb_strlen($pw) < $n || !preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw)
        || !preg_match('/[0-9]/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw)) {
        return "Password must be at least $n characters, with an uppercase letter, a lowercase letter, a number, and a symbol.";
    }
    return '';
}

/** Should the Procurement Unit get an email for this kind of notification? */
function notify_email_allowed(string $type, string $role): bool {
    if ($role !== 'admin') return true;
    if ($type === 'submission')   return app_setting('notify_on_submission') === '1';
    if ($type === 'resubmission') return app_setting('notify_on_revision') === '1';
    return true;
}

/* ─── Colours ───────────────────────────────────────────────── */

function hex_rgb(string $hex): ?array {
    if (!preg_match('/^#([0-9a-f]{6})$/i', trim($hex), $m)) return null;
    return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
}

function rel_lum(array $rgb): float {
    $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, $rgb);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function contrast_ratio(string $a, string $b): float {
    $x = hex_rgb($a); $y = hex_rgb($b);
    if (!$x || !$y) return 0.0;
    $l1 = rel_lum($x); $l2 = rel_lum($y);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/** A colour made darker (f < 1) — for the far end of a gradient and hovers. */
function shade(string $hex, float $f): string {
    $c = hex_rgb($hex) ?? [30, 82, 133];
    return sprintf('#%02x%02x%02x', (int)round($c[0] * $f), (int)round($c[1] * $f), (int)round($c[2] * $f));
}

/**
 * Checks the three colours can be read: the page under the dark text, white
 * text on the primary (sidebar, top bar, buttons), and the accent used for
 * buttons and as text on white. Returns the problems (empty = fine).
 */
function theme_problems(string $page, string $primary, string $accent): array {
    $out = [];
    foreach (['Page background' => $page, 'Primary' => $primary, 'Accent' => $accent] as $name => $c) {
        if (!hex_rgb($c)) $out[] = "$name is not a colour (use #RRGGBB).";
    }
    if ($out) return $out;
    if (contrast_ratio($page, '#0f2b4a') < 7.0) $out[] = sprintf('Page background %s is too dark for the text on it (%.1f:1, needs 7:1). Choose a lighter colour.', $page, contrast_ratio($page, '#0f2b4a'));
    if (contrast_ratio($primary, '#ffffff') < 4.5) $out[] = sprintf('Primary %s is too light for the white writing on the sidebar, top bar and buttons (%.1f:1, needs 4.5:1). Choose a darker colour.', $primary, contrast_ratio($primary, '#ffffff'));
    if (contrast_ratio($accent, '#ffffff') < 4.5) $out[] = sprintf('Accent %s is too light to read as text or behind white writing (%.1f:1, needs 4.5:1). Choose a darker colour.', $accent, contrast_ratio($accent, '#ffffff'));
    return $out;
}

/**
 * The <style> that applied custom colours. Theme colours was taken off the
 * Settings page (v43), so the standard colours are always used and nothing
 * is added. theme_problems() above is kept for the stored values.
 */
function theme_css(): string {
    return '';
}
