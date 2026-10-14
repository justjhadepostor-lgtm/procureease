<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$pdo = db();
// Settings > Active fiscal year comes back to Settings
$__back = (($_POST['back'] ?? '') === 'settings.php') ? '../settings.php' : '../admin-home.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $__back); exit; }

$newYear = (int)($_POST['fy'] ?? 0);
if ($newYear < 2000 || $newYear > 2100) {
    flash('error', 'Please choose a valid fiscal year.');
    header('Location: ' . $__back); exit;
}

// Read current active FY (so we know what "last year" means)
$oldYear = 0;
try {
    $v = $pdo->query("SELECT value FROM settings WHERE key_name = 'active_fy'")->fetchColumn();
    if ($v) $oldYear = (int)$v;
} catch (Throwable $t) {}

// No change → nothing to do
if ($newYear === $oldYear) {
    flash('info', "The system is already set to FY $newYear.");
    header('Location: ' . $__back); exit;
}

// Every document the year change sends to the Archive is written down here,
// so that setting the year back brings exactly those documents back
// (and none that someone archived by hand).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fy_auto_archive (
                    doc_type VARCHAR(20) NOT NULL,
                    doc_id INT NOT NULL,
                    dept_code VARCHAR(30) NULL,
                    fy INT NOT NULL,
                    set_to INT NOT NULL,
                    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Throwable $t) { /* recorded best-effort; the PPMP's own note still says it */ }
$__note = function (string $type, int $id, int $fy, string $dept = '') use ($pdo, $newYear): void {
    try {
        $pdo->prepare('INSERT INTO fy_auto_archive (doc_type, doc_id, dept_code, fy, set_to) VALUES (?, ?, ?, ?, ?)')
            ->execute([$type, $id, $dept, $fy, $newYear]);
    } catch (Throwable $t) {}
};
$restored = ['ppmp' => 0, 'other' => 0];

