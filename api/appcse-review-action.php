<?php
/* The Procurement Unit reviews a department's APP-CSE (v77, config/appcse-review.php):
     do=approve   — approved; counted in the APP-CSE (Consolidated) from now on
     do=revision  — sent back to the department with a note; it is open for
                    changes there and submitted again on its own
   Either one ends the PU's permission to edit it (config/edit-permission.php). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/appcse-review.php';
require_once __DIR__ . '/../config/edit-permission.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../appcse-folders.php'); exit; }
$u    = current_user();
$fy   = (int)($_POST['fy'] ?? 0);
$dept = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['dept'] ?? '')));
$do   = (string)($_POST['do'] ?? (isset($_POST['note']) ? 'revision' : ''));
$back = '../appcse-review.php?fy=' . $fy . '&dept=' . urlencode($dept);
$who  = (string)($u['full_name'] ?? 'The Procurement Unit');

[$ok, $why] = appcse_can_review($u, $fy, $dept);
if (!$ok) { flash('error', $why); header('Location: ' . $back); exit; }

try {
    if ($do === 'approve') {
        appcse_set_status($fy, $dept, 'approved', $who);
        pu_doc_edit_close('appcse', $fy . ':' . $dept);
        log_activity('Approved APP-CSE', 'appcse', "$dept · FY $fy", '');
        notify_by_dept($dept, 'approval', "Your FY $fy APP-CSE is approved",
            'The Procurement Unit approved the APP-CSE your department submitted.', 'appcse-edit.php');
        flash('success', "Approved the FY $fy APP-CSE of $dept. It is now counted in the APP-CSE (Consolidated).");
    } elseif ($do === 'revision') {
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
        if ($note === '') { flash('error', 'Write what needs to change, so the department knows what to revise.'); header('Location: ' . $back); exit; }
        appcse_set_status($fy, $dept, 'revision', $who, $note);
        pu_doc_edit_close('appcse', $fy . ':' . $dept);
        log_activity('Sent APP-CSE back for revision', 'appcse', "$dept · FY $fy", $note);
        notify_mail_reset();
        notify_by_dept($dept, 'revision', "Your FY $fy APP-CSE was sent back for revision", $who . ': ' . $note, 'appcse-edit.php');
        flash('success', "The APP-CSE went back to $dept with your note. They submit it again on its own when it is ready." . notify_mail_note());   // v106: and by email
    } else {
        flash('error', 'Unknown action.');
    }
} catch (Throwable $t) {
    error_log('appcse review: ' . $t->getMessage());
    flash('error', 'Could not complete that. Nothing was changed.');
}
header('Location: ' . $back);
exit;
