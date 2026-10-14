<?php
require_once __DIR__ . '/config/auth.php';
require_login();  // Admin (Procurement Unit) can create/open its own PPMP too

// There's nothing for the user to fill in when starting a PPMP:
//   • Department is taken from the signed-in user's account.
//   • Fiscal year is locked to the admin-set system year (active_fy()).
//   • "Prepared by" defaults to the user's name and position.
// So we skip the old intake form and go straight to the editor, which opens
// this department's FY PPMP or creates the draft automatically if none exists.
header('Location: ppmp-edit.php?fy=' . active_fy());
exit;