$pdo->beginTransaction();
try {
    // 1. Set the system-wide active fiscal year
    $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('active_fy', ?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([(string)$newYear]);

    // 2. Archive every PPMP from a fiscal year BEFORE the new active year
    //    ("old files from last year" — anything older than the year now in effect).
    $archivedCount = 0;
    $toArchive = $pdo->prepare("SELECT p.id, p.dept_code, p.fy
                                  FROM ppmps p
                                  WHERE p.fy < ?
                                    AND p.id NOT IN (SELECT ppmp_id FROM archived_ppmps)");
    $toArchive->execute([$newYear]);
    $rows = $toArchive->fetchAll();

    $ins = $pdo->prepare("INSERT INTO archived_ppmps (ppmp_id, dept_code, fy, archived_by, reason)
                          VALUES (?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $ins->execute([
            (int)$r['id'], $r['dept_code'], (int)$r['fy'], $u['full_name'],
            "Auto-archived when system year set to FY $newYear"
        ]);
        $__note('ppmp', (int)$r['id'], (int)$r['fy']);
        $archivedCount++;
    }

    // 3. Also archive Non-PS submissions from before the new year (mark archived)
    $nonpsArchived = 0;
    try {
        $__ids = $pdo->prepare("SELECT id, fy FROM nonps_submissions WHERE fy < ? AND (archived IS NULL OR archived = 0)");
        $__ids->execute([$newYear]);
        $__rows = $__ids->fetchAll();
        $ns = $pdo->prepare("UPDATE nonps_submissions
                                SET archived = 1, archived_at = NOW(), archived_by = ?
                              WHERE fy < ? AND (archived IS NULL OR archived = 0)");
        $ns->execute([$u['full_name'], $newYear]);
        $nonpsArchived = $ns->rowCount();
        foreach ($__rows as $__r) $__note('nonps', (int)$__r['id'], (int)$__r['fy']);
    } catch (Throwable $t) { /* nonps table/columns may not exist yet */ }

    // 4. And the rest of the documents from before the new year:
    //    Market Scoping, Annex and APP-CSE. Marking only — records stay put.
    //    Each department's APP-CSE carries its flag on its header row, which
    //    a department that never filled in the agency block does not have
    //    yet — give it one, so every APP-CSE reaches the Archive.
    try {
        $pdo->prepare("INSERT INTO appcse_header (fy, dept_code)
                       SELECT DISTINCT i.fy, i.dept_code FROM appcse_items i
                        WHERE i.fy < ? AND i.dept_code <> ''
                          AND NOT EXISTS (SELECT 1 FROM appcse_header h WHERE h.fy = i.fy AND h.dept_code = i.dept_code)")
            ->execute([$newYear]);
    } catch (Throwable $t) { /* older install */ }
    $otherArchived = 0;
    foreach (['market_scoping', 'annex_forms', 'appcse_header'] as $tbl) {
        try {
            // (an APP-CSE is known by its year and department — it has no id)
            $__ids = $pdo->prepare($tbl === 'appcse_header'
                ? "SELECT 0 AS id, fy, dept_code FROM `$tbl` WHERE fy < ? AND (archived IS NULL OR archived = 0)"
                : "SELECT id, fy, dept_code FROM `$tbl` WHERE fy < ? AND (archived IS NULL OR archived = 0)");
            $__ids->execute([$newYear]);
            $__rows = $__ids->fetchAll();
            $st = $pdo->prepare("UPDATE `$tbl`
                                    SET archived = 1, archived_at = NOW(), archived_by = ?
                                  WHERE fy < ? AND (archived IS NULL OR archived = 0)");
            $st->execute([$u['full_name'], $newYear]);
            $otherArchived += $st->rowCount();
            foreach ($__rows as $__r) $__note($tbl, (int)$__r['id'], (int)$__r['fy'], (string)$__r['dept_code']);
        } catch (Throwable $t) { /* table not present on this install */ }
    }

    // 5. The year is set BACK: what the year change archived comes back —
    //    every document of FY $newYear and after that went to the Archive
    //    only because a later year was set. Anything archived by hand stays.
    if ($oldYear > 0 && $newYear < $oldYear) {
        $restored = fy_restore_auto_archived($pdo, $newYear);
    }

    log_activity('Set system fiscal year', 'settings', "FY $newYear",
        "Archived $archivedCount PPMP(s), $nonpsArchived Non-PS list(s) and $otherArchived other document(s) from prior years");

    $pdo->commit();

    $msg = "System fiscal year set to FY $newYear.";
    if ($restored['ppmp'] > 0 || $restored['other'] > 0) {
        $__p = [];
        if ($restored['ppmp'] > 0)  $__p[] = $restored['ppmp'] . ' PPMP' . ($restored['ppmp'] === 1 ? '' : 's');
        if ($restored['other'] > 0) $__p[] = $restored['other'] . ' other document' . ($restored['other'] === 1 ? '' : 's');
        $msg .= ' Brought back ' . implode(' and ', $__p) . ' that the year change had moved to the Archive.';
    }
    if ($archivedCount > 0 || $nonpsArchived > 0 || $otherArchived > 0) {
        $parts = [];
        if ($archivedCount > 0)  $parts[] = "$archivedCount PPMP" . ($archivedCount === 1 ? '' : 's');
        if ($nonpsArchived > 0)  $parts[] = "$nonpsArchived Non-PS list" . ($nonpsArchived === 1 ? '' : 's');
        if ($otherArchived > 0)  $parts[] = "$otherArchived other document" . ($otherArchived === 1 ? '' : 's');
        $msg .= ' Archived ' . implode(' and ', $parts) . ' from earlier years.';
    }
    flash('success', $msg);
} catch (Throwable $t) {
    $pdo->rollBack();
    flash('error', 'Could not set the fiscal year: ' . $t->getMessage());
}

header('Location: ' . $__back); exit;

/**
 * Setting the year back to $fy: every document of FY $fy and after that the
 * year change archived comes out of the Archive, exactly as it was.
 *  · PPMPs: their archive entry says "Auto-archived when system year set to FY …".
 *  · The other documents: written down in fy_auto_archive when they were
 *    archived; for those archived before that record was kept, the ones
 *    archived by the same person at the moment the year was changed (the
 *    "Set system fiscal year" entry in the Activity logs).
 * Returns ['ppmp' => n, 'other' => n].
 */
function fy_restore_auto_archived(PDO $pdo, int $fy): array {
    $out = ['ppmp' => 0, 'other' => 0];
    // PPMPs, and the APP-CSE that travels with each
    $rows = [];
    try {
        $s = $pdo->prepare("SELECT id, ppmp_id, dept_code, fy FROM archived_ppmps
                             WHERE fy >= ? AND reason LIKE 'Auto-archived when system year set to FY%'");
        $s->execute([$fy]);
        $rows = $s->fetchAll();
    } catch (Throwable $t) {}
    foreach ($rows as $r) {
        $pdo->prepare('DELETE FROM archived_ppmps WHERE id = ?')->execute([(int)$r['id']]);
        try {
            $pdo->prepare('UPDATE appcse_header SET archived = 0, archived_at = NULL, archived_by = NULL WHERE fy = ? AND dept_code = ?')
                ->execute([(int)$r['fy'], (string)$r['dept_code']]);
        } catch (Throwable $t) {}
        $out['ppmp']++;
    }

    // The other documents
    $tables = ['nonps' => 'nonps_submissions', 'nonps_submissions' => 'nonps_submissions', 'market_scoping' => 'market_scoping',
               'annex_forms' => 'annex_forms', 'appcse_header' => 'appcse_header'];
    $noted = [];
    try {
        $s = $pdo->prepare('SELECT doc_type, doc_id, dept_code, fy FROM fy_auto_archive WHERE fy >= ?');
        $s->execute([$fy]);
        foreach ($s->fetchAll() as $r) {
            $t = $tables[(string)$r['doc_type']] ?? null;
            if ($t === 'appcse_header') $noted[$t][(int)$r['fy'] . ':' . (string)$r['dept_code']] = true;
            elseif ($t) $noted[$t][(int)$r['doc_id']] = true;
        }
    } catch (Throwable $t) {}
    // …and those archived by a year change before it was written down
    $changes = [];
    try {
        foreach ($pdo->query("SELECT full_name, created_at FROM activity_logs WHERE action = 'Set system fiscal year'")->fetchAll() as $l) {
            $changes[] = [(string)$l['full_name'], strtotime((string)$l['created_at'])];
        }
    } catch (Throwable $t) {}
    foreach (array_unique(array_values($tables)) as $t) {
        try {
            $isAc = $t === 'appcse_header';
            $s = $pdo->prepare($isAc ? "SELECT fy, dept_code, archived_at, archived_by FROM `$t` WHERE fy >= ? AND archived = 1"
                                     : "SELECT id, archived_at, archived_by FROM `$t` WHERE fy >= ? AND archived = 1");
            $s->execute([$fy]);
            foreach ($s->fetchAll() as $r) {
                $id  = $isAc ? 0 : (int)$r['id'];
                $key = $isAc ? (int)$r['fy'] . ':' . (string)$r['dept_code'] : $id;
                $auto = isset($noted[$t][$key]);
                if (!$auto && !empty($r['archived_at'])) {
                    $at = strtotime((string)$r['archived_at']);
                    foreach ($changes as [$who, $when]) {
                        if ($when && $at && abs($at - $when) <= 10 && $who === (string)$r['archived_by']) { $auto = true; break; }
                    }
                }
                if (!$auto) continue;
                if ($isAc) {
                    $pdo->prepare("UPDATE `$t` SET archived = 0, archived_at = NULL, archived_by = NULL WHERE fy = ? AND dept_code = ?")
                        ->execute([(int)$r['fy'], (string)$r['dept_code']]);
                } else {
                    $pdo->prepare("UPDATE `$t` SET archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?")->execute([$id]);
                    $out['other']++;
                }
            }
        } catch (Throwable $t2) {}
    }
    try { $pdo->prepare('DELETE FROM fy_auto_archive WHERE fy >= ?')->execute([$fy]); } catch (Throwable $t) {}
    return $out;
}
