<?php
/* ═══════════════════════════════════════════════════════════════
   Editing the APP-CSE list — Document Format › APP-CSE.

   The Procurement Unit keeps one list of categories and items per fiscal
   year (appcse_catalogue_items). Every department's APP-CSE for that year
   was started from it, so a change made here is carried into those forms
   as well, under these rules:

   · A category's name, and an item's code, description and unit, reach
     every department's APP-CSE for the year — including ones already under
     review — so that the same item is still one line on the consolidated
     APP-CSE. A department that had already changed that field on its own
     form keeps its own wording.

   · An item's unit price reaches only the APP-CSE that are still being
     prepared. Once a department has submitted, the amounts on its form are
     what Budget and the Procurement Unit are reviewing, and they are not
     changed underneath them.

   · A new item, or a new category, is added to every department's APP-CSE
     with no quantity, so no amount anywhere changes.

   · An item or a category can only be removed while no department has a
     quantity on it — and while the consolidated APP-CSE has none on it
     either. Removing it can never take away something a department asked
     for.

   · Quantities are never touched.

   Every function returns ['ok' => bool, 'msg' => string].
   =============================================================== */
require_once __DIR__ . '/appcse-items.php';

const APPCSE_MONTH_SUM = '(COALESCE(`jan`,0)+COALESCE(`feb`,0)+COALESCE(`mar`,0)+COALESCE(`apr`,0)'
                       . '+COALESCE(`may`,0)+COALESCE(`jun`,0)+COALESCE(`jul`,0)+COALESCE(`aug`,0)'
                       . '+COALESCE(`sep`,0)+COALESCE(`oct`,0)+COALESCE(`nov`,0)+COALESCE(`dec`,0))';

