<?php
/* ═══════════════════════════════════════════════════════════════
   Dashboard charts (v79) — the Superadmin's and the Budget Unit's dashboards.

   1. Budget per department: each department's PPMP Total Amount and its
      APP-CSE + Non-PS (the APP-CSE with its 10%, the Non-PS with VAT,
      logistics and inflation) as two bars, and the alloted budget as a
      marker line — so going over the allotment shows at a glance. The
      figures are the same ones the Submit window checks
      (ppmp_budget_match(), config/companions.php), documents still being
      prepared included.
   2. Submissions before the deadline: for each document, how many
      departments have it approved, waiting for review, sent back, or not
      submitted yet, with the FY deadline (Deadlines page).

   Every value is also in "Show as table" under each chart, and on hover /
   keyboard focus. Drawn in HTML/CSS (no chart library), so it works offline.

   Set before including:  $dcFy — the fiscal year shown.
   =============================================================== */
require_once __DIR__ . '/../config/companions.php';     // ppmp_budget_match()
require_once __DIR__ . '/../config/appcse-review.php';  // appcse_status()

$dcFy = (int)($dcFy ?? active_fy());
$dcPdo = db();

/* ─── Departments (active) ─────────────────────────────────── */
$dcDepts = [];
try {
    $dcDepts = $dcPdo->query("SELECT code, name FROM departments
                               WHERE status IS NULL OR status <> 'inactive'
                            ORDER BY display_order, name")->fetchAll();
} catch (Throwable $t) {
    try { $dcDepts = $dcPdo->query('SELECT code, name FROM departments ORDER BY name')->fetchAll(); } catch (Throwable $t2) {}
}

if (!function_exists('dc_peso')) {
    function dc_peso(float $v): string { return '₱' . number_format($v, 2); }
    /** ₱0 · ₱500 · ₱12.5K · ₱1.2M — for the axis */
    function dc_compact(float $v): string {
        $a = abs($v);
        if ($a >= 1e6) $s = rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M';
        elseif ($a >= 1e3) $s = rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K';
        else $s = number_format($v, 0);
        return '₱' . $s;
    }
    /** A round axis end and its step: 4 to 5 ticks of 1 / 2 / 2.5 / 5 × 10^n */
    function dc_axis(float $max): array {
        if ($max <= 0) return [1000.0, 250.0];
        $raw = $max / 4;
        $mag = pow(10, floor(log10($raw)));
        foreach ([1, 2, 2.5, 5, 10] as $m) { if ($raw <= $m * $mag) { $step = $m * $mag; break; } }
        $end = ceil($max / $step) * $step;
        return [(float)$end, (float)$step];
    }
    function dc_pct(float $v, float $end): string { return rtrim(rtrim(number_format(max(0, min(100, $v / $end * 100)), 3, '.', ''), '0'), '.') . '%'; }
}

/* ─── 1. Budget per department ─────────────────────────────── */
$dcBudget = [];
require_once __DIR__ . '/../config/academic-units.php';
foreach ($dcDepts as $d) {
    // v81: one bar per school — its programs and majors together, against the school's allotment
    if (au_is_unit((string)$d['code']) || os_is_set((string)$d['code'])) continue;   // (v83: an office's sets are in its bar)
    $m = ppmp_budget_match((string)$d['code'], $dcFy);
    $allot = $m['allot'];
    $ppmp  = (float)$m['pp']['ppmp'];
    $acnp  = (float)$m['sum'];
    if (au_has_units((string)$d['code']) || os_has_sets((string)$d['code'])) {
        $__ac = 0; $__np = 0; $__pp = 0;
        $__members = au_has_units((string)$d['code']) ? array_keys(au_units_of((string)$d['code'])) : os_family((string)$d['code']);
        foreach (array_flip($__members) as $__uc => $__ur) {
            $__m = ppmp_budget_match_own((string)$__uc, $dcFy);
            $__ac += pp_cents($__m['appcse']); $__np += pp_cents($__m['nonps']); $__pp += pp_cents((float)$__m['pp']['ppmp']);
        }
        $m['appcse'] = $__ac / 100; $m['nonps'] = $__np / 100;
        $ppmp = $__pp / 100; $acnp = ($__ac + $__np) / 100;
    }
    $state = 'none'; $note = 'No allotment set';
    if ($allot !== null) {
        $ppOver = pp_cents($ppmp) - pp_cents((float)$allot);
        $acOver = pp_cents($acnp) - pp_cents((float)$allot);
        if ($ppOver > 0 || $acOver > 0) {
            $state = 'over';
            $parts = [];
            if ($ppOver > 0) $parts[] = 'PPMP ' . dc_peso($ppOver / 100);
            if ($acOver > 0) $parts[] = 'APP-CSE + Non-PS ' . dc_peso($acOver / 100);
            $note = 'Over: ' . implode(' · ', $parts);
        } else {
            $state = 'within';
            $note = 'Within the allotment';
        }
    }
    $dcBudget[] = ['code' => (string)$d['code'], 'name' => (string)$d['name'], 'allot' => $allot,
                   'ppmp' => $ppmp, 'acnp' => $acnp, 'appcse' => (float)$m['appcse'], 'nonps' => (float)$m['nonps'],
                   'state' => $state, 'note' => $note];
}
// Over the allotment first, then the rest in the usual order
$__ord = ['over' => 0, 'within' => 1, 'none' => 1];
$__i = 0; foreach ($dcBudget as &$__r) $__r['i'] = $__i++; unset($__r);
usort($dcBudget, fn($a, $b) => [$__ord[$a['state']], $a['i']] <=> [$__ord[$b['state']], $b['i']]);
$__max = 0.0;
foreach ($dcBudget as $r) $__max = max($__max, (float)($r['allot'] ?? 0), $r['ppmp'], $r['acnp']);
[$dcEnd, $dcStep] = dc_axis($__max);
$dcTicks = [];
for ($v = 0.0; $v <= $dcEnd + 0.001; $v += $dcStep) $dcTicks[] = $v;
$dcCount = ['over' => 0, 'within' => 0, 'none' => 0];
foreach ($dcBudget as $r) $dcCount[$r['state']]++;

/* ─── 2. Submissions before the deadline ───────────────────── */
// Each department's place for each document: approved | review | back | none
$dcDocs = ['ppmp' => 'PPMP', 'appcse' => 'APP-CSE', 'nonps' => 'Non-PS list', 'annex' => 'Annex', 'scoping' => 'Market Scoping'];
$dcSub = [];
foreach ($dcDocs as $k => $label) $dcSub[$k] = ['approved' => [], 'review' => [], 'back' => [], 'none' => []];
$dcLive = fn($r) => (int)($r['archived'] ?? 0) !== 1;
foreach ($dcDepts as $d) {
    $code = (string)$d['code']; $name = (string)$d['name'];
    if (au_has_units($code)) continue;   // v81: its programs and majors are counted, each on its own
    // PPMP: the department's PPMP of the year (not archived)
    $st = 'none';
    try {
        $s = $dcPdo->prepare('SELECT p.id, p.status FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                                 AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id) ORDER BY p.id DESC');
        $s->execute([$code, $dcFy]);
        if ($p = $s->fetch()) {
            if ($p['status'] === 'approved') $st = 'approved';
            elseif (in_array($p['status'], ['submitted', 'budget_approved'], true)) $st = 'review';
            else {
                $q = $dcPdo->prepare('SELECT COUNT(*) FROM revision_requests WHERE ppmp_id = ? AND resolved = 0');
                $q->execute([(int)$p['id']]);
                if ((int)$q->fetchColumn() > 0) $st = 'back';
            }
        }
    } catch (Throwable $t) {}
    $dcSub['ppmp'][$st][] = $name;
    // APP-CSE: its own state (v77)
    $a = appcse_status($dcFy, $code);
    $dcSub['appcse'][['approved' => 'approved', 'submitted' => 'review', 'revision' => 'back'][$a] ?? 'none'][] = $name;
    // Non-PS: the list of the year (not archived)
    $st = 'none';
    try {
        $s = $dcPdo->prepare('SELECT status, archived FROM nonps_submissions WHERE dept_code = ? AND fy = ? ORDER BY id DESC');
        $s->execute([$code, $dcFy]);
        foreach ($s->fetchAll() as $r) {
            if (!$dcLive($r)) continue;
            $st = ['approved' => 'approved', 'submitted' => 'review', 'revision' => 'back'][(string)$r['status']] ?? 'none';
            break;
        }
    } catch (Throwable $t) {}
    $dcSub['nonps'][$st][] = $name;
    // Annex, Market Scoping: one or more per department — sent back first, then waiting, then approved
    foreach (['annex' => 'annex_forms', 'scoping' => 'market_scoping'] as $k => $table) {
        $have = [];
        try {
            $s = $dcPdo->prepare("SELECT status, archived FROM `$table` WHERE dept_code = ? AND fy = ?");
            $s->execute([$code, $dcFy]);
            foreach ($s->fetchAll() as $r) if ($dcLive($r)) $have[(string)$r['status']] = true;
        } catch (Throwable $t) {}
        $st = isset($have['revision']) ? 'back' : (isset($have['submitted']) ? 'review' : (isset($have['approved']) ? 'approved' : 'none'));
        $dcSub[$k][$st][] = $name;
    }
}
$dcTotal = count(array_filter($dcDepts, fn($d) => !au_has_units((string)$d['code'])));   // v81: units counted, not their school
$dcUnits = count(array_filter($dcDepts, fn($d) => au_is_unit((string)$d['code']))) > 0;
// The deadline of the year (Deadlines page)
$dcDeadline = null;
try {
    $s = $dcPdo->prepare('SELECT deadline_date FROM submission_deadlines WHERE fy = ?');
    $s->execute([$dcFy]);
    $dcDeadline = $s->fetchColumn() ?: null;
} catch (Throwable $t) {}
$dcDays = null;
if ($dcDeadline) $dcDays = (int)round((strtotime(date('Y-m-d', strtotime((string)$dcDeadline))) - strtotime(date('Y-m-d'))) / 86400);
$dcIsAdmin = (current_user()['role'] ?? '') === 'admin';

$dcStates = ['approved' => 'Approved', 'review' => 'Waiting for review', 'back' => 'Sent back', 'none' => 'Not submitted'];
$dcTip = function (array $names): string {
    $n = count($names);
    if (!$n) return '';
    $show = array_slice($names, 0, 8);
    return implode("\n", $show) . ($n > 8 ? "\n…and " . ($n - 8) . ' more' : '');
};
?>
<style>
  /* Chart colours (validated: assets/… dataviz palette; light on the card, dark on #103a5f) */
  .dc-root { --dc-s1: #2a78d6; --dc-s2: #eb6834; --dc-ink: var(--ink); --dc-ink2: var(--muted);
    --dc-grid: rgba(15, 43, 74, .10); --dc-base: rgba(15, 43, 74, .28); --dc-track: rgba(15, 43, 74, .07);
    --dc-appr: #184f95; --dc-rev: #6da7ec; --dc-back: #fab219; --dc-good: #15803d; --dc-bad: #c53030;
    --dc-surface: #f4f8fb; }
  body[data-theme="dark"] .dc-root { --dc-s1: #3987e5; --dc-s2: #d95926;
    --dc-grid: rgba(255, 255, 255, .10); --dc-base: rgba(255, 255, 255, .30); --dc-track: rgba(255, 255, 255, .10);
    --dc-appr: #9ec5f4; --dc-rev: #3987e5; --dc-surface: #103a5f; }
  .dc-root { display: grid; grid-template-columns: minmax(0, 1fr); gap: 18px; margin: 4px 0 24px; }
  .dc-card { padding: 22px 24px 18px; margin: 0; }
  .dc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px 20px; flex-wrap: wrap; margin-bottom: 14px; }
  .dc-head .card-sub { margin-bottom: 0; max-width: 640px; line-height: 1.5; }
  .dc-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; color: var(--dc-ink2); align-items: center; }
  .dc-legend span { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
  .dc-key { width: 12px; height: 10px; border-radius: 2px; display: inline-block; }
  .dc-key.line { width: 3px; height: 14px; border-radius: 1px; background: var(--dc-ink); }
  .dc-counts { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12.5px; color: var(--dc-ink); margin-bottom: 12px; }
  .dc-counts b { font-family: 'Space Grotesk', sans-serif; }
  .dc-ic { display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%; font-size: 10px; font-weight: 800; color: #fff; flex: none; }
  .dc-ic.over { background: var(--dc-bad); } .dc-ic.within { background: var(--dc-good); }
  .dc-ic.none { background: transparent; color: var(--dc-ink2); box-shadow: inset 0 0 0 1.5px var(--dc-ink2); }
  .dc-ic.back { background: var(--dc-back); color: #3d2a00; }

  /* 1. Budget per department */
  .dc-rows { position: relative; }
  .dc-row { display: grid; grid-template-columns: minmax(120px, 210px) minmax(0, 1fr) minmax(150px, 230px); gap: 6px 16px; align-items: center;
    padding: 7px 0; border-top: 1px solid var(--dc-grid); }
  .dc-row:first-child { border-top: none; }
  .dc-name { font-size: 12.5px; font-weight: 600; color: var(--dc-ink); line-height: 1.3; }
  .dc-name small { display: block; font-weight: 500; color: var(--dc-ink2); font-size: 11px; }
  .dc-plot { position: relative; height: 26px; }
  .dc-grid-line { position: absolute; top: -7px; bottom: -7px; width: 1px; background: var(--dc-grid); }
  .dc-grid-line.zero { background: var(--dc-base); }
  .dc-bar { position: absolute; left: 0; height: 10px; border-radius: 0 4px 4px 0; cursor: default; outline-offset: 2px; transition: filter .12s; }
  .dc-bar.s1 { top: 1px; background: var(--dc-s1); }
  .dc-bar.s2 { top: 15px; background: var(--dc-s2); }
  .dc-bar.zero { width: 2px !important; border-radius: 0; opacity: .55; }
  .dc-bar:hover, .dc-bar:focus-visible, .dc-mark:hover, .dc-mark:focus-visible, .dc-seg:hover, .dc-seg:focus-visible { filter: brightness(1.12) saturate(1.1); }
  .dc-mark { position: absolute; top: -4px; bottom: -4px; width: 12px; margin-left: -6px; cursor: default; }
  .dc-mark::before { content: ''; position: absolute; left: 5px; top: 0; bottom: 0; width: 2px; border-radius: 1px; background: var(--dc-ink);
    box-shadow: 0 0 0 1.5px var(--dc-surface); }
  .dc-state { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--dc-ink); line-height: 1.35; }
  .dc-axis { display: grid; grid-template-columns: minmax(120px, 210px) minmax(0, 1fr) minmax(150px, 230px); gap: 0 16px; margin-top: 2px; }
  .dc-ticks { position: relative; height: 18px; font-size: 10.5px; color: var(--dc-ink2); font-variant-numeric: tabular-nums; }
  .dc-ticks span { position: absolute; top: 3px; transform: translateX(-50%); white-space: nowrap; }
  .dc-ticks span:first-child { transform: none; }
  .dc-ticks span:last-child { transform: translateX(-100%); }

  /* 2. Submissions */
  .dc-deadline { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border-radius: 10px; font-size: 12.5px; color: var(--dc-ink);
    background: var(--navy-50); border: 1px solid var(--border); }
  .dc-deadline b { font-family: 'Space Grotesk', sans-serif; }
  .dc-deadline a { color: var(--navy-700); font-weight: 700; }
  .dc-srow { display: grid; grid-template-columns: minmax(110px, 150px) minmax(0, 1fr) minmax(140px, 190px); gap: 6px 16px; align-items: center; padding: 8px 0; border-top: 1px solid var(--dc-grid); }
  .dc-srows .dc-srow:first-child { border-top: none; }
  .dc-stack { display: flex; gap: 2px; height: 14px; border-radius: 4px; overflow: hidden; background: var(--dc-track); }
  .dc-seg { height: 100%; min-width: 3px; cursor: default; }
  .dc-seg.approved { background: var(--dc-appr); } .dc-seg.review { background: var(--dc-rev); }
  .dc-seg.back { background: var(--dc-back); } .dc-seg.none { background: var(--dc-track); }
  .dc-sval { font-size: 12.5px; color: var(--dc-ink); } .dc-sval b { font-family: 'Space Grotesk', sans-serif; }
  .dc-sval small { color: var(--dc-ink2); }

  /* Table view */
  details.dc-table { margin-top: 12px; }
  details.dc-table > summary { cursor: pointer; font-size: 12px; font-weight: 700; color: var(--navy-700); width: max-content; }
  .dc-table-wrap { overflow-x: auto; margin-top: 8px; }
  .dc-table table { width: 100%; border-collapse: collapse; font-size: 12px; color: var(--dc-ink); }
  .dc-table th, .dc-table td { padding: 6px 8px; border-bottom: 1px solid var(--dc-grid); text-align: left; vertical-align: top; }
  .dc-table th { font-size: 11px; color: var(--dc-ink2); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .dc-table td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

  /* Tooltip */
  .dc-tip { position: fixed; z-index: 1200; pointer-events: none; max-width: 280px; padding: 8px 11px; border-radius: 9px; font-size: 12px; line-height: 1.45;
    background: #0f2b4a; color: #fff; box-shadow: 0 8px 24px rgba(0, 0, 0, .22); white-space: pre-line; opacity: 0; transition: opacity .1s; }
  .dc-tip.on { opacity: 1; }
  .dc-tip b { font-family: 'Space Grotesk', sans-serif; font-size: 13px; }
  body[data-theme="dark"] .dc-tip { background: #ffffff; color: #0f2b4a; }

  @media (max-width: 760px) {
    .dc-row, .dc-axis, .dc-srow { grid-template-columns: minmax(0, 1fr); }
    .dc-axis > div:first-child, .dc-axis > div:last-child { display: none; }
  }
  @media print { .dc-tip { display: none; } }
</style>

<div class="section-head" style="margin-top: 24px;">
  <div>
    <h2 class="section-title">Departments at a glance — FY <?= $dcFy ?></h2>
    <p class="section-sub">Budget against the allotment, and who has submitted before the deadline.</p></div></div>

<div class="dc-root" id="dashCharts">
  <!-- 1. Budget per department -->
  <div class="card dc-card" id="dcBudget">
    <div class="dc-head">
      <div>
        <div class="card-title">Budget per department</div>
        </div>
      <div class="dc-legend" aria-hidden="true">
        <span><i class="dc-key" style="background: var(--dc-s1);"></i>PPMP Total Amount</span>
        <span><i class="dc-key" style="background: var(--dc-s2);"></i>APP-CSE + Non-PS</span>
        <span><i class="dc-key line"></i>Alloted budget</span></div></div>

    <?php if (!$dcBudget): ?>
      <div style="color: var(--muted); font-size: 13px; padding: 12px 0;">No departments yet.</div>
    <?php else: ?>
    <div class="dc-counts" id="dcBudgetCounts">
      <span><i class="dc-ic over">✕</i> <b><?= $dcCount['over'] ?></b> over the allotment</span>
      <span><i class="dc-ic within">✓</i> <b><?= $dcCount['within'] ?></b> within</span>
      <span><i class="dc-ic none">!</i> <b><?= $dcCount['none'] ?></b> no allotment set</span></div>

    <div class="dc-rows" role="img" aria-label="Budget per department, FY <?= $dcFy ?>: <?= $dcCount['over'] ?> over the allotment, <?= $dcCount['within'] ?> within, <?= $dcCount['none'] ?> with no allotment set. The table below has every figure.">
      <?php foreach ($dcBudget as $r):
        $tipBase = $r['name'] . "\n";
      ?>
        <div class="dc-row" data-dept="<?= e($r['code']) ?>" data-state="<?= e($r['state']) ?>">
          <div class="dc-name"><?= e($r['name']) ?><small><?= e($r['code']) ?></small></div>
          <div class="dc-plot">
            <?php foreach ($dcTicks as $tv): ?>
              <i class="dc-grid-line<?= $tv == 0 ? ' zero' : '' ?>" style="left: <?= dc_pct($tv, $dcEnd) ?>;"></i>
            <?php endforeach; ?>
            <span class="dc-bar s1<?= $r['ppmp'] <= 0 ? ' zero' : '' ?>" tabindex="0" style="width: <?= dc_pct($r['ppmp'], $dcEnd) ?>;"
                  data-tip="<?= e(dc_peso($r['ppmp']) . "\nPPMP Total Amount · " . $r['name']) ?>"></span>
            <span class="dc-bar s2<?= $r['acnp'] <= 0 ? ' zero' : '' ?>" tabindex="0" style="width: <?= dc_pct($r['acnp'], $dcEnd) ?>;"
                  data-tip="<?= e(dc_peso($r['acnp']) . "\nAPP-CSE + Non-PS · " . $r['name'] . "\nAPP-CSE " . dc_peso($r['appcse']) . ' + Non-PS ' . dc_peso($r['nonps'])) ?>"></span>
            <?php if ($r['allot'] !== null): ?>
              <span class="dc-mark" tabindex="0" style="left: <?= dc_pct((float)$r['allot'], $dcEnd) ?>;"
                    data-tip="<?= e(dc_peso((float)$r['allot']) . "\nAlloted budget · " . $r['name']) ?>"></span>
            <?php endif; ?>
          </div>
          <div class="dc-state"><i class="dc-ic <?= e($r['state']) ?>"><?= $r['state'] === 'over' ? '✕' : ($r['state'] === 'within' ? '✓' : '!') ?></i>
            <span><?= e($r['note']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="dc-axis" aria-hidden="true"><div></div>
      <div class="dc-ticks"><?php foreach ($dcTicks as $tv): ?><span style="left: <?= dc_pct($tv, $dcEnd) ?>;"><?= e(dc_compact($tv)) ?></span><?php endforeach; ?></div>
      <div></div></div>

    <details class="dc-table" id="dcBudgetTable">
      <summary>Show as table</summary>
      <div class="dc-table-wrap"><table>
        <thead><tr><th>Department</th><th style="text-align:right;">Alloted budget</th><th style="text-align:right;">PPMP Total Amount</th>
          <th style="text-align:right;">APP-CSE</th><th style="text-align:right;">Non-PS</th><th style="text-align:right;">APP-CSE + Non-PS</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($dcBudget as $r): ?>
          <tr><td><?= e($r['name']) ?></td>
            <td class="n"><?= $r['allot'] !== null ? e(dc_peso((float)$r['allot'])) : '—' ?></td>
            <td class="n"><?= e(dc_peso($r['ppmp'])) ?></td>
            <td class="n"><?= e(dc_peso($r['appcse'])) ?></td>
            <td class="n"><?= e(dc_peso($r['nonps'])) ?></td>
            <td class="n"><?= e(dc_peso($r['acnp'])) ?></td>
            <td><?= e($r['note']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></details>
    <?php endif; ?>
  </div>

  <!-- 2. Submissions before the deadline -->
  <div class="card dc-card" id="dcSubmissions">
    <div class="dc-head">
      <div>
        <div class="card-title">Submissions — FY <?= $dcFy ?></div>
</div>
      <div class="dc-deadline" id="dcDeadline">
        <?php if ($dcDeadline): ?>
          <?php if ($dcDays > 0): ?>
            <span>📅 Deadline <b><?= e(date('M j, Y', strtotime((string)$dcDeadline))) ?></b> · <?= $dcDays ?> day<?= $dcDays === 1 ? '' : 's' ?> left</span>
          <?php elseif ($dcDays === 0): ?>
            <span>📅 Deadline <b>today</b> (<?= e(date('M j, Y', strtotime((string)$dcDeadline))) ?>)</span>
          <?php else: ?>
            <span><i class="dc-ic over">!</i> Deadline <b><?= e(date('M j, Y', strtotime((string)$dcDeadline))) ?></b> passed <?= -$dcDays ?> day<?= $dcDays === -1 ? '' : 's' ?> ago</span>
          <?php endif; ?>
        <?php else: ?>
          <span>📅 No deadline set for FY <?= $dcFy ?><?php if ($dcIsAdmin): ?> · <a href="deadlines.php">Set one</a><?php endif; ?></span>
        <?php endif; ?></div></div>

    <div class="dc-legend" style="margin-bottom: 10px;" aria-hidden="true">
      <span><i class="dc-key" style="background: var(--dc-appr);"></i>Approved</span>
      <span><i class="dc-key" style="background: var(--dc-rev);"></i>Waiting for review</span>
      <span><i class="dc-key" style="background: var(--dc-back);"></i>↩ Sent back</span>
      <span><i class="dc-key" style="background: var(--dc-track); box-shadow: inset 0 0 0 1px var(--dc-base);"></i>Not submitted</span></div>

    <div class="dc-srows">
    <?php foreach ($dcDocs as $k => $label):
      $c = array_map('count', $dcSub[$k]);
      $in = $c['approved'] + $c['review'];
      $pct = $dcTotal ? (int)round($in / $dcTotal * 100) : 0;
    ?>
      <div class="dc-srow" data-doc="<?= e($k) ?>">
        <div class="dc-name"><?= e($label) ?></div>
        <div class="dc-stack" role="img" aria-label="<?= e("$label: {$c['approved']} approved, {$c['review']} waiting for review, {$c['back']} sent back, {$c['none']} not submitted") ?>">
          <?php foreach ($dcStates as $sk => $sl): if (!$c[$sk]) continue; ?>
            <span class="dc-seg <?= $sk ?>" tabindex="0" style="flex: <?= $c[$sk] ?> 1 0;"
                  data-tip="<?= e($c[$sk] . ' ' . ($sk === 'back' ? '↩ ' : '') . $sl . ' · ' . $label . "\n" . $dcTip($dcSub[$k][$sk])) ?>"></span>
          <?php endforeach; ?>
        </div>
        <div class="dc-sval"><b><?= $in ?></b> of <?= $dcTotal ?> submitted · <b><?= $pct ?>%</b>
          <?php if ($c['back']): ?><br><small><i class="dc-ic back" style="width:13px;height:13px;font-size:9px;">↩</i> <?= $c['back'] ?> sent back</small><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    </div>

    <details class="dc-table" id="dcSubTable">
      <summary>Show as table</summary>
      <div class="dc-table-wrap"><table>
        <thead><tr><th>Document</th><?php foreach ($dcStates as $sl): ?><th><?= e($sl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($dcDocs as $k => $label): ?>
          <tr><td><b><?= e($label) ?></b></td>
            <?php foreach ($dcStates as $sk => $sl): ?>
              <td><b><?= count($dcSub[$k][$sk]) ?></b><?php if ($dcSub[$k][$sk]): ?><br><span style="color: var(--muted);"><?= e(implode(', ', $dcSub[$k][$sk])) ?></span><?php endif; ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div></details>
  </div>
</div>
<script>
/* One tooltip for both charts: value first, then what it is (textContent only) */
(function () {
  var root = document.getElementById('dashCharts'); if (!root) return;
  var tip = document.createElement('div'); tip.className = 'dc-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip);
  function show(el, x, y) {
    var lines = (el.getAttribute('data-tip') || '').split('\n');
    tip.textContent = '';
    var b = document.createElement('b'); b.textContent = lines.shift(); tip.appendChild(b);
    if (lines.length) tip.appendChild(document.createTextNode('\n' + lines.join('\n')));
    tip.classList.add('on');
    var r = tip.getBoundingClientRect();
    var left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x + 12));
    var top = y - r.height - 12; if (top < 8) top = y + 16;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hide() { tip.classList.remove('on'); }
  root.addEventListener('pointermove', function (e) {
    var el = e.target.closest('[data-tip]'); if (!el || !root.contains(el)) { hide(); return; }
    show(el, e.clientX, e.clientY);
  });
  root.addEventListener('pointerleave', hide);
  root.addEventListener('focusin', function (e) {
    var el = e.target.closest('[data-tip]'); if (!el) return;
    var r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top);
  });
  root.addEventListener('focusout', hide);
  // scrolling moves a tooltip opened from the keyboard along with its bar; one from the pointer closes
  window.addEventListener('scroll', function () {
    var el = document.activeElement;
    if (el && root.contains(el) && el.hasAttribute('data-tip')) { var r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top); }
    else hide();
  }, { passive: true });
})();
</script>
