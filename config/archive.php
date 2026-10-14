<?php
/* ═══════════════════════════════════════════════════════════════
   The Archive — one set of rules for the five documents:
   PPMP, APP-CSE, Non-PS Supplies list, Annex and Market Scoping.

   · Archiving is one flag on the document itself, so the moment it is
     archived it is archived for everyone: it leaves the department's own
     pages (My Department, the editors), the Budget Unit's and the
     Procurement Unit's lists, and is found in the Archive instead.
   · When the Super Admin moves the system to a new fiscal year, every
     document of the years before is archived on its own
     (api/set-fiscal-year.php). This file is for archiving by hand.
   · Who may archive by hand:
       the Super Admin (Procurement Unit) — any document of any department;
       everyone else — their own department's documents, while they are
       still with the department (draft, or sent back for changes) or once
       they are approved. A document that is with the Budget Unit or the
       Procurement Unit for review cannot be archived by the department, so
       it cannot disappear in the middle of being reviewed.
   · Restoring brings it back exactly as it was. The Super Admin may
     restore anything; a department restores its own.
   · The PPMP and its APP-CSE travel together: archiving or restoring the
     PPMP does the same to its APP-CSE. The APP-CSE can also be archived
     on its own.
   · An archived document cannot be edited or saved; restore it first.
   =============================================================== */
require_once __DIR__ . '/auth.php';

const ARC_LABELS = [
    'ppmp'    => 'PPMP',
    'appcse'  => 'APP-CSE',
    'nonps'   => 'Non-PS Supplies list',
    'annex'   => 'Annex',
    'scoping' => 'Market Scoping',
];

/** SQL: this row is not archived. */
function arc_live_sql(string $alias = ''): string {
    $c = ($alias !== '' ? $alias . '.' : '') . 'archived';
    return "($c IS NULL OR $c = 0)";
}

