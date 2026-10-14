<?php
/**
 * OTP helpers — generate/save/verify 6-digit codes and mail them
 * to the user's email address.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp.php';

/** Generate a fresh 6-digit code (zero-padded). */
function generate_otp(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Save an OTP to the database; returns the row id. TTL in minutes. */
function save_otp(string $email, string $code, string $purpose, int $ttl_minutes = 10): int {
    // Invalidate any previous unused OTPs for this email + purpose
    db()->prepare('UPDATE email_otps SET used_at = NOW()
                   WHERE email = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$email, $purpose]);

    $stmt = db()->prepare('INSERT INTO email_otps (email, code, purpose, expires_at)
                           VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
    $stmt->execute([$email, $code, $purpose, $ttl_minutes]);
    return (int)db()->lastInsertId();
}

/** Verify a submitted code. Marks it used on success. */
function verify_otp(string $email, string $code, string $purpose): bool {
    $stmt = db()->prepare('SELECT id FROM email_otps
                           WHERE email = ? AND code = ? AND purpose = ?
                             AND used_at IS NULL
                             AND expires_at > NOW()
                           ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email, $code, $purpose]);
    $row = $stmt->fetch();
    if (!$row) return false;
    db()->prepare('UPDATE email_otps SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return true;
}

/**
 * Send an OTP email. Returns ['sent' => bool, 'code' => string].
 * Attempts PHP's mail() but always returns the code so the demo
 * flow can display it even when SMTP isn't configured in XAMPP.
 */
function send_otp_email(string $to, string $code, string $purpose): array {
    if ($purpose === 'register') {
        $subject = 'Your ProcureEase verification code';
        $intro   = "Welcome to ProcureEase. Please use the code below to verify your account.";
    } elseif ($purpose === 'login') {
        $subject = 'Your ProcureEase sign-in code';
        $intro   = "Use the code below to sign in to ProcureEase.";
    } else {
        $subject = 'Your ProcureEase password reset code';
        $intro   = "You (or someone) requested a password reset. Use the code below to continue.";
    }

    $body = "$intro\n\n"
          . "    Verification code: $code\n\n"
          . "This code expires in 10 minutes.\n"
          . "If you didn't request this, please ignore this email.\n\n"
          . "— ProcureEase\n"
          . "Aurora State College of Technology";

    $headers = "From: ProcureEase <procureease.ascot@gmail.com>\r\n"
             . "Reply-To: procureease.ascot@gmail.com\r\n"
             . "X-Mailer: PHP/" . phpversion();

    // Try PHP mail() first (works on many systems without SMTP config)
    $sent = @mail($to, $subject, $body, $headers);

    // If mail() fails, try SMTP (if configured)
    if (!$sent) {
        $sent = smtp_send($to, $subject, $body);
    }
    return ['sent' => (bool)$sent, 'code' => $code];
}

/**
 * A shortcut that generates + saves + sends an OTP in one call.
 * Returns ['code' => string, 'sent' => bool].
 */
function send_new_otp(string $email, string $purpose, int $ttl_minutes = 10): array {
    $code = generate_otp();
    save_otp($email, $code, $purpose, $ttl_minutes);
    return send_otp_email($email, $code, $purpose);
}

/**
 * Generate, save, and send an OTP by SMS.
 * The code is still stored against $otpKey (usually the account email) so verify_otp() is unchanged.
 * Returns ['sent' => bool, 'code' => string, 'error' => ?string].
 */
function send_otp_via_sms(string $mobile, string $otpKey, string $purpose, int $ttl_minutes = 10): array {
    require_once __DIR__ . '/twilio.php';
    require_once __DIR__ . '/phone.php';
    $intl = ph_mobile_intl($mobile);
    if ($intl === '') {
        return ['sent' => false, 'code' => '', 'error' => 'Invalid mobile number'];
    }
    $code = generate_otp();
    save_otp($otpKey, $code, $purpose, $ttl_minutes);
    $message = "Your ProcureEase verification code is: {$code}. Valid for 10 minutes. Do not share this code.";
    $sms = send_sms($intl, $message);
    return [
        'sent'  => !empty($sms['success']),
        'code'  => $code,
        'error' => $sms['error'] ?? null,
    ];
}
