<?php
/* ═══════════════════════════════════════════════════════════════
   Review by the Department Admin, then one Submit for the whole school (v81).

   In a school with programs and majors (config/academic-units.php):
     1. The accounts of a program prepare each unit's set (PPMP, APP-CSE,
        Non-PS list, Annex, Market Scoping) and click "Send for review".
        The school's Department Admin is notified.
     2. The Department Admin opens it, and Approves it or Returns it with a
        note (the unit's accounts are notified).
     3. When every unit of the school is approved and complete, the
        Department Admin submits the WHOLE SCHOOL at once, from My Department
        (api/submit-school.php).
   A unit sent back afterwards (Budget Unit / Procurement Unit) is
   resubmitted on its own, as before.

   The approval is for the documents as they were then: if anything in
   them changes afterwards (a fingerprint of their contents is kept with the
   approval), the unit is back to "Draft" and has to be sent for review
   again. Saving without changing anything (Print saves first) does not.

   Kept in unit_reviews: one row per unit and fiscal year.
     status  for_review | approved | returned   (no row: draft)
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/academic-units.php';

const UR_LABELS = ['draft' => 'Draft', 'for_review' => 'For review', 'approved' => 'Approved', 'returned' => 'Returned',
                   'submitted' => 'Submitted'];

/** The table (made once; never inside a transaction). */
function ur_table(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS unit_reviews (
            id INT PRIMARY KEY AUTO_INCREMENT,
            dept_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'for_review',
            note TEXT NULL,
            requested_by VARCHAR(150) NULL,
            requested_at DATETIME NULL,
            reviewed_by VARCHAR(150) NULL,
            reviewed_at DATETIME NULL,
            fingerprint VARCHAR(64) NULL,
            UNIQUE KEY uq_unit_fy (dept_code, fy)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $t) {}
}

/** The review row of a unit for the year, as stored (status 'draft' when none). */
function ur_get_raw(string $unit, int $fy): array {
    ur_table();
    try {
        $s = db()->prepare('SELECT * FROM unit_reviews WHERE dept_code = ? AND fy = ?');
        $s->execute([$unit, $fy]);
        if ($r = $s->fetch()) return $r;
    } catch (Throwable $t) {}
    return ['dept_code' => $unit, 'fy' => $fy, 'status' => 'draft', 'note' => null, 'requested_by' => null, 'requested_at' => null,
            'reviewed_by' => null, 'reviewed_at' => null, 'fingerprint' => null];
}

/** The review of a unit for the year — an approval no longer holds once the documents changed. */
function ur_get(string $unit, int $fy): array {
    $r = ur_get_raw($unit, $fy);
    if ((string)$r['status'] === 'approved' && (string)($r['fingerprint'] ?? '') !== '' && $r['fingerprint'] !== ur_fingerprint($unit, $fy)) {
        $r['status'] = 'draft';
        $r['note'] = 'Changed after it was approved by ' . ($r['reviewed_by'] ?: 'the Department Admin') . ' — send it for review again.';
        $r['changed'] = true;
    }
    return $r;
}

/**
 * A fingerprint of what the unit's FY documents say (PPMP, APP-CSE, Non-PS
 * list, Annexes, Market Scoping) — not when they were saved or by whom.
 */
