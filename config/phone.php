<?php
/**
 * Philippine mobile numbers — one format for registration, profile, and password reset.
 * Stored and compared as 11 digits starting with 09 (e.g. 09171234567).
 */

function ph_mobile_digits(string $v): string {
    return preg_replace('/\D/', '', $v) ?? '';
}

function ph_mobile_ok(string $v): bool {
    return preg_match('/^09\d{9}$/', $v) === 1;
}

/** Canonical 11-digit local form, or '' if this is not a PH mobile. */
function ph_mobile_local(string $v): string {
    $d = ph_mobile_digits($v);
    if (strlen($d) === 13 && strncmp($d, '630', 3) === 0) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 12 && strncmp($d, '63', 2) === 0) {
        $d = '0' . substr($d, 2);
    }
    if (strlen($d) === 10 && $d !== '' && $d[0] === '9') {
        $d = '0' . $d;
    }
    return ph_mobile_ok($d) ? $d : '';
}

/** International form +639XXXXXXXXX, or '' if invalid. */
function ph_mobile_intl(string $v): string {
    $local = ph_mobile_ok($v) ? $v : ph_mobile_local($v);
    return $local === '' ? '' : '+63' . substr($local, 1);
}
