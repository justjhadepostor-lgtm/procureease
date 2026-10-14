<?php
/* ═══════════════════════════════════════════════════════════════
   "Working on" (v81 / v83, moved in v87): which program / major of a school,
   or which set of an office, the signed-in account is working on.

   Shown only to an account with more than one, and only where it matters —
   in the heading of its document pages (PPMP, APP-CSE, Non-PS list, Annex,
   Market Scoping, My PPMPs, My Department) — as a small dropdown like the
   fiscal-year one. Not in the side bar.
   =============================================================== */

/** The dropdown (empty when the account has only one to work on).
    v104: $action — a page to go to after choosing (with $fy), e.g. the PPMP from its file view. */
function unit_pick_html(?array $u, string $action = '', int $fy = 0): string {
    if (!$u || count($u['scope_units'] ?? []) < 2) return '';
    static $css = false;
    $h = '';
    if (!$css) {
        $css = true;
        $h .= '<style>
          .unit-pick { display:flex; align-items:center; gap:8px; margin:10px 0 0; flex-wrap:wrap; }
          .unit-pick label { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
          .unit-pick select { min-width:220px; max-width:100%; }
        </style>';
    }
    $h .= '<form method="GET" class="unit-pick" id="unitPick" action="' . e($action) . '">'
        . ($action !== '' && $fy > 0 ? '<input type="hidden" name="fy" value="' . $fy . '">' : '')
        . '<label for="unitSelect">Working on</label>'
        . '<select id="unitSelect" name="unit" class="filter-select" onchange="this.form.submit()">';
    $last = null;
    foreach ($u['scope_units'] as $c) {
        $r = au_row($c);
        $prog = (string)($r['program_code'] ?? '');
        if ($prog !== '' && $prog !== $last) {
            if ($last !== null) $h .= '</optgroup>';
            $h .= '<optgroup label="' . e($prog . ' – ' . ($r['program_name'] ?? '')) . '">';
            $last = $prog;
        }
        $h .= '<option value="' . e($c) . '"' . (strcasecmp($c, (string)$u['dept_code']) === 0 ? ' selected' : '') . '>' . e(au_label($c)) . '</option>';
    }
    if ($last !== null) $h .= '</optgroup>';
    $h .= '</select><noscript><button type="submit" class="btn btn-ghost">Go</button></noscript></form>';
    return $h;
}

/* ═══════════════════════════════════════════════════════════════
   v99: the sets of an office, kept from the top of the PPMP — in place of
   "My Sets" in the side menu. For everyone of an office (end users,
   Department Admins, the Budget Unit, the Procurement Unit):
     Working on [Set 1 ▾]   ＋ Add Set   Remove this set
   · Working on: which set is being worked on — the PPMP, APP-CSE, Non-PS
     list, Annex and Market Scoping all follow it (as before).
   · ＋ Add Set: once the office's last set has been submitted for the year
     (config/office-sets.php os_can_add); until then it says which to submit first.
   · Remove this set: Set 2 / 3, nothing of it submitted, by whoever submits
     for the office (os_remove_set).
   A school with programs and majors keeps its own "Working on" (unit_pick_html).
   =============================================================== */
function set_bar_html(?array $u, int $fy, bool $fallback = true): string {
    if (!$u) return '';
    require_once __DIR__ . '/../config/office-sets.php';
    $office = (string)($u['school_code'] ?? ($u['dept_code'] ?? ''));
    if (!in_array((string)($u['role'] ?? ''), ['user', 'dept_admin', 'admin', 'budget'], true) || !os_eligible($office)) {
        return $fallback ? unit_pick_html($u) : '';
    }
    $active = (string)($u['dept_code'] ?? $office);
    $family = os_family($office);
    $h = '<style>
      .set-bar { display:flex; align-items:center; gap:8px; margin:10px 0 0; flex-wrap:wrap; }
      .set-bar form { margin:0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
      .set-bar label { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
      .set-bar select { min-width:200px; max-width:100%; }
      .set-bar .btn { padding:5px 11px; font-size:12px; }
      .set-bar .set-wait { font-size:11.5px; color:var(--muted); }
      .set-bar .set-remove { color:#b91c1c; }
      body[data-theme="dark"] .set-bar .set-remove { color:#fca5a5; }
    </style>';
    $h .= '<div class="set-bar" id="setBar">';
    $h .= '<form method="GET" action="ppmp-edit.php" id="unitPick">'
        . '<input type="hidden" name="fy" value="' . (int)$fy . '">'
        . '<label for="unitSelect">Working on</label>'
        . '<select id="unitSelect" name="unit" class="filter-select" onchange="this.form.submit()"' . (count($family) < 2 ? ' title="Only Set 1 so far"' : '') . '>';
    foreach ($family as $c) {
        $n = os_is_set($c) ? (int)(au_row($c)['set_no'] ?? 2) : 1;
        $h .= '<option value="' . e($c) . '"' . (strcasecmp($c, $active) === 0 ? ' selected' : '') . '>Set ' . $n . ' — ' . e(os_unit_name($c)) . '</option>';
    }
    $h .= '</select><noscript><button type="submit" class="btn btn-ghost">Go</button></noscript></form>';
    $csrf = function_exists('csrf_field') ? csrf_field() : '';
    [$canAdd, $why] = os_can_add($office, $fy);
    if ($canAdd) {
        $h .= '<form method="POST" action="api/office-sets.php" id="addSetForm">' . $csrf
            . '<input type="hidden" name="do" value="add"><input type="hidden" name="back" value="ppmp">'
            . '<button type="submit" class="btn btn-ghost" id="addSet">＋ Add Set</button></form>';
    } else {
        $h .= '<span class="set-wait" id="addSetWait">＋ Add Set · ' . e($why) . '</span>';
    }
    if (os_is_set($active) && can_submit_for_dept($u) && !os_set_sent($active)) {
        $n = (int)(au_row($active)['set_no'] ?? 2);
        $h .= '<form method="POST" action="api/office-sets.php" id="removeSetForm"'
            . ' data-confirm="Set ' . $n . ' will be taken off. Nothing of it was submitted; its drafts are kept and come back if a set is added again."'
            . ' data-confirm-title="Remove Set ' . $n . '?" data-confirm-label="Remove" data-confirm-tone="danger">' . $csrf
            . '<input type="hidden" name="do" value="remove"><input type="hidden" name="back" value="ppmp">'
            . '<input type="hidden" name="code" value="' . e($active) . '">'
            . '<button type="submit" class="btn btn-ghost set-remove" id="removeSet">Remove this set</button></form>';
    }
    return $h . '</div>';
}