function arc_ppmp_is_archived(int $ppmpId): bool {
    try {
        $s = db()->prepare('SELECT 1 FROM archived_ppmps WHERE ppmp_id = ?');
        $s->execute([$ppmpId]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $t) { return false; }
}

function arc_appcse_is_archived(int $fy, string $dept): bool {
    try {
        $s = db()->prepare('SELECT archived FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $s->execute([$fy, $dept]);
        return (int)$s->fetchColumn() === 1;
    } catch (Throwable $t) { return false; }
}

/**
 * One document, with what the rules need. $ref is the id, or "FY:DEPT" for
 * an APP-CSE. null when it does not exist.
 *   phase: 'dept' (draft / sent back), 'review' (with Budget or the PU), 'approved'
 */
function arc_load(string $type, $ref): ?array {
    $pdo = db();
    try {
        if ($type === 'ppmp') {
            $s = $pdo->prepare('SELECT id, dept_code, fy, status FROM ppmps WHERE id = ?');
            $s->execute([(int)$ref]);
            $r = $s->fetch();
            if (!$r) return null;
            return ['type' => 'ppmp', 'id' => (int)$r['id'], 'dept_code' => (string)$r['dept_code'], 'fy' => (int)$r['fy'],
                    'status' => (string)$r['status'], 'phase' => arc_ppmp_phase($r),
                    'archived' => arc_ppmp_is_archived((int)$r['id']),
                    'view' => 'ppmp-file-view.php?id=' . (int)$r['id']];
        }
        if ($type === 'appcse') {
            [$fy, $dept] = array_pad(explode(':', (string)$ref, 2), 2, '');
            $fy = (int)$fy;
            if ($fy < 2000 || $dept === '' || $dept === '__ALL__') return null;
            $s = $pdo->prepare('SELECT 1 FROM departments WHERE code = ?');
            $s->execute([$dept]);
            if (!$s->fetchColumn()) return null;
            $s = $pdo->prepare('SELECT id, dept_code, fy, status FROM ppmps WHERE dept_code = ? AND fy = ?');
            $s->execute([$dept, $fy]);
            $p = $s->fetch() ?: null;
            return ['type' => 'appcse', 'id' => 0, 'ref' => $fy . ':' . $dept, 'dept_code' => $dept, 'fy' => $fy,
                    'status' => (string)($p['status'] ?? 'draft'), 'phase' => $p ? arc_ppmp_phase($p) : 'dept',
                    'archived' => arc_appcse_is_archived($fy, $dept),
                    'view' => 'appcse-print.php?fy=' . $fy . '&dept=' . urlencode($dept)];
        }
        $table = ['nonps' => 'nonps_submissions', 'annex' => 'annex_forms', 'scoping' => 'market_scoping'][$type] ?? null;
        if (!$table) return null;
        $s = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $s->execute([(int)$ref]);
        $r = $s->fetch();
        if (!$r) return null;
        $st = (string)($r['status'] ?? 'draft');
        return ['type' => $type, 'id' => (int)$r['id'], 'dept_code' => (string)$r['dept_code'], 'fy' => (int)$r['fy'],
                'status' => $st,
                'phase' => $st === 'approved' ? 'approved' : ($st === 'submitted' ? 'review' : 'dept'),
                'archived' => (int)($r['archived'] ?? 0) === 1,
                'view' => ['nonps' => 'nonps-print.php?id=', 'annex' => 'annex-print.php?id=',
                           'scoping' => 'market-scoping-print.php?id='][$type] . (int)$r['id']];
    } catch (Throwable $t) {
        return null;
    }
}

/** Where a PPMP is: with the department, in review, or approved. */
function arc_ppmp_phase(array $p): string {
    $st = (string)($p['status'] ?? 'draft');
    if ($st === 'approved') return 'approved';
    if ($st === 'draft') return 'dept';
    // Sent back for changes: it is with the department again
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
        $s->execute([$p['dept_code'], (int)$p['fy']]);
        if ((int)$s->fetchColumn() > 0) return 'dept';
    } catch (Throwable $t) {}
    return 'review';
}

/** May this person archive (or restore) this document? [bool, why-not] */
function arc_can(array $u, array $doc, string $act): array {
    $label = ARC_LABELS[$doc['type']] ?? 'document';
    if (($u['role'] ?? '') === 'admin') return [true, ''];
    if (($u['dept_code'] ?? '') === '' || $u['dept_code'] !== $doc['dept_code']) {
        return [false, "You can only archive or restore your own department's documents."];
    }
    if ($act === 'archive' && $doc['phase'] === 'review') {
        return [false, "This $label is being reviewed, so it cannot be archived now. It can be archived while it is a draft or once it is approved."];
    }
    return [true, ''];
}

/** Archive ($on) or restore one document. Returns the message to show. */
function arc_set(array $doc, bool $on, array $u): string {
    $pdo  = db();
    $who  = (string)($u['full_name'] ?? '');
    $type = $doc['type'];
    $label = ARC_LABELS[$type] ?? 'Document';
    $where = "{$doc['dept_code']} · FY {$doc['fy']}";

    if ($type === 'ppmp') {
        if ($on) {
            if (!arc_ppmp_is_archived($doc['id'])) {
                $pdo->prepare('INSERT INTO archived_ppmps (ppmp_id, dept_code, fy, archived_by, reason) VALUES (?,?,?,?,?)')
                    ->execute([$doc['id'], $doc['dept_code'], $doc['fy'], $who, 'Archived by ' . $who]);
            }
        } else {
            $pdo->prepare('DELETE FROM archived_ppmps WHERE ppmp_id = ?')->execute([$doc['id']]);
        }
        // Its APP-CSE travels with it
        arc_set_appcse($doc['fy'], $doc['dept_code'], $on, $who);
    } elseif ($type === 'appcse') {
        arc_set_appcse($doc['fy'], $doc['dept_code'], $on, $who);
    } else {
        $table = ['nonps' => 'nonps_submissions', 'annex' => 'annex_forms', 'scoping' => 'market_scoping'][$type];
        if ($on) {
            $pdo->prepare("UPDATE `$table` SET archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?")
                ->execute([$who, $doc['id']]);
        } else {
            $pdo->prepare("UPDATE `$table` SET archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?")
                ->execute([$doc['id']]);
        }
    }

    log_activity(($on ? 'Archived ' : 'Restored ') . $label, $type, $where,
                 $on ? 'Moved to the Archive by hand.' : 'Brought back from the Archive.');

    // The Super Admin archiving another department's document: tell them
    if (($u['role'] ?? '') === 'admin' && ($u['dept_code'] ?? '') !== $doc['dept_code']) {
        try {
            require_once __DIR__ . '/notify.php';
            notify_by_dept($doc['dept_code'], 'info',
                $on ? "Your FY {$doc['fy']} $label was moved to the Archive"
                    : "Your FY {$doc['fy']} $label was restored from the Archive",
                $on ? "The Procurement Unit archived it. It is in your Archive, where it can be opened."
                    : 'The Procurement Unit brought it back. It is in your documents again.',
                $on ? 'archive.php' : $doc['view']);
        } catch (Throwable $t) {}
    }

    $and = $type === 'ppmp' ? ' (with its APP-CSE)' : '';
    // v88: a past year's document comes back into its own year's folder, for viewing
    // v104: it is shown in the current year's folders, marked with its own year
    $__past = (!$on && (int)$doc['fy'] < active_fy()) ? ' It is back in the ' . $label . ' folders of FY ' . active_fy() . ', marked FY ' . (int)$doc['fy'] . ' (view only).' : '';
    return $on ? "$label for {$doc['dept_code']} FY {$doc['fy']}$and moved to the Archive."
               : "$label for {$doc['dept_code']} FY {$doc['fy']}$and restored from the Archive.$__past";
}

function arc_set_appcse(int $fy, string $dept, bool $on, string $who): void {
    $pdo = db();
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $s->execute([$fy, $dept]);
        if ((int)$s->fetchColumn() === 0) {
            if (!$on) return;                 // nothing to restore
            $pdo->prepare('INSERT INTO appcse_header (fy, dept_code) VALUES (?, ?)')->execute([$fy, $dept]);
        }
        if ($on) {
            $pdo->prepare('UPDATE appcse_header SET archived = 1, archived_at = NOW(), archived_by = ? WHERE fy = ? AND dept_code = ?')
                ->execute([$who, $fy, $dept]);
        } else {
            $pdo->prepare('UPDATE appcse_header SET archived = 0, archived_at = NULL, archived_by = NULL WHERE fy = ? AND dept_code = ?')
                ->execute([$fy, $dept]);
        }
    } catch (Throwable $t) {}
}

