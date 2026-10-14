<?php
/* The positions offered wherever a position is picked (registration, and the
   Super Admin's Profile), in this order; "Others" after them lets someone
   type a position that is not on the list.

   v78: the Super Admin keeps this list on User Management (add / remove).
   It is stored in the settings table (key "reg_positions", a JSON list);
   until it is first changed there, the list below is used. Taking a position
   off the list does not change anyone's position on file. */
require_once __DIR__ . '/db.php';

const REG_POSITIONS = [
    'SUC President',
    'Vice President for Academic Affairs',
    'Vice President for Planning and Finance',
    'Board Secretary',
    'Director',
    'Dean',
    'Unit Head',
    'Attorney IV',
    'Internal Auditor',
    'Registrar',
    'Planning Officer',
    'Information Officer',
    'Accountant',
    'Administrative Officer',
    'Associate Professor',
    'Assistant Professor',
    'Instructor',
    'Legal Assistant',
    'Nurse',
    'Laboratory Technician',
];

const REG_POSITIONS_KEY = 'reg_positions';
const REG_POSITION_MAX  = 100;   // the same limit as a position typed under "Others"

/** The positions on the list now, in order. */
function reg_positions(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = REG_POSITIONS;
    try {
        $s = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([REG_POSITIONS_KEY]);
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null) {
            $list = json_decode((string)$v, true);
            if (is_array($list)) $cache = array_values(array_filter(array_map('strval', $list), fn($p) => $p !== ''));
            if (is_array($list)) $cache = reg_positions_v91($cache);
        }
    } catch (Throwable $t) {}
    return $cache;
}

/** A position as it is kept: spaces tidied, at most REG_POSITION_MAX characters. */
function reg_position_clean(string $p): string {
    return trim(preg_replace('/\s+/u', ' ', $p));
}

/** Is $p on the list already (any letter case)? */
function reg_position_index(string $p, ?array $list = null): int {
    $key = mb_strtolower(reg_position_clean($p));
    foreach (($list ?? reg_positions()) as $i => $x) if (mb_strtolower($x) === $key) return $i;
    return -1;
}

/** Keeps the list for everyone. */
function reg_positions_save(array $list): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([REG_POSITIONS_KEY]);
    $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')
        ->execute([REG_POSITIONS_KEY, json_encode(array_values($list), JSON_UNESCAPED_UNICODE)]);
    reg_positions(true);
}

/** v91, once: a list already kept on User Management gets the new names
    ("Vice President for …") and "Unit Head" after "Dean". */
function reg_positions_v91(array $list): array {
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM settings WHERE key_name = ?');
        $s->execute(['reg_positions_v91']);
        if ((int)$s->fetchColumn() > 0) return $list;
        $ren = ['office of the vice president for academic affairs'    => 'Vice President for Academic Affairs',
                'office of the vice president for planning and finance' => 'Vice President for Planning and Finance'];
        $out = [];
        foreach ($list as $p) {
            $p = $ren[mb_strtolower($p)] ?? $p;
            if (reg_position_index($p, $out) < 0) $out[] = $p;
        }
        if (reg_position_index('Unit Head', $out) < 0) {
            $at = reg_position_index('Dean', $out);
            array_splice($out, $at < 0 ? count($out) : $at + 1, 0, ['Unit Head']);
        }
        db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute(['reg_positions_v91', '1']);
        if ($out !== $list) {
            db()->prepare('UPDATE settings SET value = ? WHERE key_name = ?')
                ->execute([json_encode($out, JSON_UNESCAPED_UNICODE), REG_POSITIONS_KEY]);
        }
        return $out;
    } catch (Throwable $t) { return $list; }
}
