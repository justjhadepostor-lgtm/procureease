<?php
/* ═══════════════════════════════════════════════════════════════
   Everything a department sends with its PPMP.

   One Submit, on the PPMP, sends the whole set for the fiscal year:
     PPMP                → the Budget Unit (the PU for Budget's own)
     APP-CSE (v77), Non-PS Supplies list, Annex, Market Scoping
                         → the Procurement Unit
   All of them are required. The PPMP cannot be submitted until each one is
   ready; what is missing is listed on the PPMP's Submit window.

   Ready means:
     APP-CSE         at least one quantity entered
     Non-PS list     the year's list exists (as before)
     Annex           at least one annex with its project name
     Market Scoping  at least one document, with at least one activity
                     ticked (☑) in "Market Scoping Activity/ies Conducted"
   A document already submitted or approved counts, and is left as it is.
   A blank Annex or Market Scoping draft (opened but never filled in) is
   left as a draft and does not hold the PPMP back when another one is ready.
   An archived document counts only if it was approved; a draft in the
   Archive has to be restored first.

   After the PPMP is submitted, a companion sent back for revision, or one
   started later, is submitted on its own page as before.
   =============================================================== */
require_once __DIR__ . '/auth.php';

/** A Market Scoping document with something in it: a ticked box, or (an
 *  old free-form one) any text at all. */
function companion_scoping_ready(string $html): bool {
    if (mb_strpos($html, '☐') !== false || mb_strpos($html, '☑') !== false) {
        return mb_strpos($html, '☑') !== false;
    }
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) !== '';
}

