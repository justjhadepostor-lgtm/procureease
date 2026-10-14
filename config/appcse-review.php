<?php
/* ═══════════════════════════════════════════════════════════════
   The APP-CSE goes straight to the Procurement Unit (v77).

   It is still submitted with the rest of the set (one Submit on the PPMP),
   but it no longer travels with the PPMP to the Budget Unit. Only the
   PPMP goes there. The APP-CSE goes to the Procurement Unit, like the
   Non-PS list, Annex and Market Scoping, and has a state of its own,
   kept on its header row (appcse_header.status):

     draft      the department is working on it
     submitted  with the Procurement Unit (APP-CSE Folders), read-only
     revision   the Procurement Unit asked for changes, with a note; open
                again, and submitted on its own when done
     approved   approved by the Procurement Unit — only these are counted
                in the APP-CSE (Consolidated)

   An APP-CSE from before v77 has no state of its own yet; it follows the
   PPMP it went with until the Procurement Unit acts on it.
   =============================================================== */
require_once __DIR__ . '/auth.php';

const APPCSE_STATES = ['draft', 'submitted', 'revision', 'approved'];

/** Adds the state columns to appcse_header once (never inside a transaction). */
function appcse_flow_columns(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM appcse_header')->fetchAll(PDO::FETCH_COLUMN);
        if (!$have) return;
        $add = ['status' => 'VARCHAR(20) NULL', 'submitted_at' => 'DATETIME NULL', 'submitted_by' => 'VARCHAR(150) NULL',
                'reviewed_at' => 'DATETIME NULL', 'reviewed_by' => 'VARCHAR(150) NULL',
                // the note of a request for revision (also added by ensure_v39_columns(); here so this never depends on it)
                'revision' => 'TINYINT(1) DEFAULT 0', 'revision_note' => 'TEXT NULL',
                'revision_by' => 'VARCHAR(100) NULL', 'revision_at' => 'TIMESTAMP NULL'];
        foreach ($add as $c => $spec) {
            if (!in_array($c, $have, true)) db()->exec("ALTER TABLE appcse_header ADD COLUMN $c $spec");
        }
    } catch (Throwable $t) {}
}

/** The PPMP of this department and year: ['id','status'] or null. */
function appcse_ppmp(int $fy, string $dept): ?array {
    try {
        $s = db()->prepare('SELECT id, status FROM ppmps WHERE dept_code = ? AND fy = ? ORDER BY id DESC');
        $s->execute([$dept, $fy]);
        $r = $s->fetch();
        return $r ? ['id' => (int)$r['id'], 'status' => (string)$r['status']] : null;
    } catch (Throwable $t) { return null; }
}

/** The APP-CSE's header row, or null. */
function appcse_header_row(int $fy, string $dept): ?array {
    appcse_flow_columns();
    try {
        $s = db()->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $s->execute([$fy, $dept]);
        return $s->fetch() ?: null;
    } catch (Throwable $t) { return null; }
}

/** Where this APP-CSE stands: draft | submitted | revision | approved. */
function appcse_status(int $fy, string $dept, ?array $row = null): string {
    $row = $row ?? appcse_header_row($fy, $dept);
    $st = (string)($row['status'] ?? '');
    if (in_array($st, APPCSE_STATES, true)) return $st;
    // From before v77: it followed its PPMP
    if ($row && (int)($row['revision'] ?? 0) === 1) return 'revision';
    $p = appcse_ppmp($fy, $dept);
    if (!$p) return 'draft';
    if ($p['status'] === 'approved') return 'approved';
    if (in_array($p['status'], ['submitted', 'budget_approved'], true)) return 'submitted';
    return 'draft';
}

/** Everything a page shows about it: status, who sent it and when, who reviewed it, the note. */
function appcse_info(int $fy, string $dept): array {
    $row = appcse_header_row($fy, $dept);
    $st  = appcse_status($fy, $dept, $row);
    $p   = null;
    if (empty($row['submitted_at']) && $st !== 'draft') $p = appcse_ppmp($fy, $dept);
    $sub = $row['submitted_at'] ?? null; $by = $row['submitted_by'] ?? null;
    if (!$sub && $p) {
        try {
            $s = db()->prepare('SELECT submitted_at, submitted_by FROM ppmps WHERE id = ?');
            $s->execute([$p['id']]);
            if ($r = $s->fetch()) { $sub = $r['submitted_at']; $by = $r['submitted_by']; }
        } catch (Throwable $t) {}
    }
    return ['status' => $st, 'submitted_at' => $sub, 'submitted_by' => (string)($by ?? ''),
            'reviewed_at' => $row['reviewed_at'] ?? null, 'reviewed_by' => (string)($row['reviewed_by'] ?? ''),
            'note' => (string)($row['revision_note'] ?? ''), 'note_by' => (string)($row['revision_by'] ?? ''),
            'note_at' => $row['revision_at'] ?? null];
}

