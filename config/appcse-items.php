<?php
// Backs the fully-editable APP-CSE table (add/edit/delete rows).
// First time a FY is opened, appcse_items is seeded from the PS-DBM
// catalogue (config/appcse-catalogue.php), pulling in any quantities /
// unit prices already saved under the old fixed-catalogue system
// (appcse_qty) so nothing entered previously is lost. After the seed,
// appcse_items is the sole source of truth for that FY.

const APPCSE_MONTHS = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];

/* A department's APP-CSE travels with its PPMP; once the PPMP is in one of
   these states the APP-CSE is under review and its figures are frozen. */
const APPCSE_LOCKED_STATUSES = ['submitted', 'budget_approved', 'approved'];

/* ═══════════════════════════════════════════════════════════════
   The catalogue — the categories and items every department's APP-CSE
   starts from.

   It used to be the fixed array in config/appcse-catalogue.php. It is now
   kept per fiscal year in appcse_catalogue_items, and the Procurement Unit
   edits it on the Document Format page. A year's list is started the first
   time that year is opened:
     · from the latest earlier year's list, so the Procurement Unit's edits
       carry forward the same way the rest of the Document Format does;
     · from the built-in array when there is no earlier list, or when
       departments already have forms for that year — those forms were made
       from the built-in array, so the list has to match them.
   =============================================================== */
function appcse_catalogue_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS appcse_catalogue_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fy INT NOT NULL,
            `part` VARCHAR(5) NOT NULL DEFAULT 'I',
            `category` VARCHAR(150) NOT NULL DEFAULT '',
            code VARCHAR(60) DEFAULT '',
            description VARCHAR(255) DEFAULT '',
            unit VARCHAR(40) DEFAULT '',
            unit_price DECIMAL(14,2) DEFAULT 0,
            sort_order INT DEFAULT 0,
            updated_by VARCHAR(150) DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appcse_cat_fy (fy)
        ) ENGINE=InnoDB");
    } catch (Throwable $t) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS appcse_consol_edits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fy INT NOT NULL,
            line_key VARCHAR(255) NOT NULL DEFAULT '',
            is_added TINYINT(1) NOT NULL DEFAULT 0,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            `part` VARCHAR(5) NOT NULL DEFAULT 'I',
            `category` VARCHAR(150) NOT NULL DEFAULT '',
            item_key VARCHAR(255) NOT NULL DEFAULT '',
            code VARCHAR(60) NULL,
            description VARCHAR(255) NULL,
            unit VARCHAR(40) NULL,
            unit_price DECIMAL(14,2) NULL,
            `jan` DECIMAL(12,2) NULL, `feb` DECIMAL(12,2) NULL, `mar` DECIMAL(12,2) NULL,
            `apr` DECIMAL(12,2) NULL, `may` DECIMAL(12,2) NULL, `jun` DECIMAL(12,2) NULL,
            `jul` DECIMAL(12,2) NULL, `aug` DECIMAL(12,2) NULL, `sep` DECIMAL(12,2) NULL,
            `oct` DECIMAL(12,2) NULL, `nov` DECIMAL(12,2) NULL, `dec` DECIMAL(12,2) NULL,
            sort_order INT DEFAULT 0,
            updated_by VARCHAR(150) DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appcse_consol_fy (fy)
        ) ENGINE=InnoDB");
    } catch (Throwable $t) {}
}

