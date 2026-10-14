<?php
/* A department submits its APP-CSE on its own (config/appcse-review.php):
   after the Procurement Unit sent it back for revision, or when its PPMP has
   already gone without it. It goes to the Procurement Unit; nothing may go
   over the allotment (config/companions.php). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/appcse-review.php';
require_once __DIR__ . '/../config/companions.php';
require_once __DIR__ . '/../config/archive.php';
require_once __DIR__ . '/../config/notify.php';
require_login();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../appcse-edit.php'); exit; }
$u    = current_user();
$dept = (string)($u['dept_code'] ?? '');
$fy   = active_fy();
$back = '../appcse-edit.php';

if ($dept === '') { flash('error', 'Your account has no department.'); header('Location: ../user-dashboard.php'); exit; }
if (!can_submit_for_dept($u)) {
    $head = dept_submitter_name($u);
    flash('error', $head !== '' ? "Only your Department Admin ($head) can submit this. Your work is saved — ask them to send it."
                                : 'Only your Department Admin can submit this.');
    header('Location: ' . $back); exit;
}
$__st = appcse_status($fy, $dept);
$p = appcse_ppmp($fy, $dept);
$__solo = $__st === 'revision' || ($__st === 'draft' && $p && $p['status'] !== 'draft');
if (!$__solo) {
    flash('error', $__st === 'draft' ? 'This APP-CSE is submitted together with your PPMP. Use Submit on the PPMP — it sends the whole set at once.'
                 : ($__st === 'approved' ? 'This APP-CSE is already approved.' : 'This APP-CSE is already with the Procurement Unit.'));
    header('Location: ' . $back); exit;
}
if (arc_appcse_is_archived($fy, $dept)) { flash('error', 'This APP-CSE is in the Archive. Restore it first.'); header('Location: ' . $back); exit; }

$q = db()->prepare("SELECT COUNT(*) FROM appcse_items WHERE dept_code = ? AND fy = ?
                      AND (`jan`+`feb`+`mar`+`apr`+`may`+`jun`+`jul`+`aug`+`sep`+`oct`+`nov`+`dec`) > 0");
$q->execute([$dept, $fy]);
if ((int)$q->fetchColumn() === 0) { flash('error', 'Your APP-CSE is empty. Enter the monthly quantities you need, then submit.'); header('Location: ' . $back); exit; }

$bm = ppmp_budget_match($dept, $fy, (int)($p['id'] ?? 0));
if (!$bm['ok']) {
    flash('error', 'Not submitted. ' . $bm['note']);
    header('Location: ' . $back); exit;
}

$__again = $__st === 'revision';
appcse_set_status($fy, $dept, 'submitted', (string)($u['full_name'] ?? ''));
log_activity($__again ? 'Resubmitted APP-CSE after revision' : 'Submitted APP-CSE', 'appcse', "$dept · FY $fy", 'Sent to the Procurement Unit on its own.');
notify_by_role('admin', $__again ? 'resubmission' : 'submission',
    ($__again ? 'APP-CSE resubmitted after revision: ' : 'New APP-CSE submitted: ') . "$dept · FY $fy",
    ($u['full_name'] ?? '') . ($__again ? ' made the changes asked for and sent the FY ' . $fy . ' APP-CSE back.' : ' submitted the FY ' . $fy . ' APP-CSE.'),
    'appcse-review.php?fy=' . $fy . '&dept=' . urlencode($dept));
flash('success', 'Submitted. Your ' . ($__again ? 'revised ' : '') . 'APP-CSE went to the Procurement Unit.');
celebrate_submitted("FY $fy APP-CSE", 'Procurement Unit');   // v97
header('Location: ' . $back);
exit;
