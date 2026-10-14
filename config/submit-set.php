<?php
/* ═══════════════════════════════════════════════════════════════
   The Submit window — one for the whole set.

   Submit on any of the five documents (PPMP, APP-CSE, Non-PS list, Annex,
   Market Scoping) opens this same window: what goes, and a ✓ or ✗ for
   each. When everything is ✓ the set is submitted from there, whichever
   document it was opened on. It always submits through
   api/submit-ppmp.php, so every rule there applies the same way.

   ppmp-edit.php renders it with the page; the other four fetch it from
   api/submit-set.php after saving, so it reflects what was just typed.
   =============================================================== */
require_once __DIR__ . '/companions.php';

/**
 * The "Within the allotment" line (empty when there is no budget check):
 * ✓ exactly the allotment · ! below it, or no allotment yet (can still be
 * submitted) · ✗ over it (cannot be submitted).
 */
function submit_set_budget_li(?array $bm, bool $revision = false): string {
    if (!$bm) return '';
    // The PPMP's own Total Amount against the allotment (v77): ✓ within · ✗ over · ! none set yet
    $pp = $bm['pp'] ?? null;
    $ppLi = '';
    if ($pp) {
        $pc = $pp['state'] === 'within' ? 'ok' : ($pp['state'] === 'over' ? 'miss' : 'warn');
        $ppLi = '<li class="' . $pc . '" id="submitPpmpAllot" data-key="ppmp-allot" data-state="' . e($pp['state']) . '"'
              . ($pp['allot'] !== null ? ' data-allot="' . number_format((float)$pp['allot'], 2, '.', '') . '"' : '')
              . (!empty($pp['pool']) ? ' data-pool="' . e($pp['pool']) . '" data-noun="' . e($pp['noun'] ?? 'units') . '"' : '') . '>'
              . '<span class="set-mark">' . ($pc === 'ok' ? '✓' : ($pc === 'miss' ? '✗' : '!')) . '</span>'
              . '<span class="set-name">PPMP within the allotment</span>'
              . '<span class="set-note"><span class="pp-text">' . e($pp['note']) . '</span></span></li>';
    }
    $state = $bm['state'] ?? ($bm['ok'] ? 'equal' : 'over');
    // v97: a resubmission sends only the PPMP — the APP-CSE + Non-PS line is shown only when it stops it (✗)
    if ($revision && $state !== 'over') return $ppLi;
    $cls   = $state === 'equal' ? 'ok' : ($state === 'over' ? 'miss' : 'warn');
    $mark  = $state === 'equal' ? '✓' : ($state === 'over' ? '✗' : '!');
    return $ppLi . '<li class="' . $cls . '" id="submitBudget" data-key="budget" data-state="' . e($state) . '"'
         . ' data-appcse="' . number_format($bm['appcse'], 2, '.', '') . '" data-nonps="' . number_format($bm['nonps'], 2, '.', '') . '"'
         . ($bm['allot'] !== null ? ' data-allot="' . number_format((float)$bm['allot'], 2, '.', '') . '"' : '') . '>'
         . '<span class="set-mark">' . $mark . '</span><span class="set-name">APP-CSE + Non-PS within the allotment</span>'
         . '<span class="set-note"><span class="bm-text">' . e($bm['note']) . '</span>'
         . ' <span class="bm-links"' . (in_array($state, ['under', 'over'], true) ? '' : ' hidden') . '><a href="appcse-edit.php">APP-CSE</a> · <a href="nonps-list.php">Non-PS</a></span></span></li>';
}

/**
 * Everything inside the window above its buttons: the title, what happens,
 * the ✓/✗ list and the line under it.
 *   $ppmpLi — the PPMP's own line (the PPMP page says "This plan").
 */
function submit_set_body(int $fy, bool $hasRevision, string $ppmpStatus, bool $toPU, ?array $set, ?array $bm, string $ppmpLi, string $toHead = ''): string {
    ob_start();
?>
    <?php $__backTo = ($hasRevision && $ppmpStatus !== 'draft') || $toPU ? 'Procurement Unit' : 'Budget Unit'; ?>
    <?php if ($toHead !== ''): /* v112: an end user — the set goes to the Department Admin (config/head-submit.php) */ ?>
    <div class="modal-title" id="submitToHeadTitle">Send this to your Department Admin?</div>
    <div class="modal-text"> Clicking <strong>Submit</strong> sends your whole FY <?= (int)$fy ?> set at once — the PPMP, APP-CSE, Non-PS list,
      Annex and Market Scoping — to <strong><?= e($toHead) ?></strong>, your Department Admin, who reviews it and submits it to the
      <strong><?= $__backTo ?></strong> and the <strong>Procurement Unit</strong>. You can pull it back with <strong>Unsubmit</strong> until then.
    </div>
    <?php elseif ($hasRevision): ?>
    <div class="modal-title">Resubmit this PPMP to the <?= $__backTo ?>?</div>
    <div class="modal-text" id="resubmitText"> Only the PPMP that was sent back goes back, to the
      <strong><?= $__backTo ?></strong>. Your APP-CSE, Non-PS list, Annex and Market Scoping are not sent again.
    </div>
    <?php else: ?>
    <div class="modal-title"><?= $toPU ? 'Send this PPMP to the Procurement Unit?' : 'Send this PPMP to the Budget Unit?' ?></div>
    <div class="modal-text"> Clicking <strong>Submit</strong> sends your whole FY <?= (int)$fy ?> set at once: the PPMP to the
      <strong><?= $toPU ? 'Procurement Unit' : 'Budget Unit' ?></strong>, and the APP-CSE, Non-PS list, Annex and Market Scoping to the
      <strong>Procurement Unit</strong>. You can pull it back with the <strong>Unsubmit</strong> button as long as Budget hasn't reviewed it yet.
    </div>
    <?php endif; ?>
    <?php if ($set): ?>
    <ul class="set-list" id="submitSet">
      <?= $ppmpLi . "\n" ?>
      <?php foreach ($set['items'] as $__k => $__i): ?>
        <?php if ($hasRevision && $__i['ok']) continue;   /* v97: a resubmission lists only what goes back (the PPMP) — and anything that stops it */ ?>
        <li class="<?= $__i['ok'] ? 'ok' : 'miss' ?>" data-key="<?= e($__k) ?>">
          <span class="set-mark"><?= $__i['ok'] ? '✓' : '✗' ?></span>
          <span class="set-name"><?= e($__i['label']) ?></span>
          <span class="set-note"><?= e(($hasRevision && !empty($__i['send'])) ? 'Stays here — a resubmission sends only the PPMP.' : $__i['note']) ?><?php if (!$__i['ok']): ?> <a href="<?= e($__i['link']) ?>">Open</a><?php endif; ?></span></li>
      <?php endforeach; ?>
      <?= submit_set_budget_li($bm, $hasRevision) ?>
    </ul>
    <div class="set-block" id="submitBlocked"<?= ($set['ok'] && $bm && $bm['ok'] && submit_set_ppmp_ok($ppmpLi)) ? ' hidden' : '' ?>>Everything above is required. Complete what is marked ✗, then submit again.</div>
    <?php elseif ($bm): ?>
    <ul class="set-list" id="submitSet"><?= submit_set_budget_li($bm, $hasRevision) ?></ul>
    <div class="set-block" id="submitBlocked"<?= $bm['ok'] ? ' hidden' : '' ?>>It is over the allotment, so nothing can be sent. Correct what is marked ✗, then submit again.</div>
    <?php endif; ?>
<?php
    return ob_get_clean();
}

