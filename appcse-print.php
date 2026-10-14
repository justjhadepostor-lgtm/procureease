<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-items.php';
require_once __DIR__ . '/config/appcse-signatories.php';
require_login();
ensure_admin_tables();

$u   = current_user();
$pdo = db();
$fy  = (int)($_GET['fy'] ?? active_fy());

/* Whose APP-CSE is being printed.
   A department always prints its own. The Procurement Unit may print any
   department's, which is how it opens one from the Archive or the
   consolidated view. */
$myDept   = (string)($u['dept_code'] ?? '');
$isAdmin  = ($u['role'] ?? '') === 'admin';
$deptCode = $isAdmin ? (string)($_GET['dept'] ?? $myDept) : $myDept;
// The Budget Unit opens the APP-CSE a department submitted with its PPMP —
// only once it has been submitted, never a department's working copy.
if (($u['role'] ?? '') === 'budget' && isset($_GET['dept'])
    && !in_array((string)$_GET['dept'], [$myDept, '__ALL__'], true)) {
    $__req = (string)$_GET['dept'];
    $__st  = '';
    try {
        $__q = $pdo->prepare('SELECT status FROM ppmps WHERE dept_code = ? AND fy = ?');
        $__q->execute([$__req, $fy]);
        $__st = (string)($__q->fetchColumn() ?: '');
    } catch (Throwable $t) {}
    if (!in_array($__st, APPCSE_LOCKED_STATUSES, true)) {
        http_response_code(403);
        exit('This department has not submitted its FY ' . $fy . ' APP-CSE yet.');
    }
    $deptCode = $__req;
}
$__otherDept = !in_array($deptCode, [$myDept, '__ALL__'], true);
// ?dept=__ALL__ prints the consolidated copy — every approved department's
// quantities added together, on this same form. Procurement Unit only.
$consolidated = ($isAdmin && $deptCode === '__ALL__');
if (!$consolidated && $deptCode === '') { http_response_code(404); exit('No department.'); }

$deptName = $deptCode;
if (!$consolidated) {
    try {
        $dn = $pdo->prepare('SELECT name FROM departments WHERE code = ?');
        $dn->execute([$deptCode]);
        $deptName = (string)($dn->fetchColumn() ?: $deptCode);
    } catch (Throwable $t) {}
    appcse_ensure_seeded($pdo, $fy, $deptCode);
    $grouped = appcse_get_grouped($pdo, $fy, $deptCode);
} else {
    $deptName = appcse_institution_name($pdo);
    $grouped  = appcse_consolidated_grouped($pdo, $fy);
}

// Same defaults as the editor: the office is the department whose APP-CSE
// this is, not a hardcoded 'Procurement Unit'.
$hdr = $consolidated ? appcse_consolidated_header($pdo, $fy) : ['department'=>$deptName,'region'=>'III','address'=>'Barangay Zabali, Baler, Aurora','agency_code'=>'','contact_person'=>'','position'=>'','org_type'=>'','email'=>'','telephone'=>'','total_items'=>null,'sig1_name'=>'','sig1_position'=>'','sig2_name'=>'','sig2_position'=>'','sig3_name'=>'','sig3_position'=>'','sig_date_value'=>''];
if (!$consolidated) {
    try {
        $h = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $h->execute([$fy, $deptCode]);
        if ($row = $h->fetch()) {
            foreach ($row as $k => $v) {
                if (array_key_exists($k, $hdr) && (trim((string)$v) !== '' || str_starts_with((string)$k, 'sig') || $k === 'sig_date_value')) $hdr[$k] = $v;
            }
        }
    } catch (Throwable $t) {}
}
if (trim((string)($hdr['department'] ?? '')) === '') $hdr['department'] = $deptName;
if (trim((string)($hdr['region'] ?? '')) === '') $hdr['region'] = 'III';
if (trim((string)($hdr['address'] ?? '')) === '') $hdr['address'] = 'Barangay Zabali, Baler, Aurora';

$months = APPCSE_MONTHS;
function nz($n){ return $n==0?'0':rtrim(rtrim(number_format($n,2),'0'),'.'); }

