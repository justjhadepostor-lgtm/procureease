<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/xlsx-writer.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$pdo = db();

// Default FY = active FY set by the Procurement Unit
$activeFY = active_fy();
$reportFy = (int)($_GET['fy'] ?? $activeFY);

// ─── Report generators ────────────────────────────────────────
$want = $_GET['generate'] ?? '';

if ($want !== '') {
    switch ($want) {
        case 'app':                          gen_app_xlsx($pdo, $reportFy, $u); break;
        case 'app-cse':                      gen_appcse_xlsx($pdo, $reportFy, $u); break;
        case 'nonps':                        gen_nonps_xlsx($pdo, $reportFy, $u); break;
        case 'indicative':                   gen_indicative_html($pdo, $reportFy, $u); break;
        case 'submission-status':            gen_submission_status_html($pdo, $reportFy, $u); break;
        case 'fund-allocation':              gen_fund_allocation_html($pdo, $reportFy, $u); break;
        case 'category-aggregation':         gen_category_aggregation_html($pdo, $reportFy, $u); break;
        case 'historical-trends':            gen_historical_trends_html($pdo, $u); break;
    }
    // If we fall through, no report matched — show the page normally
}

// ─── APP (Annual Procurement Plan) — XLSX ──────────────────────
function gen_app_xlsx(PDO $pdo, int $fy, array $u): void {
    $stmt = $pdo->prepare("SELECT p.dept_code, d.name AS dept_name, e.category, e.description, e.proj_type,
                                  e.quantity, e.mode, e.pre_conference, e.activity_start, e.activity_end,
                                  e.delivery, e.fund, e.amount, e.supporting_docs, e.remarks
                             FROM ppmp_entries e
                             JOIN ppmps p ON p.id = e.ppmp_id
                        LEFT JOIN departments d ON d.code = p.dept_code
                            WHERE p.fy = ?
                              AND p.status IN ('submitted','budget_approved','approved')
                         ORDER BY p.dept_code, e.category, e.amount DESC");
    $stmt->execute([$fy]);
    $rows = $stmt->fetchAll();

    $catLabels = ['training'=>'Training & Seminars','consulting'=>'Consulting Services','nonps'=>'Non-PS Supplies','appcse'=>'APP-CSE'];

    $data = [];
    $data[] = ['Annual Procurement Plan (APP) — FY ' . $fy]; // title row
    $data[] = ['Aurora State College of Technology · Procurement Unit · Governed by RA 12009 (NGPA)'];
    $data[] = []; // spacer
    $data[] = ['Dept Code', 'Department', 'Category', 'Description', 'Procurement Project Type',
               'Quantity', 'Mode of Procurement', 'Pre-Proc Conf', 'Activity Start', 'Activity End',
               'Delivery', 'Fund Source', 'Amount (PhP)', 'Supporting Docs', 'Remarks'];

    $total = 0;
    foreach ($rows as $r) {
        $data[] = [
            $r['dept_code'], $r['dept_name'], $catLabels[$r['category']] ?? $r['category'],
            $r['description'], $r['proj_type'], $r['quantity'], $r['mode'], $r['pre_conference'],
            $r['activity_start'], $r['activity_end'], $r['delivery'], $r['fund'],
            (float)$r['amount'], $r['supporting_docs'], $r['remarks'],
        ];
        $total += (float)$r['amount'];
    }
    // Grand total row
    $data[] = ['','','','','','','','','','','','GRAND TOTAL', $total, '', ''];

    $x = new SimpleXlsxWriter();
    $x->addSheet('APP FY' . $fy, $data, [
        'titleRow'     => 1,
        'titleCols'    => 15,
        'headerRow'    => 4,
        'columnWidths' => [12, 30, 18, 40, 18, 12, 22, 12, 12, 12, 12, 12, 15, 25, 25],
    ]);

    log_activity('Generated report', 'report', 'APP', "Generated FY $fy Annual Procurement Plan (XLSX).");
    $x->download('APP-FY' . $fy . '.xlsx');
}

// ─── APP-CSE — XLSX ────────────────────────────────────────────
function gen_appcse_xlsx(PDO $pdo, int $fy, array $u): void {
    require_once __DIR__ . '/config/appcse-items.php';
    // The report exports the Procurement Unit's own APP-CSE
    $__dept = (string)($u['dept_code'] ?? '');
    appcse_ensure_seeded($pdo, $fy, $__dept);
    $grouped = appcse_get_grouped($pdo, $fy, $__dept);
    $months = APPCSE_MONTHS;

    $data = [];
    $data[] = ['APP-CSE ' . $fy . ' — Aurora State College of Technology · Procurement Unit'];
    $data[] = ['Annual Procurement Plan for Common-Use Supplies and Equipment · For PS-DBM'];
    $data[] = [];
    $data[] = ['Code', 'Item & Specifications', 'Unit',
               'Jan','Feb','Mar','Q1','Q1 Amount',
               'Apr','May','Jun','Q2','Q2 Amount',
               'Jul','Aug','Sep','Q3','Q3 Amount',
               'Oct','Nov','Dec','Q4','Q4 Amount',
               'Total Qty','Unit Price','Total Amount'];

    $partTotals = ['I' => 0, 'II' => 0];
    foreach (['I','II'] as $part) {
        if (empty($grouped[$part])) continue;
        $data[] = [$part === 'I' ? 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)'
                                 : 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM'];
        foreach ($grouped[$part] as $cat => $rows) {
            $data[] = [$cat];
            foreach ($rows as $r) {
                $up = (float)$r['unit_price'];
                $row = [$r['code'], $r['description'], $r['unit']];
                $tot = 0;
                for ($q = 0; $q < 4; $q++) {
                    $qs = 0;
                    for ($k = 0; $k < 3; $k++) {
                        $mv = (float)$r[$months[$q*3+$k]];
                        $row[] = $mv; $qs += $mv;
                    }
                    $row[] = $qs;            // Q total
                    $row[] = $qs * $up;      // Q amount
                    $tot += $qs;
                }
                $row[] = $tot;
                $row[] = $up;
                $row[] = $tot * $up;
                $partTotals[$part] += $tot * $up;
                $data[] = $row;
            }
        }
    }

    // Footer totals per part — A to E, as on the form (config/price-provision.php)
    require_once __DIR__ . '/config/price-provision.php';
    require_once __DIR__ . '/config/doc-format.php';
    $sum = appcse_summary($partTotals, appcse_transport($fy, $__dept), (int)$fy);
    $pad = array_fill(0, 24, '');
    foreach (['I','II'] as $pk) {
        $p = $sum[$pk];
        $data[] = [];
        $data[] = [doc_fmt('appcse', $pk === 'I' ? 'sum_part1_title' : 'sum_part2_title', $fy)];
        $data[] = array_merge($pad, [doc_fmt('appcse', 'sum_a', $fy), $p['a']]);
        $data[] = array_merge($pad, [doc_fmt('appcse', 'sum_b', $fy), $p['b']]);
        $data[] = array_merge($pad, [doc_fmt('appcse', 'sum_c', $fy), $p['c']]);
        $data[] = array_merge($pad, [doc_fmt('appcse', 'sum_d', $fy), $p['d']]);
        $data[] = array_merge($pad, [str_replace(["\r\n", "\n"], ' ', doc_fmt('appcse', 'sum_e', $fy)), pp_figures_words($p['d'])]);
    }

    $x = new SimpleXlsxWriter();
    $x->addSheet('APP-CSE FY' . $fy, $data, ['titleRow' => 1, 'titleCols' => 27, 'headerRow' => 4]);
    log_activity('Generated report', 'report', 'APP-CSE', "Generated FY $fy APP-CSE (XLSX).");
    $x->download('APP-CSE-FY' . $fy . '.xlsx');
}

// ─── Non-PS Supplies — XLSX ────────────────────────────────────
function gen_nonps_xlsx(PDO $pdo, int $fy, array $u): void {
    $stmt = $pdo->prepare("SELECT p.dept_code, d.name AS dept_name, e.description, e.quantity, e.mode,
                                  e.fund, e.amount, e.remarks
                             FROM ppmp_entries e
                             JOIN ppmps p ON p.id = e.ppmp_id
                        LEFT JOIN departments d ON d.code = p.dept_code
                            WHERE p.fy = ?
                              AND p.status IN ('submitted','budget_approved','approved')
                              AND e.category = 'nonps'
                         ORDER BY p.dept_code, e.amount DESC");
    $stmt->execute([$fy]);
    $rows = $stmt->fetchAll();

    $data = [];
    $data[] = ['List of Non-PS Supplies — FY ' . $fy];
    $data[] = ['Items unavailable at PS-DBM · Consolidated across all departments'];
    $data[] = [];
    $data[] = ['Dept Code', 'Department', 'Item / Description', 'Quantity', 'Mode of Procurement',
               'Fund Source', 'Amount (PhP)', 'Remarks'];

    $total = 0;
    foreach ($rows as $r) {
        $data[] = [
            $r['dept_code'], $r['dept_name'], $r['description'], $r['quantity'], $r['mode'],
            $r['fund'], (float)$r['amount'], $r['remarks'],
        ];
        $total += (float)$r['amount'];
    }
    $data[] = ['','','','','','GRAND TOTAL', $total, ''];

    $x = new SimpleXlsxWriter();
    $x->addSheet('Non-PS FY' . $fy, $data, [
        'titleRow' => 1, 'titleCols' => 8, 'headerRow' => 4,
        'columnWidths' => [12, 30, 40, 12, 22, 12, 15, 25],
    ]);
    log_activity('Generated report', 'report', 'Non-PS', "Generated FY $fy List of Non-PS Supplies (XLSX).");
    $x->download('Non-PS-Supplies-FY' . $fy . '.xlsx');
}

// ─── Indicative APP for mPhilGEPS — printable HTML ─────────────
function gen_indicative_html(PDO $pdo, int $fy, array $u): void {
    $stmt = $pdo->prepare("SELECT p.dept_code, d.name AS dept_name, e.description, e.mode, e.amount, e.fund
                             FROM ppmp_entries e
                             JOIN ppmps p ON p.id = e.ppmp_id
                        LEFT JOIN departments d ON d.code = p.dept_code
                            WHERE p.fy = ?
                              AND p.status IN ('submitted','budget_approved','approved')
                         ORDER BY p.dept_code, e.amount DESC");
    $stmt->execute([$fy]);
    $rows = $stmt->fetchAll();
    log_activity('Generated report', 'report', 'Indicative APP', "Generated FY $fy Indicative APP.");
    render_printable_report('Indicative APP for mPhilGEPS — FY ' . $fy,
        'Posting-ready indicative plan · Aurora State College of Technology',
        ['Dept', 'Department', 'Procurement Item', 'Mode', 'Fund', 'Indicative Amount (PhP)'],
        array_map(fn($r) => [$r['dept_code'], $r['dept_name'], $r['description'], $r['mode'], $r['fund'], number_format((float)$r['amount'], 2)], $rows),
        array_sum(array_map(fn($r) => (float)$r['amount'], $rows)),
        5   // total column index
    );
}

// ─── Submission Status Report ──────────────────────────────────
function gen_submission_status_html(PDO $pdo, int $fy, array $u): void {
    $stmt = $pdo->prepare("
        SELECT d.code, d.name, d.dept_group,
               (SELECT COUNT(*) FROM ppmps p WHERE p.dept_code = d.code AND p.fy = ?
                  AND p.status IN ('submitted','budget_approved','approved')) AS submitted_cnt,
               (SELECT COALESCE(SUM(e.amount),0) FROM ppmps p
                  JOIN ppmp_entries e ON e.ppmp_id = p.id
                 WHERE p.dept_code = d.code AND p.fy = ?
                   AND p.status IN ('submitted','budget_approved','approved')) AS total_amount,
               (SELECT MAX(p.submitted_at) FROM ppmps p WHERE p.dept_code = d.code AND p.fy = ?
                  AND p.status IN ('submitted','budget_approved','approved')) AS last_submitted
          FROM departments d
      ORDER BY d.display_order, d.code");
    $stmt->execute([$fy, $fy, $fy]);
    $rows = $stmt->fetchAll();
    require_once __DIR__ . '/config/academic-units.php';
    $rows = au_listable($rows);   // v81: a school's programs and majors, not the school itself
    $rows = au_active_rows($rows);   // v92: a deactivated department is not shown

    $tableRows = [];
    $submitted = 0; $totalAmount = 0;
    foreach ($rows as $r) {
        $status = (int)$r['submitted_cnt'] > 0 ? '✓ Submitted' : ' Not submitted';
        if ((int)$r['submitted_cnt'] > 0) $submitted++;
        $totalAmount += (float)$r['total_amount'];
        $tableRows[] = [
            $r['code'], $r['name'], $r['dept_group'], $status,
            $r['last_submitted'] ? date('M j, Y', strtotime($r['last_submitted'])) : '—',
            number_format((float)$r['total_amount'], 2),
        ];
    }
    log_activity('Generated report', 'report', 'Submission Status', "Generated FY $fy Submission Status Report.");
    render_printable_report('Submission Status Report — FY ' . $fy,
        "$submitted of " . count($rows) . " departments have submitted for FY $fy.",
        ['Dept Code', 'Department', 'Group', 'Status', 'Last Submission', 'Total Amount (PhP)'],
        $tableRows, $totalAmount, 5);
}

// ─── Fund Allocation Analysis ──────────────────────────────────
function gen_fund_allocation_html(PDO $pdo, int $fy, array $u): void {
    $funds = fund_codes();
    $tableRows = [];
    $totalAll = 0;
    foreach ($funds as $f) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) AS total, COUNT(DISTINCT p.dept_code) AS depts, COUNT(*) AS items
                              FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                             WHERE p.fy = ? AND e.fund = ?
                               AND p.status IN ('submitted','budget_approved','approved')");
        $s->execute([$fy, $f]);
        $r = $s->fetch();
        $tableRows[] = [$f, (int)$r['items'], (int)$r['depts'], number_format((float)$r['total'], 2)];
        $totalAll += (float)$r['total'];
    }
    log_activity('Generated report', 'report', 'Fund Allocation', "Generated FY $fy Fund Allocation Analysis.");
    render_printable_report('Fund Allocation Analysis — FY ' . $fy,
        'Breakdown of consolidated PPMP budgets by fund source · Aurora State College of Technology',
        ['Fund Source', 'Line Items', 'Departments', 'Total Amount (PhP)'],
        $tableRows, $totalAll, 3);
}

// ─── Category Aggregation Report ───────────────────────────────
function gen_category_aggregation_html(PDO $pdo, int $fy, array $u): void {
    $cats = ['training'=>'Training & Seminars','consulting'=>'Consulting Services','nonps'=>'Non-PS Supplies','appcse'=>'APP-CSE'];
    $tableRows = [];
    $totalAll = 0;
    foreach ($cats as $code => $label) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) AS total, COUNT(DISTINCT p.dept_code) AS depts, COUNT(*) AS items
                              FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                             WHERE p.fy = ? AND e.category = ?
                               AND p.status IN ('submitted','budget_approved','approved')");
        $s->execute([$fy, $code]);
        $r = $s->fetch();
        $tableRows[] = [$label, (int)$r['items'], (int)$r['depts'], number_format((float)$r['total'], 2)];
        $totalAll += (float)$r['total'];
    }
    log_activity('Generated report', 'report', 'Category Aggregation', "Generated FY $fy Category Aggregation Report.");
    render_printable_report('Category Aggregation Report — FY ' . $fy,
        'Totals per NGPA procurement category · Aurora State College of Technology',
        ['Category', 'Line Items', 'Departments', 'Total Amount (PhP)'],
        $tableRows, $totalAll, 3);
}

