<?php
/* ═══════════════════════════════════════════════════════════════
   v104: what waited at the top of "My PPMPs" (user-dashboard.php) — the
   submission deadline banner and the Procurement Unit's requests to edit
   this department's documents (Grant / Decline) — shown at the top of the
   PPMP page, since My PPMPs now opens the PPMP itself. Same wording, same
   buttons, same answers (api/respond-edit.php).
   pe_dept_notices($u) prints it; everything stays inside the function, so
   nothing on the page that calls it is touched.
   =============================================================== */
function pe_dept_notices(array $u, bool $__onPpmpPage = true): void {
require_once __DIR__ . '/../config/edit-permission.php';
ensure_edit_request_columns();
$editReqs = [];
try {
    $__erq = db()->prepare('SELECT * FROM edit_requests WHERE dept_code = ? AND status = ? AND closed_at IS NULL ORDER BY requested_at DESC');
    $__erq->execute([(string)($u['dept_code'] ?? ''), 'pending']);
    $editReqs = $__erq->fetchAll();
} catch (Throwable $t) { $editReqs = []; }
?>
<?php
// ─── Deadline banner — show any active/upcoming/passed deadline ──────
try {
    $today = date('Y-m-d');
    $ds = db()->prepare('SELECT * FROM submission_deadlines WHERE fy >= ? ORDER BY deadline_date ASC');
    $ds->execute([active_fy()]);
    $upcoming = $ds->fetchAll();
    foreach ($upcoming as $d):
        $daysLeft = floor((strtotime($d['deadline_date']) - strtotime($today)) / 86400);
        // Has this dept already submitted for that FY?
        $c = db()->prepare("SELECT COUNT(*) FROM ppmps WHERE dept_code = ? AND fy = ?
                             AND status IN ('submitted','budget_approved','approved')");
        $c->execute([$u['dept_code'], (int)$d['fy']]);
        $alreadySubmitted = (int)$c->fetchColumn() > 0;
        if ($alreadySubmitted) continue; // no need to nag them

        if ($daysLeft < 0) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '⏰';
            $title = "OVERDUE: FY " . (int)$d['fy'] . " PPMP submission was due " . date('F j, Y', strtotime($d['deadline_date']));
            $sub   = "The deadline passed " . abs((int)$daysLeft) . " " . (abs((int)$daysLeft) == 1 ? 'day' : 'days') . " ago. Please submit as soon as possible.";
        } elseif ($daysLeft === 0.0 || $daysLeft === 0) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '⏰';
            $title = "DUE TODAY: FY " . (int)$d['fy'] . " PPMP submission";
            $sub   = "Today is the last day to submit your FY " . (int)$d['fy'] . " PPMP.";
        } elseif ($daysLeft <= 7) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '';
            $title = "Due in " . (int)$daysLeft . " " . ($daysLeft == 1 ? 'day' : 'days') . ": FY " . (int)$d['fy'] . " PPMP";
            $sub   = "Submission deadline: " . date('l, F j, Y', strtotime($d['deadline_date']));
        } else {
            $bg = 'var(--navy-50)'; $bd = 'var(--navy-500)'; $fg = 'var(--navy-700)'; $icon = '';
            $title = "Upcoming: FY " . (int)$d['fy'] . " PPMP submission";
            $sub   = "Deadline: " . date('l, F j, Y', strtotime($d['deadline_date'])) . " · " . (int)$daysLeft . " days from today";
        }
?>
  <div style="background: <?= $bg ?>; border: 1px solid <?= $bd ?>; border-left: 4px solid <?= $bd ?>; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px; display: flex; align-items: center; gap: 14px;">
    <div style="font-size: 26px;"><?= $icon ?></div>
    <div style="flex: 1;">
      <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13.5px; color: <?= $fg ?>; letter-spacing: 0.02em;">
        <?= e($title) ?></div>
      <div style="font-size: 12.5px; color: var(--ink); margin-top: 3px;">
        <?= e($sub) ?>
        <?php if (!empty($d['note'])): ?>
          <br><em style="color: var(--muted); font-size: 11.5px;"><?= e($d['note']) ?></em>
        <?php endif; ?></div></div>
    <?php if (empty($__onPpmpPage)): ?><a href="new-ppmp.php" style="background: #093c5d; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 12px; white-space: nowrap;">Start / continue PPMP →</a><?php endif; ?></div>
<?php endforeach;
} catch (Throwable $t) { /* table may not exist */ } ?>

    <?php foreach ($editReqs as $__er): ?>
      <div class="edit-req-banner" id="editReqTop<?= (int)$__er['id'] ?>">
        <div class="edit-req-banner-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
        <div style="flex: 1;">
          <div class="edit-req-title">Edit permission requested</div>
          <div class="edit-req-desc">
            The Procurement Unit (<strong><?= e($__er['requested_by']) ?></strong>) asks to edit your
            <strong><?= e(pu_edit_doc_label($__er)) ?></strong>. It can't make changes unless you grant access.
            <?php if (trim((string)$__er['reason']) !== ''): ?>
              <br><span style="font-weight: 600;">Reason:</span> <?= nl2br(e($__er['reason'])) ?>
            <?php endif; ?>
          </div>
          <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
            <form method="POST" action="api/respond-edit.php" style="display: inline;" data-confirm="This edit request will be declined." data-confirm-title="Decline edit request?" data-confirm-label="Decline" data-confirm-tone="danger">
              <input type="hidden" name="request_id" value="<?= (int)$__er['id'] ?>">
              <input type="hidden" name="response" value="declined">
              <input type="hidden" name="return_to" value="user-dashboard.php">
              <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">Decline</button></form>
            <form method="POST" action="api/respond-edit.php" style="display: inline;"
                  data-confirm="<?= in_array((string)($__er['doc_type'] ?? ''), ['', 'ppmp'], true)
                      ? 'The Procurement Unit will be able to edit this PPMP until it gives final approval or sends the PPMP back to you.'
                      : 'The Procurement Unit will be able to edit this ' . e(PU_EDIT_DOCS[(string)$__er['doc_type']] ?? 'document') . ' until it approves it or sends it back to you.' ?>"
                  data-confirm-title="Grant edit access?" data-confirm-label="Grant access" data-confirm-tone="info">
              <input type="hidden" name="request_id" value="<?= (int)$__er['id'] ?>">
              <input type="hidden" name="response" value="granted">
              <input type="hidden" name="return_to" value="user-dashboard.php">
              <button type="submit" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;">Grant edit access</button></form>
          </div></div></div>
    <?php endforeach; ?>
<?php }
