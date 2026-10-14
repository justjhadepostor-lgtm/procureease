<?php
/* ═══════════════════════════════════════════════════════════════
   The Procurement Unit edits a department's PPMP only with permission.

   While a PPMP is with the Procurement Unit (after the Budget Unit has
   approved it, before final approval — ppmp_with_pu()), the PU asks the
   department first (api/request-edit.php). The department grants or declines
   (api/respond-edit.php). Only with a grant can the PU open the PPMP in the
   editor (ppmp-edit.php?id=…) and save changes (api/save-ppmp.php).

   A grant belongs to that one PPMP and lasts until the PU gives final
   approval or sends the PPMP back for revision (pu_edit_close()); after
   that, editing again needs a new request.

   The Procurement Unit's own PPMP needs no permission: it is its own.
   =============================================================== */
require_once __DIR__ . '/db.php';

/** Adds edit_requests.closed_at (when a grant or request stopped counting). */
function ensure_edit_request_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM edit_requests')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('closed_at', $have, true)) {
            db()->exec('ALTER TABLE edit_requests ADD COLUMN closed_at TIMESTAMP NULL');
        }
        // v77: the Non-PS list and the APP-CSE too — which document a request is for
        if ($have && !in_array('doc_type', $have, true)) db()->exec('ALTER TABLE edit_requests ADD COLUMN doc_type VARCHAR(20) NULL');
        if ($have && !in_array('doc_ref', $have, true))  db()->exec('ALTER TABLE edit_requests ADD COLUMN doc_ref VARCHAR(60) NULL');
    } catch (Throwable $t) {}
}

/**
 * Where the PU stands on editing this PPMP:
 *   ['state' => 'own'|'none'|'pending'|'granted'|'declined', 'req' => the latest request or null]
 */
function pu_edit_state(array $ppmp, ?array $u = null): array {
    ensure_edit_request_columns();
    $u = $u ?? (function_exists('current_user') ? current_user() : null);
    if ($u && (string)($u['dept_code'] ?? '') !== '' && (string)$u['dept_code'] === (string)($ppmp['dept_code'] ?? '')) {
        return ['state' => 'own', 'req' => null];
    }
    $req = null;
    try {
        $s = db()->prepare("SELECT * FROM edit_requests WHERE ppmp_id = ? AND (doc_type IS NULL OR doc_type = '' OR doc_type = 'ppmp')
                              AND closed_at IS NULL ORDER BY id DESC LIMIT 1");
        $s->execute([(int)($ppmp['id'] ?? 0)]);
        $req = $s->fetch() ?: null;
    } catch (Throwable $t) {}
    if (!$req) return ['state' => 'none', 'req' => null];
    $st = (string)$req['status'];
    return ['state' => in_array($st, ['pending', 'granted', 'declined'], true) ? $st : 'none', 'req' => $req];
}

/** May the PU edit this PPMP right now? (it is with the PU, and the department said yes) */
function pu_edit_allowed(array $ppmp, ?array $u = null): bool {
    if (!function_exists('ppmp_with_pu') || !ppmp_with_pu($ppmp)) return false;
    return in_array(pu_edit_state($ppmp, $u)['state'], ['own', 'granted'], true);
}

/** The PPMP left the PU's hands (final approval, or sent back): its permission ends. */
function pu_edit_close(int $ppmpId): void {
    ensure_edit_request_columns();
    try {
        db()->prepare("UPDATE edit_requests SET closed_at = NOW() WHERE ppmp_id = ? AND (doc_type IS NULL OR doc_type = '' OR doc_type = 'ppmp')
                         AND closed_at IS NULL")->execute([$ppmpId]);
    } catch (Throwable $t) {}
}

/* ═══════════════════════════════════════════════════════════════
   v77: the same for a department's Non-PS list and APP-CSE.

   The Procurement Unit (Superadmin) edits them only with the permission of
   the department that submitted them, while they are with the PU
   (submitted, not yet approved). A request is for one document:
     nonps    doc_ref = the Non-PS list's id
     appcse   doc_ref = "FY:DEPT"
   The department grants or declines it on its dashboard, as for a PPMP.
   A grant lasts until the PU approves the document or sends it back.
   =============================================================== */
const PU_EDIT_DOCS = ['ppmp' => 'PPMP', 'nonps' => 'Non-PS list', 'appcse' => 'APP-CSE'];

/** What a request is for, in words: "FY 2027 Non-PS list" */
function pu_edit_doc_label(array $req): string {
    $t = (string)($req['doc_type'] ?? '');
    return 'FY ' . (int)($req['fy'] ?? 0) . ' ' . (PU_EDIT_DOCS[$t !== '' ? $t : 'ppmp'] ?? 'PPMP');
}

/** Where the PU stands on editing this Non-PS list / APP-CSE: own | none | pending | granted | declined */
function pu_doc_edit_state(string $type, string $ref, string $dept, ?array $u = null): array {
    ensure_edit_request_columns();
    $u = $u ?? (function_exists('current_user') ? current_user() : null);
    if ($u && (string)($u['dept_code'] ?? '') !== '' && (string)$u['dept_code'] === $dept) return ['state' => 'own', 'req' => null];
    $req = null;
    try {
        $s = db()->prepare('SELECT * FROM edit_requests WHERE doc_type = ? AND doc_ref = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1');
        $s->execute([$type, $ref]);
        $req = $s->fetch() ?: null;
    } catch (Throwable $t) {}
    if (!$req) return ['state' => 'none', 'req' => null];
    $st = (string)$req['status'];
    return ['state' => in_array($st, ['pending', 'granted', 'declined'], true) ? $st : 'none', 'req' => $req];
}

/** May the PU edit it right now? ($withPu: the document is with the PU, submitted and not yet approved) */
function pu_doc_edit_allowed(string $type, string $ref, string $dept, bool $withPu, ?array $u = null): bool {
    if (!$withPu) return false;
    return in_array(pu_doc_edit_state($type, $ref, $dept, $u)['state'], ['own', 'granted'], true);
}

/** The document left the PU's hands (approved, or sent back): its permission ends. */
function pu_doc_edit_close(string $type, string $ref): void {
    ensure_edit_request_columns();
    try {
        db()->prepare('UPDATE edit_requests SET closed_at = NOW() WHERE doc_type = ? AND doc_ref = ? AND closed_at IS NULL')->execute([$type, $ref]);
    } catch (Throwable $t) {}
}
