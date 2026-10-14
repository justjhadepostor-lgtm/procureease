<?php
/* ═══════════════════════════════════════════════════════════════
   Past fiscal years in the Procurement Unit's folders.

   When the Super Admin moves the system to a new fiscal year, every document
   of the years before goes to the Archive on its own. When one of them is
   restored (by the department or by the Super Admin), it comes back into its
   department's folder, inside a folder of its own year:

       Department  ›  FY 2026  ›  the restored file

   It is for viewing only (fy_is_past()): no editing, submitting or reviewing,
   and it is not counted in the active year or in any consolidation.
   v89: a restored draft is shown too (marked Draft) — whatever was
   restored comes back into its department's folder.

   Used by PPMP Folders, Non-PS Supplies, Annex and Market Scoping.
   =============================================================== */
require_once __DIR__ . '/auth.php';

/**
 * Restored documents of past years (drafts too — v89), not in the Archive:
 *   [dept_code => [fy => [row, …]]], newest year first.
 * row: id, fy, dept_code, status, submitted_at, submitted_by, title, amount (or null), view
 */
function past_year_docs(string $type, string $for = 'admin'): array {
    // v90: the Procurement Unit sees a department's restored document only if it was approved;
    // the Budget Unit, a PPMP that had reached it (not a draft). A draft stays the department's own.
    $fy  = active_fy();
    $pdo = db();
    $rows = [];
    try {
        if ($type === 'ppmp') {
            $s = $pdo->prepare("SELECT p.id, p.dept_code, p.fy, p.status, p.submitted_at, p.submitted_by,
                                       (SELECT COALESCE(SUM(e.amount), 0) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS amount
                                  FROM ppmps p
                                 WHERE p.fy < ?
                                   AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)");
            $s->execute([$fy]);
            foreach ($s->fetchAll() as $r) $rows[] = $r + ['title' => 'FY ' . (int)$r['fy'] . ' PPMP', 'view' => 'ppmp-file-view.php?id=' . (int)$r['id']];
        } elseif ($type === 'nonps') {
            $s = $pdo->prepare("SELECT s.id, s.dept_code, s.fy, s.status, s.submitted_at, s.submitted_by,
                                       (SELECT COALESCE(SUM(i.total_cost), 0) FROM nonps_items i WHERE i.submission_id = s.id) AS amount
                                  FROM nonps_submissions s
                                 WHERE s.fy < ? AND (s.archived IS NULL OR s.archived = 0)");
            $s->execute([$fy]);
            // v80: a list's amount is its GRAND TOTAL (with VAT, logistics, inflation)
            require_once __DIR__ . '/doc-format.php';
            require_once __DIR__ . '/price-provision.php';
            foreach ($s->fetchAll() as $r) {
                $r['amount'] = nonps_grand_for((float)$r['amount'], (int)$r['fy']);
                $rows[] = $r + ['title' => 'FY ' . (int)$r['fy'] . ' Non-PS Supplies list', 'view' => 'nonps-print.php?id=' . (int)$r['id']];
            }
        } elseif ($type === 'annex') {
            $s = $pdo->prepare("SELECT id, dept_code, fy, status, submitted_at, submitted_by, annex, project_name, total_estimated AS amount
                                  FROM annex_forms
                                 WHERE fy < ? AND (archived IS NULL OR archived = 0)");
            $s->execute([$fy]);
            foreach ($s->fetchAll() as $r) {
                $t = 'FY ' . (int)$r['fy'] . ' Annex ' . $r['annex'] . (trim((string)$r['project_name']) !== '' ? ' · ' . trim((string)$r['project_name']) : '');
                $rows[] = $r + ['title' => $t, 'view' => 'annex-print.php?id=' . (int)$r['id']];
            }
        } elseif ($type === 'appcse') {
            // v88: the APP-CSE too (APP-CSE Folders) — one per department and year, with quantities, not a draft
            foreach (past_year_appcse_rows($fy, null, true) as $r) $rows[] = $r;
        } elseif ($type === 'scoping') {
            $s = $pdo->prepare("SELECT id, dept_code, fy, status, submitted_at, submitted_by, title
                                  FROM market_scoping
                                 WHERE fy < ? AND (archived IS NULL OR archived = 0)");
            $s->execute([$fy]);
            foreach ($s->fetchAll() as $r) {
                $t = 'FY ' . (int)$r['fy'] . ' Market Scoping' . (trim((string)$r['title']) !== '' ? ' · ' . trim((string)$r['title']) : '');
                $rows[] = ['title' => $t, 'amount' => null, 'view' => 'market-scoping-print.php?id=' . (int)$r['id']] + $r;
            }
        }
    } catch (Throwable $t) { $rows = []; }

    require_once __DIR__ . '/office-sets.php';
    $out = [];
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? 'draft');
        if ($for === 'admin'  && $st !== 'approved') continue;
        if ($for === 'budget' && $st === 'draft') continue;
        // v90: a set's document is in its office's folder, marked with its set
        $sl = pe_folder_label((string)$r['dept_code']);   // v104: or its program / major
        if ($sl !== '') $r['title'] .= ' · ' . $sl;
        $out[pe_folder_of((string)$r['dept_code'])][(int)$r['fy']][] = $r;   // v104: a program's / major's in its school's folder
    }
    foreach ($out as &$byFy) krsort($byFy);
    unset($byFy);
    return $out;
}

/**
 * A department's APP-CSEs of past years (not in the Archive, with quantities):
 * rows like past_year_docs(). $withDrafts: also those never submitted.
 */
function past_year_appcse_rows(int $activeFy, ?string $dept, bool $withDrafts): array {
    $out = [];
    try {
        require_once __DIR__ . '/appcse-review.php';
        require_once __DIR__ . '/price-provision.php';
        $sql = "SELECT DISTINCT i.fy, i.dept_code FROM appcse_items i
                 WHERE i.fy < ? AND (i.jan+i.feb+i.mar+i.apr+i.may+i.jun+i.jul+i.aug+i.sep+i.oct+i.nov+i.`dec`) > 0"
             . ($dept !== null ? ' AND i.dept_code = ?' : '');
        $s = db()->prepare($sql);
        $s->execute($dept !== null ? [$activeFy, $dept] : [$activeFy]);
        foreach ($s->fetchAll() as $r) {
            $fy = (int)$r['fy']; $d = (string)$r['dept_code'];
            if (arc_appcse_is_archived_py($fy, $d)) continue;
            $info = appcse_info($fy, $d);
            $st = (string)($info['status'] ?? 'draft');
            if ($st === 'draft' && !$withDrafts) continue;
            $out[] = ['id' => 0, 'dept_code' => $d, 'fy' => $fy, 'status' => $st,
                      'submitted_at' => $info['submitted_at'] ?? null, 'submitted_by' => $info['submitted_by'] ?? null,
                      'title' => 'FY ' . $fy . ' APP-CSE',
                      'amount' => (float)appcse_summary(appcse_part_totals($fy, $d), appcse_transport($fy, $d), $fy)['d'],
                      'view' => 'appcse-print.php?fy=' . $fy . '&dept=' . urlencode($d)];
        }
    } catch (Throwable $t) {}
    return $out;
}
function arc_appcse_is_archived_py(int $fy, string $dept): bool {
    try {
        $s = db()->prepare('SELECT archived FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $s->execute([$fy, $dept]);
        return (int)$s->fetchColumn() === 1;
    } catch (Throwable $t) { return false; }
}

/**
 * v88: one department's own documents of past years that are not in the
 * Archive — restored ones, drafts included — for its own My PPMPs page:
 * [fy => [row, …]], newest year first. View only.
 */
function past_year_docs_of(string $dept): array {
    // v90: an office's sets are one folder — Set 1, Set 2, Set 3 together, each marked
    require_once __DIR__ . '/office-sets.php';
    $codes = os_family(os_folder_of($dept));
    if (!$codes) $codes = [$dept];
    $out = [];
    foreach ($codes as $c) {
        $sl = count($codes) > 1 ? os_set_label($c) : '';
        foreach (past_year_docs_of_one($c) as $fy => $docs) {
            foreach ($docs as $d) {
                if ($sl !== '') $d['title'] .= ' · ' . $sl;
                $out[$fy][] = $d;
            }
        }
    }
    krsort($out);
    return $out;
}

/** One code's own documents of past years, not in the Archive (drafts included). */
function past_year_docs_of_one(string $dept): array {
    $fy  = active_fy();
    $pdo = db();
    $rows = [];
    $q = function (string $sql, array $a) use ($pdo): array {
        try { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); } catch (Throwable $t) { return []; }
    };
    foreach ($q("SELECT p.id, p.dept_code, p.fy, p.status, p.submitted_at, p.submitted_by,
                        (SELECT COALESCE(SUM(e.amount), 0) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS amount
                   FROM ppmps p WHERE p.dept_code = ? AND p.fy < ?
                    AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)", [$dept, $fy]) as $r) {
        $rows[] = $r + ['title' => 'FY ' . (int)$r['fy'] . ' PPMP', 'view' => 'ppmp-file-view.php?id=' . (int)$r['id']];
    }
    foreach (past_year_appcse_rows($fy, $dept, true) as $r) $rows[] = $r;
    require_once __DIR__ . '/doc-format.php';
    require_once __DIR__ . '/price-provision.php';
    foreach ($q("SELECT s.id, s.dept_code, s.fy, s.status, s.submitted_at, s.submitted_by,
                        (SELECT COALESCE(SUM(i.total_cost), 0) FROM nonps_items i WHERE i.submission_id = s.id) AS amount
                   FROM nonps_submissions s WHERE s.dept_code = ? AND s.fy < ? AND (s.archived IS NULL OR s.archived = 0)", [$dept, $fy]) as $r) {
        $r['amount'] = nonps_grand_for((float)$r['amount'], (int)$r['fy']);
        $rows[] = $r + ['title' => 'FY ' . (int)$r['fy'] . ' Non-PS Supplies list', 'view' => 'nonps-print.php?id=' . (int)$r['id']];
    }
    foreach ($q("SELECT id, dept_code, fy, status, submitted_at, submitted_by, annex, project_name, total_estimated AS amount
                   FROM annex_forms WHERE dept_code = ? AND fy < ? AND (archived IS NULL OR archived = 0)", [$dept, $fy]) as $r) {
        $t = 'FY ' . (int)$r['fy'] . ' Annex ' . $r['annex'] . (trim((string)$r['project_name']) !== '' ? ' · ' . trim((string)$r['project_name']) : '');
        $rows[] = $r + ['title' => $t, 'view' => 'annex-print.php?id=' . (int)$r['id']];
    }
    foreach ($q("SELECT id, dept_code, fy, status, submitted_at, submitted_by, title
                   FROM market_scoping WHERE dept_code = ? AND fy < ? AND (archived IS NULL OR archived = 0)", [$dept, $fy]) as $r) {
        $t = 'FY ' . (int)$r['fy'] . ' Market Scoping' . (trim((string)$r['title']) !== '' ? ' · ' . trim((string)$r['title']) : '');
        $rows[] = ['title' => $t, 'amount' => null, 'view' => 'market-scoping-print.php?id=' . (int)$r['id']] + $r;
    }
    $out = [];
    foreach ($rows as $r) $out[(int)$r['fy']][] = $r;
    krsort($out);
    return $out;
}

/**
 * v104: a past year's documents restored from the Archive are shown in the
 * department's folder of the current fiscal year, with its other files —
 * each marked with its own year and "View only" (not inside an FY sub-folder).
 * ($base is kept for the callers; the files open from their own View.)
 */
function past_year_folders_html(string $base, string $dept, array $byFy): string {
    if (!$byFy) return '';
    $docs = [];
    foreach ($byFy as $fy => $list) foreach ($list as $d) $docs[] = $d;
    return past_year_restored_html($docs);
}

/**
 * v104: a department's own restored files of one kind (ppmp, nonps, annex,
 * appcse, scoping), shown on its own page for that kind — My PPMPs opens the
 * PPMP form, so they are listed under it. '' when there are none.
 */
function past_year_mine_html(string $type, string $dept): string {
    $prefix = ['ppmp' => 'ppmp-file-view.php', 'nonps' => 'nonps-print.php', 'annex' => 'annex-print.php',
               'appcse' => 'appcse-print.php', 'scoping' => 'market-scoping-print.php'][$type] ?? '';
    if ($prefix === '' || $dept === '') return '';
    $docs = [];
    foreach (past_year_docs_of($dept) as $fy => $list) {
        foreach ($list as $d) if (strpos((string)$d['view'], $prefix . '?') === 0) $docs[] = $d;
    }
    return $docs ? past_year_restored_html($docs) : '';
}

/** "Restored from the Archive" with its files (view only). */
function past_year_restored_html(array $docs): string {
    $n = count($docs);
    return '<div class="past-years" id="pastYears">'
         . '<div class="section-head" style="margin-top: 22px;"><div>'
         . '<h3 class="section-title" style="font-size: 16px;">Restored from the Archive</h3>'
         . '<p class="section-sub">' . $n . ' file' . ($n === 1 ? '' : 's') . ' of an earlier fiscal year · view only</p>'
         . '</div></div>' . past_year_files_html($docs) . '</div>';
}

/** One past year's files, each with a View button only. */
function past_year_files_html(array $docs): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $st = ['submitted' => 'Submitted', 'budget_approved' => 'Approved by Budget', 'approved' => 'Approved', 'revision' => 'Sent back', 'draft' => 'Draft'];
    $o = '';
    foreach ($docs as $d) {
        $o .= '<div class="card past-year-file" style="margin-bottom: 14px; padding: 14px 18px; display: flex; align-items: center; gap: 12px 14px; flex-wrap: wrap;">'
            . '<div style="background: var(--navy-100); color: var(--navy-700); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>'
            . '<div style="flex: 1 1 240px; min-width: 0;">'
            . '<div style="font-family: \'Space Grotesk\', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink); overflow-wrap: anywhere;">' . $h($d['title'])
            . ' <span style="background: var(--navy-50); color: var(--ink); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-left: 6px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">'
            . $h($st[$d['status']] ?? $d['status']) . '</span>'
            . ' <span style="background: var(--navy-50); color: var(--ink); border: 1px dashed var(--navy-500); padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-left: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">View only</span></div>'
            . '<div style="font-size: 12px; color: var(--muted); margin-top: 3px;">'
            . (!empty($d['submitted_at']) ? 'Submitted ' . $h(date('M j, Y', strtotime($d['submitted_at']))) : 'Not submitted')
            . (!empty($d['submitted_by']) ? ' · By ' . $h($d['submitted_by']) : '') . '</div></div>'
            . '<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">'
            . ($d['amount'] !== null ? '<span style="font-family: \'Space Grotesk\', sans-serif; font-size: 16px; font-weight: 700; color: var(--ink);">₱' . number_format((float)$d['amount'], 2) . '</span>' : '')
            . '<a href="' . $h($d['view']) . '" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">View</a></div></div>';
    }
    return $o;
}

/** The page for one past year inside a department's folder. */
function past_year_page_html(string $base, string $baseLabel, array $dept, string $group, int $fy, array $docs): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $back = $base . '?dept=' . urlencode((string)$dept['code']);
    return '<div class="breadcrumb">'
         . '<a href="' . $h($base) . '">' . $h($baseLabel) . '</a><span>›</span>'
         . '<a href="' . $h($base . '?group=' . urlencode($group)) . '">' . $h($group) . '</a><span>›</span>'
         . '<a href="' . $h($back) . '">' . $h($dept['code']) . ' — ' . $h($dept['name']) . '</a><span>›</span>'
         . '<span>FY ' . $fy . '</span></div>'
         . '<div class="section-head"><div>'
         . '<h2 class="section-title">' . $h($dept['code']) . ' · FY ' . $fy . '</h2>'
         . '<p class="section-sub">' . count($docs) . ' file' . (count($docs) === 1 ? '' : 's') . ' · view only</p></div>'
         . '<a href="' . $h($back) . '" class="btn btn-ghost">← Back</a></div>'
         . ($docs ? past_year_files_html($docs)
                  : '<div style="background: var(--white); border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;">No restored FY ' . $fy . ' files in this folder.</div>');
}

/**
 * v111: a PPMP folder's restored files are not listed under the PPMP — they are in a
 * folder of their own inside it, "Restored". $href opens it. '' when there are none.
 */
function past_year_restored_folder_html(string $href, array $docs): string {
    if (!$docs) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $n = count($docs);
    $ys = [];
    foreach ($docs as $d) $ys[(int)$d['fy']] = true;
    krsort($ys);
    return '<div class="folder-grid" id="restoredFolder" style="margin-top: 22px;">'
         . '<a href="' . $h($href) . '" class="folder past-year-folder">'
         . '<div class="folder-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>'
         . '<div class="folder-info"><div class="folder-code">Restored</div>'
         . '<div class="folder-full">Restored from the Archive</div>'
         . '<div class="folder-meta"><span>' . $n . ' file' . ($n === 1 ? '' : 's') . '</span><span class="dot"></span>'
         . '<span>FY ' . implode(', ', array_keys($ys)) . '</span><span class="dot"></span><span>View only</span></div></div></a></div>';
}

/** v111: inside the "Restored" folder — its files (view only). $crumbs: [[label, href], …, [last label]]. */
function past_year_restored_page_html(array $crumbs, string $title, string $back, array $docs): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $bc = [];
    foreach ($crumbs as $c) $bc[] = isset($c[1]) ? '<a href="' . $h($c[1]) . '">' . $h($c[0]) . '</a>' : '<span>' . $h($c[0]) . '</span>';
    $n = count($docs);
    return '<div class="breadcrumb">' . implode('<span>›</span>', $bc) . '</div>'
         . '<div class="section-head" id="restoredPage"><div>'
         . '<h2 class="section-title">' . $h($title) . '</h2>'
         . '<p class="section-sub">Restored from the Archive · ' . $n . ' file' . ($n === 1 ? '' : 's') . ' · view only</p></div>'
         . '<a href="' . $h($back) . '" class="btn btn-ghost">← Back</a></div>'
         . ($docs ? past_year_files_html($docs)
                  : '<div style="background: var(--white); border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;">No restored files here. Restore them from the Archive to see them.</div>');
}

/** v111: one department's own restored PPMPs (all years, newest first). */
function past_year_ppmps_of(string $dept): array {
    $out = [];
    foreach (past_year_docs_of($dept) as $fy => $list) foreach ($list as $d) if (strpos((string)$d['view'], 'ppmp-file-view.php?') === 0) $out[] = $d;
    return $out;
}

/** v89: on a department's folder card — which past years were restored into it ('' when none). */
function past_year_tag(array $byFy): string {
    if (!$byFy) return '';
    $ys = array_keys($byFy);
    rsort($ys);
    return '<span class="dot"></span><span class="past-tag">Restored FY ' . implode(', ', array_map('intval', $ys)) . '</span>';
}
