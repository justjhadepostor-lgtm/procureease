<?php
/**
 * Compact SMTP client that speaks enough of the protocol to send mail via Gmail.
 * Uses PHP's built-in stream_socket_client — no PHPMailer, no Composer, no
 * external libraries. Just plain sockets + STARTTLS + AUTH LOGIN.
 *
 * Reads credentials from config/mail-config.php first (zero-admin setup);
 * falls back to the `settings` table only if the file wasn't filled in.
 */

require_once __DIR__ . '/mail-config.php';

/** Look up a mail setting: file-config wins, DB is fallback. */
function smtp_setting(string $key, string $default = ''): string {
    // File-config takes priority — set once at install, works forever
    $fromFile = [
        'smtp_host'      => defined('MAIL_HOST')      ? (string)MAIL_HOST      : '',
        'smtp_port'      => defined('MAIL_PORT')      ? (string)MAIL_PORT      : '',
        'smtp_user'      => defined('MAIL_USERNAME')  ? (string)MAIL_USERNAME  : '',
        'smtp_pass'      => defined('MAIL_PASSWORD')  ? (string)MAIL_PASSWORD  : '',
        'smtp_from'      => defined('MAIL_USERNAME')  ? (string)MAIL_USERNAME  : '',
        'smtp_from_name' => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : '',
    ];
    if (!empty($fromFile[$key])) return $fromFile[$key];

    // DB fallback (admin-set values from mail-settings.php)
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = [];
            $rows = db()->query("SELECT key_name, value FROM settings WHERE key_name LIKE 'smtp_%'")->fetchAll();
            foreach ($rows as $r) $cache[$r['key_name']] = $r['value'];
        } catch (Throwable $t) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

/**
 * Send an email via SMTP. Returns true on success, false on failure.
 * Uses SMTP if configured; falls back to false so the caller can decide
 * whether to try mail() or expose a demo code.
 */
function smtp_send(string $to, string $subject, string $body): bool {
    $host = smtp_setting('smtp_host', 'smtp.gmail.com');
    $port = (int)smtp_setting('smtp_port', '587');
    $user = smtp_setting('smtp_user');
    $pass = str_replace(' ', '', smtp_setting('smtp_pass'));  // Gmail shows the password with spaces — strip them
    $from = smtp_setting('smtp_from', $user);
    $fromName = smtp_setting('smtp_from_name', 'ProcureEase');

    if (!$user || !$pass || !$host) {
        error_log("SMTP: Missing credentials - host=$host, user=$user");
        return false;
    }

    // ─── Open connection ──────────────────────────────────
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 15);
    if (!$sock) {
        error_log("SMTP connect failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($sock, 15);

    // Helper: read the multi-line SMTP response (looks like "250-... \n 250 ...").
    $readResp = function() use ($sock): string {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $out .= $line;
            // A line whose 4th char is a SPACE marks the end of the response
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };
    $send = function(string $cmd) use ($sock) {
        fwrite($sock, $cmd . "\r\n");
    };
    $expect = function(string $resp, int $code): bool {
        return (int)substr(trim($resp), 0, 3) === $code;
    };

    try {
        // Initial greeting
        if (!$expect($readResp(), 220)) throw new Exception('No SMTP greeting');

        // EHLO
        $send("EHLO procureease.local");
        if (!$expect($readResp(), 250)) throw new Exception('EHLO failed');

        // STARTTLS if we're on port 587
        if ($port === 587) {
            $send("STARTTLS");
            if (!$expect($readResp(), 220)) throw new Exception('STARTTLS failed');
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('TLS handshake failed');
            }
            // EHLO again after TLS
            $send("EHLO procureease.local");
            if (!$expect($readResp(), 250)) throw new Exception('Post-TLS EHLO failed');
        }

        // AUTH LOGIN
        $send("AUTH LOGIN");
        if (!$expect($readResp(), 334)) throw new Exception('AUTH LOGIN not accepted');
        $send(base64_encode($user));
        if (!$expect($readResp(), 334)) throw new Exception('Username rejected');
        $send(base64_encode($pass));
        $authResp = $readResp();
        if (!$expect($authResp, 235)) {
            throw new Exception('Auth failed: ' . trim($authResp));
        }

        // MAIL FROM / RCPT TO
        $send("MAIL FROM:<{$from}>");
        if (!$expect($readResp(), 250)) throw new Exception('MAIL FROM rejected');
        $send("RCPT TO:<{$to}>");
        if (!$expect($readResp(), 250)) throw new Exception('RCPT TO rejected');

        // DATA
        $send("DATA");
        if (!$expect($readResp(), 354)) throw new Exception('DATA rejected');

        $headers = "From: " . mb_encode_mimeheader($fromName) . " <{$from}>\r\n"
                 . "To: {$to}\r\n"
                 . "Subject: " . mb_encode_mimeheader($subject) . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n"
                 . "Date: " . date('r') . "\r\n";

        // Dot-stuff any lines beginning with a period (RFC 5321 §4.5.2)
        $safeBody = preg_replace('/^\./m', '..', $body);
        $send($headers . "\r\n" . $safeBody . "\r\n.");
        if (!$expect($readResp(), 250)) throw new Exception('Message body rejected');

        // QUIT
        $send("QUIT");
        fclose($sock);
        return true;
    } catch (Throwable $ex) {
        error_log('smtp_send error: ' . $ex->getMessage());
        @fclose($sock);
        return false;
    }
}
