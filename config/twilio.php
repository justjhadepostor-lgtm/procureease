<?php
/**
 * v110: Verification codes by text message through SMS Gateway for Android (https://sms-gate.app).
 *
 * The free "SMS Gateway for Android" app turns an Android phone into the sender: the phone's own
 * SIM sends each text. Its username and password go in config/sms-config.php (like mail-config.php):
 *   Cloud mode: POST https://api.sms-gate.app/3rdparty/v1/messages  (the phone and this PC only need internet)
 *   Local mode: POST http://<phone's local IP>:8080/message          (the phone and this PC on the same Wi-Fi)
 * Both use HTTP Basic auth with the username and password the app shows, and the body
 *   {"textMessage": {"text": "..."}, "phoneNumbers": ["+639XXXXXXXXX"]}
 *
 * (The file keeps its old name because config/otp.php loads it for send_sms().)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/phone.php';

/** What config/sms-config.php says; [] when that file is not there. */
function sms_gateway_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $config = [];
    $file = __DIR__ . '/sms-config.php';
    if (is_file($file)) {
        require_once $file;
        $config = [
            'provider'         => 'smsgate',
            'smsgate_mode'     => defined('SMS_GATEWAY_MODE') ? (string)SMS_GATEWAY_MODE : 'cloud',
            'smsgate_username' => defined('SMS_GATEWAY_USERNAME') ? trim((string)SMS_GATEWAY_USERNAME) : '',
            'smsgate_password' => defined('SMS_GATEWAY_PASSWORD') ? (string)SMS_GATEWAY_PASSWORD : '',
            'smsgate_local_ip' => defined('SMS_GATEWAY_LOCAL_IP') ? trim((string)SMS_GATEWAY_LOCAL_IP) : '',
        ];
        if (defined('SMS_GATEWAY_API_BASE')) $config['smsgate_api_base'] = (string)SMS_GATEWAY_API_BASE;
    }
    return $config;
}

/** Is SMS Gateway set up (username, password, and the phone's IP in Local mode)? */
function sms_gateway_enabled(): bool {
    $cfg = sms_gateway_config();
    if (($cfg['provider'] ?? '') !== 'smsgate') return false;
    return !empty($cfg['smsgate_username']) && !empty($cfg['smsgate_password'])
        && (($cfg['smsgate_mode'] ?? 'cloud') !== 'local' || !empty($cfg['smsgate_local_ip']));
}

/** Sends one text message. ['success' => bool, 'error' => string|null] */
function send_sms(string $to, string $message): array {
    if (!sms_gateway_enabled()) {
        return ['success' => false, 'error' => 'SMS Gateway is not set up'];
    }
    return smsgate_send_sms($to, $message);
}

/** Sends one text message through SMS Gateway for Android. */
function smsgate_send_sms(string $to, string $message): array {
    $cfg  = sms_gateway_config();
    $user = trim((string)($cfg['smsgate_username'] ?? ''));
    $pass = (string)($cfg['smsgate_password'] ?? '');
    $mode = ($cfg['smsgate_mode'] ?? 'cloud') === 'local' ? 'local' : 'cloud';
    if ($user === '' || $pass === '') {
        return ['success' => false, 'error' => 'SMS Gateway is not set up'];
    }
    $intl = ph_mobile_intl($to);
    if ($intl === '') {
        return ['success' => false, 'error' => 'Invalid phone number format'];
    }
    if ($mode === 'local') {
        $host = trim((string)($cfg['smsgate_local_ip'] ?? ''));
        if ($host === '') return ['success' => false, 'error' => "Local mode: the phone's local IP address is not set"];
        $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
        if (strpos($host, ':') === false) $host .= ':8080';
        $url = 'http://' . $host . '/message';
    } else {
        $url = rtrim((string)($cfg['smsgate_api_base'] ?? 'https://api.sms-gate.app/3rdparty/v1'), '/') . '/messages';
    }
    $payload = json_encode(['textMessage' => ['text' => $message], 'phoneNumbers' => [$intl]]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (stripos($url, 'https://') === 0) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        sms_curl_ca($ch);
    }
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) {
        error_log('SMS Gateway cURL error: ' . $error);
        return ['success' => false, 'error' => 'Could not reach SMS Gateway'
            . ($mode === 'local' ? " (is the phone on the same Wi-Fi, with the app's Local server Online?)" : '') . ': ' . $error];
    }
    $result = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        if (is_array($result) && strtolower((string)($result['state'] ?? '')) === 'failed') {
            return ['success' => false, 'error' => 'the phone could not send it'];
        }
        return ['success' => true, 'error' => null, 'id' => is_array($result) ? ($result['id'] ?? null) : null];
    }
    $errorMsg = is_array($result) ? (string)($result['message'] ?? $result['error'] ?? '') : '';
    if ($httpCode === 401) $errorMsg = 'wrong username or password (copy them again from the app)';
    error_log('SMS Gateway API error: HTTP ' . $httpCode . ' ' . $errorMsg);
    return ['success' => false, 'error' => 'HTTP ' . $httpCode . ($errorMsg !== '' ? ' — ' . $errorMsg : '')];
}

/**
 * XAMPP's PHP often has no certificate list for cURL (php.ini "curl.cainfo" is empty), so HTTPS to the
 * Cloud server would fail with "SSL certificate problem". When none is set, the list that comes with
 * ProcureEase (config/cacert.pem, Mozilla's) is used. The certificate is still checked.
 */
function sms_curl_ca($ch): void {
    $set = trim((string)ini_get('curl.cainfo'), " \t\"'");
    if ($set !== '' && is_file($set)) return;   // php.ini already has a working list
    $pem = __DIR__ . '/cacert.pem';
    if (is_file($pem)) curl_setopt($ch, CURLOPT_CAINFO, $pem);
}