/** Make sure this fiscal year has its own list (see above). */
function appcse_catalogue_ensure(PDO $pdo, int $fy): void {
    appcse_catalogue_tables($pdo);
    try {
        $c = $pdo->prepare('SELECT COUNT(*) FROM appcse_catalogue_items WHERE fy = ?');
        $c->execute([$fy]);
        if ((int)$c->fetchColumn() > 0) return;
    } catch (Throwable $t) {
        return;
    }

    $rows = null;
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM appcse_items WHERE fy = ?');
        $s->execute([$fy]);
        $formsExist = (int)$s->fetchColumn() > 0;
        if (!$formsExist) {
            $p = $pdo->prepare('SELECT MAX(fy) FROM appcse_catalogue_items WHERE fy < ?');
            $p->execute([$fy]);
            $prev = (int)($p->fetchColumn() ?: 0);
            if ($prev > 0) {
                $r = $pdo->prepare("SELECT `part`, `category`, code, description, unit, unit_price, sort_order
                                      FROM appcse_catalogue_items WHERE fy = ?
                                  ORDER BY FIELD(`part`,'I','II'), sort_order, id");
                $r->execute([$prev]);
                $rows = $r->fetchAll();
            }
        }
    } catch (Throwable $t) {}

    if (!$rows) {
        require_once __DIR__ . '/appcse-catalogue.php';
        $rows = [];
        $order = 0;
        foreach (appcse_catalogue() as [$code, $desc, $unit, $price, $part, $cat]) {
            $rows[] = ['part' => $part, 'category' => $cat, 'code' => $code,
                       'description' => $desc, 'unit' => $unit,
                       'unit_price' => $price, 'sort_order' => $order++];
        }
    }

    $ins = $pdo->prepare("INSERT INTO appcse_catalogue_items
                            (fy, `part`, `category`, code, description, unit, unit_price, sort_order, updated_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'System')");
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $ins->execute([$fy, $r['part'], $r['category'], (string)$r['code'],
                           (string)$r['description'], (string)$r['unit'],
                           (float)$r['unit_price'], (int)$r['sort_order']]);
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

/** This year's list, in order. */
function appcse_catalogue_rows(PDO $pdo, int $fy): array {
    appcse_catalogue_ensure($pdo, $fy);
    $rows = [];
    try {
        $s = $pdo->prepare("SELECT * FROM appcse_catalogue_items WHERE fy = ?
                          ORDER BY FIELD(`part`,'I','II'), sort_order, id");
        $s->execute([$fy]);
        $rows = $s->fetchAll();
    } catch (Throwable $t) {}
    if ($rows) return $rows;

    // The table could not be read (or written): a department still gets
    // the built-in list, exactly as it did before the list was editable.
    require_once __DIR__ . '/appcse-catalogue.php';
    $order = 0;
    foreach (appcse_catalogue() as [$code, $desc, $unit, $price, $part, $cat]) {
        $rows[] = ['id' => 0, 'fy' => $fy, 'part' => $part, 'category' => $cat, 'code' => $code,
                   'description' => $desc, 'unit' => $unit, 'unit_price' => $price,
                   'sort_order' => $order++];
    }
    return $rows;
}

/** The same list as [part][category] => rows. */
function appcse_catalogue_grouped(PDO $pdo, int $fy): array {
    $g = ['I' => [], 'II' => []];
    foreach (appcse_catalogue_rows($pdo, $fy) as $r) {
        $part = ($r['part'] === 'II') ? 'II' : 'I';
        $g[$part][$r['category']][] = $r;
    }
    return $g;
}

function appcse_ensure_seeded(PDO $pdo, int $fy, string $dept): void {
    if ($dept === '') return;
    try {
        $c = $pdo->prepare('SELECT COUNT(*) FROM appcse_items WHERE fy = ? AND dept_code = ?');
        $c->execute([$fy, $dept]);
        if ((int)$c->fetchColumn() > 0) return; // this department already has its form
    } catch (Throwable $t) {
        return;
    }

    // The Procurement Unit's list for this year (Document Format › APP-CSE)
    $catalogue = appcse_catalogue_rows($pdo, $fy);
    if (!$catalogue) return;

    // Legacy quantities saved before rows became editable. They belong to the
    // Procurement Unit's own form, so only its seeding picks them up — a
    // department starts from the catalogue with empty quantities.
    $legacy = [];
    try {
        $pu = (string)($pdo->query("SELECT dept_code FROM users WHERE role = 'admin' AND dept_code IS NOT NULL LIMIT 1")->fetchColumn() ?: '');
        if ($pu !== '' && $dept === $pu) {
            $s = $pdo->prepare('SELECT * FROM appcse_qty WHERE fy = ?');
            $s->execute([$fy]);
            foreach ($s->fetchAll() as $r) $legacy[$r['item_code']] = $r;
        }
    } catch (Throwable $t) {}

    $months = APPCSE_MONTHS;
    $cols = 'fy, dept_code, `part`, `category`, code, description, unit, unit_price, ' . implode(', ', array_map(fn($m) => "`$m`", $months)) . ', sort_order';
    $ph   = implode(', ', array_fill(0, 8 + count($months) + 1, '?'));
    $ins  = $pdo->prepare("INSERT INTO appcse_items ($cols) VALUES ($ph)");

    $pdo->beginTransaction();
    try {
        foreach ($catalogue as $it) {
            $code = (string)$it['code'];
            $lg = ($code !== '') ? ($legacy[$code] ?? null) : null;
            $up = $lg ? (float)$lg['unit_price'] : (float)$it['unit_price'];
            $vals = [$fy, $dept, $it['part'], $it['category'], $code,
                     (string)$it['description'], (string)$it['unit'], $up];
            foreach ($months as $m) $vals[] = $lg ? (float)$lg[$m] : 0;
            $vals[] = (int)$it['sort_order'];
            $ins->execute($vals);
        }
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
    }
}

// Returns rows grouped as [part][category] => list of item rows, in stored order.
function appcse_get_grouped(PDO $pdo, int $fy, string $dept = ''): array {
    $s = $pdo->prepare("SELECT * FROM appcse_items WHERE fy = ? AND dept_code = ? ORDER BY FIELD(`part`,'I','II'), sort_order, id");
    $s->execute([$fy, $dept]);
    $grouped = ['I' => [], 'II' => []];
    foreach ($s->fetchAll() as $r) {
        $part = ($r['part'] === 'II') ? 'II' : 'I';
        $grouped[$part][$r['category']][] = $r;
    }
    return $grouped;
}

/**
 * Which departments are counted in the consolidated APP-CSE for a fiscal year.
 *
 * v77: a department's APP-CSE goes straight to the Procurement Unit. It is
 * counted here the moment the Procurement Unit approves the APP-CSE (APP-CSE
 * Folders), and not before — one still with the PU, or sent back, is not in yet.
 *
 * Returns [['code' => .., 'name' => .., 'status' => the APP-CSE's state], ...]
 * for every department with a PPMP or an APP-CSE that year, so a page can show who is in and who
 * is still on the way. Use $approvedOnly to get just the counted ones.
 */
function appcse_counted_departments(PDO $pdo, int $fy, bool $approvedOnly = false): array {
    // v77: the APP-CSE's own state (config/appcse-review.php) — counted once
    // the Procurement Unit approves the APP-CSE, whatever its PPMP is doing
    require_once __DIR__ . '/appcse-review.php';
    require_once __DIR__ . '/archive.php';
    $out = [];
    try {
        $codes = [];
        $q = $pdo->prepare('SELECT dept_code FROM ppmps WHERE fy = ?');
        $q->execute([$fy]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) $codes[(string)$c] = true;
        appcse_flow_columns();
        $q = $pdo->prepare("SELECT dept_code FROM appcse_header WHERE fy = ? AND status IS NOT NULL AND status <> '' AND dept_code <> '__ALL__'");
        $q->execute([$fy]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) $codes[(string)$c] = true;
        if (!$codes) return [];
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $q = $pdo->prepare("SELECT code, name, display_order FROM departments WHERE code IN ($ph) ORDER BY display_order, code");
        $q->execute(array_keys($codes));
        $named = [];
        foreach ($q->fetchAll() as $r) $named[(string)$r['code']] = (string)$r['name'];
        foreach (array_keys($codes) as $c) if (!isset($named[$c])) $named[$c] = $c;
        foreach ($named as $code => $name) {
            if (arc_appcse_is_archived($fy, $code)) continue;
            $st = appcse_status($fy, $code);
            if ($approvedOnly && $st !== 'approved') continue;
            $out[] = ['code' => $code, 'name' => $name ?: $code, 'status' => $st];
        }
    } catch (Throwable $t) {}
    return $out;
}

/**
 * The consolidated APP-CSE, in the same shape appcse_get_grouped() returns,
 * so the one form template can render either.
 *
 * Every approved department's rows are added together line by line. Lines are
 * matched on their item code — the code is what PS-DBM buys against — and on
 * the description when a line has no code.
 *
 * The unit price is the weighted average of what the departments carry, which
 * is simply their shared catalogue price in the ordinary case where they all
 * carry the same one. It is a weighted average rather than "the first seen"
 * so that quantity x price still equals the true sum of the departments'
 * amounts even if two of them priced an item differently.
 */
function appcse_consolidated_grouped(PDO $pdo, int $fy, bool $withEdits = true): array {
    $months = APPCSE_MONTHS;
    $codes  = array_column(appcse_counted_departments($pdo, $fy, true), 'code');
    $grouped = ['I' => [], 'II' => []];
    if (!$codes) return $grouped;

    $ph = implode(',', array_fill(0, count($codes), '?'));
    try {
        $s = $pdo->prepare("SELECT * FROM appcse_items
                             WHERE fy = ? AND dept_code IN ($ph)
                          ORDER BY FIELD(`part`,'I','II'), sort_order, id");
        $s->execute(array_merge([$fy], $codes));
        $rows = $s->fetchAll();
    } catch (Throwable $t) {
        return $grouped;
    }

    $lines = [];          // "part|category|key" => accumulated row
    foreach ($rows as $r) {
        $part = ($r['part'] === 'II') ? 'II' : 'I';
        $cat  = (string)$r['category'];
        $key  = trim((string)$r['code']) !== '' ? trim((string)$r['code']) : trim((string)$r['description']);
        $k    = $part . '|' . $cat . '|' . $key;

        if (!isset($lines[$k])) {
            $lines[$k] = [
                'part' => $part, 'category' => $cat,
                'code' => $r['code'], 'description' => $r['description'], 'unit' => $r['unit'],
                'unit_price' => 0.0, 'sort_order' => (int)$r['sort_order'],
                '__qty' => 0.0, '__amount' => 0.0, '__lastPrice' => (float)$r['unit_price'],
            ];
            foreach ($months as $m) $lines[$k][$m] = 0.0;
        }
        $line  = &$lines[$k];
        $price = (float)$r['unit_price'];
        $qty   = 0.0;
        foreach ($months as $m) {
            $v = (float)($r[$m] ?? 0);
            $line[$m] += $v;
            $qty += $v;
        }
        $line['__qty']       += $qty;
        $line['__amount']    += $qty * $price;
        $line['__lastPrice']  = $price;
        $line['sort_order']   = min($line['sort_order'], (int)$r['sort_order']);
        unset($line);
    }

    foreach ($lines as $k => &$line) {
        $line['unit_price'] = $line['__qty'] > 0
            ? $line['__amount'] / $line['__qty']       // weighted average
            : $line['__lastPrice'];                    // nothing ordered — show the catalogue price
        unset($line['__qty'], $line['__amount'], $line['__lastPrice']);
        $line['__key']    = $k;      // which line this is, for the PU's edits
        $line['__edited'] = [];      // the fields the PU has changed on it
        $line['__added']  = false;
    }
    unset($line);

    if ($withEdits) $lines = appcse_consol_apply($pdo, $fy, $lines);

    uasort($lines, fn($a, $b) => [$a['part'], $a['sort_order']] <=> [$b['part'], $b['sort_order']]);
    foreach ($lines as $line) $grouped[$line['part']][$line['category']][] = $line;
    return $grouped;
}

/* ═══════════════════════════════════════════════════════════════
   The Procurement Unit's edits to the consolidated APP-CSE.

   The consolidated form is still worked out from the approved departments
   every time it is opened. What the Procurement Unit changes on it is kept
   apart, in appcse_consol_edits, one row per line, and laid over that sum:

     · a field left NULL follows the departments' sum
     · a field with a value is the Procurement Unit's, and stays so when more
       departments are counted later
     · hidden = 1 takes the line off the consolidated form only
     · is_added = 1 is a line the Procurement Unit added itself

   Nothing here writes to any department's own APP-CSE.
   =============================================================== */
const APPCSE_CONSOL_FIELDS = ['code', 'description', 'unit', 'unit_price',
                              'jan','feb','mar','apr','may','jun',
                              'jul','aug','sep','oct','nov','dec'];

/** Every edit for a year, keyed by line. */
function appcse_consol_edits(PDO $pdo, int $fy): array {
    appcse_catalogue_tables($pdo);
    $out = [];
    try {
        $s = $pdo->prepare('SELECT * FROM appcse_consol_edits WHERE fy = ? ORDER BY id');
        $s->execute([$fy]);
        foreach ($s->fetchAll() as $r) $out[(string)$r['line_key']] = $r;
    } catch (Throwable $t) {}
    return $out;
}

/** Lay the edits over the summed lines (see above). */
function appcse_consol_apply(PDO $pdo, int $fy, array $lines): array {
    $num = ['unit_price', 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    foreach (appcse_consol_edits($pdo, $fy) as $key => $ed) {
        if (!empty($ed['is_added'])) {
            if (!empty($ed['hidden'])) continue;
            $line = ['part' => ($ed['part'] === 'II') ? 'II' : 'I',
                     'category' => (string)$ed['category'],
                     // after everything the departments carry in its category
                     'sort_order' => 1000000000 + (int)$ed['id'],
                     '__key' => $key, '__added' => true, '__edited' => APPCSE_CONSOL_FIELDS];
            foreach (APPCSE_CONSOL_FIELDS as $f) {
                $line[$f] = in_array($f, $num, true) ? (float)($ed[$f] ?? 0) : (string)($ed[$f] ?? '');
            }
            $lines[$key] = $line;
            continue;
        }
        if (!isset($lines[$key])) continue;          // the line is no longer in the sum
        if (!empty($ed['hidden'])) { unset($lines[$key]); continue; }
        foreach (APPCSE_CONSOL_FIELDS as $f) {
            if ($ed[$f] === null) continue;
            $lines[$key][$f] = in_array($f, $num, true) ? (float)$ed[$f] : (string)$ed[$f];
            $lines[$key]['__edited'][] = $f;
        }
    }
    return $lines;
}

/** Lines the Procurement Unit took off the consolidated form, to put back. */
function appcse_consolidated_removed(PDO $pdo, int $fy): array {
    $edits = appcse_consol_edits($pdo, $fy);
    $hidden = array_filter($edits, fn($e) => !empty($e['hidden']) && empty($e['is_added']));
    if (!$hidden) return [];
    $out = [];
    foreach (appcse_consolidated_grouped($pdo, $fy, false) as $byCat) {
        foreach ($byCat as $rows) {
            foreach ($rows as $r) {
                if (isset($hidden[$r['__key']])) $out[] = $r;
            }
        }
    }
    return $out;
}

/** The institution's own name, as set on the Settings page. */
function appcse_institution_name(PDO $pdo): string {
    try {
        $v = (string)$pdo->query("SELECT value FROM settings WHERE key_name = 'institution_name'")->fetchColumn();
        if (trim($v) !== '') return $v;
    } catch (Throwable $t) {}
    return 'Aurora State College of Technology';
}

/**
 * The agency block on the consolidated APP-CSE.
 *
 * It is the institution's document, not any one department's, so the office
 * is the institution. The region, address and contact details come from the
 * Procurement Unit's own APP-CSE header, which is where they are maintained.
 */
function appcse_consolidated_header(PDO $pdo, int $fy, bool $withEdits = true): array {
    $hdr = ['department' => appcse_institution_name($pdo), 'region' => 'III',
            'address' => '', 'agency_code' => '', 'contact_person' => '',
            'position' => '', 'org_type' => '', 'email' => '', 'telephone' => ''];
    try {
        $v = (string)$pdo->query("SELECT value FROM settings WHERE key_name = 'institution_address'")->fetchColumn();
        if (trim($v) !== '') $hdr['address'] = $v;
    } catch (Throwable $t) {}
    if ($hdr['address'] === '') $hdr['address'] = 'Barangay Zabali, Baler, Aurora';

    try {
        $pu = (string)$pdo->query("SELECT dept_code FROM users WHERE role = 'admin' AND dept_code IS NOT NULL LIMIT 1")->fetchColumn();
        if ($pu !== '') {
            $h = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
            $h->execute([$fy, $pu]);
            if ($row = $h->fetch()) {
                // everything but the office name, which stays the institution
                foreach (['region', 'address', 'agency_code', 'contact_person',
                          'position', 'org_type', 'email', 'telephone'] as $k) {
                    if (trim((string)($row[$k] ?? '')) !== '') $hdr[$k] = $row[$k];
                }
            }
        }
    } catch (Throwable $t) {}

    // What the Procurement Unit typed on the consolidated form itself wins.
    // It is kept under the department code __ALL__; a blank field follows
    // the values above.
    if ($withEdits) {
        try {
            $h = $pdo->prepare("SELECT * FROM appcse_header WHERE fy = ? AND dept_code = '__ALL__'");
            $h->execute([$fy]);
            if ($row = $h->fetch()) {
                foreach (array_keys($hdr) as $k) {
                    if (trim((string)($row[$k] ?? '')) !== '') $hdr[$k] = $row[$k];
                }
            }
        } catch (Throwable $t) {}
    }
    return $hdr;
}
