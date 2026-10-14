<?php
/* ═══════════════════════════════════════════════════════════════
   The 10% provision for price changes — APP-CSE and Non-PS.

   APP-CSE, per Part (I and II), as on the official form:
     A. TOTAL            what was entered: quantity × unit price
     B. 10% of A         additional provision for price changes
     C. transport and freight cost, if applicable — typed by the
        department (0 when not applicable)
     D. GRAND TOTAL      A + B + C
     E. Approved budget by the agency head, in figures and words — D

   Non-PS list (v76 — the 10% was replaced by this):
     TOTAL               what was entered: quantity × net unit cost
     + VAT               12% of TOTAL
     + logistics         3.3% of TOTAL
     + inflation         3.5% of TOTAL
     GRAND TOTAL         TOTAL + the three  (= TOTAL × 1.188)
   The three rates and their labels are on the Document Format page.

   Every figure is worked out in centavos and rounded once, to the
   centavo, so the screen, the printout and the budget check always
   show the same amount.
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/doc-format.php';   // the Non-PS rates and labels

const PRICE_PROVISION_RATE = 0.10;

/** A peso amount in whole centavos. */
function pp_cents(float $v): int { return (int)round($v * 100); }

/** 10% of an amount already in centavos, rounded to the centavo. */
function pp_ten_cents(int $cents): int { return (int)round($cents / 10); }

/** v94: the APP-CSE's additional provision for price changes, in %, for a fiscal year (Document Format; 10 until changed). */
function appcse_rate(?int $fy = null): float {
    $r = nonps_rate_value(doc_fmt_raw('appcse', 'rate_b', $fy ?? doc_fmt_year()));
    return $r ?? 10.0;
}

/** v94: $rate % of an amount in centavos, rounded half up to the centavo (the same in JavaScript). */
function pp_rate_cents(int $cents, float $rate): int {
    return intdiv(max(0, $cents) * (int)round($rate * 10000) + 500000, 1000000);
}

/** ₱ amount, e.g. ₱9,900.00 */
function pp_peso(float $v): string { return '₱' . number_format($v, 2); }

/** Adds appcse_header.transport_1 / transport_2 (C. of Part I and II) once. */
function pp_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    // Changing a table ends an open transaction (MySQL), so never from inside one
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM appcse_header')->fetchAll(PDO::FETCH_COLUMN);
        if (!$have) return;
        foreach (['transport_1', 'transport_2'] as $c) {
            if (!in_array($c, $have, true)) db()->exec("ALTER TABLE appcse_header ADD COLUMN $c DECIMAL(14,2) NOT NULL DEFAULT 0");
        }
        // Optional manual override for the APP-CSE bottom grand total (D). NULL means use auto-compute.
        foreach (['override_d_1', 'override_d_2'] as $c) {
            if (!in_array($c, $have, true)) db()->exec("ALTER TABLE appcse_header ADD COLUMN $c DECIMAL(14,2) NULL DEFAULT NULL");
        }
    } catch (Throwable $t) {}
}

/**
 * C. for each Part. A department's own; for '__ALL__' (the consolidated
 * APP-CSE) the sum of the departments counted in it (approved ones).
 */
