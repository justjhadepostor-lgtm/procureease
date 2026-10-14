<?php
/* ═══════════════════════════════════════════════════════════════
   v104: Market Scoping — pictures, each with the words that go under it.

   Up to 10 pictures a document. On the printout they come at the end of
   the document, in order, each with its words under it, and each kept
   whole: when one still fits on the page it stays there, when it does not
   it moves to the next page together with its words.

   v101/v103 kept one picture on the document itself
   (market_scoping.signatory_image / signatory_caption). That one becomes
   the first picture here the first time the document is opened.
   =============================================================== */
require_once __DIR__ . '/db.php';

const MS_PHOTO_MAX  = 10;
const MS_PHOTO_DIR  = 'uploads/signatory-images';
const MS_PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

/** The table, once. */
function ms_photos_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $sqlite = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        db()->exec($sqlite
            ? "CREATE TABLE IF NOT EXISTS market_scoping_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, scoping_id INTEGER NOT NULL,
                 path VARCHAR(255) NOT NULL, caption VARCHAR(500) NULL, sort_order INTEGER DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
            : "CREATE TABLE IF NOT EXISTS market_scoping_photos (id INT PRIMARY KEY AUTO_INCREMENT, scoping_id INT NOT NULL,
                 path VARCHAR(255) NOT NULL, caption VARCHAR(500) NULL, sort_order INT DEFAULT 0,
                 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_scoping (scoping_id)) ENGINE=InnoDB");
    } catch (Throwable $t) {}
}

/** The pictures of a document, in order: [['id','path','caption'], …]. */
function ms_photos(int $scopingId): array {
    if ($scopingId <= 0) return [];
    ms_photos_table();
    $pdo = db();
    // the one picture of v101/v103 comes over the first time
    try {
        $s = $pdo->prepare('SELECT signatory_image, signatory_caption FROM market_scoping WHERE id = ?');
        $s->execute([$scopingId]);
        $old = $s->fetch();
        if ($old && trim((string)($old['signatory_image'] ?? '')) !== '') {
            $pdo->prepare('INSERT INTO market_scoping_photos (scoping_id, path, caption, sort_order) VALUES (?, ?, ?, 1)')
                ->execute([$scopingId, (string)$old['signatory_image'], ($old['signatory_caption'] ?? null) ?: null]);
            $pdo->prepare('UPDATE market_scoping SET signatory_image = NULL, signatory_caption = NULL WHERE id = ?')->execute([$scopingId]);
        }
    } catch (Throwable $t) {}
    try {
        $s = $pdo->prepare('SELECT id, path, caption FROM market_scoping_photos WHERE scoping_id = ? ORDER BY sort_order, id');
        $s->execute([$scopingId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'path' => (string)$r['path'], 'caption' => (string)($r['caption'] ?? '')], $s->fetchAll());
    } catch (Throwable $t) { return []; }
}

/**
 * Save what came with a save of the document: words changed, pictures
 * removed, new pictures (each with its words). Returns a message when
 * something could not be taken ('' otherwise).
 *   $p       the request (JSON or form): photo_caption {id: words}, photo_remove [id…], new_caption [words…]
 *   $files   $_FILES['photos'] (several) — or null
 */
function ms_photos_save(int $scopingId, array $p, ?array $files): string {
    if ($scopingId <= 0) return '';
    $have = ms_photos($scopingId);
    $pdo = db();
    $ids = array_column($have, 'id');
    $note = '';
    // words
    foreach ((array)($p['photo_caption'] ?? []) as $id => $cap) {
        if (!in_array((int)$id, $ids, true)) continue;
        $cap = mb_substr(trim((string)$cap), 0, 500);
        $pdo->prepare('UPDATE market_scoping_photos SET caption = ? WHERE id = ? AND scoping_id = ?')->execute([$cap !== '' ? $cap : null, (int)$id, $scopingId]);
    }
    // removed
    foreach ((array)($p['photo_remove'] ?? []) as $id) {
        $id = (int)$id;
        if (!in_array($id, $ids, true)) continue;
        $path = '';
        foreach ($have as $h) if ($h['id'] === $id) $path = $h['path'];
        $pdo->prepare('DELETE FROM market_scoping_photos WHERE id = ? AND scoping_id = ?')->execute([$id, $scopingId]);
        // the file too, when it is one of these pictures and no other row uses it
        if (strpos($path, MS_PHOTO_DIR . '/') === 0) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM market_scoping_photos WHERE path = ?');
            $s->execute([$path]);
            if ((int)$s->fetchColumn() === 0) @unlink(__DIR__ . '/../' . $path);
        }
    }
    // new ones
    if ($files && isset($files['name']) && is_array($files['name'])) {
        $count = count(ms_photos($scopingId));
        $caps = array_values((array)($p['new_caption'] ?? []));
        $s = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM market_scoping_photos WHERE scoping_id = ?');
        $s->execute([$scopingId]);
        $order = (int)$s->fetchColumn();
        $dir = __DIR__ . '/../' . MS_PHOTO_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $skipped = 0;
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { if (($files['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $skipped++; continue; }
            if ($count >= MS_PHOTO_MAX) { $skipped++; continue; }
            $tmp = (string)$files['tmp_name'][$i];
            $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : '';
            if (!isset(MS_PHOTO_TYPES[$mime]) || (int)$files['size'][$i] > 3 * 1024 * 1024) { $skipped++; continue; }
            $file = 'scoping-' . $scopingId . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . MS_PHOTO_TYPES[$mime];
            $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dir . '/' . $file) : @rename($tmp, $dir . '/' . $file);
            if (!$ok) { $skipped++; continue; }
            $cap = mb_substr(trim((string)($caps[$i] ?? '')), 0, 500);
            $pdo->prepare('INSERT INTO market_scoping_photos (scoping_id, path, caption, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$scopingId, MS_PHOTO_DIR . '/' . $file, $cap !== '' ? $cap : null, ++$order]);
            $count++;
        }
        if ($skipped) $note = "$skipped picture(s) could not be added — up to " . MS_PHOTO_MAX . ' pictures, JPG / PNG / WEBP / GIF, under 3 MB each.';
    }
    return $note;
}