/** What the PPMP of this department and year would send, and what is missing. */
function ppmp_companions(string $dept, int $fy): array {
    $pdo = db();
    $items = [];
    $live = fn($r) => (int)($r['archived'] ?? 0) !== 1;

    // ── APP-CSE ──
    $acArchived = false; $acQty = 0;
    try {
        $s = $pdo->prepare('SELECT archived FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $s->execute([$fy, $dept]);
        $acArchived = (int)$s->fetchColumn() === 1;
    } catch (Throwable $t) {}
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM appcse_items WHERE dept_code = ? AND fy = ?
                              AND (`jan`+`feb`+`mar`+`apr`+`may`+`jun`+`jul`+`aug`+`sep`+`oct`+`nov`+`dec`) > 0");
        $s->execute([$dept, $fy]);
        $acQty = (int)$s->fetchColumn();
    } catch (Throwable $t) { $acQty = 1; }   // no table yet: not this check's to block
    // v77: it goes to the Procurement Unit, with a state of its own (config/appcse-review.php)
    require_once __DIR__ . '/appcse-review.php';
    $acSt = appcse_status($fy, $dept);
    if (!$acArchived && in_array($acSt, ['submitted', 'approved'], true)) {
        $items['appcse'] = ['label' => 'APP-CSE', 'link' => 'appcse-edit.php', 'send' => [], 'ok' => true,
            'note' => $acSt === 'approved' ? 'Approved' : 'Already with the Procurement Unit'];
    } else {
        $items['appcse'] = ['label' => 'APP-CSE', 'link' => 'appcse-edit.php',
            'send' => (!$acArchived && $acQty > 0) ? [$fy . ':' . $dept] : [],
            'ok'   => !$acArchived && $acQty > 0,
            'note' => $acArchived ? 'In the Archive — restore it first.'
                    : ($acQty > 0 ? "$acQty item" . ($acQty === 1 ? '' : 's') . ' with quantities · will be sent to the Procurement Unit'
                                  : 'Still empty — enter the monthly quantities you need.')];
    }

    // ── Non-PS Supplies list ──
    $rows = [];
    try {
        $s = $pdo->prepare('SELECT * FROM nonps_submissions WHERE dept_code = ? AND fy = ? ORDER BY id DESC');
        $s->execute([$dept, $fy]);
        $rows = $s->fetchAll();
    } catch (Throwable $t) {}
    $it = ['label' => 'Non-PS Supplies list', 'link' => 'nonps-list.php', 'send' => [], 'ok' => false,
           'note' => 'Not started — create your list (it may be empty).'];
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if (!$live($r)) {
            if ($st === 'approved') { $it['ok'] = true; $it['note'] = 'Approved'; break; }
            $it['note'] = 'In the Archive — restore it first.';
            continue;
        }
        $it['ok'] = true;
        if (in_array($st, ['draft', 'revision'], true)) { $it['send'][] = (int)$r['id']; $it['note'] = 'Will be sent to the Procurement Unit'; }
        else $it['note'] = $st === 'approved' ? 'Approved' : 'Already with the Procurement Unit';
        break;
    }
    $items['nonps'] = $it;

    // ── Annex ──
    $rows = [];
    try {
        $s = $pdo->prepare('SELECT * FROM annex_forms WHERE dept_code = ? AND fy = ? ORDER BY id');
        $s->execute([$dept, $fy]);
        $rows = $s->fetchAll();
    } catch (Throwable $t) {}
    $send = []; $done = 0; $blank = 0; $archivedDraft = 0;
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if (!$live($r)) { if ($st === 'approved') $done++; else $archivedDraft++; continue; }
        if (in_array($st, ['submitted', 'approved'], true)) { $done++; continue; }
        if (trim((string)($r['project_name'] ?? '')) !== '') $send[] = (int)$r['id']; else $blank++;
    }
    $n = count($send);
    $items['annex'] = ['label' => 'Annex', 'link' => 'annex-edit.php', 'send' => $send, 'ok' => ($n + $done) > 0,
        'note' => ($n + $done) > 0
            ? trim(($n ? "$n will be sent to the Procurement Unit" : '') . ($n && $done ? ' · ' : '') . ($done ? "$done already sent" : ''))
            : ($blank ? 'Fill in the project name of your Annex.'
                      : ($archivedDraft ? 'Your Annex is in the Archive — restore it first.' : 'Not started — prepare at least one Annex.'))];

    // ── Market Scoping ──
    $rows = [];
    try {
        $s = $pdo->prepare('SELECT * FROM market_scoping WHERE dept_code = ? AND fy = ? ORDER BY id');
        $s->execute([$dept, $fy]);
        $rows = $s->fetchAll();
    } catch (Throwable $t) {}
    $send = []; $done = 0; $unticked = 0; $archivedDraft = 0;
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if (!$live($r)) { if ($st === 'approved') $done++; else $archivedDraft++; continue; }
        if (in_array($st, ['submitted', 'approved'], true)) { $done++; continue; }
        if (companion_scoping_ready((string)($r['content'] ?? ''))) $send[] = (int)$r['id']; else $unticked++;
    }
    $n = count($send);
    $items['scoping'] = ['label' => 'Market Scoping', 'link' => 'market-scoping-edit.php', 'send' => $send, 'ok' => ($n + $done) > 0,
        'note' => ($n + $done) > 0
            ? trim(($n ? "$n will be sent to the Procurement Unit" : '') . ($n && $done ? ' · ' : '') . ($done ? "$done already sent" : ''))
            : ($unticked ? 'Tick (☑) at least one activity conducted in section 3 of your Market Scoping.'
                         : ($archivedDraft ? 'Your Market Scoping is in the Archive — restore it first.' : 'Not started — prepare your Market Scoping.'))];

    $ok = true;
    foreach ($items as $i) $ok = $ok && $i['ok'];
    return ['items' => $items, 'ok' => $ok];
}

