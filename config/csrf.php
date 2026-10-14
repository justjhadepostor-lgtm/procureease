<?php
/* ═══════════════════════════════════════════════════════════════
   CSRF protection (v79).

   Every request that changes something (POST, and PUT/PATCH/DELETE) must
   carry this session's security token, or it is refused before the page or
   API runs. Another website can make a signed-in person's browser send a
   request to ProcureEase, but it cannot read our pages, so it never knows
   the token.

   Where the token travels:
     · forms      a hidden field named "_csrf" — added to every POST form by
                  assets/csrf.js (pages with includes/head.php), and written
                  into the forms of the sign-in pages with csrf_field()
     · fetch/XHR  the "X-CSRF-Token" header — added by assets/csrf.js to every
                  request the page sends to ProcureEase itself

   One token per session: it is made the first time it is needed, survives
   signing in (session_regenerate_id keeps the session's data) and ends when
   the session ends (signing out). config/auth.php calls csrf_guard() on
   every request.
   =============================================================== */

/** This session's token (made the first time it is needed). */
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['__csrf']) || !is_string($_SESSION['__csrf'])) {
        $_SESSION['__csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['__csrf'];
}

/** The hidden field for a form written by hand (pages without includes/head.php). */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** The token for assets/csrf.js, in the page's <head>. */
function csrf_meta(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** The token this request carries, from the form or from the header. */
function csrf_sent_token(): string {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) ? $t : '';
}

/** Refuses any request that changes something without this session's token. */
function csrf_guard(): void {
    if (PHP_SAPI === 'cli') return;
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    // An upload larger than PHP's post_max_size arrives with nothing in it
    // (no fields, no files, so no token either). Nothing can be done with
    // an empty request, so it goes on to the page, which says it was too big.
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (!$_POST && !$_FILES && $len > 0 && $len > csrf_ini_bytes((string)ini_get('post_max_size'))) return;

    $want = (string)($_SESSION['__csrf'] ?? '');
    $sent = csrf_sent_token();
    if ($want !== '' && $sent !== '' && hash_equals($want, $sent)) return;
    csrf_reject();
}

function csrf_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') return PHP_INT_MAX;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n > 0 ? $n : PHP_INT_MAX;
}

/** Was this request sent by the page's own script (fetch / XHR), not a form? */
function csrf_wants_json(): bool {
    $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    if ($mode !== '' && $mode !== 'navigate') return true;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) return true;
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) return true;
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function csrf_reject(): void {
    $signedIn = !empty($_SESSION['user_id']);
    $msg = $signedIn
        ? 'Nothing was changed: this page was open for too long, or the request did not come from ProcureEase. Reload the page and try again.'
        : 'Your session has expired. Please try again.';

    // Kept in the Activity Log, so a blocked attempt can be seen later
    try {
        $who = null;
        if ($signedIn) {
            $s = db()->prepare('SELECT username, full_name, role FROM users WHERE id = ?');
            $s->execute([(int)$_SESSION['user_id']]);
            $who = $s->fetch() ?: null;
        }
        db()->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$who['username'] ?? '(not signed in)', $who['full_name'] ?? '', $who['role'] ?? '',
                       'Blocked a request without a valid security token', 'security',
                       basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
                       'A ' . strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) . ' to '
                       . (string)($_SERVER['SCRIPT_NAME'] ?? '') . ' had no valid CSRF token, so nothing was done.',
                       (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $t) {}

    if (csrf_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $msg, 'message' => $msg, 'csrf' => true]);
        exit;
    }

    // A form: back to the page it came from (this site only), with the message
    $back = 'index.php';
    $ref  = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '') === $host) {
        $back = $ref;
    }
    if ($signedIn) $_SESSION['flash'][] = ['type' => 'error', 'msg' => $msg];
    else           $_SESSION['login_error'] = $msg;
    header('Location: ' . $back, true, 303);
    exit;
}
