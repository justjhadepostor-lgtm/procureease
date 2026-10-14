<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  PROCUREEASE  ·  GOOGLE SIGN-IN (OAuth 2.0)
 * ═══════════════════════════════════════════════════════════════
 *
 * Credentials are set by the admin inside the app
 * (Settings → Google Sign-In), and stored in the settings table.
 * No Composer package is used — this speaks OAuth directly.
 *
 * ─── HOW TO GET THE CLIENT ID AND SECRET (one time) ───────────────
 *
 *  1. Open  https://console.cloud.google.com/
 *  2. Create a project (or pick an existing one).
 *  3. Go to  APIs & Services → OAuth consent screen
 *       · User type: External
 *       · Fill in the app name and your support email, then Save.
 *  4. Go to  APIs & Services → Credentials
 *       · Create credentials → OAuth client ID
 *       · Application type: Web application
 *       · Authorised redirect URI: paste the exact callback URL
 *         shown on the Google Sign-In settings page in ProcureEase,
 *         e.g.  http://localhost/procureease/google-callback.php
 *  5. Copy the Client ID and Client secret into ProcureEase.
 *
 * ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google-config.php';

/**
 * Read a google_* value.
 * The database wins when it holds a value, so the admin can change things
 * in-app; otherwise we fall back to the constants in google-config.php,
 * which survive a database rebuild.
 */
function google_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query("SELECT key_name, value FROM settings WHERE key_name LIKE 'google_%'")->fetchAll();
            foreach ($rows as $r) $cache[$r['key_name']] = $r['value'];
        } catch (Throwable $t) { $cache = []; }
    }

    $fromDb = trim((string)($cache[$key] ?? ''));
    if ($fromDb !== '') return $fromDb;

    // Fall back to the file
    if ($key === 'google_client_id'     && defined('GOOGLE_CLIENT_ID')     && GOOGLE_CLIENT_ID     !== '') return GOOGLE_CLIENT_ID;
    if ($key === 'google_client_secret' && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '') return GOOGLE_CLIENT_SECRET;

    return $default;
}

/** Where the credentials currently in use came from: 'database', 'file', or 'none'. */
function google_config_source(): string {
    try {
        $rows = db()->query("SELECT value FROM settings WHERE key_name = 'google_client_id'")->fetchAll();
        if (!empty($rows) && trim((string)$rows[0]['value']) !== '') return 'database';
    } catch (Throwable $t) {}
    if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') return 'file';
    return 'none';
}

/** Whether Google Sign-In has been configured by the admin. */
function google_is_configured(): bool {
    return google_setting('google_client_id') !== ''
        && google_setting('google_client_secret') !== '';
}

/** The callback URL Google must redirect back to. */
function google_redirect_uri(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Directory this app lives in, e.g. /procureease
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // When called from inside /api, step back up to the app root
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return "$scheme://$host$dir/google-callback.php";
}

/** Build the URL that sends the person to Google's consent screen. */
function google_auth_url(string $state, string $mode = 'signin'): string {
    $params = [
        'client_id'     => google_setting('google_client_id'),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state . '|' . $mode,
        'access_type'   => 'online',
        // Always land on "Choose an account", and keep Google's screens in English
        'prompt'        => 'select_account',
        'hl'            => 'en',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/** POST helper that works with or without cURL. */
function google_http_post(string $url, array $fields): ?array {
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code >= 400) return null;
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $body,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return null;
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

/** GET helper that works with or without cURL. */
function google_http_get(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code >= 400) return null;
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return null;
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

/** Exchange the one-time code for an access token. */
function google_exchange_code(string $code): ?array {
    return google_http_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => google_setting('google_client_id'),
        'client_secret' => google_setting('google_client_secret'),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

/** Fetch the signed-in person's Google profile. */
function google_fetch_profile(string $accessToken): ?array {
    return google_http_get('https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . urlencode($accessToken));
}