/** Submit every companion that is ready and not yet sent. Returns the labels sent. */
function ppmp_companions_send(array $plan, array $u, string $dept, int $fy, bool $quiet = false): array {
    // $quiet (v81): the whole school is being submitted — one notice for all of it is sent after
    require_once __DIR__ . '/notify.php';
    $pdo = db();
    $who = (string)($u['full_name'] ?? '');
    $deptLabel = (string)($u['dept_name'] ?? $dept);
    $sent = [];
    $spec = [
        'nonps'   => ['nonps_submissions', 'Non-PS list',    'nonps-review.php?sub=',      'New Non-PS Supplies list submitted',
                      "$deptLabel submitted their List of Non-PS Supplies and Materials for FY $fy, with their PPMP."],
        'annex'   => ['annex_forms',       'Annex',          'annex-review.php?id=',       'New Annex submitted',
                      "$deptLabel submitted their annex for FY $fy, with their PPMP."],
        'scoping' => ['market_scoping',    'Market Scoping', 'market-scoping-review.php?id=', 'New Market Scoping submitted',
                      "$deptLabel submitted their market scoping for FY $fy, with their PPMP."],
    ];
    // The APP-CSE: straight to the Procurement Unit (v77)
    if (!empty($plan['items']['appcse']['send'])) {
        try {
            require_once __DIR__ . '/appcse-review.php';
            appcse_set_status($fy, $dept, 'submitted', $who);
            if (!$quiet) notify_by_role('admin', 'submission', 'New APP-CSE submitted',
                "$deptLabel submitted their APP-CSE for FY $fy, with their PPMP.", 'appcse-review.php?fy=' . $fy . '&dept=' . urlencode($dept));
            log_activity('Submitted APP-CSE', 'appcse', "$dept · FY $fy", 'Sent to the Procurement Unit together with the PPMP.');
            $sent['APP-CSE'] = 1;
        } catch (Throwable $t) { error_log('APP-CSE submit failed: ' . $t->getMessage()); }   // the rest of the set still goes
    }
    foreach ($spec as $key => [$table, $label, $link, $title, $body]) {
        foreach ($plan['items'][$key]['send'] ?? [] as $id) {
            $__u = $pdo->prepare("UPDATE `$table` SET status = 'submitted', submitted_by = ?, submitted_at = NOW(), review_note = NULL
                            WHERE id = ? AND status IN ('draft','revision')");
            $__u->execute([$who, $id]);
            if ($__u->rowCount() > 0) {   // v79: version history (config/doc-versions.php)
                require_once __DIR__ . '/doc-versions.php';
                doc_snapshot($key, (string)$id, 'submitted');
            }
            if (!$quiet) notify_by_role('admin', 'submission', $title, $body, $link . $id);
            log_activity("Submitted $label", $key, "$dept · FY $fy", 'Sent together with the PPMP.');
            $sent[$label] = ($sent[$label] ?? 0) + 1;
        }
    }
    return $sent;
}

/**
 * May this Non-PS list / Annex / Market Scoping be submitted on its own?
 * Only once the PPMP it goes with has been submitted, or when it was sent
 * back for revision. Before that it goes with the PPMP.
 */
function companion_solo_submit(string $dept, int $fy, string $docStatus): array {
    if ($docStatus === 'revision') return [true, ''];
    try {
        $s = db()->prepare('SELECT status FROM ppmps WHERE dept_code = ? AND fy = ?');
        $s->execute([$dept, $fy]);
        $st = $s->fetchColumn();
        if ($st !== false && (string)$st !== 'draft') return [true, ''];
    } catch (Throwable $t) { return [true, '']; }
    return [false, "This is submitted together with your FY $fy PPMP. Use Submit on the PPMP — it sends the PPMP, APP-CSE, Non-PS list, Annex and Market Scoping at once."];
}

/* ═══════════════════════════════════════════════════════════════
   The budget: what is spent may not go over the allotment.

   The Budget Unit allots each department its budget for the year
   (dept_budgets, shown as "Total Budget" on the PPMP). It is not changed
   by the department — the department fits its prices to it. What the
   department spends is the APP-CSE (with its 10% additional provision for
   price changes) and the Non-PS list (with VAT, logistics and inflation)
   (config/price-provision.php):

       APP-CSE  = D. GRAND TOTAL of Part I + Part II   (A + 10% of A + C)
       Non-PS   = GRAND TOTAL: TOTAL + VAT + logistics + inflation (v76;
                  12% + 3.3% + 3.5% of TOTAL = TOTAL × 1.188)

       APP-CSE + Non-PS  =  the allotment   ✓ can be submitted
                         <  the allotment   ! can be submitted — shown as a warning
                         >  the allotment   ✗ cannot be submitted
       no allotment set yet                 ! can be submitted — shown as a warning

   Checked when the PPMP is submitted or resubmitted, when a sent-back
   APP-CSE is submitted on its own, and when the Non-PS list is submitted
   on its own.
   =============================================================== */
require_once __DIR__ . '/price-provision.php';

function ppmp_budget_peso(float $v): string { return '₱' . number_format($v, 2); }

/** The department's allotment for the year from the Budget Unit, or null when none is set. */
function dept_allotment(string $dept, int $fy): ?float {
    try {
        $s = db()->prepare('SELECT alloted_budget FROM dept_budgets WHERE dept_code = ? AND fy = ?');
        $s->execute([$dept, $fy]);
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null) return (float)$v;
    } catch (Throwable $t) {}
    return null;
}

