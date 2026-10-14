<?php
/* ═══════════════════════════════════════════════════════════════
   v110: the phone that texts the verification codes
   (Forgot password and Register, when a mobile number is used).

   Texts go out through SMS Gateway for Android (https://sms-gate.app),
   a free app on an Android phone with a SIM and load — the phone's SIM
   sends each code to the user's own number, automatically.

   One-time setup (like the Gmail account in mail-config.php):
     1. Install the app on the Android phone (the APK from sms-gate.app),
        and allow it to send SMS.
     2. In the app, turn on "Cloud server". The Username and Password appear there.
     3. Put them below and save this file. Keep the phone on, with the app running.

   Local mode instead (no internet; the phone and this computer on the same Wi-Fi):
   turn on "Local server" in the app, set SMS_GATEWAY_MODE to 'local' and put the
   phone's local IP address (shown in the app) in SMS_GATEWAY_LOCAL_IP.

   Left blank, nothing is texted: a mobile number on Forgot password is told
   to use the email address instead.
   =============================================================== */

define('SMS_GATEWAY_MODE',     'cloud');   // 'cloud' or 'local'
define('SMS_GATEWAY_USERNAME', 'HJYPQK');        // from the app
define('SMS_GATEWAY_PASSWORD', 'xrnp5wbkluxzas');        // from the app
define('SMS_GATEWAY_LOCAL_IP', '');        // local mode only, e.g. '192.168.1.25'
