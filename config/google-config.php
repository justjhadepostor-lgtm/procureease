<?php
/* ═══════════════════════════════════════════════════════════════
   GOOGLE SIGN-IN CREDENTIALS
   ═══════════════════════════════════════════════════════════════

   You can set these two values in either place:

     1. In the app — Admin → Email Settings → Google Sign-In.
        Stored in the database. Convenient, but it disappears if the
        database is ever re-imported or rebuilt.

     2. Right here in this file. Survives database changes, and moves
        with the project when you copy or upload it.

   If both are filled in, the database values win, so you can still
   change them from inside the app without editing files.

   ─── WHERE TO GET THEM ─────────────────────────────────────────
     console.cloud.google.com
       → APIs & Services → Credentials
       → OAuth client ID (Web application)

   Make sure the callback URL shown on the Email Settings page is
   listed under "Authorised redirect URIs" for that client.
   =============================================================== */

define('GOOGLE_CLIENT_ID',     '21550419774-isp10ifnp4e2p55hpnh6emkalpq1dt19.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