function ur_fingerprint(string $unit, int $fy): string {
    $pdo = db();
    $skip = fn(string $k) => $k === 'id' || $k === 'status' || $k === 'archived' || $k === 'review_note'
                          || preg_match('/(_at|_by|_id)$/', $k);
    $rows = function (string $sql, array $a) use ($pdo, $skip): array {
        $out = [];
        try {
            $s = $pdo->prepare($sql); $s->execute($a);
            foreach ($s->fetchAll() as $r) {
                $keep = [];
                foreach ($r as $k => $v) if (!$skip((string)$k)) $keep[$k] = $v === null ? null : (string)$v;
                ksort($keep);
                $out[] = json_encode($keep, JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $t) {}
        sort($out);
        return $out;
    };
    $parts = [];
    $p = ur_ppmp($unit, $fy);
    if ($p) {
        $parts['ppmp'] = $rows('SELECT * FROM ppmps WHERE id = ?', [(int)$p['id']]);
        $parts['ppmp_entries'] = $rows('SELECT * FROM ppmp_entries WHERE ppmp_id = ?', [(int)$p['id']]);
    }
    $parts['appcse_items']  = $rows('SELECT * FROM appcse_items WHERE dept_code = ? AND fy = ?', [$unit, $fy]);
    $parts['appcse_header'] = $rows('SELECT * FROM appcse_header WHERE dept_code = ? AND fy = ?', [$unit, $fy]);
    try {
        $s = $pdo->prepare('SELECT id FROM nonps_submissions WHERE dept_code = ? AND fy = ? AND COALESCE(archived, 0) = 0 ORDER BY id');
        $s->execute([$unit, $fy]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $i) {
            $parts['nonps'][] = $rows('SELECT * FROM nonps_submissions WHERE id = ?', [(int)$i]);
            $parts['nonps'][] = $rows('SELECT * FROM nonps_items WHERE submission_id = ?', [(int)$i]);
        }
        $s = $pdo->prepare('SELECT id FROM annex_forms WHERE dept_code = ? AND fy = ? AND COALESCE(archived, 0) = 0 ORDER BY id');
        $s->execute([$unit, $fy]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $i) {
            $parts['annex'][] = $rows('SELECT * FROM annex_forms WHERE id = ?', [(int)$i]);
            $parts['annex'][] = $rows('SELECT * FROM annex_items WHERE form_id = ?', [(int)$i]);
        }
    } catch (Throwable $t) {}
    $parts['scoping'] = $rows('SELECT * FROM market_scoping WHERE dept_code = ? AND fy = ? AND COALESCE(archived, 0) = 0', [$unit, $fy]);
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
}

function ur_put(string $unit, int $fy, array $f): void {
    ur_table();
    $cur = ur_get_raw($unit, $fy);
    $row = array_merge($cur, $f);
    try {
        db()->prepare('DELETE FROM unit_reviews WHERE dept_code = ? AND fy = ?')->execute([$unit, $fy]);
        if (($row['status'] ?? 'draft') === 'draft' && empty($row['note'])) return;
        db()->prepare('INSERT INTO unit_reviews (dept_code, fy, status, note, requested_by, requested_at, reviewed_by, reviewed_at, fingerprint)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$unit, $fy, $row['status'], $row['note'], $row['requested_by'], $row['requested_at'], $row['reviewed_by'], $row['reviewed_at'],
                       $row['fingerprint'] ?? null]);
    } catch (Throwable $t) { error_log('unit review: ' . $t->getMessage()); }
}

/** The unit's PPMP of the year (not in the Archive): id, status — or null. */
function ur_ppmp(string $unit, int $fy): ?array {
    try {
        $s = db()->prepare('SELECT p.id, p.status, p.submitted_at, p.submitted_by FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                               AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)
                          ORDER BY p.id DESC');
        $s->execute([$unit, $fy]);
        return $s->fetch() ?: null;
    } catch (Throwable $t) { return null; }
}

/** Has the unit's set already been submitted (its PPMP is past draft, or sent back after a submission)? */
function ur_submitted(string $unit, int $fy): bool {
    $p = ur_ppmp($unit, $fy);
    if ($p && (string)$p['status'] !== 'draft') return true;
    return ur_has_revision($unit, $fy);
}

function ur_has_revision(string $unit, int $fy): bool {
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
        $s->execute([$unit, $fy]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $t) { return false; }
}

/** Where the unit stands: draft | for_review | approved | returned | submitted. */
function ur_status(string $unit, int $fy): string {
    if (ur_submitted($unit, $fy)) return 'submitted';
    return (string)ur_get($unit, $fy)['status'];
}

/** Is this account working on a unit whose first submission goes with the whole school? */
function ur_whole_school(array $u, int $fy): bool {
    $unit = (string)($u['dept_code'] ?? '');
    return au_is_unit($unit) && !ur_submitted($unit, $fy);
}

/** Is this account the Department Admin of the unit's school? */
function ur_is_dean(array $u, string $unit): bool {
    if (($u['role'] ?? '') !== 'dept_admin') return false;
    return strcasecmp((string)($u['school_code'] ?? ''), au_school_of($unit)) === 0;
}

/** A unit's accounts send it for review: the Department Admin is told. */
function ur_request(string $unit, int $fy, array $u): array {
    if (!au_is_unit($unit)) return [false, 'That is not a program or major of a school.'];
    if (ur_submitted($unit, $fy)) return [false, 'This has already been submitted.'];
    $who = (string)($u['full_name'] ?? '');
    ur_put($unit, $fy, ['status' => 'for_review', 'note' => null, 'requested_by' => $who, 'requested_at' => date('Y-m-d H:i:s'),
                        'reviewed_by' => null, 'reviewed_at' => null, 'fingerprint' => null]);
    $school = au_school_of($unit);
    $label  = au_label($unit);
    try {
        require_once __DIR__ . '/notify.php';
        $s = db()->prepare("SELECT id FROM users WHERE dept_code = ? AND role = 'dept_admin' AND COALESCE(status, 'active') = 'active'");
        $s->execute([$school]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            notify_user((int)$id, 'review', "For review: $label · FY $fy",
                "$who sent the FY $fy documents of $label for your review and approval.", 'dept-tracking.php?fy=' . $fy . '#unit-' . rawurlencode($unit));
        }
    } catch (Throwable $t) {}
    log_activity('Sent for review', 'unit', "$unit · FY $fy", "$who sent the FY $fy documents of $label to the Department Admin for review.");
    return [true, "Sent for review. The Department Admin of $school was notified."];
}

/** The Department Admin approves or returns a unit. */
function ur_decide(string $unit, int $fy, bool $approve, string $note, array $u): array {
    if (!au_is_unit($unit)) return [false, 'That is not a program or major of a school.'];
    if (!ur_is_dean($u, $unit)) return [false, 'Only the Department Admin of the school can do that.'];
    if (ur_submitted($unit, $fy)) return [false, 'This has already been submitted.'];
    $note = trim($note);
    if (!$approve && $note === '') return [false, 'Write what has to be changed, so they know what to fix.'];
    $who = (string)($u['full_name'] ?? '');
    ur_put($unit, $fy, ['status' => $approve ? 'approved' : 'returned', 'note' => $note !== '' ? $note : null,
                        'reviewed_by' => $who, 'reviewed_at' => date('Y-m-d H:i:s'),
                        'fingerprint' => $approve ? ur_fingerprint($unit, $fy) : null]);
    $label = au_label($unit);
    try {
        require_once __DIR__ . '/notify.php';
        notify_by_dept($unit, 'review', ($approve ? 'Approved: ' : 'Returned: ') . "$label · FY $fy",
            $approve ? "$who approved the FY $fy documents of $label. They go to the Budget Unit and the Procurement Unit when the whole school is submitted."
                     : "$who returned the FY $fy documents of $label: $note",
            'ppmp-edit.php?fy=' . $fy . '&unit=' . rawurlencode($unit));
    } catch (Throwable $t) {}
    log_activity($approve ? 'Approved for submission' : 'Returned for changes', 'unit', "$unit · FY $fy",
                 $approve ? "Approved the FY $fy documents of $label." : "Returned the FY $fy documents of $label: $note");
    return [true, $approve ? "$label approved." : "$label returned with your note."];
}

/**
 * Everything the Department Admin's overview shows for one school and year:
 * one line per unit — status, the set (✓/✗ per document) and the budget.
 */
function ur_school_overview(string $school, int $fy): array {
    require_once __DIR__ . '/companions.php';
    $rows = [];
    foreach (au_units_of($school) as $code => $r) {
        $p   = ur_ppmp($code, $fy);
        $rev = ur_get($code, $fy);
        $st  = ur_status($code, $fy);
        $set = ppmp_companions($code, $fy);
        $rows[$code] = ['code' => $code, 'label' => au_label($code), 'program' => (string)$r['program_code'],
                        'major' => (string)($r['major_name'] ?? ''), 'ppmp' => $p, 'status' => $st, 'review' => $rev,
                        'set' => $set, 'complete' => $p !== null && $set['ok']];
    }
    return $rows;
}

/** The status chip (same colours everywhere). */
function ur_chip(string $status): string {
    $cls = ['draft' => 'ur-draft', 'for_review' => 'ur-review', 'approved' => 'ur-ok', 'returned' => 'ur-back', 'submitted' => 'ur-sent'][$status] ?? 'ur-draft';
    return '<span class="ur-chip ' . $cls . '">' . e(UR_LABELS[$status] ?? ucfirst($status)) . '</span>';
}

/** The CSS of the chips and the bar (printed once per page). */
function ur_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
      .ur-chip { display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.02em; border:1.5px solid; white-space:nowrap; }
      .ur-draft  { background:var(--navy-50); color:var(--ink); border-color:var(--navy-100); }
      .ur-review { background:#fef3c7; color:#7c4a03; border-color:#fcd34d; }
      .ur-ok     { background:#ecfdf3; color:#14532d; border-color:#86efac; }
      .ur-back   { background:#fef2f2; color:#991b1b; border-color:#fca5a5; }
      .ur-sent   { background:#eef2ff; color:#1e3a8a; border-color:#a5b4fc; }
      .ur-bar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding:12px 16px; margin-bottom:16px; background:var(--white);
                border:1px solid var(--navy-100); border-left:4px solid var(--navy-700); border-radius:12px; font-size:12.5px; color:var(--ink); }
      .ur-bar .ur-what { flex:1 1 260px; line-height:1.5; }
      .ur-bar .ur-what b { font-family:"Space Grotesk",sans-serif; }
      .ur-bar form { margin:0; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
      .ur-bar .ur-note { margin-top:4px; color:#991b1b; }
      body[data-theme="dark"] .ur-review { background:#3a2a0a; color:#fde68a; border-color:#a16207; }
      body[data-theme="dark"] .ur-ok { background:#123222; color:#c9f2d7; border-color:#2f7a4c; }
      body[data-theme="dark"] .ur-back { background:#3a1414; color:#fecaca; border-color:#9a2c2c; }
      body[data-theme="dark"] .ur-sent { background:#16245a; color:#dbe4ff; border-color:#5b6fd1; }
      body[data-theme="dark"] .ur-bar .ur-note { color:#fecaca; }
    </style>';
}

/**
 * The bar on the five document pages of a unit (before it is submitted):
 * where it stands, and "Send for review" — or, for the Department Admin,
 * Approve (Return, with a note, is on My Department). The page's own
 * Submit becomes "Submit whole school" (ur_submit_link()).
 */
function ur_bar(array $u, int $fy, string $back = ''): string {
    $unit = (string)($u['dept_code'] ?? '');
    if (!au_is_unit($unit) || ur_submitted($unit, $fy)) return '';
    if ($back === '') {   // back to this page
        $q = (string)($_SERVER['QUERY_STRING'] ?? '');
        $back = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dept-tracking.php')) . ($q !== '' && preg_match('#^[A-Za-z0-9_=&%.:\-]*$#', $q) ? '?' . $q : '');
    }
    $r     = ur_get($unit, $fy);
    $st    = (string)$r['status'];
    $dean  = ur_is_dean($u, $unit);
    $label = au_label($unit);
    $school = au_school_of($unit);
    $h  = ur_css();
    $h .= '<div class="ur-bar" id="unitReviewBar" data-status="' . e($st) . '">';
    $h .= '<div class="ur-what"><b>' . e($label) . '</b> · FY ' . $fy . ' · ' . ur_chip($st);
    if ($st === 'for_review' && !empty($r['requested_by'])) $h .= ' <span>sent by ' . e((string)$r['requested_by']) . '</span>';
    if (in_array($st, ['approved', 'returned'], true) && !empty($r['reviewed_by'])) $h .= ' <span>by ' . e((string)$r['reviewed_by']) . '</span>';
    if (!empty($r['note'])) $h .= '<div class="ur-note">' . e((string)$r['note']) . '</div>';
    $h .= '</div>';
    $tok = function_exists('csrf_field') ? csrf_field() : '';
    if ($dean) {
        if ($st !== 'approved') {
            $h .= '<form method="POST" action="api/unit-review.php">' . $tok
                . '<input type="hidden" name="unit" value="' . e($unit) . '"><input type="hidden" name="fy" value="' . $fy . '">'
                . '<input type="hidden" name="back" value="' . e($back) . '">'
                . '<input type="hidden" name="do" value="approve">'
                . '<button type="submit" class="btn btn-primary" id="approveUnit" style="padding:7px 14px; font-size:12.5px;">✓ Approve</button></form>';
        }
    } elseif (in_array($st, ['draft', 'returned'], true)) {
        $h .= '<form method="POST" action="api/unit-review.php" data-confirm="The Department Admin of ' . e($school) . ' will be notified to review the FY ' . $fy
            . ' documents of ' . e($label) . '." data-confirm-title="Send for review?" data-confirm-label="Send" data-confirm-tone="info">' . $tok
            . '<input type="hidden" name="unit" value="' . e($unit) . '"><input type="hidden" name="fy" value="' . $fy . '">'
            . '<input type="hidden" name="back" value="' . e($back) . '"><input type="hidden" name="do" value="request">'
            . '<button type="submit" class="btn btn-primary" id="sendForReview" style="padding:7px 14px; font-size:12.5px;">📨 Send for review</button></form>';
    }
    $h .= '</div>';
    return $h;
}

/** In place of a unit's Submit, for its Department Admin: the way to the whole-school Submit. */
function ur_submit_link(int $fy, string $school): string {
    return '<a href="dept-tracking.php?fy=' . $fy . '#schoolSubmit" class="btn btn-primary" id="submitWholeSchool" style="padding:8px 18px; font-size:13px; text-decoration:none;"'
         . ' title="Every program and major of ' . e($school) . ' is submitted at once, from My Department">📤 Submit whole school</a>';
}