/** Sets the state, with who and when. $note is the request for revision. */
function appcse_set_status(int $fy, string $dept, string $status, string $who, ?string $note = null): void {
    appcse_flow_columns();
    $pdo = db();
    $s = $pdo->prepare('SELECT COUNT(*) FROM appcse_header WHERE fy = ? AND dept_code = ?');
    $s->execute([$fy, $dept]);
    if ((int)$s->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO appcse_header (fy, dept_code) VALUES (?, ?)')->execute([$fy, $dept]);
    }
    $w = [$fy, $dept];
    switch ($status) {
        case 'submitted':
            $pdo->prepare("UPDATE appcse_header SET status = 'submitted', submitted_at = NOW(), submitted_by = ?, revision = 0
                            WHERE fy = ? AND dept_code = ?")->execute(array_merge([$who], $w));
            break;
        case 'approved':
            $pdo->prepare("UPDATE appcse_header SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?, revision = 0
                            WHERE fy = ? AND dept_code = ?")->execute(array_merge([$who], $w));
            break;
        case 'revision':
            $pdo->prepare("UPDATE appcse_header SET status = 'revision', revision = 1, revision_note = ?, revision_by = ?, revision_at = NOW(),
                                  reviewed_at = NOW(), reviewed_by = ? WHERE fy = ? AND dept_code = ?")
                ->execute(array_merge([(string)$note, $who, $who], $w));
            break;
        default:   // back to draft (the department pulled its set back)
            $pdo->prepare("UPDATE appcse_header SET status = 'draft', submitted_at = NULL, submitted_by = NULL, revision = 0
                            WHERE fy = ? AND dept_code = ?")->execute($w);
    }
    // v79: version history (config/doc-versions.php)
    require_once __DIR__ . '/doc-versions.php';
    doc_snapshot('appcse', $fy . ':' . $dept, ['submitted' => 'submitted', 'approved' => 'approved', 'revision' => 'revision'][$status] ?? 'unsubmitted',
                 $status === 'revision' ? (string)$note : '');
}

/** The open request for revision — ['note','by','at'] — or null. */
function appcse_revision(int $fy, string $dept): ?array {
    $i = appcse_info($fy, $dept);
    if ($i['status'] !== 'revision') return null;
    return ['note' => $i['note'], 'by' => $i['note_by'], 'at' => $i['note_at']];
}

/**
 * May $u approve this APP-CSE, or ask for its revision, now? [bool, why-not]
 * The Procurement Unit, while the APP-CSE is with it.
 */
function appcse_can_review(array $u, int $fy, string $dept): array {
    if (($u['role'] ?? '') !== 'admin') return [false, 'Only the Procurement Unit reviews an APP-CSE.'];
    require_once __DIR__ . '/archive.php';
    if (arc_appcse_is_archived($fy, $dept)) return [false, 'This APP-CSE is in the Archive.'];
    if (fy_is_past($fy)) return [false, fy_past_msg('APP-CSE', $fy)];
    $st = appcse_status($fy, $dept);
    if ($st !== 'submitted') {
        return [false, $st === 'approved' ? 'This APP-CSE is already approved.'
                    : ($st === 'revision' ? 'This APP-CSE was sent back for revision and is not back yet.' : 'This APP-CSE has not been submitted yet.')];
    }
    return [true, ''];
}

/* Kept for the pages written before v77 */
function appcse_can_send_back(array $u, int $fy, string $dept): array { return appcse_can_review($u, $fy, $dept); }
function appcse_set_revision(int $fy, string $dept, ?string $note, string $by): void {
    if ($note === null) appcse_set_status($fy, $dept, 'submitted', $by);
    else appcse_set_status($fy, $dept, 'revision', $by, $note);
}
/** Nothing to clear any more: sending the PPMP back or submitting it again leaves the APP-CSE as it is. */
function appcse_clear_revision(int $fy, string $dept): void {}

/** May the department change it now? (draft or sent back for revision) */
function appcse_open_for_dept(int $fy, string $dept): bool {
    return in_array(appcse_status($fy, $dept), ['draft', 'revision'], true);
}
