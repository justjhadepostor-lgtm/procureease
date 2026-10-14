<?php
/* ═══════════════════════════════════════════════════════════════
   Submit the whole school at once (v81) — its Department Admin only.

   Every program and major (unit) of the school that has not been
   submitted yet goes together. Each one needs:
     • its PPMP, and its complete set (APP-CSE, Non-PS list, Annex,
       Market Scoping — the same rules as a single department's Submit)
     • the Department Admin's approval (config/unit-review.php)
   and what they spend together has to be within the allotment
   (config/allot-shares.php, config/companions.php).

   Then, for each unit: its PPMP goes to the Budget Unit and the rest of
   its set to the Procurement Unit — exactly what one department's Submit
   does — with one notice to each office for the whole school.
   =============================================================== */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/companions.php';
require_once __DIR__ . '/unit-review.php';
require_once __DIR__ . '/allot-shares.php';

/**
 * What a whole-school Submit would do, and what stops it.
 * Returns: ready, units (overview rows), send (codes to send), done (codes already sent),
 *          problems (sentences), budget (pool label => ppmp_budget_match result).
 */
function school_submit_check(string $school, int $fy): array {
    $rows = ur_school_overview($school, $fy);
    $send = []; $done = []; $problems = [];
    foreach ($rows as $code => $r) {
        if ($r['status'] === 'submitted') { $done[] = $code; continue; }
        $send[] = $code;
        $why = [];
        if (!$r['ppmp']) $why[] = 'no PPMP yet';
        foreach ($r['set']['items'] as $i) if (!$i['ok']) $why[] = $i['label'] . ' not ready';
        if ($r['status'] !== 'approved') $why[] = 'not approved yet (' . (UR_LABELS[$r['status']] ?? $r['status']) . ')';
        if ($why) $problems[] = $r['label'] . ': ' . implode(', ', $why) . '.';
    }
    // The budget: once per pool (a program with its own share, the rest, or the whole school)
    $budget = []; $seen = [];
    foreach ($send as $code) {
        $pool = allot_pool($code, $fy);
        $key  = $pool['kind'] . ':' . $pool['program'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $bm = ppmp_budget_match($code, $fy);
        // the PPMPs of the pool together (the unit's own line counts what the others leave)
        $tot = array_sum(allot_ppmp_totals($pool['units'], $fy));
        $ppOver = $pool['allot'] !== null && pp_cents($tot) > pp_cents((float)$pool['allot']);
        $budget[$pool['label']] = $bm + ['pool_ppmp' => $tot, 'pool_ppmp_over' => $ppOver];
        if ($bm['state'] === 'over') {
            $problems[] = 'Over the allotment — ' . $bm['note'];
        } elseif ($ppOver) {
            $problems[] = 'The PPMPs sharing ' . $pool['label'] . ' add up to ' . ppmp_budget_peso($tot) . ', '
                        . ppmp_budget_peso(round($tot - (float)$pool['allot'], 2)) . ' over its ' . ppmp_budget_peso((float)$pool['allot']) . '.';
        }
    }
    if (fy_is_past($fy)) $problems[] = fy_past_msg('set', $fy);
    if (!$send) $problems[] = $done ? "Every program and major of $school has already been submitted for FY $fy." : "$school has no programs to submit.";
    return ['ready' => !$problems, 'units' => $rows, 'send' => $send, 'done' => $done, 'problems' => $problems, 'budget' => $budget];
}

/** Submits every unit in $check['send']. Returns what was sent: [unit code => [labels]]. */
function school_submit_do(string $school, int $fy, array $check, array $u): array {
    require_once __DIR__ . '/notify.php';
    require_once __DIR__ . '/doc-versions.php';
    $pdo = db();
    $who = (string)($u['full_name'] ?? '');
    $sent = [];
    foreach ($check['send'] as $code) {
        $r = $check['units'][$code];
        $ppmpId = (int)$r['ppmp']['id'];
        $plan = $r['set'];   // worked out before the PPMP changes (an APP-CSE without a state of its own follows its PPMP)
        $up = $pdo->prepare("UPDATE ppmps SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ? AND status = 'draft'");
        $up->execute([$who, $ppmpId]);
        if ($up->rowCount() === 0) continue;   // someone else got there first
        doc_snapshot('ppmp', (string)$ppmpId, 'submitted');
        $row = au_row($code);
        $uu  = $u;
        $uu['dept_code'] = $code;
        $uu['dept_name'] = (string)($row['name'] ?? $code);
        $done = [];
        try { $done = ppmp_companions_send($plan, $uu, $code, $fy, true); }
        catch (Throwable $t) { error_log('school submit, companions of ' . $code . ': ' . $t->getMessage()); }
        try { $pdo->prepare('UPDATE revision_requests SET resolved = 1 WHERE dept_code = ? AND fy = ? AND resolved = 0')->execute([$code, $fy]); }
        catch (Throwable $t) {}
        $n = 0;
        try { $c = $pdo->prepare('SELECT COUNT(*) FROM ppmp_entries WHERE ppmp_id = ?'); $c->execute([$ppmpId]); $n = (int)$c->fetchColumn(); } catch (Throwable $t) {}
        log_activity('Submitted PPMP', 'ppmp', "$code · FY $fy", "Submitted the FY $fy PPMP with $n procurement entries, with the whole $school.");
        $sent[$code] = array_merge(['PPMP'], array_keys($done));
    }
    if ($sent) {
        $sName = (string)(au_row($school)['name'] ?? $school);
        $list  = implode(', ', array_map('au_label', array_keys($sent)));
        $cnt   = count($sent);
        notify_by_role('budget', 'submission', "PPMPs submitted for review: $school · FY $fy",
            "$who submitted the FY $fy PPMPs of $sName — $cnt program" . ($cnt === 1 ? '' : 's') . "/major" . ($cnt === 1 ? '' : 's') . ": $list.",
            'budget-dashboard.php');
        notify_by_role('admin', 'submission', "New documents submitted: $school · FY $fy",
            "$who submitted the FY $fy APP-CSE, Non-PS lists, Annexes and Market Scoping of $sName ($cnt program" . ($cnt === 1 ? '' : 's') . "/major" . ($cnt === 1 ? '' : 's') . ").",
            'admin-dashboard.php');
        log_activity('Submitted the whole school', 'department', "$school · FY $fy", "Submitted $cnt program(s)/major(s) at once: $list.");
    }
    return $sent;
}