$partTotals = ['I'=>0, 'II'=>0];
doc_fmt_use_year($fy);   // the APP-CSE of this fiscal year
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>APP-CSE FY <?= $fy ?></title>
<style>
  @page { size: A4 landscape; margin: 8mm 6mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; color:#000; margin:0; padding:14px; font-size:8px; }
  .hd { text-align:center; margin-bottom:8px; }
  .hd .t1 { font-size:12px; font-weight:bold; }
  .hd .t2 { font-size:10px; font-weight:bold; }
  .info { font-size:8px; margin:8px 0; }
  table { border-collapse:collapse; width:100%; }
  th,td { border:1px solid #000; padding:2px 3px; text-align:center; }
  th { background:#e8e8e8; -webkit-print-color-adjust:exact; print-color-adjust:exact; font-size:7px; }
  td.d { text-align:left; white-space:nowrap; }
  td.c { text-align:left; font-family:monospace; font-size:6.5px; }
  tr.part td { background:#133458; color:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; text-align:left; font-weight:bold; font-size:9px; }
  tr.cat td { background:#dce7f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; text-align:left; font-weight:bold; }
  .foot { margin-top:10px; font-size:9px; width:60%; }
  .foot td { border:none; text-align:left; padding:2px 6px; }
  .foot td.n { text-align:right; font-weight:bold; }
  .sign { margin-top:24px; display:flex; justify-content:space-between; font-size:9px; }
  .sign .col { text-align:center; width:30%; }
  .sign .nm { border-top:1px solid #000; margin-top:26px; padding-top:3px; font-weight:bold; }
  .toolbar { margin-bottom:12px; } .toolbar a, .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: none; color: #fff; font-family: Arial, sans-serif; margin-right: 6px; }
  @media print { .toolbar{display:none!important;} body{padding:0;} }
  /* Letterhead */
  .letterhead { display:grid; grid-template-columns:80px 1fr 80px; align-items:center; gap:10px; margin-bottom:10px; }
  .letterhead .seal { width:80px; height:80px; display:flex; align-items:center; justify-content:center; }
  .letterhead .seal img { width:74px; height:74px; object-fit:contain; }
  .letterhead-center { text-align:center; }
  .rep-line { font-size:10px; }
  .school-name { font-size:15px; font-weight:700; letter-spacing:0.02em; margin:1px 0; }
  .school-addr { font-size:9px; font-style:italic; }
  .doc-title { font-size:11px; font-weight:700; margin-top:5px; }
  .flag { width:80px; height:52px; background:linear-gradient(to bottom,#0038a8 0 33.33%,#fff 33.33% 66.66%,#ce1126 66.66% 100%); position:relative; border:1px solid #ccc; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .flag::before { content:''; position:absolute; left:0; top:0; height:100%; width:40%; background:#fff; clip-path:polygon(0 0,100% 50%,0 100%); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .form-title { text-align:center; font-size:11px; font-weight:bold; margin:8px 0 6px; }
  .intro { font-size:7.5px; text-align:center; line-height:1.4; margin:0 auto 8px; max-width:80%; }
  .intro .lbl { font-weight:bold; }
  .reminders { font-size:7px; line-height:1.4; margin:10px 0 8px; }
  .reminders .rem-head { font-style:italic; margin-bottom:3px; }
  .reminders .rem { display:grid; grid-template-columns:24px 1fr; column-gap:4px; margin-bottom:2px; font-weight:bold; }
  .reminders .rem a { color:#1155cc; }
  .note { font-size:7.5px; font-style:italic; margin:8px 0; }
  .agency-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:3px 20px; font-size:8px; margin-bottom:8px; }
  .agency-grid > div { display:grid; grid-template-columns:auto 1fr; align-items:center; column-gap:4px; row-gap:2px; }
  .agency-grid .fld { display:contents; }
  .agency-grid .fld .lb { text-align:right; white-space:nowrap; }
  .agency-grid .fld .bx { border:1px solid #999; background:#f0f0f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; min-height:11px; padding:0 4px; }
</style></head>
<body>
  <div class="toolbar">
    <?php
      // Back to wherever a reader of this copy came from: the consolidated
      // form, the Budget Unit's folders, or the department's own editor.
      $__back = $consolidated ? 'appcse-consolidated.php?fy=' . $fy
              : ($__otherDept ? ($isAdmin ? 'appcse-consolidated.php?fy=' . $fy : 'budget-dashboard.php') : 'appcse-edit.php');
    ?>
    <a href="<?= e($__back) ?>" style="background:#64748B;">← Back</a>
    <button onclick="window.print()" style="background:#1c4c86;">🖨️ Print</button>
  </div>

  <?php
    $acConsolidated = $consolidated;
    require __DIR__ . '/includes/appcse-form.php';
  ?>
<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body></html>