// ─── Historical Trends (year-over-year) ────────────────────────
function gen_historical_trends_html(PDO $pdo, array $u): void {
    $s = $pdo->query("SELECT p.fy, COALESCE(SUM(e.amount),0) AS total,
                             COUNT(DISTINCT p.dept_code) AS depts,
                             COUNT(*) AS items
                        FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                       WHERE p.status IN ('submitted','budget_approved','approved')
                    GROUP BY p.fy ORDER BY p.fy DESC");
    $rows = $s->fetchAll();
    $tableRows = [];
    $prevTotal = null;
    foreach ($rows as $r) {
        $total = (float)$r['total'];
        $delta = $prevTotal === null ? '—' :
            (($total - $prevTotal) >= 0 ? '+' : '') . number_format($total - $prevTotal, 2);
        $tableRows[] = ['FY ' . (int)$r['fy'], (int)$r['depts'], (int)$r['items'], number_format($total, 2), $delta];
        $prevTotal = $total;
    }
    log_activity('Generated report', 'report', 'Historical Trends', 'Generated Historical Trends report.');
    render_printable_report('Historical Trends — All Fiscal Years',
        'Year-over-year comparison of consolidated PPMP totals · Aurora State College of Technology',
        ['Fiscal Year', 'Departments', 'Line Items', 'Total Amount (PhP)', 'YoY Change'],
        $tableRows, 0, -1);
}

// ─── Helper: emit a printable HTML report page ────────────────
function render_printable_report(string $title, string $subtitle, array $headers, array $rows, float $grandTotal = 0, int $totalCol = -1): void {
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
      * { box-sizing: border-box; }
      body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 24px; color: var(--ink); background: var(--navy-50); }
      .paper { max-width: 1100px; margin: 0 auto; background: white; padding: 40px 48px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
      .letterhead { text-align: center; border-bottom: 2px solid #121358; padding-bottom: 14px; margin-bottom: 18px; }
      .letterhead .rp { font-size: 11px; }
      .letterhead .school { font-size: 17px; font-weight: 700; letter-spacing: 0.02em; margin: 2px 0; color: var(--navy-900); }
      .letterhead .addr { font-size: 10px; font-style: italic; color: var(--muted); }
      h1 { font-size: 20px; margin: 6px 0 4px; color: var(--navy-900); }
      .subtitle { font-size: 13px; color: var(--muted); margin-bottom: 20px; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 16px; }
      thead th { background: var(--navy-50); color: var(--ink); text-align: left; padding: 8px 10px; border: 1px solid var(--border); font-weight: 700; text-transform: uppercase; font-size: 10.5px; letter-spacing: 0.04em; }
      tbody td { padding: 8px 10px; border: 1px solid var(--border); vertical-align: top; }
      tbody tr:nth-child(even) { background: var(--navy-50); }
      tfoot td { background: var(--navy-50); font-weight: 700; padding: 10px; border: 1px solid var(--border); }
      .actions { text-align: right; margin-bottom: 12px; }
      .btn { display: inline-block; background: var(--orange-50); color: white; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 13px; border: none; cursor: pointer; font-family: inherit; }
      .btn.ghost { background: var(--navy-50); color: var(--ink); margin-right: 6px; }
      .footer { margin-top: 28px; font-size: 11px; color: var(--muted); text-align: center; border-top: 1px solid var(--border); padding-top: 14px; }
      @media print {
        body { background: white; padding: 0; }
        .paper { box-shadow: none; padding: 20mm; }
        .actions { display: none; }
      }
    </style></head>
    <body>
    <div class="paper">
      <div class="actions">
        <button onclick="window.close()" class="btn ghost">Close</button>
        <button onclick="window.print()" class="btn"> Print / Save as PDF</button></div>
      <div class="letterhead">
        <div class="rp">Republic of the Philippines</div>
        <div class="school">AURORA STATE COLLEGE OF TECHNOLOGY</div>
        <div class="addr">Brgy. Zabali, 3200 Baler, Aurora, Philippines</div>
        <div style="font-size: 11px; color: var(--ink); margin-top: 4px; font-weight: 600;">PROCUREMENT UNIT</div></div>
      <h1><?= htmlspecialchars($title) ?></h1>
      <div class="subtitle"><?= htmlspecialchars($subtitle) ?></div>
      <table>
        <thead>
          <tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($r as $c): ?><td><?= htmlspecialchars((string)$c) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= count($headers) ?>" style="text-align: center; color: var(--muted); padding: 24px;">No data available for this report.</td></tr>
          <?php endif; ?></tbody>
        <?php if ($grandTotal > 0 && $totalCol >= 0): ?>
          <tfoot>
            <tr>
              <?php for ($i = 0; $i < count($headers); $i++): ?>
                <td<?= $i === $totalCol ? '' : '' ?>><?php
                  if ($i === 0) echo 'GRAND TOTAL';
                  elseif ($i === $totalCol) echo '₱' . number_format($grandTotal, 2);
                ?></td>
              <?php endfor; ?></tr></tfoot>
        <?php endif; ?></table>
      <div class="footer"> Generated by ProcureEase · <?= date('F j, Y g:i a') ?> ·
        Governed by RA 12009 (NGPA)
      </div></div></body></html>
    <?php
    exit;
}

// ─── Reports listing page ─────────────────────────────────────


$reports = [
    ['type' => 'ppmp',    'name' => 'Project Procurement Management Plan', 'format' => 'PDF', 'desc' => "Every department's approved PPMP, laid out as they filled it in."],
    ['type' => 'nonps',   'name' => 'List of Non-PS Supplies',             'format' => 'PDF', 'desc' => "Every department's approved Non-PS list, in the same form they used."],
    ['type' => 'scoping', 'name' => 'Market Scoping',                      'format' => 'PDF', 'desc' => "Every department's approved market scoping document."],
];

// ─── What each report would actually contain, read live from the data ───
$reportStats = ['ppmp' => ['n' => 0, 'total' => 0], 'nonps' => ['n' => 0, 'total' => 0], 'scoping' => ['n' => 0, 'total' => 0]];
try {
    $r = $pdo->prepare("SELECT COUNT(DISTINCT p.id) AS n, COALESCE(SUM(e.amount),0) AS total
                          FROM ppmps p LEFT JOIN ppmp_entries e ON e.ppmp_id = p.id
                         WHERE p.fy = ? AND p.status = 'approved'");
    $r->execute([$reportFy]);
    if ($row = $r->fetch()) $reportStats['ppmp'] = ['n' => (int)$row['n'], 'total' => (float)$row['total']];

    $r = $pdo->prepare("SELECT COUNT(DISTINCT s.id) AS n, COALESCE(SUM(i.total_cost),0) AS total
                          FROM nonps_submissions s LEFT JOIN nonps_items i ON i.submission_id = s.id
                         WHERE s.fy = ? AND s.status = 'approved'
                           AND (s.archived IS NULL OR s.archived = 0)");
    $r->execute([$reportFy]);
    // v80: the approved lists' GRAND TOTAL (with VAT, logistics, inflation), as on Non-PS (Consolidated)
    require_once __DIR__ . '/config/price-provision.php';
    if ($row = $r->fetch()) $reportStats['nonps'] = ['n' => (int)$row['n'], 'total' => nonps_grand_for((float)$row['total'], (int)$reportFy)];

    $r = $pdo->prepare("SELECT COUNT(*) AS n FROM market_scoping WHERE fy = ? AND status = 'approved'");
    $r->execute([$reportFy]);
    if ($row = $r->fetch()) $reportStats['scoping'] = ['n' => (int)$row['n'], 'total' => 0];
} catch (Throwable $t) {}

$activeNav = 'reports';
$pageTitle = 'Reports';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Reports · FY <?= $reportFy ?></h1>
        <p class="page-sub">
          <?php $totalDocs = array_sum(array_column($reportStats, 'n')); ?>
          <?= $totalDocs ?> approved document<?= $totalDocs === 1 ? '' : 's' ?> available for FY <?= $reportFy ?>.
          Reports are built from the data as it stands right now.
        </p></div>
    </div>
    <?php render_flashes(); ?>

    <div class="card">
      <div class="card-title">Approved documents</div>
      <?php foreach ($reports as $r):
        $st    = $reportStats[$r['type']] ?? ['n' => 0, 'total' => 0];
        $count = $st['n'];
        $ready = $count > 0;
      ?>
        <div class="file" style="margin-bottom: 10px;">
          <div class="file-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
          <div>
            <div class="file-name"><?= e($r['name']) ?> <span style="background: var(--navy-50); color: var(--ink); padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-left: 4px;"><?= e($r['format']) ?></span></div>
            <div class="file-meta"><?= e($r['desc']) ?></div>
            <div class="file-meta" style="margin-top: 3px; font-weight: 600; color: <?= $ready ? 'var(--green-500)' : 'var(--orange-600)' ?>;">
              <?php if ($ready): ?>
                <?= $count ?> approved document<?= $count === 1 ? '' : 's' ?>
                <?php if ($st['total'] > 0): ?> · ₱<?= number_format($st['total'], 2) ?><?php endif; ?>
              <?php else: ?>
                Nothing approved yet for FY <?= $reportFy ?>
              <?php endif; ?>
            </div></div>
          <span></span>
          <a href="report-view.php?type=<?= urlencode($r['type']) ?>&fy=<?= $reportFy ?>" target="_blank" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;">Generate</a>
        </div>
      <?php endforeach; ?></div>

  </main></div></body></html>
