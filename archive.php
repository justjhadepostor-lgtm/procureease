<?php
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/appcse-review.php';
require_login();
ensure_admin_tables();

$activeNav = 'archive';
$pageTitle = 'Archive';
$pdo = db();
$u = current_user();
$role = $u['role'] ?? 'user';
$isAdmin = ($role === 'admin');
$myDept  = $u['dept_code'] ?? '';

function can_touch_dept(bool $isAdmin, string $myDept, string $deptCode): bool {
    return $isAdmin || ($myDept !== '' && ($myDept === $deptCode || strcasecmp(os_folder_of($myDept), os_folder_of($deptCode)) === 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Non-PS restore ──
    if ($action === 'nonps_restore' && !empty($_POST['nonps_id'])) {
        $nid = (int)$_POST['nonps_id'];
        $p = $pdo->prepare('SELECT dept_code, fy FROM nonps_submissions WHERE id = ?');
        $p->execute([$nid]); $row = $p->fetch();
        if ($row && can_touch_dept($isAdmin, $myDept, $row['dept_code'])) {
            $pdo->prepare("UPDATE nonps_submissions SET archived = 0, archived_at = NULL WHERE id = ?")->execute([$nid]);
            log_activity('Restored Non-PS list from archive', 'nonps', "{$row['dept_code']} · FY {$row['fy']}");
            
            // Redirect directly to the Non-PS print view
            flash('success', 'Non-PS list restored from the archive.');
            header('Location: nonps-print.php?id=' . $nid);
            exit;
        } else {
            flash('error', 'You can only restore your own department\'s Non-PS lists.');
            header('Location: archive.php'); exit;
        }
    }

    // ── Permanent delete for archived Annex / Market Scoping / APP-CSE ──
    if (in_array($action, ['annex_delete','scoping_delete','appcse_delete'], true)) {
        $ok = false; $details = ''; $label = '';
        try {
            if ($action === 'annex_delete') {
                $id = (int)($_POST['doc_id'] ?? 0);
                $q = $pdo->prepare('SELECT id,dept_code,fy FROM annex_forms WHERE id=? AND archived=1'); $q->execute([$id]); $r = $q->fetch();
                if ($r && can_touch_dept($isAdmin, $myDept, (string)$r['dept_code'])) {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM annex_items WHERE form_id=?')->execute([$id]);
                    $pdo->prepare('DELETE FROM annex_forms WHERE id=?')->execute([$id]);
                    $pdo->commit(); $ok = true; $details = $r['dept_code'].' · FY '.$r['fy']; $label = 'Annex';
                }
            } elseif ($action === 'scoping_delete') {
                $id = (int)($_POST['doc_id'] ?? 0);
                $q = $pdo->prepare('SELECT id,dept_code,fy FROM market_scoping WHERE id=? AND archived=1'); $q->execute([$id]); $r = $q->fetch();
                if ($r && can_touch_dept($isAdmin, $myDept, (string)$r['dept_code'])) {
                    $pdo->prepare('DELETE FROM market_scoping WHERE id=?')->execute([$id]);
                    $ok = true; $details = $r['dept_code'].' · FY '.$r['fy']; $label = 'Market Scoping';
                }
            } else {
                $fyDelete = (int)($_POST['fy'] ?? 0); $deptDelete = trim((string)($_POST['dept_code'] ?? ''));
                $q = $pdo->prepare('SELECT archived FROM appcse_header WHERE fy=? AND dept_code=?'); $q->execute([$fyDelete,$deptDelete]); $isArchived = $q->fetchColumn();
                if ($isArchived !== false && (int)$isArchived === 1 && can_touch_dept($isAdmin, $myDept, $deptDelete)) {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM appcse_items WHERE fy=? AND dept_code=?')->execute([$fyDelete,$deptDelete]);
                    $pdo->prepare('DELETE FROM appcse_header WHERE fy=? AND dept_code=?')->execute([$fyDelete,$deptDelete]);
                    $pdo->commit(); $ok = true; $details = $deptDelete.' · FY '.$fyDelete; $label = 'APP-CSE';
                }
            }
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('archive permanent delete failed: '.$t->getMessage()); }
        if ($ok) { log_activity('Permanently deleted archived '.$label, strtolower(str_replace(' ','',$label)), $details); flash('success', $label.' permanently deleted from Archive.'); }
        else flash('error', 'Could not delete that archived document, or you do not have permission.');
        header('Location: archive.php'); exit;
    }

    // ── Non-PS permanent delete ──
    if ($action === 'nonps_delete' && !empty($_POST['nonps_id'])) {
        $nid = (int)$_POST['nonps_id'];
        $p = $pdo->prepare('SELECT dept_code, fy FROM nonps_submissions WHERE id = ?');
        $p->execute([$nid]); $row = $p->fetch();
        if ($row && can_touch_dept($isAdmin, $myDept, $row['dept_code'])) {
            try {
                $pdo->prepare('DELETE FROM nonps_items WHERE submission_id = ?')->execute([$nid]);
                $pdo->prepare('DELETE FROM nonps_submissions WHERE id = ?')->execute([$nid]);
                log_activity('Permanently deleted Non-PS list', 'nonps', "{$row['dept_code']} · FY {$row['fy']}");
                flash('success', 'Non-PS list permanently deleted.');
            } catch (Throwable $t) {
                flash('error', 'Could not delete the Non-PS list.');
            }
        } else {
            flash('error', 'You can only delete your own department\'s Non-PS lists.');
        }
        header('Location: archive.php'); exit;
    }

    if ($action === 'archive' && !empty($_POST['ppmp_id'])) {
        // The same rules as every other document (config/archive.php)
        $doc = arc_load('ppmp', (int)$_POST['ppmp_id']);
        if (!$doc) {
            flash('error', 'That PPMP could not be found.');
        } else {
            [$ok, $why] = arc_can($u, $doc, 'archive');
            if (!$ok)                 flash('error', $why);
            elseif ($doc['archived']) flash('error', 'That PPMP is already in the archive.');
            else {
                try { flash('success', arc_set($doc, true, $u)); }
                catch (Throwable $t) { flash('error', 'Could not archive the PPMP. Please try again.'); }
            }
        }

    } elseif ($action === 'restore' && (!empty($_POST['id']) || !empty($_POST['ppmp_id']))) {
        // By the archive entry (the PPMP's file view) or by the PPMP itself
        if (empty($_POST['id'])) {
            $__a = $pdo->prepare('SELECT id FROM archived_ppmps WHERE ppmp_id = ?');
            $__a->execute([(int)$_POST['ppmp_id']]);
            $_POST['id'] = (int)$__a->fetchColumn();
        }
        $arch_id = (int)$_POST['id'];
        $p = $pdo->prepare('SELECT dept_code, fy, ppmp_id FROM archived_ppmps WHERE id = ?');
        $p->execute([$arch_id]);
        $row = $p->fetch();
        if ($row && can_touch_dept($isAdmin, $myDept, $row['dept_code'])) {
            $ppmp_id = (int)$row['ppmp_id'];
            $pdo->prepare('DELETE FROM archived_ppmps WHERE id = ?')->execute([$arch_id]);
            // its APP-CSE comes back with it
            arc_set_appcse((int)$row['fy'], (string)$row['dept_code'], false, (string)($u['full_name'] ?? ''));
            log_activity('Restored PPMP from archive', 'ppmp', "{$row['dept_code']} · FY {$row['fy']}");
            
            // Redirect directly to the PPMP file view
            flash('success', "Restored {$row['dept_code']} FY {$row['fy']} from the archive.");
            header('Location: ppmp-file-view.php?id=' . $ppmp_id);
            exit;
        } else {
            flash('error', 'You can only restore your own department\'s PPMPs.');
            header('Location: archive.php');
            exit;
        }

    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $arch_id = (int)$_POST['id'];
        $p = $pdo->prepare('SELECT ppmp_id, dept_code, fy FROM archived_ppmps WHERE id = ?');
        $p->execute([$arch_id]);
        $row = $p->fetch();
        if ($row && can_touch_dept($isAdmin, $myDept, $row['dept_code'])) {
            $ppmp_id = (int)$row['ppmp_id'];
            try {
                $pdo->beginTransaction();

                if ($isAdmin) {
                    // Admin: move to recoverable trash (Backup & Restore)
                    $pp = $pdo->prepare('SELECT * FROM ppmps WHERE id = ?');
                    $pp->execute([$ppmp_id]);
                    $ppmpRow = $pp->fetch(PDO::FETCH_ASSOC);
                    $ee = $pdo->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ?');
                    $ee->execute([$ppmp_id]);
                    $entryRows = $ee->fetchAll(PDO::FETCH_ASSOC);
                    $snapshot = json_encode(['ppmp' => $ppmpRow, 'entries' => $entryRows]);
                    $totalAmt = array_sum(array_map(fn($e) => (float)($e['amount'] ?? 0), $entryRows));
                    $pdo->prepare('INSERT INTO deleted_ppmps (ppmp_id, dept_code, fy, snapshot, entry_count, total_amount, deleted_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$ppmp_id, $row['dept_code'], (int)$row['fy'], $snapshot, count($entryRows), $totalAmt, $u['full_name']]);
                }

                // Remove the live PPMP + its archive entry
                $pdo->prepare('DELETE FROM ppmp_entries WHERE ppmp_id = ?')->execute([$ppmp_id]);
                $pdo->prepare('DELETE FROM ppmps WHERE id = ?')->execute([$ppmp_id]);
                $pdo->prepare('DELETE FROM archived_ppmps WHERE id = ?')->execute([$arch_id]);
                foreach (['revision_requests', 'edit_requests'] as $tbl) {
                    try { $pdo->prepare("DELETE FROM $tbl WHERE ppmp_id = ?")->execute([$ppmp_id]); } catch (Throwable $t) {}
                }
                $pdo->commit();

                if ($isAdmin) {
                    log_activity('Deleted PPMP to trash', 'ppmp', "{$row['dept_code']} · FY {$row['fy']}",
                        'Moved to Backup & Restore trash — recoverable until permanently deleted there.');
                    flash('success', "{$row['dept_code']} FY {$row['fy']} moved to Backup & Restore, where it can still be recovered.");
                } else {
                    log_activity('Permanently deleted PPMP', 'ppmp', "{$row['dept_code']} · FY {$row['fy']}",
                        'Permanently deleted from archive.');
                    flash('success', "{$row['dept_code']} FY {$row['fy']} has been permanently deleted.");
                }
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('archive delete failed: ' . $t->getMessage());
                flash('error', 'Could not delete the PPMP. Please try again.');
            }
        } else {
            flash('error', 'You can only delete your own department\'s PPMPs.');
        }
    }
    header('Location: archive.php');
    exit;
}

/* v90: who sees what in the Archive.
   · An office's sets are one folder: its accounts see Set 1, 2 and 3 together.
   · The Procurement Unit sees its own documents, and a department's only if it was approved —
     a department's draft (or one still in review) is the department's own. */
$__family = $isAdmin ? [] : os_family(os_folder_of($myDept));
$__wide   = $isAdmin || count($__family) > 1;
$__mine   = os_folder_of((string)($u['school_code'] ?? $myDept));
$__keep = function (string $dept, string $status) use ($isAdmin, $__family, $__mine): bool {
    if (!$isAdmin) { foreach ($__family as $f) if (strcasecmp($f, $dept) === 0) return true; return false; }
    if (os_folder_of($dept) === $__mine) return true;
    return $status === 'approved';
};
if ($__wide) {
    $stmt = $pdo->query("SELECT a.*, d.name AS dept_name, (SELECT p.status FROM ppmps p WHERE p.id = a.ppmp_id) AS doc_status,
                                (SELECT COUNT(*) FROM ppmp_entries e WHERE e.ppmp_id = a.ppmp_id) AS entry_count,
                                (SELECT COALESCE(SUM(amount),0) FROM ppmp_entries e WHERE e.ppmp_id = a.ppmp_id) AS total_amount
                           FROM archived_ppmps a
                      LEFT JOIN departments d ON d.code = a.dept_code
                       ORDER BY a.archived_at DESC");
    $archived = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT a.*, d.name AS dept_name, (SELECT p.status FROM ppmps p WHERE p.id = a.ppmp_id) AS doc_status,
                                  (SELECT COUNT(*) FROM ppmp_entries e WHERE e.ppmp_id = a.ppmp_id) AS entry_count,
                                  (SELECT COALESCE(SUM(amount),0) FROM ppmp_entries e WHERE e.ppmp_id = a.ppmp_id) AS total_amount
                             FROM archived_ppmps a
                        LEFT JOIN departments d ON d.code = a.dept_code
                            WHERE a.dept_code = ?
                         ORDER BY a.archived_at DESC");
    $stmt->execute([$myDept]);
    $archived = $stmt->fetchAll();
}

// ─── Archived Non-PS submissions ───────────────────────────
$archivedNonps = [];
try {
    if ($__wide) {
        $ns = $pdo->query("SELECT n.*, d.name AS dept_name,
                                  (SELECT COUNT(*) FROM nonps_items i WHERE i.submission_id = n.id) AS item_count
                             FROM nonps_submissions n
                        LEFT JOIN departments d ON d.code = n.dept_code
                            WHERE n.archived = 1
                         ORDER BY n.archived_at DESC");
        $archivedNonps = $ns->fetchAll();
    } else {
        $ns = $pdo->prepare("SELECT n.*, d.name AS dept_name,
                                    (SELECT COUNT(*) FROM nonps_items i WHERE i.submission_id = n.id) AS item_count
                               FROM nonps_submissions n
                          LEFT JOIN departments d ON d.code = n.dept_code
                              WHERE n.archived = 1 AND n.dept_code = ?
                           ORDER BY n.archived_at DESC");
        $ns->execute([$myDept]);
        $archivedNonps = $ns->fetchAll();
    }
} catch (Throwable $t) { $archivedNonps = []; }

/* ─── Everything else belonging to a finished fiscal year ─────
   Market Scoping, Annex and APP-CSE. Read-only here: they are listed in
   the year tree and opened from their own pages, exactly as before. */
$deptFilter = $__wide ? '' : ' AND dept_code = ' . $pdo->quote($myDept);

$archivedScoping = [];
try {
    $archivedScoping = $pdo->query("SELECT id, fy, dept_code, title, status, archived_at
                                      FROM market_scoping
                                     WHERE archived = 1$deptFilter
                                  ORDER BY fy DESC, dept_code")->fetchAll();
} catch (Throwable $t) { $archivedScoping = []; }

$archivedAnnex = [];
try {
    $archivedAnnex = $pdo->query("SELECT id, fy, dept_code, annex, project_name, total_estimated, status, archived_at
                                    FROM annex_forms
                                   WHERE archived = 1$deptFilter
                                ORDER BY fy DESC, dept_code")->fetchAll();
} catch (Throwable $t) { $archivedAnnex = []; }

// APP-CSE: each department's own (it travels with its PPMP) sits under that
// department; the consolidated one sits directly under its year.
$archivedAppcse = [];
try {
    $archivedAppcse = $pdo->query("SELECT fy, dept_code, archived_at FROM appcse_header
                                    WHERE archived = 1 ORDER BY fy DESC")->fetchAll();
} catch (Throwable $t) { $archivedAppcse = []; }

/* ─── Fold everything into  year -> department -> documents ─── */
$deptNames = [];
try {
    foreach ($pdo->query('SELECT code, name FROM departments')->fetchAll() as $d) {
        $deptNames[$d['code']] = $d['name'];
    }
} catch (Throwable $t) {}

$tree = [];
$addDoc = function (int $fy, string $dept, array $doc, string $status = '') use (&$tree, $__keep) {
    if ($dept === '') return;
    if (!$__keep($dept, $status)) return;                     // v90
    $sl = os_set_label($dept);                                // v90: a set, in its office's folder
    if ($sl !== '') $doc['label'] .= ' · ' . $sl;
    $tree[$fy][os_folder_of($dept)][] = $doc;
};

foreach ($archived as $a) {
    $addDoc((int)$a['fy'], (string)$a['dept_code'], [
        'type'  => 'PPMP',
        'label' => 'PPMP',
        'meta'  => (int)$a['entry_count'] . ' item' . ((int)$a['entry_count'] === 1 ? '' : 's')
                   . ' · PHP ' . number_format((float)$a['total_amount'], 2),
        'link'  => 'ppmp-file-view.php?id=' . (int)$a['ppmp_id'],
        'row'   => $a,
    ], (string)($a['doc_status'] ?? ''));
}
foreach ($archivedNonps as $n) {
    $addDoc((int)$n['fy'], (string)$n['dept_code'], [
        'type'  => 'NONPS',
        'label' => 'Non-PS Supplies',
        'meta'  => (int)$n['item_count'] . ' item' . ((int)$n['item_count'] === 1 ? '' : 's'),
        'link'  => 'nonps-print.php?id=' . (int)$n['id'],
        'row'   => $n,
    ], (string)($n['status'] ?? ''));
}
foreach ($archivedScoping as $m) {
    $addDoc((int)$m['fy'], (string)$m['dept_code'], [
        'type'  => 'SCOPING',
        'label' => 'Market Scoping',
        'meta'  => trim((string)($m['title'] ?? '')) !== '' ? (string)$m['title'] : ucfirst((string)$m['status']),
        'link'  => 'market-scoping-print.php?id=' . (int)$m['id'],
        'row'   => $m,
    ], (string)($m['status'] ?? ''));
}
foreach ($archivedAnnex as $x) {
    $addDoc((int)$x['fy'], (string)$x['dept_code'], [
        'type'  => 'ANNEX',
        'label' => 'Annex ' . ($x['annex'] ?? ''),
        'meta'  => trim((string)($x['project_name'] ?? '')) !== ''
                   ? (string)$x['project_name']
                   : 'PHP ' . number_format((float)($x['total_estimated'] ?? 0), 2),
        'link'  => 'annex-print.php?id=' . (int)$x['id'],
        'row'   => $x,
    ], (string)($x['status'] ?? ''));
}

$appcseByYear = [];
foreach ($archivedAppcse as $c) {
    $__d = (string)($c['dept_code'] ?? '');
    if ($__d === '' || $__d === '__ALL__') { $appcseByYear[(int)$c['fy']] = $c; continue; }
    $addDoc((int)$c['fy'], $__d, [
        'type'  => 'APPCSE',
        'label' => 'APP-CSE',
        'meta'  => 'Submitted with the PPMP',
        'link'  => 'appcse-print.php?fy=' . (int)$c['fy'] . '&dept=' . urlencode($__d),
        'row'   => $c,
    ], appcse_status((int)$c['fy'], $__d));
}

$years = array_unique(array_merge(array_keys($tree), array_keys($appcseByYear)));
rsort($years);

$totalDocs = 0;
foreach ($tree as $byDept) { foreach ($byDept as $docs) $totalDocs += count($docs); }
$totalDocs += count($appcseByYear);
$newestYear = $years ? $years[0] : 0;
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  /* Archive folder tree — Year -> Department -> Document */
  .arc-year { border: 1px solid var(--border); border-radius: 12px; background: var(--white); margin-bottom: 12px; overflow: hidden; }
  .arc-year > summary {
    display: flex; align-items: center; gap: 10px; cursor: pointer; list-style: none;
    padding: 15px 18px; background: var(--navy-50); border-bottom: 1px solid transparent;
  }
  .arc-year[open] > summary { border-bottom-color: var(--border); }
  .arc-year > summary::-webkit-details-marker { display: none; }
  .arc-yr { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--navy-900); }
  .arc-count {
    margin-left: auto; font-size: 11px; font-weight: 700; color: var(--muted);
    background: var(--white); border: 1px solid var(--border); border-radius: 999px; padding: 2px 10px;
  }
  .arc-dept { margin: 10px 14px 10px 26px; border-left: 2px solid var(--border); padding-left: 14px; }
  .arc-dept > summary { display: flex; align-items: center; gap: 9px; cursor: pointer; list-style: none; padding: 7px 0; }
  .arc-dept > summary::-webkit-details-marker { display: none; }
  .arc-dept-name { font-weight: 600; font-size: 13.5px; color: var(--ink); }
  .arc-dept-code { font-family: 'Space Grotesk', sans-serif; font-size: 11px; color: var(--muted); }
  .arc-dept-flat { padding-top: 4px; }
  .arc-doc {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; margin: 5px 0 5px 22px;
    border: 1px solid var(--border); border-radius: 9px; background: var(--white);
  }
  .arc-doc:hover { border-color: var(--navy-100); background: var(--navy-50); }
  .arc-doc-main { flex: 1; min-width: 0; }
  .arc-doc-label { font-size: 13px; font-weight: 600; color: var(--ink); }
  .arc-doc-meta { font-size: 11.5px; color: var(--muted); margin-top: 1px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .arc-open { padding: 5px 13px; font-size: 12px; border-radius: 8px; }
  .arc-ico { font-size: 15px; line-height: 1; }
  @media (max-width: 640px) {
    .arc-dept { margin-left: 10px; } .arc-doc { margin-left: 8px; flex-wrap: wrap; }
  }
</style>
<div class="app">
<?php
  if ($isAdmin)                 require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($role === 'budget')   require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Archive</h1>
        <p class="page-sub"><?= $isAdmin ? 'Documents from previous fiscal years, and anything archived by hand.' : 'Your department\'s documents from previous fiscal years, and anything archived by hand.' ?></p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php if ($totalDocs === 0): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <div style="font-size: 42px; margin-bottom: 12px;">&#128451;</div>
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">The archive is empty</div>
        <div style="font-size: 13.5px;">Documents move here on their own once their fiscal year ends, or when they are archived by hand.</div>
      </div>
    <?php else: ?>
      <?php foreach ($years as $yr): ?>
        <?php
          $yearDepts = $tree[$yr] ?? [];
          // A year holding only the APP-CSE is empty for a department user
          if (!$isAdmin && !$yearDepts) continue;
          ksort($yearDepts);
          $yearCount = 0;
          foreach ($yearDepts as $dd) $yearCount += count($dd);
          if ($isAdmin && isset($appcseByYear[$yr])) $yearCount++;
        ?>
        <details class="arc-year"<?= (int)$yr === (int)$newestYear ? ' open' : '' ?>>
          <summary>
            <span class="arc-ico">&#128193;</span>
            <span class="arc-yr">FY <?= (int)$yr ?></span>
            <span class="arc-count"><?= $yearCount ?> document<?= $yearCount === 1 ? '' : 's' ?></span>
          </summary>

          <?php /* APP-CSE is prepared and opened by the Procurement Unit only,
                   so it is listed here for them alone — a department user
                   would simply be bounced off appcse-print.php. */ ?>
          <?php if ($isAdmin && isset($appcseByYear[$yr])): ?>
            <div class="arc-dept arc-dept-flat">
              <div class="arc-doc">
                <span class="arc-ico">&#128196;</span>
                <div class="arc-doc-main">
                  <div class="arc-doc-label">APP-CSE</div>
                  <div class="arc-doc-meta">One per fiscal year, for the whole institution</div>
                </div>
                <a class="btn btn-ghost arc-open" href="appcse-print.php?fy=<?= (int)$yr ?>" target="_blank">Open</a>
              </div>
            </div>
          <?php endif; ?>

          <?php foreach ($yearDepts as $deptCode => $docs): ?>
            <details class="arc-dept" open>
              <summary>
                <span class="arc-ico">&#128193;</span>
                <span class="arc-dept-name"><?= e($deptNames[$deptCode] ?? $deptCode) ?></span>
                <span class="arc-dept-code"><?= e($deptCode) ?></span>
                <span class="arc-count"><?= count($docs) ?></span>
              </summary>
              <?php foreach ($docs as $doc): ?>
                <div class="arc-doc">
                  <span class="arc-ico">&#128196;</span>
                  <div class="arc-doc-main">
                    <div class="arc-doc-label"><?= e($doc['label']) ?></div>
                    <div class="arc-doc-meta"><?= e($doc['meta']) ?></div>
                  </div>
                  <a class="btn btn-ghost arc-open" href="<?= e($doc['link']) ?>" target="_blank">Open</a>
                  <?php
                    /* Restore, for every kind of document (config/archive.php) */
                    $__t = ['PPMP' => 'ppmp', 'NONPS' => 'nonps', 'SCOPING' => 'scoping', 'ANNEX' => 'annex', 'APPCSE' => 'appcse'][$doc['type']] ?? '';
                    $__r = $__t === 'ppmp' ? (int)$doc['row']['ppmp_id']
                         : ($__t === 'appcse' ? (int)$doc['row']['fy'] . ':' . (string)($doc['row']['dept_code'] ?? $deptCode) : (int)($doc['row']['id'] ?? 0));   // v93: a set's own APP-CSE
                  ?>
                  <?= $__t !== '' ? arc_button($u, arc_load($__t, $__r), 'archive.php', ' padding:5px 13px; font-size:12px; border-radius:8px;') : '' ?>
                  <?php if ($__t === 'ppmp' && $isAdmin && !empty($doc['row']['id'])): ?>
                    <form method="POST" style="display:inline" data-confirm="<?= $isAdmin ? 'Delete this archived PPMP? It will be removed from Archive and moved to Backup & Restore, where it can be recovered.' : 'Permanently delete this archived PPMP and its items? This cannot be undone.' ?>" data-confirm-title="Delete archived PPMP" data-confirm-label="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$doc['row']['id'] ?>">
                      <button class="btn btn-ghost" type="submit" style="padding:5px 13px;font-size:12px;border-radius:8px;color:#b91c1c">Delete</button>
                    </form>
                  <?php elseif ($__t === 'nonps' && !empty($doc['row']['id'])): ?>
                    <form method="POST" style="display:inline" data-confirm="Permanently delete this archived Non-PS list and its items?" data-confirm-title="Delete archived Non-PS" data-confirm-label="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="nonps_delete"><input type="hidden" name="nonps_id" value="<?= (int)$doc['row']['id'] ?>">
                      <button class="btn btn-ghost" type="submit" style="padding:5px 13px;font-size:12px;border-radius:8px;color:#b91c1c">Delete</button>
                    </form>
                  <?php elseif ($isAdmin && $__t === 'annex' && !empty($doc['row']['id'])): ?>
                    <form method="POST" style="display:inline" data-confirm="Permanently delete this archived Annex and its item rows? This cannot be undone." data-confirm-title="Delete archived Annex" data-confirm-label="Delete" data-confirm-tone="danger"><input type="hidden" name="action" value="annex_delete"><input type="hidden" name="doc_id" value="<?= (int)$doc['row']['id'] ?>"><button class="btn btn-ghost" type="submit" style="padding:5px 13px;font-size:12px;border-radius:8px;color:#b91c1c">Delete</button></form>
                  <?php elseif ($isAdmin && $__t === 'scoping' && !empty($doc['row']['id'])): ?>
                    <form method="POST" style="display:inline" data-confirm="Permanently delete this archived Market Scoping document? This cannot be undone." data-confirm-title="Delete archived Market Scoping" data-confirm-label="Delete" data-confirm-tone="danger"><input type="hidden" name="action" value="scoping_delete"><input type="hidden" name="doc_id" value="<?= (int)$doc['row']['id'] ?>"><button class="btn btn-ghost" type="submit" style="padding:5px 13px;font-size:12px;border-radius:8px;color:#b91c1c">Delete</button></form>
                  <?php elseif ($isAdmin && $__t === 'appcse' && !empty($doc['row']['dept_code'])): ?>
                    <form method="POST" style="display:inline" data-confirm="Permanently delete this archived APP-CSE and its item rows? This cannot be undone." data-confirm-title="Delete archived APP-CSE" data-confirm-label="Delete" data-confirm-tone="danger"><input type="hidden" name="action" value="appcse_delete"><input type="hidden" name="fy" value="<?= (int)$doc['row']['fy'] ?>"><input type="hidden" name="dept_code" value="<?= e($doc['row']['dept_code']) ?>"><button class="btn btn-ghost" type="submit" style="padding:5px 13px;font-size:12px;border-radius:8px;color:#b91c1c">Delete</button></form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </details>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
