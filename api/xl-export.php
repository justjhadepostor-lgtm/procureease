<?php
/* v105: a department's document as the Procurement Unit's file (config/xl-forms.php):
   ?doc=ppmp&id=N · ?doc=appcse · ?doc=nonps&id=N · ?doc=annex&id=N (every annex of its set) · ?doc=scoping&id=N */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/xl-forms.php';
require_login();
ensure_admin_tables();

$u    = current_user();
$pdo  = db();
$doc  = (string)($_GET['doc'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$mine = (string)($u['dept_code'] ?? '');
$isPU = ($u['role'] ?? '') === 'admin';

function xl_export_stop(string $msg): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

try {
    switch ($doc) {
        case 'ppmp': {
            $s = $pdo->prepare('SELECT * FROM ppmps WHERE id = ?');
            $s->execute([$id]);
            $p = $s->fetch();
            // its own department's PPMP; the Procurement Unit, any PPMP it can open
            if (!$p || ((string)$p['dept_code'] !== $mine && !$isPU)) xl_export_stop('That PPMP was not found.');
            $e = $pdo->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id');
            $e->execute([$id]);
            xl_send(xl_ppmp_export($p, $e->fetchAll()), 'PPMP.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        case 'appcse': {
            if ($mine === '') xl_export_stop('Your account has no department, so it has no APP-CSE.');
            $fy = active_fy();
            xl_send(xl_appcse_export($fy, $mine, (string)($u['dept_name'] ?? $mine)), 'APPCSE.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        case 'nonps': {
            $s = $pdo->prepare('SELECT s.*, d.name AS dept_name FROM nonps_submissions s LEFT JOIN departments d ON d.code = s.dept_code WHERE s.id = ?');
            $s->execute([$id]);
            $sub = $s->fetch();
            if (!$sub || (string)$sub['dept_code'] !== $mine) xl_export_stop('That Non-PS list was not found.');
            $q = $pdo->prepare('SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id');
            $q->execute([$id]);
            xl_send(xl_nonps_export($sub, $q->fetchAll(), (string)($sub['dept_name'] ?? $mine), $u), 'NONPS.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        case 'annex': {
            require_once __DIR__ . '/../config/annex-extra.php';
            $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ?');
            $s->execute([$id]);
            $f = $s->fetch();
            if (!$f || (string)$f['dept_code'] !== $mine) xl_export_stop('That Annex was not found.');
            // every annex of the set (A–E), the open one first
            $forms = [];
            $all = $pdo->prepare('SELECT * FROM annex_forms WHERE dept_code = ? AND fy = ? AND (archived IS NULL OR archived = 0) ORDER BY annex, id');
            try { $all->execute([$mine, (int)$f['fy']]); $rows = $all->fetchAll(); }
            catch (Throwable $t) { $rows = [$f]; }
            usort($rows, fn($a, $b) => ((int)$b['id'] === $id) <=> ((int)$a['id'] === $id) ?: strcmp((string)$a['annex'], (string)$b['annex']));
            $it = $pdo->prepare('SELECT * FROM annex_items WHERE form_id = ? ORDER BY sort_order, id');
            foreach ($rows as $r) { $it->execute([(int)$r['id']]); $forms[] = [$r, $it->fetchAll()]; }
            xl_send(xl_annex_export($forms, $mine), 'ANNEX.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        case 'scoping': {
            $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ?');
            $s->execute([$id]);
            $d = $s->fetch();
            if (!$d || (string)$d['dept_code'] !== $mine) xl_export_stop('That Market Scoping was not found.');
            xl_send(ms_export_docx($d), 'MARKET SCOPING.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
    }
    xl_export_stop('Nothing to export.');
} catch (Throwable $t) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The file could not be made: ' . $t->getMessage();
}