/**
 * The PPMP itself against the allotment (v77): its Total Amount may not go
 * over what the Budget Unit allotted, or none of the documents can be sent.
 * Returns state (within | over | none), the PPMP's total, the allotment and a sentence.
 */
function ppmp_allot_check(string $dept, int $fy, int $ppmpId = 0, ?float $allot = null): array {
    $pdo = db();
    if ($ppmpId <= 0) {
        try {
            $s = $pdo->prepare('SELECT p.id FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                                   AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)
                              ORDER BY p.id DESC');
            $s->execute([$dept, $fy]);
            $ppmpId = (int)$s->fetchColumn();
        } catch (Throwable $t) { $ppmpId = 0; }
    }
    $total = 0.0;
    if ($ppmpId > 0) {
        try {
            $s = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM ppmp_entries WHERE ppmp_id = ?');
            $s->execute([$ppmpId]);
            $total = (float)$s->fetchColumn();
        } catch (Throwable $t) {}
    }
    $total = pp_cents($total) / 100;
    if ($allot === null) {
        return ['state' => 'none', 'ppmp' => $total, 'allot' => null,
                'note' => 'PPMP Total Amount ' . ppmp_budget_peso($total) . '. The Budget Unit has not set your FY ' . $fy . ' allotment yet.'];
    }
    $over = pp_cents($total) - pp_cents($allot);
    if ($over > 0) {
        return ['state' => 'over', 'ppmp' => $total, 'allot' => $allot,
                'note' => 'PPMP Total Amount ' . ppmp_budget_peso($total) . ' is ' . ppmp_budget_peso($over / 100) . ' over your allotment of '
                        . ppmp_budget_peso($allot) . '. Lower the amounts on the PPMP so it is within the allotment.'];
    }
    return ['state' => 'within', 'ppmp' => $total, 'allot' => $allot,
            'note' => 'PPMP Total Amount ' . ppmp_budget_peso($total) . ' of your ' . ppmp_budget_peso($allot) . ' allotment'
                    . ($over < 0 ? ' · ' . ppmp_budget_peso(-$over / 100) . ' remaining.' : ' · all of it used.')];
}

/**
 * $ppmpId: the PPMP being submitted (0 = this department's PPMP of the year).
 * Returns ok (may be submitted), state (equal | under | over | none), the
 * figures and a sentence saying so.
 *
 * v81: a unit of a school (a program or major, config/academic-units.php)
 * has no allotment of its own: it shares one with the other units of its
 * pool (config/allot-shares.php). What they spend TOGETHER is checked
 * against what the pool has.
 */
function ppmp_budget_match(string $dept, int $fy, int $ppmpId = 0): array {
    require_once __DIR__ . '/academic-units.php';
    // v83: an office's sets share the office's allotment, like the units of a school
    if (!au_is_unit($dept) && !os_pooled($dept)) return ppmp_budget_match_own($dept, $fy, $ppmpId);
    return ppmp_budget_match_pool($dept, $fy, $ppmpId);
}

/** One department (or office, or school without units) against its own allotment — as before v81. */
function ppmp_budget_match_own(string $dept, int $fy, int $ppmpId = 0): array {
    $pdo = db();

    // The APP-CSE: D. GRAND TOTAL of both Parts (A + 10% + C)
    $acSum   = appcse_summary(appcse_part_totals($fy, $dept), appcse_transport($fy, $dept), (int)$fy);
    $appcse  = $acSum['d'];

    // The Non-PS list of the year — the same one that goes with the PPMP — its GRAND TOTAL
    $nonpsBase = 0.0;
    try {
        $s = $pdo->prepare('SELECT id, status, archived FROM nonps_submissions WHERE dept_code = ? AND fy = ? ORDER BY id DESC');
        $s->execute([$dept, $fy]);
        $list = 0;
        foreach ($s->fetchAll() as $r) {
            if ((int)($r['archived'] ?? 0) !== 1) { $list = (int)$r['id']; break; }
            if ($list === 0 && (string)$r['status'] === 'approved') $list = (int)$r['id'];
        }
        if ($list > 0) {
            $q = $pdo->prepare('SELECT COALESCE(SUM(total_cost), 0) FROM nonps_items WHERE submission_id = ?');
            $q->execute([$list]);
            $nonpsBase = (float)$q->fetchColumn();
        }
    } catch (Throwable $t) {}
    // the rates of this fiscal year (Document Format), whatever page is asking
    $__fyWas = $GLOBALS['__doc_fmt_year'] ?? null;
    doc_fmt_use_year($fy);
    $np    = nonps_provision($nonpsBase);
    doc_fmt_use_year($__fyWas);
    $nonps = $np['grand'];

    $allot = dept_allotment($dept, $fy);
    $sumC  = pp_cents($appcse) + pp_cents($nonps);          // compared to the centavo
    $sum   = $sumC / 100;
    $head  = 'APP-CSE ' . ppmp_budget_peso($appcse) . ' + Non-PS ' . ppmp_budget_peso($nonps) . ' = ' . ppmp_budget_peso($sum)
           . ' (the APP-CSE with its ' . nonps_rate_text(appcse_rate((int)$fy)) . '%, the Non-PS with VAT, logistics and inflation)';
    if ($allot === null) {
        $state = 'none'; $diff = 0.0;
        $note  = $head . '. The Budget Unit has not set your FY ' . $fy . ' allotment yet, so it could not be checked. You can still submit.';
    } else {
        $diffC = $sumC - pp_cents($allot);
        $diff  = $diffC / 100;
        if ($diffC === 0) {
            $state = 'equal';
            $note  = $head . ', exactly your allotment of ' . ppmp_budget_peso($allot) . '.';
        } elseif ($diffC < 0) {
            $state = 'under';
            $note  = $head . ', ' . ppmp_budget_peso(-$diff) . ' below your allotment of ' . ppmp_budget_peso($allot) . '. You can still submit.';
        } else {
            $state = 'over';
            $note  = $head . ', ' . ppmp_budget_peso($diff) . ' over your allotment of ' . ppmp_budget_peso($allot)
                   . '. Lower the prices or quantities on the APP-CSE or the Non-PS list so it is within the allotment.';
        }
    }
    // …and the PPMP itself may not go over the allotment either (v77)
    $pp = ppmp_allot_check($dept, $fy, $ppmpId, $allot);
    if ($pp['state'] === 'over') $note = $pp['note'] . ($state === 'over' ? ' ' . $note : '');
    return ['ok' => $state !== 'over' && $pp['state'] !== 'over', 'state' => $state, 'allot' => $allot, 'pp' => $pp,
            'appcse' => $appcse, 'appcse_base' => $acSum['I']['a'] + $acSum['II']['a'],
            'nonps' => $nonps, 'nonps_base' => $np['total'],
            'sum' => $sum, 'diff' => $diff, 'note' => $note];
}

/**
 * A unit against its pool (v81): the APP-CSE + Non-PS of every unit of the
 * pool together, and their PPMPs together, against the pool's allotment.
 * Same keys as ppmp_budget_match_own(); appcse / nonps / sum are the pool's,
 * 'own' has this unit's, 'pool' says which pool.
 */
function ppmp_budget_match_pool(string $unit, int $fy, int $ppmpId = 0): array {
    require_once __DIR__ . '/allot-shares.php';
    $pool  = allot_pool($unit, $fy);
    $allot = $pool['allot'];
    $acC = 0; $npC = 0; $acBase = 0.0; $npBase = 0.0; $own = null;
    foreach ($pool['units'] as $c) {
        $m = ppmp_budget_match_own($c, $fy, strcasecmp($c, $unit) === 0 ? $ppmpId : 0);
        $acC += pp_cents($m['appcse']); $npC += pp_cents($m['nonps']);
        $acBase += $m['appcse_base']; $npBase += $m['nonps_base'];
        if (strcasecmp($c, $unit) === 0) $own = $m;
    }
    if ($own === null) $own = ppmp_budget_match_own($unit, $fy, $ppmpId);
    $n     = count($pool['units']);
    $noun  = (string)($pool['noun'] ?? 'units');   // units of a school · sets of an office (v83)
    $one   = $noun === 'sets' ? 'set' : 'unit';
    $who   = $n > 1 ? 'All ' . $n . ' ' . $noun . ' sharing ' . $pool['label'] : 'This ' . $one . ', on ' . $pool['label'];
    $sumC  = $acC + $npC;
    $sum   = $sumC / 100;
    $head  = $who . ': APP-CSE ' . ppmp_budget_peso($acC / 100) . ' + Non-PS ' . ppmp_budget_peso($npC / 100) . ' = ' . ppmp_budget_peso($sum)
           . ' (the APP-CSE with its ' . nonps_rate_text(appcse_rate((int)$fy)) . '%, the Non-PS with VAT, logistics and inflation; this ' . $one . ' '
           . ppmp_budget_peso($own['appcse']) . ' + ' . ppmp_budget_peso($own['nonps']) . ')';
    if ($allot === null) {
        $state = 'none'; $diff = 0.0;
        $note  = $head . '. The Budget Unit has not set the FY ' . $fy . ' allotment of ' . $pool['school'] . ' yet, so it could not be checked. You can still submit.';
    } else {
        $diffC = $sumC - pp_cents($allot);
        $diff  = $diffC / 100;
        if ($diffC === 0) {
            $state = 'equal';
            $note  = $head . ', exactly ' . $pool['label'] . ' of ' . ppmp_budget_peso($allot) . '.';
        } elseif ($diffC < 0) {
            $state = 'under';
            $note  = $head . ', ' . ppmp_budget_peso(-$diff) . ' below ' . $pool['label'] . ' of ' . ppmp_budget_peso($allot) . '. You can still submit.';
        } else {
            $state = 'over';
            $note  = $head . ', ' . ppmp_budget_peso($diff) . ' over ' . $pool['label'] . ' of ' . ppmp_budget_peso($allot)
                   . '. Lower the prices or quantities on the APP-CSE or the Non-PS lists so it is within the allotment.';
        }
    }
    // The PPMPs of the pool together: this unit may use what the others leave
    $tot    = allot_ppmp_totals($pool['units'], $fy);
    $mine   = (float)$own['pp']['ppmp'];
    $others = 0;
    foreach ($tot as $c => $v) if (strcasecmp($c, $unit) !== 0) $others += pp_cents($v);
    if ($allot === null) {
        $pp = ['state' => 'none', 'ppmp' => $mine, 'allot' => null, 'pool' => $pool['label'],
               'note' => 'PPMP Total Amount ' . ppmp_budget_peso($mine) . '. The Budget Unit has not set the FY ' . $fy . ' allotment of ' . $pool['school'] . ' yet.'];
    } else {
        $left = (pp_cents($allot) - $others) / 100;
        $over = pp_cents($mine) - pp_cents($left);
        $pp = ['state' => $over > 0 ? 'over' : 'within', 'ppmp' => $mine, 'allot' => $left, 'pool' => $pool['label'], 'noun' => $noun,
               'note' => ppmp_pool_pp_note($mine, $left, $pool['label'], $noun)];
    }
    if ($pp['state'] === 'over') $note = $pp['note'] . ($state === 'over' ? ' ' . $note : '');
    return ['ok' => $state !== 'over' && $pp['state'] !== 'over', 'state' => $state, 'allot' => $allot, 'pp' => $pp,
            'appcse' => $acC / 100, 'appcse_base' => $acBase, 'nonps' => $npC / 100, 'nonps_base' => $npBase,
            'sum' => $sum, 'diff' => $diff, 'note' => $note, 'own' => $own, 'pool' => $pool];
}

/** The sentence for a unit's PPMP against what its pool has left (the PPMP page says it the same way, live). */
function ppmp_pool_pp_note(float $mine, float $left, string $label, string $noun = 'units'): string {
    $over = pp_cents($mine) - pp_cents($left);
    $others = 'the other ' . $noun . '\'';
    if ($over > 0) {
        return 'PPMP Total Amount ' . ppmp_budget_peso($mine) . ' is ' . ppmp_budget_peso($over / 100) . ' over the ' . ppmp_budget_peso($left)
             . ' left of ' . $label . ' (after ' . $others . ' PPMPs). Lower the amounts on the PPMP so it is within the allotment.';
    }
    return 'PPMP Total Amount ' . ppmp_budget_peso($mine) . ' of the ' . ppmp_budget_peso($left) . ' left of ' . $label
         . ' (after ' . $others . ' PPMPs)' . ($over < 0 ? ' · ' . ppmp_budget_peso(-$over / 100) . ' remaining.' : ' · all of it used.');
}