/** The small Archive / Restore button a page shows for one document (''
 *  when this person may not). $back is where to return after. */
function arc_button(array $u, ?array $doc, string $back, string $style = ''): string {
    if (!$doc) return '';
    $act = $doc['archived'] ? 'restore' : 'archive';
    [$ok] = arc_can($u, $doc, $act);
    if (!$ok) return '';
    $label = ARC_LABELS[$doc['type']] ?? 'document';
    $ref   = $doc['type'] === 'appcse' ? $doc['ref'] : (string)$doc['id'];
    $msg   = $act === 'archive'
        ? "This $label moves to the Archive for everyone" . ($doc['type'] === 'ppmp' ? ', together with its APP-CSE' : '')
          . '. It leaves the lists and cannot be edited until it is restored.'
        : "This $label comes back out of the Archive, exactly as it was.";
    
    // For restore, redirect to the document's view page
    if ($act === 'restore') {
        $back = $doc['view'] ?? $back;
    }
    
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<form method="POST" action="api/archive-doc.php" class="arc-form" style="display:inline; margin:0;"'
         . ' data-confirm="' . $h($msg) . '" data-confirm-title="' . ($act === 'archive' ? 'Archive' : 'Restore') . ' this ' . $h($label) . '?"'
         . ' data-confirm-label="' . ($act === 'archive' ? 'Archive' : 'Restore') . '" data-confirm-tone="info">'
         . '<input type="hidden" name="type" value="' . $h($doc['type']) . '">'
         . '<input type="hidden" name="ref" value="' . $h($ref) . '">'
         . '<input type="hidden" name="act" value="' . $act . '">'
         . '<input type="hidden" name="back" value="' . $h($back) . '">'
         . '<button type="submit" class="btn btn-ghost arc-btn" style="padding:8px 14px; font-size:13px; color:#133458;' . $h($style) . '">'
         . ($act === 'archive' ? '🗄️ Archive' : '↩ Restore') . '</button></form>';
}

/** Shown on an editor when the document is in the Archive. */
function arc_redirect_if_archived(bool $archived, string $label, string $to): void {
    if (!$archived) return;
    flash('error', "This $label is in the Archive, so it cannot be edited. Restore it from the Archive to work on it again.");
    header('Location: ' . $to);
    exit;
}