function appcse_transport(int $fy, string $dept): array {
    pp_ensure_columns();
    $out = ['I' => 0.0, 'II' => 0.0];
    $codes = [$dept];
    if ($dept === '__ALL__') {
        require_once __DIR__ . '/appcse-items.php';
        $codes = array_column(appcse_counted_departments(db(), $fy, true), 'code');
    }
    if (!$codes) return $out;
    try {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $s = db()->prepare("SELECT COALESCE(SUM(transport_1), 0), COALESCE(SUM(transport_2), 0)
                              FROM appcse_header WHERE fy = ? AND dept_code IN ($ph)");
        $s->execute(array_merge([$fy], $codes));
        $r = $s->fetch(PDO::FETCH_NUM);
        if ($r) { $out['I'] = round((float)$r[0], 2); $out['II'] = round((float)$r[1], 2); }
    } catch (Throwable $t) {}
    return $out;
}

/**
 * A, B, C, D (and E = D) of each Part from the Part totals and C.
 * Returns ['I' => [a, b, c, d], 'II' => [...], 'd' => D of both Parts].
 */
function appcse_summary(array $partTotals, array $transport, ?int $fy = null): array {
    $rate = appcse_rate($fy);   // v94: the year's % (Document Format)
    $out = ['d' => 0.0, 'rate' => $rate];
    $dAll = 0;
    foreach (['I', 'II'] as $pk) {
        $a = pp_cents((float)($partTotals[$pk] ?? 0));
        $b = pp_rate_cents($a, $rate);
        $c = max(0, pp_cents((float)($transport[$pk] ?? 0)));
        $d = $a + $b + $c;
        $dAll += $d;
        $out[$pk] = ['a' => $a / 100, 'b' => $b / 100, 'c' => $c / 100, 'd' => $d / 100];
    }
    $out['d'] = $dAll / 100;
    return $out;
}

/** A of each Part for one department's APP-CSE, from what was entered. */
function appcse_part_totals(int $fy, string $dept): array {
    $out = ['I' => 0.0, 'II' => 0.0];
    $months = '(`jan`+`feb`+`mar`+`apr`+`may`+`jun`+`jul`+`aug`+`sep`+`oct`+`nov`+`dec`)';
    try {
        $s = db()->prepare("SELECT `part`, COALESCE(SUM($months * unit_price), 0) FROM appcse_items
                             WHERE dept_code = ? AND fy = ? GROUP BY `part`");
        $s->execute([$dept, $fy]);
        foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[$r[0] === 'II' ? 'II' : 'I'] += (float)$r[1];
        }
    } catch (Throwable $t) {}
    return $out;
}

/* ── Non-PS: what is added to the TOTAL ─────────────────────────
   Each is a percentage of the TOTAL, worked out on its own and rounded
   to the centavo; the GRAND TOTAL is the TOTAL plus the three. */
const NONPS_CHARGES = [
    ['vat', 'lbl_vat', 'rate_vat', 12.0],
    ['log', 'lbl_log', 'rate_log', 3.3],
    ['inf', 'lbl_inf', 'rate_inf', 3.5],
];

/** A rate typed on the Document Format page: a number from 0 to 100, else null. */
function nonps_rate_value(string $v): ?float {
    $v = trim(str_replace('%', '', $v));
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f < 0 || $f > 100) ? null : round($f, 4);
}

/** 12 → "12", 3.30 → "3.3" */
function nonps_rate_text(float $r): string { return rtrim(rtrim(number_format($r, 4, '.', ''), '0'), '.'); }

/** The three, for the year being shown: [[key, label, rate], …] */
function nonps_charges(): array {
    $out = [];
    foreach (NONPS_CHARGES as [$k, $lblKey, $rateKey, $def]) {
        $r = nonps_rate_value(doc_fmt('nonps', $rateKey));
        $out[] = [$k, doc_fmt('nonps', $lblKey), $r ?? $def];
    }
    return $out;
}

/**
 * v80: the GRAND TOTAL of a Non-PS list of fiscal year $fy (with that year's
 * rates), from its TOTAL — what every page shows as a list's amount, so the
 * amount is the same wherever the list appears.
 */
function nonps_grand_for(float $total, int $fy): float {
    $was = $GLOBALS['__doc_fmt_year'] ?? null;
    doc_fmt_use_year($fy);
    $g = (float)nonps_provision($total)['grand'];
    doc_fmt_use_year($was !== null ? (int)$was : null);
    return $g;
}

/** Non-PS: the TOTAL, each charge, and the GRAND TOTAL. */
function nonps_provision(float $total): array {
    $t = max(0, pp_cents($total));
    $lines = []; $add = 0;
    foreach (nonps_charges() as [$k, $label, $rate]) {
        // in whole numbers: centavos × (rate × 10,000), rounded half up — the same on screen
        $c = intdiv($t * (int)round($rate * 10000) + 500000, 1000000);
        $add += $c;
        $lines[] = ['key' => $k, 'label' => $label, 'rate' => $rate, 'amount' => $c / 100];
    }
    return ['total' => $t / 100, 'lines' => $lines, 'grand' => ($t + $add) / 100];
}

/**
 * The charge rows under TOTAL, in the page's own cell style.
 *   $lblAttrs / $valAttrs  attributes of the label cell and the amount cell
 *   $peso                  amounts with ₱ (printouts) or without (editors)
 *   $after                 anything the page adds at the end of each row
 */
function nonps_charge_rows(array $np, string $lblAttrs, string $valAttrs, bool $peso, string $after = ''): string {
    $h = '';
    foreach ($np['lines'] as $l) {
        $h .= '<tr class="np-charge"><td ' . $lblAttrs . '>' . htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8')
            . ' (' . nonps_rate_text((float)$l['rate']) . '%)</td>'
            . '<td ' . $valAttrs . ' data-np-rate="' . nonps_rate_text((float)$l['rate']) . '" data-np-peso="' . ($peso ? 1 : 0) . '">'
            . ($peso ? '₱' : '') . number_format($l['amount'], 2) . '</td>' . $after . '</tr>';
    }
    return $h;
}

