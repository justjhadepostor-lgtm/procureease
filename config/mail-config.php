<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  PROCUREEASE  ·  MAIL CONFIGURATION  (edit once, works forever)
 * ═══════════════════════════════════════════════════════════════
 *
 * Fill in the Gmail address + app password below, save this file,
 * and every OTP / password-reset / notification email will go
 * directly to the recipient's Gmail inbox.
 *
 * The admin never has to touch anything in the app — it just works.
 *
 * ─── HOW TO GET A GMAIL APP PASSWORD (2 minutes, one time) ────────
 *
 *  1. Open  https://myaccount.google.com/security  in your browser.
 *
 *  2. Sign in to the Gmail account you want ProcureEase to send
 *     from  (e.g. procureease@ascot.edu.com).
 *
 *  3. Turn on  "2-Step Verification"  if it isn't on yet.
 *     (Google requires this before it will issue app passwords.)
 *
 *  4. Open  https://myaccount.google.com/apppasswords
 *     Give the app any name — e.g. "ProcureEase" — and click Create.
 *     Google shows a 16-character password with spaces, like:
 *          abcd efgh ijkl mnop
 *     Copy it. Spaces don't matter — they're stripped automatically.
 *
 *  5. Paste it into  MAIL_PASSWORD  below and save this file.
 *
 * ─── AFTER FILLING THIS IN ────────────────────────────────────────
 *
 *  Every OTP and system email will be delivered straight to the
 *  recipient's Gmail inbox.  No admin action required.
 *
 *  If you leave the fields blank, the system falls back to on-screen
 *  demo codes (fine for testing, not for a real defense/demo).
 *
 * ═══════════════════════════════════════════════════════════════ */

// ─── PASTE YOUR CREDENTIALS HERE ───────────────────────────────────
define('MAIL_USERNAME',  'procureease.ascot@gmail.com');
define('MAIL_PASSWORD',  'kemm yqbk tfkr dany');

// ─── OPTIONAL (defaults are usually fine) ──────────────────────────
define('MAIL_FROM_NAME', 'ProcureEase — ASCOT');
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);

// ═══════════════════════════════════════════════════════════════════
// Don't edit below unless you know what you're doing.
// ═══════════════════════════════════════════════════════════════════

/** Whether the file-based mail config is filled in and ready to use. */
function mail_is_configured(): bool {
    return defined('MAIL_USERNAME') && MAIL_USERNAME !== ''
        && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '';
}
