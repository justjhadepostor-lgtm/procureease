<?php
/* v112: an end user submits the department's FY set to its Department Admin — or takes it back.
   The same checks as the Department Admin's Submit window (config/submit-set.php); see config/head-submit.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/submit-set.php';
require_once __DIR__ . '/../config/head-submit.php';
require_login();
$u  = current_user();
$fy = (int)($_POST['fy'] ?? 0);
if ($fy < 2000 || $fy > 2100) $fy = active_fy();
$back = '../ppmp-edit.php?fy=' . $fy;
// back to the page it was sent from (one of the five documents)
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$refPage = basename((string)parse_url($ref, PHP_URL_PATH));
$refQuery = (string)parse_url($ref, PHP_URL_QUERY);
if (in_array($refPage, ['ppmp-edit.php', 'nonps-edit.php', 'annex-edit.php', 'market-scoping-edit.php', 'appcse-edit.php'], true)) {
    $back = '../' . $refPage . ($refQuery !== '' && preg_match('#^[A-Za-z0-9_=&%.\-]*$#', $refQuery) ? '?' . $refQuery : '');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
if (!hs_applies($u)) {
    flash('error', can_submit_for_dept($u) ? 'You submit the set yourself, with Submit.' : 'Only an end user of a department with a Department Admin submits to the Department Admin.');
    header('Location: ' . $back); exit;
}
$dept = (string)$u['dept_code'];
$head = hs_head_name($u);

if (($_POST['do'] ?? '') === 'unsubmit') {
    if (hs_get($dept, $fy)) {
        hs_clear($dept, $fy);
        log_activity('Unsubmitted from the Department Admin', 'ppmp', "$dept · FY $fy", "{$u['full_name']} took the FY $fy set back from $head.");
        flash('success', "Unsubmitted. The FY $fy set is back with you; submit it again when it is ready.");
    }
    header('Location: ' . $back); exit;
}

$chk = submit_set_for($u, $fy, true);
if (empty($chk['ok'])) { flash('error', (string)($chk['error'] ?? 'This cannot be submitted now.')); header('Location: ' . $back); exit; }
if (empty($chk['ready'])) {
    flash('error', 'Not submitted: everything in the Submit window is required. Complete what is marked ✗, then submit again.');
    header('Location: ' . $back); exit;
}
if (!hs_put($dept, $fy, $u)) {
    flash('error', 'Something went wrong — it was not submitted. Please try again.');
    header('Location: ' . $back); exit;
}
require_once __DIR__ . '/../config/notify.php';
notify_mail_reset();
$n = 0;
try {
    $s = db()->prepare("SELECT id FROM users WHERE dept_code = ? AND role = 'dept_admin' AND COALESCE(status, 'active') = 'active'");
    $s->execute([$dept]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        notify_user((int)$id, 'submission', "Submitted to you: {$dept} · FY $fy",
            "{$u['full_name']} submitted the FY $fy PPMP, APP-CSE, Non-PS list, Annex and Market Scoping to you. Review them, then Submit them to the Budget Unit and the Procurement Unit.",
            'ppmp-edit.php?fy=' . $fy);
        $n++;
    }
} catch (Throwable $t) {}
log_activity('Submitted to the Department Admin', 'ppmp', "$dept · FY $fy", "{$u['full_name']} submitted the FY $fy set to $head.");
// v134: "✓ Submitted to …" on the page says it — a message only when the email could not go
$__t = $GLOBALS['__pe_mail_tally'] ?? ['tried' => 0, 'sent' => 0];
if ((int)($__t['tried'] ?? 0) === 0 || (int)($__t['sent'] ?? 0) < (int)($__t['tried'] ?? 0)) {
    flash('error', trim(notify_mail_note()));
}
header('Location: ' . $back); exit;