/** Whole number in words (uppercase), 0 … 999,999,999,999,999. */
function pp_words_int(int $n): string {
    static $ones = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN',
                    'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
    static $tens = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
    static $scales = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];
    if ($n === 0) return 'ZERO';
    $three = function (int $x) use ($ones, $tens): string {
        $w = [];
        if ($x >= 100) { $w[] = $ones[intdiv($x, 100)] . ' HUNDRED'; $x %= 100; }
        if ($x >= 20) { $w[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : ''); }
        elseif ($x > 0) { $w[] = $ones[$x]; }
        return implode(' ', $w);
    };
    $parts = [];
    for ($i = 0; $n > 0 && $i < count($scales); $i++) {
        $chunk = $n % 1000;
        if ($chunk) array_unshift($parts, $three($chunk) . ($scales[$i] !== '' ? ' ' . $scales[$i] : ''));
        $n = intdiv($n, 1000);
    }
    return implode(' ', $parts);
}

/** e.g. NINE THOUSAND NINE HUNDRED PESOS AND 50/100 ONLY */
function pp_words(float $amount): string {
    $c = max(0, pp_cents($amount));
    $p = intdiv($c, 100); $cv = $c % 100;
    return pp_words_int($p) . ($p === 1 ? ' PESO' : ' PESOS')
         . ($cv ? ' AND ' . str_pad((string)$cv, 2, '0', STR_PAD_LEFT) . '/100' : '') . ' ONLY';
}

/** E. — the approved budget in figures and words: ₱9,900.00 (NINE THOUSAND NINE HUNDRED PESOS ONLY) */
function pp_figures_words(float $amount): string {
    return pp_peso($amount) . ' (' . pp_words($amount) . ')';
}

/** The same rounding and words in JavaScript, for the live totals on screen. */
function pp_js(): string {
    static $sent = false;
    if ($sent) return '';
    $sent = true;
    return <<<'JS'
<script>
window.PP = (function(){
  function cents(v){ v = parseFloat(v); if (!isFinite(v)) return 0; return Math.round(+(v * 100).toFixed(6)); }
  function ten(c){ return Math.round(c / 10); }
  /* v94: r % of c centavos, half up — the same as pp_rate_cents() */
  function pct(c, r){ r = Math.round(parseFloat(r) * 10000); if (!isFinite(r)) r = 100000;
    return Number((BigInt(Math.max(0, c)) * BigInt(r) + 500000n) / 1000000n); }
  function peso(c){ return '₱' + (c / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  var ONES = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN',
              'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
  var TENS = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
  var SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];
  function three(x){
    var w = [];
    if (x >= 100) { w.push(ONES[Math.floor(x / 100)] + ' HUNDRED'); x %= 100; }
    if (x >= 20) w.push(TENS[Math.floor(x / 10)] + (x % 10 ? '-' + ONES[x % 10] : ''));
    else if (x > 0) w.push(ONES[x]);
    return w.join(' ');
  }
  function wordsInt(n){
    if (n === 0) return 'ZERO';
    var parts = [];
    for (var i = 0; n > 0 && i < SCALES.length; i++) {
      var ch = n % 1000;
      if (ch) parts.unshift(three(ch) + (SCALES[i] ? ' ' + SCALES[i] : ''));
      n = Math.floor(n / 1000);
    }
    return parts.join(' ');
  }
  function words(c){
    c = Math.max(0, c); var p = Math.floor(c / 100), cv = c % 100;
    return wordsInt(p) + (p === 1 ? ' PESO' : ' PESOS') + (cv ? ' AND ' + (cv < 10 ? '0' : '') + cv + '/100' : '') + ' ONLY';
  }
  function figuresWords(c){ return peso(c) + ' (' + words(c) + ')'; }
  /* Non-PS: the charge rows under TOTAL and the GRAND TOTAL, from the TOTAL in pesos */
  function nonps(total){
    var t = cents(total), add = 0;
    document.querySelectorAll('[data-np-rate]').forEach(function(td){
      var r = Math.round(parseFloat(td.dataset.npRate) * 10000);
      var c = Number((BigInt(Math.max(0, t)) * BigInt(r) + 500000n) / 1000000n); add += c;
      td.textContent = td.dataset.npPeso === '1' ? peso(c) : peso(c).slice(1);
    });
    var g = document.getElementById('npGrand');
    if (g) g.textContent = g.dataset.npPeso === '1' ? peso(t + add) : peso(t + add).slice(1);
  }
  return { cents: cents, ten: ten, pct: pct, peso: peso, words: words, figuresWords: figuresWords, nonps: nonps };
})();
</script>
JS;
}
