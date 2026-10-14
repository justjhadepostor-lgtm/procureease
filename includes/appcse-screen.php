<?php
/* ═══════════════════════════════════════════════════════════════
   The APP-CSE, as it looks on screen — one copy, two pages.

   appcse-edit.php          a department fills this in
   appcse-consolidated.php  the Procurement Unit reads the same form with
                            the approved departments' quantities added in

   They used to be different documents: the department had the official
   form, and the consolidated page had a plain summary table with none of
   its headings, quarters, totals or signatures. It is one file now, so
   they cannot look different from each other again.

   Expects from the page that includes it:
     $fy          int    the fiscal year
     $grouped     array  [part][category] => rows
     $hdr         array  the agency block
     $acReadOnly  bool   true for a department whose APP-CSE has already
                         been submitted
     $acConsolEdit bool  true on the Procurement Unit's consolidated copy:
                         cells it has changed are marked, and a changed line
                         gets a ↺ button that puts it back to the sum
   =============================================================== */
$acReadOnly   = $acReadOnly ?? false;
$acConsolEdit = $acConsolEdit ?? false;
$acRO = $acReadOnly ? ' readonly' : '';   // dropped straight into the inputs
// v104: only the Super Admin (Procurement Unit) adds items to the APP-CSE; a department fills in what is there
$acCanAdd = $acCanAdd ?? (function_exists('current_user') && ((current_user()['role'] ?? '') === 'admin'));
// " ov" on a cell the Procurement Unit changed on the consolidated copy
$acOv = function (array $r, string $f) use ($acConsolEdit): string {
    return ($acConsolEdit && in_array($f, $r['__edited'] ?? [], true)) ? ' ov' : '';
};
?>
<style>
  /* Keep the topbar/sidebar still while this page's wide table scrolls —
     grid columns default to min-width:auto, so without this the whole
     .main column (and page) stretches to fit the table and scrolls with it. */
  .main { min-width: 0; }
  .ac-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
  /* Find a supply: narrows the rows on screen; nothing is taken off the form */
  .ac-find { display: flex; align-items: center; gap: 8px 14px; flex-wrap: wrap; margin: 0 0 10px; }
  .ac-find .search-field { flex: 0 1 380px; min-width: 0; }
  .ac-find-count { font-size: 12px; color: var(--muted); }
  table.appcse tr.ac-hide { display: none; }
  /* Under the table, as on the printed form (includes/appcse-summary.php) */
  .ac-foot-wrap { margin-top: 12px; padding: 16px 18px; }
  table.appcse { border-collapse: collapse; font-size: 11px; min-width: 1500px; width: 100%; }
  table.appcse th, table.appcse td { border: 1px solid #d7dee6; padding: 3px 5px; text-align: center; }
  table.appcse thead th { background: var(--navy-50); color: var(--ink); font-weight: 700; position: sticky; top: 0; z-index: 2; }
  table.appcse .part-row td { background: #133458; color: #fff; text-align: left; font-weight: 700; font-size: 12px; padding: 7px 10px; }
  table.appcse .cat-row td { background: var(--navy-100); color: #133458; text-align: left; font-weight: 700; font-size: 11px; }
  table.appcse td.desc { text-align: left; white-space: nowrap; }
  table.appcse td.code { text-align: left; font-family: monospace; font-size: 10px; color: var(--muted); white-space: nowrap; }
  table.appcse input.f-code, table.appcse input.f-desc, table.appcse input.f-unit, table.appcse input.f-price {
    border: 1px solid transparent; background: transparent; font-family: inherit; font-size: 11px;
    color: var(--ink); padding: 3px 4px; border-radius: 4px; width: 100%; box-sizing: border-box;
  }
  table.appcse input.f-code { font-family: monospace; font-size: 10px; color: var(--muted); }
  table.appcse input.f-price { text-align: center; }
  table.appcse input.f-code:focus, table.appcse input.f-desc:focus,
  table.appcse input.f-unit:focus, table.appcse input.f-price:focus {
    border-color: #4A7AAD; background: #fff; outline: none;
  }
  table.appcse td.rowdel { width: 26px; padding: 0; }
  table.appcse .rowdel-btn {
    border: none; background: transparent; color: #B3312C; font-size: 15px; line-height: 1;
    cursor: pointer; padding: 4px 6px; border-radius: 4px;
  }
  table.appcse .rowdel-btn:hover { background: #FBE7E6; }
  .cat-addrow { padding: 4px 10px !important; text-align: left !important; background: #F4F7FA !important; }
  .cat-addrow button {
    border: 1px dashed #9FB4C7; background: #fff; color: #133458; font-size: 11px;
    padding: 4px 10px; border-radius: 6px; cursor: pointer;
  }
  .cat-addrow button:hover { background: #EAF1F7; }
  table.appcse input.m { width: 42px; border: 1px solid transparent; background: transparent; text-align: center; font-size: 11px; padding: 3px 2px; border-radius: 4px; }
  table.appcse input.m:focus { border-color: #4A7AAD; background: #fff; outline: none; }
  table.appcse td.qsum, table.appcse td.qamt { background: #f6f9fc; font-weight: 600; }
  table.appcse td.total, table.appcse td.amount { background: #eef4f9; font-weight: 700; color: #133458; white-space: nowrap; }
  table.appcse td.price { color: var(--muted); white-space: nowrap; }
  /* The consolidated copy: a cell the Procurement Unit changed, and the
     button that puts a line back to the departments' sum */
  table.appcse input.ov { background:#fff4d6; box-shadow: inset 0 -2px 0 #b7791f; color:#133458; }
  table.appcse input.ov:focus { background:#fff; }
  /* the paper stays white in dark mode, and so does this mark on it */
  body[data-theme="dark"] table.appcse input.ov { background:#fff4d6 !important; }
  body[data-theme="dark"] table.appcse input.ov:focus { background:#fff !important; }
  table.appcse td.rowdel.wide { width:52px; white-space:nowrap; }
  table.appcse .rowreset-btn {
    border:none; background:transparent; color:#133458; font-size:14px; line-height:1;
    cursor:pointer; padding:4px 5px; border-radius:4px;
  }
  table.appcse .rowreset-btn:hover { background:#EAF1F7; }
  .grand-box { display:flex; gap:16px; flex-wrap:wrap; margin:0 0 16px; }
  .grand-box .gb { flex:1 1 0; min-width:200px; background:#fff; border:1px solid var(--border); border-left:4px solid #133458; border-radius:10px; padding:16px 22px; }
  .grand-box .gb .lbl { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
  .grand-box .gb .val { font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:700; color:#133458; margin-top:4px; }
  /* Official form header (matches the printed APP-CSE) */
  .ac-official { background:#fff; border:1px solid var(--border); border-radius:10px; padding:20px 24px; margin-bottom:18px; }
  .ac-letterhead { display:grid; grid-template-columns:70px 1fr 70px; align-items:center; gap:12px; margin-bottom:12px; }
  .ac-letterhead .seal img { width:66px; height:66px; object-fit:contain; }
  .ac-letterhead .center { text-align:center; }
  .ac-letterhead .rep { font-size:11px; color:var(--ink); }
  .ac-letterhead .school { font-family:'Space Grotesk',sans-serif; font-size:17px; font-weight:700; color:#133458; }
  .ac-letterhead .addr { font-size:10px; font-style:italic; color:var(--muted); }
  .ac-letterhead .flag { width:70px; height:46px; background:linear-gradient(to bottom,#0038a8 0 33.33%,#fff 33.33% 66.66%,#ce1126 66.66% 100%); position:relative; border:1px solid #ccc; }
  .ac-letterhead .flag::before { content:''; position:absolute; left:0; top:0; height:100%; width:40%; background:#fff; clip-path:polygon(0 0,100% 50%,0 100%); }
  .ac-formtitle { text-align:center; font-weight:700; font-size:13px; color:#133458; margin:10px 0 10px; }
  .ac-intro { font-size:11px; color:var(--ink); line-height:1.5; text-align:center; margin:0 auto 12px; max-width:900px; }
  .ac-reminders { font-size:11px; color:#000; line-height:1.45; margin-top:16px; margin-bottom:14px; }
  .ac-reminders .rem-head { font-style:italic; margin-bottom:5px; }
  .ac-reminders .rem { display:grid; grid-template-columns:34px 1fr; column-gap:6px; margin-bottom:4px; font-weight:700; }
  .ac-reminders .rem .n { font-weight:700; }
  .ac-reminders .rem a { color:#1155cc; }
  .ac-note { font-size:11px; font-style:italic; color:#000; margin:14px 0 12px; }
  .ac-note b { font-style:italic; }
  /* Agency info — 3-column layout with right-aligned labels + boxed fields */
  .ac-agency-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px 28px; font-size:11px; }
  .ac-agency-grid > div { display:grid; grid-template-columns:auto 1fr; align-items:center; column-gap:6px; row-gap:3px; }
  .ac-agency-grid .fld { display:contents; }
  .ac-agency-grid .fld .lb { text-align:right; white-space:nowrap; }
  .ac-agency-grid .fld .bx { border:1px solid #999; background:#f0f0f0; min-height:16px; padding:1px 6px; font-size:10px; }
  .ac-agency-grid .fld input.bx { width:100%; box-sizing:border-box; font-family:inherit; font-size:11px; color:#133458; padding:3px 6px; }
  .ac-agency-grid .fld input.bx:focus { outline:none; background:#fff; border-color:#4A7AAD; box-shadow:0 0 0 2px rgba(74,122,173,0.15); }
</style>
    <div class="grand-box">
      <div class="gb"><div class="lbl"><?= e(doc_fmt('appcse', 'lbl_part1')) ?></div><div class="val" id="gtPartI">₱0.00</div></div>
      <div class="gb"><div class="lbl"><?= e(doc_fmt('appcse', 'lbl_part2')) ?></div><div class="val" id="gtPartII">₱0.00</div></div>
      <div class="gb" style="border-left-color:#E8DA8F;"><div class="lbl"><?= e(doc_fmt('appcse', 'lbl_grand')) ?></div><div class="val" id="gtGrand">₱0.00</div></div>
      <?php /* D of Part I + D of Part II: what the APP-CSE comes to with the 10% (and C.) */ ?>
      <div class="gb" style="border-left-color:#15803d;"><div class="lbl"><?= e(doc_fmt('appcse', 'lbl_grand_d')) ?></div><div class="val" id="gtGrandD">₱0.00</div></div>
    </div>

    <!-- Official APP-CSE header (same content as the printed form) -->
    <div class="ac-official">
      <div class="ac-formtitle"><?= doc_nl('appcse', 'form_title', $fy) ?></div>

      <div class="ac-intro">
        <span class="lbl"><?= e(doc_fmt('appcse', 'intro_label')) ?></span> <?= doc_nl('appcse', 'intro_body') ?>
      </div>

      <div class="ac-reminders">
        <div class="rem-head"><?= e(doc_fmt('appcse', 'reminders_label')) ?></div>
        <?= doc_block('appcse', 'reminders_body', (int)$fy) ?>
      </div>

      <div class="ac-note"><?= doc_nl('appcse', 'note_text', $fy) ?></div>

      <div class="ac-agency-grid">
        <div>
          <div class="fld"><span class="lb">Department/Bureau/Office:</span><input class="bx hdr" id="h_department" value="<?= e($hdr['department']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">Region:</span><input class="bx hdr" id="h_region" value="<?= e($hdr['region']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">Address:</span><input class="bx hdr" id="h_address" value="<?= e($hdr['address']) ?>"<?= $acRO ?>></div>
        </div>
        <div>
          <div class="fld"><span class="lb">Agency Code/UACS:</span><input class="bx hdr" id="h_agency_code" value="<?= e($hdr['agency_code']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">Organization Type:</span><input class="bx hdr" id="h_org_type" value="<?= e($hdr['org_type']) ?>"<?= $acRO ?>></div>
        </div>
        <div>
          <div class="fld"><span class="lb">Contact Person:</span><input class="bx hdr" id="h_contact_person" value="<?= e($hdr['contact_person']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">Position:</span><input class="bx hdr" id="h_position" value="<?= e($hdr['position']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">E-mail :</span><input class="bx hdr" id="h_email" value="<?= e($hdr['email']) ?>"<?= $acRO ?>></div>
          <div class="fld"><span class="lb">Telephone/Mobile Nos:</span><input class="bx hdr" id="h_telephone" value="<?= e($hdr['telephone']) ?>"<?= $acRO ?>></div>
        </div>
      </div>
    </div>

    <?php require_once __DIR__ . '/../config/ui.php'; ?>
    <div class="ac-find" role="search">
      <?= search_field(['id' => 'acFind', 'placeholder' => 'Search supplies — item, code or category…', 'label' => 'Search the supplies on this APP-CSE',
                        'attrs' => 'maxlength="80"']) ?>
      <span class="ac-find-count" id="acFindCount" role="status" aria-live="polite"></span>
    </div>
    <div class="ac-wrap">
      <table class="appcse">
        <thead>
          <tr>
            <th rowspan="2" style="width:<?= $acConsolEdit ? 52 : 26 ?>px;"></th>
            <th rowspan="2" style="min-width:90px;"><?= doc_nl('appcse', 'col_code') ?></th>
            <th rowspan="2" style="min-width:230px;"><?= doc_nl('appcse', 'col_item') ?></th>
            <th rowspan="2"><?= doc_nl('appcse', 'col_uom') ?></th>
            <th colspan="20"><?= doc_nl('appcse', 'col_months') ?></th>
            <th rowspan="2"><?= doc_nl('appcse', 'col_totqty') ?></th>
            <th rowspan="2"><?= doc_nl('appcse', 'col_price') ?></th>
            <th rowspan="2"><?= doc_nl('appcse', 'col_amount') ?></th>
          </tr>
          <tr>
            <th><?= e(doc_fmt('appcse', 'mon_1')) ?></th><th><?= e(doc_fmt('appcse', 'mon_2')) ?></th><th><?= e(doc_fmt('appcse', 'mon_3')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_1')) ?></th><th><?= doc_nl('appcse', 'qamt_1') ?></th>
            <th><?= e(doc_fmt('appcse', 'mon_4')) ?></th><th><?= e(doc_fmt('appcse', 'mon_5')) ?></th><th><?= e(doc_fmt('appcse', 'mon_6')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_2')) ?></th><th><?= doc_nl('appcse', 'qamt_2') ?></th>
            <th><?= e(doc_fmt('appcse', 'mon_7')) ?></th><th><?= e(doc_fmt('appcse', 'mon_8')) ?></th><th><?= e(doc_fmt('appcse', 'mon_9')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_3')) ?></th><th><?= doc_nl('appcse', 'qamt_3') ?></th>
            <th><?= e(doc_fmt('appcse', 'mon_10')) ?></th><th><?= e(doc_fmt('appcse', 'mon_11')) ?></th><th><?= e(doc_fmt('appcse', 'mon_12')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_4')) ?></th><th><?= doc_nl('appcse', 'qamt_4') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (['I' => 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)',
                        'II' => 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM'] as $partKey => $partLabel): ?>
          <tr class="part-row"><td colspan="27"><?= e($partLabel) ?></td></tr>
          <?php foreach (($grouped[$partKey] ?? []) as $cat => $rows): ?>
            <tr class="cat-row" data-cat-row><td colspan="27"><?= e($cat) ?></td></tr>
            <?php foreach ($rows as $r):
              $price = (float)$r['unit_price'];
            ?>
              <tr data-id="<?= (int)($r['id'] ?? 0) ?>" data-price="<?= $price ?>" data-part="<?= $partKey ?>" data-cat="<?= e($cat) ?>"<?php if ($acConsolEdit): ?> data-key="<?= e($r['__key'] ?? '') ?>"<?= !empty($r['__added']) ? ' data-added="1"' : '' ?><?php endif; ?>>
                <td class="rowdel<?= $acConsolEdit ? ' wide' : '' ?>"><?php if (!$acReadOnly): ?><button type="button" class="rowdel-btn" title="<?= $acConsolEdit ? 'Remove this line from the consolidated APP-CSE' : 'Delete row' ?>" onclick="deleteRow(this)">✕</button><?php endif; ?><?php if ($acConsolEdit && empty($r['__added']) && !empty($r['__edited'])): ?><button type="button" class="rowreset-btn" title="Put this line back to the departments' sum" aria-label="Put this line back to the departments' sum" onclick="resetLine(this)">↺</button><?php endif; ?></td>
                <td class="code"><input class="f-code<?= $acOv($r, 'code') ?>" value="<?= e($r['code']) ?>"<?= $acRO ?>></td>
                <td class="desc"><input class="f-desc<?= $acOv($r, 'description') ?>" value="<?= e($r['description']) ?>"<?= $acRO ?>></td>
                <td><input class="f-unit<?= $acOv($r, 'unit') ?>" value="<?= e($r['unit']) ?>"<?= $acRO ?>></td>
                <?php
                  $qGroups = [['jan','feb','mar'], ['apr','may','jun'], ['jul','aug','sep'], ['oct','nov','dec']];
                  foreach ($qGroups as $qi => $mset):
                    foreach ($mset as $mn):
                      $val = rtrim(rtrim(number_format((float)$r[$mn],2,'.',''),'0'),'.');
                ?>
                      <td><input class="m<?= $acOv($r, $mn) ?>" data-m="<?= $mn ?>" value="<?= $val ?>" oninput="recalcRow(this.closest('tr'))"<?= $acRO ?>></td>
                <?php endforeach; ?>
                    <td class="qsum" data-q="<?= $qi ?>">0</td>
                    <td class="qamt" data-qa="<?= $qi ?>">0.00</td>
                <?php endforeach; ?>
                <td class="total">0</td>
                <td class="price"><input class="f-price money<?= $acOv($r, 'unit_price') ?>" inputmode="decimal" value="<?= rtrim(rtrim(number_format($price,2,'.',''),'0'),'.') ?>" oninput="recalcRow(this.closest('tr'))"<?= $acRO ?>></td>
                <td class="amount">₱0.00</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$acReadOnly && $acCanAdd): ?>
            <tr class="cat-addrow-tr" data-cat="<?= e($cat) ?>" data-part="<?= $partKey ?>">
              <td colspan="27" class="cat-addrow"><button type="button" onclick="addRow(this)">+ Add item to this category</button></td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      /* Under the table, the same as the printed form (includes/appcse-summary.php):
         A to E of each Part, the statement and the signatures. C. is typed by the
         department on its own APP-CSE; on the consolidated copy it is the counted
         departments' C. added together. The figures update as you type. */
      require_once __DIR__ . '/../config/price-provision.php';
      $__acDept = $acConsolEdit ? '__ALL__' : (string)($acDeptCode ?? ($deptCode ?? ''));
      $__acA = ['I' => 0.0, 'II' => 0.0];
      foreach ($grouped as $__pk => $__byCat) foreach ($__byCat as $__rows) foreach ($__rows as $__r) {
          $__q = 0.0; foreach (APPCSE_MONTHS as $__m) $__q += (float)($__r[$__m] ?? 0);
          $__acA[$__pk === 'II' ? 'II' : 'I'] += $__q * (float)$__r['unit_price'];
      }
      $acSum = appcse_summary($__acA, appcse_transport((int)$fy, $__acDept), (int)$fy);
      $acSumScreen = true;
      $acSumEditC  = !$acReadOnly && !$acConsolEdit;
    ?>
    <div class="ac-wrap ac-foot-wrap" id="acFoot" data-rate="<?= e(nonps_rate_text((float)$acSum['rate'])) ?>">
      <?php require __DIR__ . '/appcse-summary.php'; ?>
    </div>
    <?= pp_js() ?>

<script>
/* Quarter totals, line totals and the three boxes at the top. Shared by the
   department's editor and the Procurement Unit's consolidated copy, so both
   add up the same way. */
  function peso(n){ const cents = Number.isInteger(n) ? n : Math.round((Number(n)||0)*100); return '₱' + (cents/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function numstr(n){ return (Math.round((Number(n)||0)*100)/100).toLocaleString('en-US',{maximumFractionDigits:2}); }
  function moneyCents(v){ return Math.round((Number.parseFloat(v)||0)*100); }
  function rowPrice(tr){ const inp = tr.querySelector('input.f-price'); return inp ? (parseFloat(inp.value)||0) : (parseFloat(tr.dataset.price)||0); }

  function recalcRow(tr){
    const price = rowPrice(tr);
    const months = tr.querySelectorAll('input.m');
    let vals = [];
    months.forEach(function(inp){ vals.push(parseFloat(inp.value)||0); });
    let total = 0;
    for (let q=0; q<4; q++){
      const qsum = vals[q*3] + vals[q*3+1] + vals[q*3+2];
      const qamtCents = Math.round(qsum * moneyCents(price));
      tr.querySelector('.qsum[data-q="'+q+'"]').textContent = numstr(qsum);
      tr.querySelector('.qamt[data-qa="'+q+'"]').textContent = (qamtCents/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
      total += qsum;
    }
    tr.querySelector('.total').textContent = numstr(total);
    tr.querySelector('.amount').textContent = peso(Math.round(total * moneyCents(price)));
    recalcGrand();
  }

  function recalcGrand(){
    let pI = 0, pII = 0;
    document.querySelectorAll('tbody tr[data-id]').forEach(function(tr){
      const price = rowPrice(tr);
      const total = parseFloat(tr.querySelector('.total').textContent.replace(/,/g,''))||0;
      const amtCents = Math.round(total * moneyCents(price));
      if (tr.dataset.part === 'I') pI += amtCents; else pII += amtCents;
    });
    document.getElementById('gtPartI').textContent = peso(pI);
    document.getElementById('gtPartII').textContent = peso(pII);
    document.getElementById('gtGrand').textContent = peso(pI + pII);
    // Under the table, per Part: A, B = 10% of A, C as typed, D = A + B + C, E = D in figures and words
    if (!window.PP) return;
    let dAll = 0;
    [['I', pI], ['II', pII]].forEach(function(p){
      const t = document.querySelector('#acFoot table[data-part="' + p[0] + '"]'); if (!t) return;
      const ci = t.querySelector('input.ac-c'), cc = t.querySelector('[data-f="c"]');
      const a = Math.round(p[1]), b = PP.pct(a, (document.getElementById('acFoot') || {dataset: {}}).dataset.rate || 10), c = Math.max(0, PP.cents(ci ? ci.value : (cc ? cc.dataset.c : 0))), autoD = a + b + c;
      const di = t.querySelector('input.ac-d-override');
      const d = di && di.value.trim() !== '' ? Math.max(0, PP.cents(di.value)) : autoD;
      const put = function(f, v){ const el = t.querySelector('[data-f="' + f + '"] .v'); if (el) el.textContent = PP.peso(v).slice(1); };
      put('a', a); put('b', b); if (!ci) put('c', c); if (!di) put('d', d);
      const e = t.querySelector('[data-f="e"]'); if (e) e.textContent = PP.figuresWords(d);
      dAll += d;
    });
    const gd = document.getElementById('gtGrandD'); if (gd) gd.textContent = PP.peso(dAll);
  }


  /* Search supplies: shows only the lines whose item, code, unit or category
     has what was typed (a category's name shows all of its lines). Hidden lines
     still count in every total and are still saved — only the view narrows. */
  (function(){
    const box = document.getElementById('acFind'); if (!box) return;
    const count = document.getElementById('acFindCount');
    const val = (tr, cls) => { const i = tr.querySelector('input.' + cls); return i ? i.value : ''; };
    function run(){
      const q = box.value.trim().toLowerCase();
      const table = document.querySelector('table.appcse'); if (!table) return;
      let shown = 0, catRow = null, catHit = false, catAny = false, partRow = null, partAny = false;
      const rows = Array.prototype.slice.call(table.tBodies[0].rows);
      function closeCat(){ if (catRow) catRow.classList.toggle('ac-hide', q !== '' && !catAny); }
      function closePart(){ if (partRow) partRow.classList.toggle('ac-hide', q !== '' && !partAny); }
      rows.forEach(function(tr){
        if (tr.classList.contains('part-row')) { closeCat(); closePart(); partRow = tr; partAny = false; catRow = null; return; }
        if (tr.hasAttribute('data-cat-row')) {
          closeCat(); catRow = tr; catAny = false;
          catHit = q !== '' && tr.textContent.toLowerCase().indexOf(q) > -1;
          return;
        }
        if (tr.classList.contains('cat-addrow-tr')) { tr.classList.toggle('ac-hide', q !== ''); return; }
        if (!tr.hasAttribute('data-id')) return;
        const text = [val(tr, 'f-code'), val(tr, 'f-desc'), val(tr, 'f-unit')].join(' ').toLowerCase();
        const hit = q === '' || catHit || text.indexOf(q) > -1;
        tr.classList.toggle('ac-hide', !hit);
        if (hit) { shown++; catAny = true; partAny = true; }
      });
      closeCat(); closePart();
      count.textContent = q === '' ? '' : (shown ? shown + (shown === 1 ? ' supply found' : ' supplies found') : 'No supplies match “' + box.value.trim() + '”');
    }
    box.addEventListener('input', run);
    window.acFindRun = run;   // a line added while searching shows up
  })();

  // Fill in the totals for the rows already on the page
  document.querySelectorAll('tbody tr[data-id]').forEach(recalcRow);
</script>