/** The PPMP line's own mark (the PPMP page's "This plan" is always ✓). */
function submit_set_ppmp_ok(string $ppmpLi): bool {
    return strpos($ppmpLi, '<li class="miss"') !== 0;
}

/** May the set be submitted as it stands? */
function submit_set_ready(?array $set, ?array $bm, string $ppmpLi): bool {
    return !(($set && !$set['ok']) || ($bm && !$bm['ok'])) && submit_set_ppmp_ok($ppmpLi);
}

/**
 * The window for this department's FY set, as the other four documents show
 * it. Returns ok=false with a reason when there is nothing to submit.
 */
function submit_set_for(array $u, int $fy, bool $toHead = false): array {
    $dept = (string)($u['dept_code'] ?? '');
    // v112: an end user's window sends the set to the Department Admin (config/head-submit.php)
    if (!$toHead && !can_submit_for_dept($u)) {
        $head = dept_submitter_name($u);
        return ['ok' => false, 'error' => $head !== '' ? "$head submits for your department." : 'Only your Department Admin can submit.'];
    }
    $pdo = db();
    // The PPMP of the year (not one in the Archive) — the one the set is submitted with
    $ppmp = null;
    try {
        $s = $pdo->prepare('SELECT p.id, p.status FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                               AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)
                          ORDER BY p.id DESC');
        $s->execute([$dept, $fy]);
        $ppmp = $s->fetch() ?: null;
    } catch (Throwable $t) { $ppmp = null; }
    $hasRevision = false;
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
        $s->execute([$dept, $fy]);
        $hasRevision = (int)$s->fetchColumn() > 0;
    } catch (Throwable $t) {}
    $status = (string)($ppmp['status'] ?? 'draft');
    if ($ppmp && ($status === 'approved' || ($status !== 'draft' && !$hasRevision))) {
        return ['ok' => false, 'error' => "Your FY $fy PPMP has already been submitted."];
    }
    // v81: a program / major of a school goes with the whole school the first time (My Department)
    require_once __DIR__ . '/academic-units.php';
    if (!$hasRevision && au_is_unit($dept)) {
        return ['ok' => false, 'error' => 'The programs and majors of ' . au_school_of($dept) . ' are submitted all at once. '
            . 'Open My Department and use "Submit whole school" when every one is approved.'];
    }

    // The PPMP's own line
    if ($ppmp) {
        $n = 0;
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM ppmp_entries WHERE ppmp_id = ?');
            $s->execute([(int)$ppmp['id']]);
            $n = (int)$s->fetchColumn();
        } catch (Throwable $t) {}
        $ppmpLi = '<li class="ok"><span class="set-mark">✓</span><span class="set-name">PPMP</span><span class="set-note">Your FY ' . $fy . ' plan · '
                . $n . ' item' . ($n === 1 ? '' : 's') . '</span></li>';
    } else {
        $ppmpLi = '<li class="miss"><span class="set-mark">✗</span><span class="set-name">PPMP</span><span class="set-note">Not started — prepare your PPMP.'
                . ' <a href="ppmp-edit.php?fy=' . $fy . '">Open</a></span></li>';
    }
    $set = ppmp_companions($dept, $fy);
    $bm  = ppmp_budget_match($dept, $fy, (int)($ppmp['id'] ?? 0));
    return [
        'ok'      => true,
        'ppmp_id' => (int)($ppmp['id'] ?? 0),
        'fy'      => $fy,
        'ready'   => submit_set_ready($set, $bm, $ppmpLi),
        'html'    => submit_set_body($fy, $hasRevision, $status, ppmp_skips_budget($dept), $set, $bm, $ppmpLi,
                                     $toHead ? (dept_submitter_name($u) ?: 'your Department Admin') : ''),
    ];
}