/** One space between words, nothing at either end. */
function appcse_clean_text(?string $s): string {
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

/** A category name as typed, or an error. */
function appcse_clean_category(?string $name): array {
    $n = appcse_clean_text($name);
    if ($n === '')              return [null, 'Type a name for the category.'];
    if (mb_strlen($n) > 150)    return [null, 'A category name can be at most 150 characters.'];
    return [$n, null];
}

/** An item as typed — [code, description, unit, unit_price] — or an error. */
function appcse_clean_item(array $in): array {
    $code = appcse_clean_text($in['code'] ?? '');
    $desc = appcse_clean_text($in['description'] ?? '');
    $unit = appcse_clean_text($in['unit'] ?? '');
    $raw  = str_replace([',', '₱', ' '], '', (string)($in['unit_price'] ?? ''));

    if ($desc === '')             return [null, 'Type the item and its specifications.'];
    if (mb_strlen($desc) > 255)   return [null, 'The item description can be at most 255 characters.'];
    if (mb_strlen($code) > 60)    return [null, 'The item code can be at most 60 characters.'];
    if ($unit === '')             return [null, 'Type the unit of measure (for example: piece, box, ream).'];
    if (mb_strlen($unit) > 40)    return [null, 'The unit of measure can be at most 40 characters.'];
    if ($raw === '' || !is_numeric($raw))
                                  return [null, 'The price must be a number, for example 58.90.'];
    $price = round((float)$raw, 2);
    if ($price < 0)               return [null, 'The price cannot be below zero.'];
    if ($price > 999999999999.99) return [null, 'The price is too large.'];
    return [['code' => $code, 'description' => $desc, 'unit' => $unit, 'unit_price' => $price], null];
}

/** Departments whose APP-CSE is under review for the year. */
function appcse_locked_depts(PDO $pdo, int $fy): array {
    try {
        $ph = implode(',', array_fill(0, count(APPCSE_LOCKED_STATUSES), '?'));
        $s  = $pdo->prepare("SELECT dept_code FROM ppmps WHERE fy = ? AND status IN ($ph)");
        $s->execute(array_merge([$fy], APPCSE_LOCKED_STATUSES));
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $t) {
        return [];
    }
}

/** Departments that already have an APP-CSE for the year. */
function appcse_seeded_depts(PDO $pdo, int $fy): array {
    try {
        $s = $pdo->prepare('SELECT DISTINCT dept_code FROM appcse_items WHERE fy = ?');
        $s->execute([$fy]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $t) {
        return [];
    }
}

/**
 * The names of the departments that have a quantity on these lines.
 * $match is ['code' => ..] or ['description' => ..], or [] for the whole
 * category.
 */
function appcse_qty_holders(PDO $pdo, int $fy, string $part, string $cat, array $match = []): array {
    $sql  = "SELECT DISTINCT i.dept_code, d.name
               FROM appcse_items i LEFT JOIN departments d ON d.code = i.dept_code
              WHERE i.fy = ? AND i.`part` = ? AND i.`category` = ?";
    $args = [$fy, $part, $cat];
    foreach ($match as $f => $v) { $sql .= " AND i.$f = ?"; $args[] = $v; }
    $sql .= ' AND ' . preg_replace('/`(\w+)`/', 'i.`$1`', APPCSE_MONTH_SUM) . ' <> 0';
    try {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        $out = [];
        foreach ($s->fetchAll() as $r) $out[] = (string)($r['name'] ?: $r['dept_code']);
        sort($out);
        return $out;
    } catch (Throwable $t) {
        return ['(could not be checked)'];
    }
}

/** True when the consolidated APP-CSE carries a quantity of the PU's own here. */
function appcse_consol_has_qty(PDO $pdo, int $fy, string $part, string $cat, ?string $itemKey = null): bool {
    appcse_catalogue_tables($pdo);
    $sql  = "SELECT COUNT(*) FROM appcse_consol_edits
              WHERE fy = ? AND `part` = ? AND `category` = ? AND hidden = 0
                AND " . APPCSE_MONTH_SUM . " <> 0";
    $args = [$fy, $part, $cat];
    if ($itemKey !== null) { $sql .= ' AND is_added = 0 AND item_key = ?'; $args[] = $itemKey; }
    try {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $t) {
        return true;       // cannot tell — be safe and keep it
    }
}

function appcse_category_exists(PDO $pdo, int $fy, string $part, string $name, ?string $except = null): bool {
    $sql  = 'SELECT COUNT(*) FROM appcse_catalogue_items WHERE fy = ? AND `part` = ? AND UPPER(`category`) = UPPER(?)';
    $args = [$fy, $part, $name];
    if ($except !== null) { $sql .= ' AND `category` <> ?'; $args[] = $except; }
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return (int)$s->fetchColumn() > 0;
}

function appcse_code_taken(PDO $pdo, int $fy, string $code, int $exceptId = 0): bool {
    if ($code === '') return false;
    $s = $pdo->prepare('SELECT COUNT(*) FROM appcse_catalogue_items WHERE fy = ? AND UPPER(code) = UPPER(?) AND id <> ?');
    $s->execute([$fy, $code, $exceptId]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Make room for one row right after the last row of a category (or, for a
 * new category, after the last row of its part) and return the position.
 * $dept === null works on the list itself; otherwise on that department's
 * APP-CSE.
 */
function appcse_make_room(PDO $pdo, int $fy, string $part, string $cat, ?string $dept): int {
    $tbl  = $dept === null ? 'appcse_catalogue_items' : 'appcse_items';
    $base = 'fy = ?' . ($dept === null ? '' : ' AND dept_code = ?');
    $args = $dept === null ? [$fy] : [$fy, $dept];

    $q = $pdo->prepare("SELECT MAX(sort_order) FROM $tbl WHERE $base AND `part` = ? AND `category` = ?");
    $q->execute(array_merge($args, [$part, $cat]));
    $last = $q->fetchColumn();
    if ($last === null || $last === false) {
        $q = $pdo->prepare("SELECT MAX(sort_order) FROM $tbl WHERE $base AND `part` = ?");
        $q->execute(array_merge($args, [$part]));
        $last = $q->fetchColumn();
    }
    if ($last === null || $last === false) {
        // nothing in this part yet: Part I goes first, Part II after everything
        if ($part === 'I') {
            $last = -1;
        } else {
            $q = $pdo->prepare("SELECT MAX(sort_order) FROM $tbl WHERE $base");
            $q->execute($args);
            $m = $q->fetchColumn();
            $last = ($m === null || $m === false) ? -1 : (int)$m;
        }
    }
    $last = (int)$last;
    $pdo->prepare("UPDATE $tbl SET sort_order = sort_order + 1 WHERE $base AND sort_order > ?")
        ->execute(array_merge($args, [$last]));
    return $last + 1;
}

/** Put one new list item into every department's APP-CSE, with no quantity. */
function appcse_push_item(PDO $pdo, int $fy, string $part, string $cat, array $it, string $by): int {
    $n = 0;
    foreach (appcse_seeded_depts($pdo, $fy) as $dept) {
        // a department that already carries this item keeps its own line
        $f = $it['code'] !== '' ? 'code' : 'description';
        $c = $pdo->prepare("SELECT COUNT(*) FROM appcse_items
                             WHERE fy = ? AND dept_code = ? AND `part` = ? AND `category` = ? AND UPPER($f) = UPPER(?)");
        $c->execute([$fy, $dept, $part, $cat, $it[$f]]);
        if ((int)$c->fetchColumn() > 0) continue;

        $pos = appcse_make_room($pdo, $fy, $part, $cat, $dept);
        $pdo->prepare("INSERT INTO appcse_items
                         (fy, dept_code, `part`, `category`, code, description, unit, unit_price, sort_order, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$fy, $dept, $part, $cat, $it['code'], $it['description'], $it['unit'],
                       $it['unit_price'], $pos, $by]);
        $n++;
    }
    return $n;
}

function appcse_plural(int $n, string $one, string $many): string {
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/* ─── Categories ─────────────────────────────────────────────── */

function appcse_category_rename(PDO $pdo, int $fy, string $part, string $old, ?string $new, string $by): array {
    [$name, $err] = appcse_clean_category($new);
    if ($err) return ['ok' => false, 'msg' => $err];
    if ($name === $old) return ['ok' => true, 'msg' => 'Nothing to change — the name is the same.'];
    if (appcse_category_exists($pdo, $fy, $part, $name, $old)) {
        return ['ok' => false, 'msg' => "Part $part already has a category named “{$name}”."];
    }
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('UPDATE appcse_catalogue_items SET `category` = ?, updated_by = ? WHERE fy = ? AND `part` = ? AND `category` = ?');
        $s->execute([$name, $by, $fy, $part, $old]);
        if ($s->rowCount() === 0) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'That category is no longer on the list.']; }

        $d = $pdo->prepare('SELECT COUNT(DISTINCT dept_code) FROM appcse_items WHERE fy = ? AND `part` = ? AND `category` = ?');
        $d->execute([$fy, $part, $old]);
        $depts = (int)$d->fetchColumn();
        $pdo->prepare('UPDATE appcse_items SET `category` = ? WHERE fy = ? AND `part` = ? AND `category` = ?')
            ->execute([$name, $fy, $part, $old]);

        // the consolidated APP-CSE's own edits follow the line to its new name
        $pdo->prepare("UPDATE appcse_consol_edits
                          SET `category` = ?, line_key = CONCAT(`part`, '|', ?, '|', item_key)
                        WHERE fy = ? AND `part` = ? AND `category` = ? AND is_added = 0")
            ->execute([$name, $name, $fy, $part, $old]);
        $pdo->prepare('UPDATE appcse_consol_edits SET `category` = ? WHERE fy = ? AND `part` = ? AND `category` = ? AND is_added = 1')
            ->execute([$name, $fy, $part, $old]);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The category could not be renamed. Nothing was changed.'];
    }
    log_activity('Renamed APP-CSE category', 'format', 'appcse', "FY $fy, Part $part: '{$old}' -> '{$name}'");
    return ['ok' => true, 'msg' => "Renamed to “{$name}”"
        . ($depts ? ' on the list and in ' . appcse_plural($depts, 'department APP-CSE', 'department APP-CSEs') . '.' : '.')];
}

function appcse_category_add(PDO $pdo, int $fy, string $part, ?string $name, array $first, string $by): array {
    $part = ($part === 'II') ? 'II' : 'I';
    [$name, $err] = appcse_clean_category($name);
    if ($err) return ['ok' => false, 'msg' => $err];
    [$it, $err] = appcse_clean_item($first);
    if ($err) return ['ok' => false, 'msg' => 'First item: ' . $err];
    if (appcse_category_exists($pdo, $fy, $part, $name)) {
        return ['ok' => false, 'msg' => "Part $part already has a category named “{$name}”."];
    }
    if (appcse_code_taken($pdo, $fy, $it['code'])) {
        return ['ok' => false, 'msg' => "The code {$it['code']} is already used by another item on the list."];
    }
    $pdo->beginTransaction();
    try {
        $pos = appcse_make_room($pdo, $fy, $part, $name, null);
        $pdo->prepare("INSERT INTO appcse_catalogue_items
                         (fy, `part`, `category`, code, description, unit, unit_price, sort_order, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$fy, $part, $name, $it['code'], $it['description'], $it['unit'], $it['unit_price'], $pos, $by]);
        $n = appcse_push_item($pdo, $fy, $part, $name, $it, $by);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The category could not be added. Nothing was changed.'];
    }
    log_activity('Added APP-CSE category', 'format', 'appcse', "FY $fy, Part $part: '{$name}' with '{$it['description']}'");
    return ['ok' => true, 'msg' => "Added “{$name}” to Part $part"
        . ($n ? ', and to ' . appcse_plural($n, 'department APP-CSE', 'department APP-CSEs') . ' with no quantity yet.' : '.')];
}

function appcse_category_delete(PDO $pdo, int $fy, string $part, string $name, string $by): array {
    $holders = appcse_qty_holders($pdo, $fy, $part, $name);
    if ($holders) {
        return ['ok' => false, 'msg' => "“{$name}” was not removed: " . implode(', ', $holders)
            . (count($holders) === 1 ? ' has' : ' have') . " quantities on it for FY $fy."];
    }
    if (appcse_consol_has_qty($pdo, $fy, $part, $name)) {
        return ['ok' => false, 'msg' => "“{$name}” was not removed: the consolidated APP-CSE has quantities on it."];
    }
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('DELETE FROM appcse_catalogue_items WHERE fy = ? AND `part` = ? AND `category` = ?');
        $s->execute([$fy, $part, $name]);
        if ($s->rowCount() === 0) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'That category is no longer on the list.']; }
        $pdo->prepare('DELETE FROM appcse_items WHERE fy = ? AND `part` = ? AND `category` = ?')->execute([$fy, $part, $name]);
        $pdo->prepare('DELETE FROM appcse_consol_edits WHERE fy = ? AND `part` = ? AND `category` = ?')->execute([$fy, $part, $name]);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The category could not be removed. Nothing was changed.'];
    }
    log_activity('Removed APP-CSE category', 'format', 'appcse', "FY $fy, Part $part: '{$name}'");
    return ['ok' => true, 'msg' => "Removed “{$name}” and its items from the FY $fy list and from every department's APP-CSE."];
}

/* ─── Items ──────────────────────────────────────────────────── */

function appcse_item_add(PDO $pdo, int $fy, string $part, string $cat, array $in, string $by): array {
    [$it, $err] = appcse_clean_item($in);
    if ($err) return ['ok' => false, 'msg' => $err];
    $chk = $pdo->prepare('SELECT COUNT(*) FROM appcse_catalogue_items WHERE fy = ? AND `part` = ? AND `category` = ?');
    $chk->execute([$fy, $part, $cat]);
    if ((int)$chk->fetchColumn() === 0) return ['ok' => false, 'msg' => 'That category is no longer on the list.'];
    if (appcse_code_taken($pdo, $fy, $it['code'])) {
        return ['ok' => false, 'msg' => "The code {$it['code']} is already used by another item on the list."];
    }
    $pdo->beginTransaction();
    try {
        $pos = appcse_make_room($pdo, $fy, $part, $cat, null);
        $pdo->prepare("INSERT INTO appcse_catalogue_items
                         (fy, `part`, `category`, code, description, unit, unit_price, sort_order, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$fy, $part, $cat, $it['code'], $it['description'], $it['unit'], $it['unit_price'], $pos, $by]);
        $n = appcse_push_item($pdo, $fy, $part, $cat, $it, $by);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The item could not be added. Nothing was changed.'];
    }
    log_activity('Added APP-CSE item', 'format', 'appcse', "FY $fy, '{$cat}': " . trim($it['code'] . ' ' . $it['description']));
    return ['ok' => true, 'msg' => "Added “{$it['description']}”"
        . ($n ? ' to the list and to ' . appcse_plural($n, 'department APP-CSE', 'department APP-CSEs') . ' with no quantity yet.' : ' to the list.')];
}

function appcse_item_get(PDO $pdo, int $fy, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM appcse_catalogue_items WHERE id = ? AND fy = ?');
    $s->execute([$id, $fy]);
    return $s->fetch() ?: null;
}

function appcse_item_update(PDO $pdo, int $fy, int $id, array $in, string $by): array {
    $old = appcse_item_get($pdo, $fy, $id);
    if (!$old) return ['ok' => false, 'msg' => 'That item is no longer on the list.'];
    [$it, $err] = appcse_clean_item($in);
    if ($err) return ['ok' => false, 'msg' => $err];
    if (appcse_code_taken($pdo, $fy, $it['code'], $id)) {
        return ['ok' => false, 'msg' => "The code {$it['code']} is already used by another item on the list."];
    }

    $oCode = (string)$old['code']; $oDesc = (string)$old['description'];
    $oUnit = (string)$old['unit']; $oPrice = round((float)$old['unit_price'], 2);
    $same = $it['code'] === $oCode && $it['description'] === $oDesc
         && $it['unit'] === $oUnit && abs($it['unit_price'] - $oPrice) < 0.005;
    if ($same) return ['ok' => true, 'msg' => 'Nothing to change — the item is the same.'];

    $part = (string)$old['part']; $cat = (string)$old['category'];
    // A department's line is this item when it carries the item's code — or,
    // for an item with no code, its description.
    $byCode = $oCode !== '';
    $where  = 'fy = ? AND `part` = ? AND `category` = ? AND ' . ($byCode ? 'code = ?' : 'description = ?');
    $wargs  = [$fy, $part, $cat, $byCode ? $oCode : $oDesc];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE appcse_catalogue_items SET code = ?, description = ?, unit = ?, unit_price = ?, updated_by = ? WHERE id = ?')
            ->execute([$it['code'], $it['description'], $it['unit'], $it['unit_price'], $by, $id]);

        $c = $pdo->prepare("SELECT COUNT(DISTINCT dept_code) FROM appcse_items WHERE $where");
        $c->execute($wargs);
        $depts = (int)$c->fetchColumn();

        // Each field is carried only where the department still has the
        // list's old value, so its own wording is kept.
        if ($it['unit'] !== $oUnit) {
            $pdo->prepare("UPDATE appcse_items SET unit = ? WHERE $where AND unit = ?")
                ->execute(array_merge([$it['unit']], $wargs, [$oUnit]));
        }
        $priceHeld = 0;
        if (abs($it['unit_price'] - $oPrice) >= 0.005) {
            // Only forms still being prepared: a submitted form's amounts are
            // what is being reviewed.
            $locked = appcse_locked_depts($pdo, $fy);
            $sql  = "UPDATE appcse_items SET unit_price = ? WHERE $where AND ABS(unit_price - ?) < 0.005";
            $args = array_merge([$it['unit_price']], $wargs, [$oPrice]);
            if ($locked) {
                $sql .= ' AND dept_code NOT IN (' . implode(',', array_fill(0, count($locked), '?')) . ')';
                $args = array_merge($args, $locked);
                $h = $pdo->prepare("SELECT COUNT(DISTINCT dept_code) FROM appcse_items WHERE $where AND dept_code IN ("
                                   . implode(',', array_fill(0, count($locked), '?')) . ')');
                $h->execute(array_merge($wargs, $locked));
                $priceHeld = (int)$h->fetchColumn();
            }
            $pdo->prepare($sql)->execute($args);
        }
        if ($byCode) {
            if ($it['description'] !== $oDesc) {
                $pdo->prepare("UPDATE appcse_items SET description = ? WHERE $where AND description = ?")
                    ->execute(array_merge([$it['description']], $wargs, [$oDesc]));
            }
            if ($it['code'] !== $oCode) {           // the key itself, last
                $pdo->prepare("UPDATE appcse_items SET code = ? WHERE $where")
                    ->execute(array_merge([$it['code']], $wargs));
            }
        } else {
            if ($it['code'] !== '') {
                $pdo->prepare("UPDATE appcse_items SET code = ? WHERE $where AND (code = '' OR code IS NULL)")
                    ->execute(array_merge([$it['code']], $wargs));
            }
            if ($it['description'] !== $oDesc) {    // the key itself, last
                $pdo->prepare("UPDATE appcse_items SET description = ? WHERE $where")
                    ->execute(array_merge([$it['description']], $wargs));
            }
        }

        // the consolidated APP-CSE's own edits follow the line
        $oKey = $byCode ? $oCode : $oDesc;
        $nKey = $it['code'] !== '' ? $it['code'] : $it['description'];
        if ($nKey !== $oKey) {
            $pdo->prepare("UPDATE appcse_consol_edits
                              SET item_key = ?, line_key = CONCAT(`part`, '|', `category`, '|', ?)
                            WHERE fy = ? AND `part` = ? AND `category` = ? AND item_key = ? AND is_added = 0")
                ->execute([$nKey, $nKey, $fy, $part, $cat, $oKey]);
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The item could not be saved. Nothing was changed.'];
    }

    log_activity('Edited APP-CSE item', 'format', 'appcse', "FY $fy, '{$cat}': " . trim($oCode . ' ' . $oDesc)
        . ' -> ' . trim($it['code'] . ' ' . $it['description']) . ', ' . $it['unit'] . ', PHP ' . number_format($it['unit_price'], 2));
    $msg = "Saved “{$it['description']}”"
         . ($depts ? ' on the list and in ' . appcse_plural($depts, 'department APP-CSE', 'department APP-CSEs') . '.' : '.');
    if ($priceHeld) {
        $msg .= ' The new price was not applied to ' . appcse_plural($priceHeld, 'APP-CSE', 'APP-CSEs')
              . ' already submitted for review.';
    }
    return ['ok' => true, 'msg' => $msg];
}

function appcse_item_delete(PDO $pdo, int $fy, int $id, string $by): array {
    $old = appcse_item_get($pdo, $fy, $id);
    if (!$old) return ['ok' => false, 'msg' => 'That item is no longer on the list.'];
    $part = (string)$old['part']; $cat = (string)$old['category'];
    $byCode = (string)$old['code'] !== '';
    $match  = $byCode ? ['code' => (string)$old['code']] : ['description' => (string)$old['description']];
    $label  = (string)$old['description'];

    $holders = appcse_qty_holders($pdo, $fy, $part, $cat, $match);
    if ($holders) {
        return ['ok' => false, 'msg' => "“{$label}” was not removed: " . implode(', ', $holders)
            . (count($holders) === 1 ? ' has' : ' have') . " a quantity on it for FY $fy."];
    }
    $key = $byCode ? (string)$old['code'] : $label;
    if (appcse_consol_has_qty($pdo, $fy, $part, $cat, $key)) {
        return ['ok' => false, 'msg' => "“{$label}” was not removed: the consolidated APP-CSE has a quantity on it."];
    }
    $f = array_key_first($match);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM appcse_catalogue_items WHERE id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM appcse_items WHERE fy = ? AND `part` = ? AND `category` = ? AND $f = ?")
            ->execute([$fy, $part, $cat, $match[$f]]);
        $pdo->prepare('DELETE FROM appcse_consol_edits WHERE fy = ? AND `part` = ? AND `category` = ? AND item_key = ? AND is_added = 0')
            ->execute([$fy, $part, $cat, $key]);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'The item could not be removed. Nothing was changed.'];
    }
    log_activity('Removed APP-CSE item', 'format', 'appcse', "FY $fy, '{$cat}': " . trim($old['code'] . ' ' . $label));
    return ['ok' => true, 'msg' => "Removed “{$label}” from the FY $fy list and from every department's APP-CSE."];
}
