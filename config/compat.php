<?php
/**
 * Small compatibility shims.
 *
 * The app leans on mbstring for cutting and comparing text. XAMPP ships with
 * it enabled, but some PHP builds (and a few shared hosts) do not, and without
 * it every page that trims a description would fatal. These fallbacks are only
 * defined when the real functions are absent, so nothing changes on a normal
 * setup.
 */

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen((string)$string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        return $length === null
            ? substr((string)$string, $start)
            : substr((string)$string, $start, $length);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) {
        return strtolower((string)$string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null) {
        return strtoupper((string)$string);
    }
}

if (!function_exists('mb_str_split')) {
    function mb_str_split($string, $length = 1, $encoding = null) {
        return str_split((string)$string, max(1, (int)$length));
    }
}
