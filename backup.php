<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/backup-lib.php';   // pe_zip_write_encrypted(), pe_make_backup()
require_admin();
$u = current_user();
$activeNav = 'backup';
$pageTitle = 'Backup';

// Auto-create the activity_logs / settings / backups tables if they don't exist yet
ensure_admin_tables();

$pdo = db();
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);


// ─── Handle DOWNLOAD (streams file, does not render) ─────────
if (isset($_GET['download'])) {
    $fname = basename($_GET['download']);
    if (!preg_match('/^procureease-[\d\-]+\.(sql|zip)$/', $fname)) {
        flash('error', 'Invalid backup filename.');
        header('Location: backup.php'); exit;
    }
    $fpath = $backupDir . '/' . $fname;
    if (!is_file($fpath)) {
        flash('error', 'Backup file not found on disk.');
        header('Location: backup.php'); exit;
    }
    log_activity('Downloaded backup', 'backup', $fname, 'Downloaded backup file.');
    header('Content-Type: ' . (substr($fname, -4) === '.zip' ? 'application/zip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($fpath));
    readfile($fpath);
    exit;
}

// ─── Handle POST actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        try {
            // Ensure backup directory exists and is writable
            if (!is_dir($backupDir)) {
                if (!@mkdir($backupDir, 0755, true)) {
                    throw new Exception("Cannot create backups/ directory. Check folder permissions in XAMPP.");
                }
            }
            if (!is_writable($backupDir)) {
                throw new Exception("The backups/ directory is not writable. Check folder permissions in XAMPP.");
            }

            // The dump and the encrypted ZIP (config/backup-lib.php)
            $__b = pe_make_backup($pdo, (string)$u['username'], 'Encrypted ZIP backup by admin');
            $zipName = $__b['name']; $zipPath = $__b['path']; $size = $__b['size'];
            $tables = array_fill(0, $__b['tables'], ''); $totalRows = $__b['rows'];

            log_activity('Backed up database', 'backup', $zipName,
                "Encrypted ZIP: " . count($tables) . " tables, $totalRows rows, "
                . round($size / 1024, 1) . " KB.");

            flash('success', "Backup generated: $zipName (" . round($size / 1024, 1) . " KB, " . count($tables) . " tables, $totalRows rows).");

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            readfile($zipPath);
            exit;
        } catch (Throwable $t) {
            flash('error', 'Backup failed: ' . $t->getMessage());
        }
    } elseif ($action === 'set_password') {
        // v96: the password the backup .zip asks for
        [$__ok, $__msg] = backup_password_change((string)($_POST['current_password'] ?? ''), (string)($_POST['new_password'] ?? ''),
                                                 (string)($_POST['confirm_password'] ?? ''), (string)($u['full_name'] ?? ''));
        flash($__ok ? 'success' : 'error', $__msg);
        header('Location: backup.php#backupPassword'); exit;
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare('SELECT filename FROM backups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            @unlink($backupDir . '/' . $row['filename']);
            $pdo->prepare('DELETE FROM backups WHERE id = ?')->execute([$id]);
            log_activity('Deleted backup', 'backup', $row['filename'], 'Backup file deleted.');
            flash('success', 'Backup deleted.');
        } else {
            flash('error', 'Backup not found.');
        }

    } elseif ($action === 'restore_ppmp' && !empty($_POST['id'])) {
        // Restore a deleted PPMP from trash back into the live tables
        $tid = (int)$_POST['id'];
        $stmt = $pdo->prepare('SELECT * FROM deleted_ppmps WHERE id = ?');
        $stmt->execute([$tid]);
        $trash = $stmt->fetch();
        if ($trash) {
            try {
                $pdo->beginTransaction();
                $data = json_decode($trash['snapshot'], true);
                $pp = $data['ppmp'] ?? null;
                $entries = $data['entries'] ?? [];
                if ($pp) {
                    // Reinsert the PPMP (let it take a fresh id)
                    $cols = array_keys($pp);
                    $place = implode(', ', array_fill(0, count($cols), '?'));
                    $pdo->prepare('INSERT INTO ppmps (`' . implode('`, `', $cols) . '`) VALUES (' . $place . ')')
                        ->execute(array_values($pp));
                    $newId = (int)$pdo->lastInsertId();
                    foreach ($entries as $en) {
                        $en['ppmp_id'] = $newId;
                        unset($en['id']);
                        $ecols = array_keys($en);
                        $eplace = implode(', ', array_fill(0, count($ecols), '?'));
                        $pdo->prepare('INSERT INTO ppmp_entries (`' . implode('`, `', $ecols) . '`) VALUES (' . $eplace . ')')
                            ->execute(array_values($en));
                    }
                }
                $pdo->prepare('DELETE FROM deleted_ppmps WHERE id = ?')->execute([$tid]);
                $pdo->commit();
                log_activity('Restored PPMP from trash', 'ppmp', "{$trash['dept_code']} · FY {$trash['fy']}", 'Recovered from Backup & Restore.');
                flash('success', "Recovered {$trash['dept_code']} FY {$trash['fy']} back into the system.");
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('restore ppmp failed: ' . $t->getMessage());
                flash('error', 'Could not restore the PPMP. Please try again.');
            }
        } else {
            flash('error', 'Deleted PPMP not found.');
        }

    } elseif ($action === 'purge_ppmp' && !empty($_POST['id'])) {
        // Permanently delete a PPMP from trash — gone for good
        $tid = (int)$_POST['id'];
        $stmt = $pdo->prepare('SELECT dept_code, fy FROM deleted_ppmps WHERE id = ?');
        $stmt->execute([$tid]);
        $trash = $stmt->fetch();
        if ($trash) {
            $pdo->prepare('DELETE FROM deleted_ppmps WHERE id = ?')->execute([$tid]);
            log_activity('Permanently deleted PPMP', 'ppmp', "{$trash['dept_code']} · FY {$trash['fy']}",
                'Purged from trash — cannot be recovered.');
            flash('success', "{$trash['dept_code']} FY {$trash['fy']} has been permanently deleted. It cannot be recovered.");
        } else {
            flash('error', 'Deleted PPMP not found.');
        }
    }
    header('Location: backup.php');
    exit;
}

// Load the deleted-PPMP trash for display
$deletedPPMPs = $pdo->query("SELECT * FROM deleted_ppmps ORDER BY deleted_at DESC")->fetchAll();

// ─── Load data for rendering ─────────────────────────────────
$backups = $pdo->query('SELECT * FROM backups ORDER BY created_at DESC')->fetchAll();
$tables    = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$rowCount  = 0;
foreach ($tables as $t) {
    $rowCount += (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$lastBackup = $backups[0] ?? null;

function fmtSize(int $bytes): string {
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Backup</h1>
        <p class="page-sub">Generate, download, and manage database backups.</p>
      </div>
      <form method="POST" action="backup.php" data-no-loader style="margin: 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;"><?php /* v97: a download — the page stays, so no loading icon */ ?>
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Generate backup
        </button>
      </form>
    </div>
    <?php render_flashes(); ?>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Tables</div>
        <div class="stat-value"><?= count($tables) ?></div>
        <div class="stat-meta">Database schema tables</div>
      </div>
      <div class="stat">
        <div class="stat-label">Total rows</div>
        <div class="stat-value"><?= number_format($rowCount) ?></div>
        <div class="stat-meta">Across all tables</div>
      </div>
      <div class="stat">
        <div class="stat-label">Backups on file</div>
        <div class="stat-value"><?= count($backups) ?></div>
        <div class="stat-meta">Historical snapshots</div>
      </div>
      <div class="stat">
        <div class="stat-label">Last backup</div>
        <div class="stat-value" style="font-size: 20px;">
          <?= $lastBackup ? date('M j', strtotime($lastBackup['created_at'])) : '—' ?>
        </div>
        <div class="stat-meta">
          <?= $lastBackup ? date('g:i a', strtotime($lastBackup['created_at'])) : 'No backups yet' ?>
        </div>
      </div>
    </div>

    <!-- ─── Deleted PPMPs (recoverable trash) ─── -->
    <div class="card" style="border-left: 4px solid var(--orange-500); margin-bottom: 20px;">
      <div class="card-title" style="display: flex; align-items: center; gap: 10px;">
        🗑 Deleted PPMPs
        <span style="background: var(--accent); color: var(--accent-ink); font-size: 11px; padding: 2px 10px; border-radius: 999px; font-weight: 700; margin-left: auto;">
          <?= count($deletedPPMPs) ?> in trash
        </span>
      </div>

      <?php if (!$deletedPPMPs): ?>
        <div style="text-align: center; padding: 34px 16px; color: var(--muted); font-size: 13.5px;">
          Nothing in the trash. Deleted PPMPs from the Archive will appear here.
        </div>
      <?php else: ?>
        <?php foreach ($deletedPPMPs as $d): ?>
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border); flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
              <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 14px; color: var(--ink);">
                <?= e($d['dept_code']) ?> · FY <?= (int)$d['fy'] ?>
              </div>
              <div style="font-size: 12px; color: var(--muted); margin-top: 3px;">
                <?= (int)$d['entry_count'] ?> entries · ₱<?= number_format((float)$d['total_amount'], 2) ?>
                &nbsp;·&nbsp; deleted by <?= e($d['deleted_by']) ?> · <?= reltime_span($d['deleted_at']) ?>
              </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
              <form method="POST" style="margin: 0;" data-confirm="This brings <?= e($d['dept_code']) ?> FY <?= (int)$d['fy'] ?> back into the system with all its entries." data-confirm-title="Restore PPMP?" data-confirm-label="Restore" data-confirm-tone="info">
                <input type="hidden" name="action" value="restore_ppmp">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 12.5px; color: var(--ink);">↩ Restore</button>
              </form>
              <form method="POST" style="margin: 0;" data-confirm="This permanently deletes <?= e($d['dept_code']) ?> FY <?= (int)$d['fy'] ?> for good. It cannot be recovered afterwards." data-confirm-title="Permanently delete?" data-confirm-label="Delete forever" data-confirm-tone="danger">
                <input type="hidden" name="action" value="purge_ppmp">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 12.5px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);">🗑 Delete permanently</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title">Backup history</div>
      <div class="card-sub">All backup snapshots in reverse chronological order.</div>

      <?php if (!$backups): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--muted); font-style: italic;">
          No backups yet. Click "Generate backup now" to create the first one.
        </div>
      <?php else: ?>
        <div style="margin-top: 8px;">
          <?php foreach ($backups as $b):
            $physical = is_file($backupDir . '/' . $b['filename']);
          ?>
            <div class="file" style="margin-bottom: 10px;">
              <div class="file-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
              </div>
              <div>
                <div class="file-name"><?= e($b['filename']) ?>
                  <?php if (!$physical): ?>
                    <span style="background: var(--orange-100); color: var(--orange-600); font-size: 10px; padding: 2px 8px; border-radius: 999px; font-weight: 700; margin-left: 6px; text-transform: uppercase;">Seed</span>
                  <?php endif; ?>
                </div>
                <div class="file-meta">
                  <span><?= e(fmtSize((int)$b['size_bytes'])) ?></span>
                  <span class="dot"></span>
                  <span><?= (int)$b['tables_count'] ?> tables · <?= number_format((int)$b['rows_count']) ?> rows</span>
                  <span class="dot"></span>
                  <span><?= e(date('M j, Y · g:i a', strtotime($b['created_at']))) ?></span>
                  <span class="dot"></span>
                  <span>by <?= e($b['created_by']) ?></span>
                </div>
                <?php if (!empty($b['notes'])): ?>
                  <div style="font-size: 12px; color: var(--muted); margin-top: 4px;"><?= e($b['notes']) ?></div>
                <?php endif; ?>
              </div>
              <span></span>
              <div style="display: flex; gap: 6px;">
                <?php if ($physical): ?>
                  <a href="backup.php?download=<?= urlencode($b['filename']) ?>"
                     class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">Download</a>
                <?php else: ?>
                  <span class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; opacity: 0.5;">No file</span>
                <?php endif; ?>
                <form method="POST" action="backup.php" style="margin: 0;"
                      data-confirm="This backup will be permanently deleted. This cannot be undone." data-confirm-title="Delete backup?" data-confirm-label="Delete" data-confirm-tone="danger">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: var(--orange-600);">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- v96: the password the backup .zip asks for -->
    <div class="card" id="backupPassword">
      <div class="card-title">Backup password</div>
      <div style="font-size: 13px; color: var(--muted); margin: 4px 0 14px; line-height: 1.6;">
        The backup <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">.zip</code> asks for this password when it is opened.
        <?php $__pwAt = backup_password_changed_at(); ?>
        <?= $__pwAt !== '' ? 'Last changed ' . e(date('M j, Y g:i A', strtotime($__pwAt))) . '.' : 'Not changed yet.' ?>
        Backups made before a change keep the password they were made with.</div>
      <form method="POST" action="backup.php" class="bp-form" autocomplete="off">
        <input type="hidden" name="action" value="set_password">
        <label class="bp-f"><span>Current password</span>
          <input type="password" name="current_password" class="form-input" required autocomplete="current-password"></label>
        <label class="bp-f"><span>New password</span>
          <input type="password" name="new_password" class="form-input" required minlength="<?= BACKUP_PW_MIN ?>" maxlength="<?= BACKUP_PW_MAX ?>" autocomplete="new-password"></label>
        <label class="bp-f"><span>Confirm new password</span>
          <input type="password" name="confirm_password" class="form-input" required minlength="<?= BACKUP_PW_MIN ?>" maxlength="<?= BACKUP_PW_MAX ?>" autocomplete="new-password"></label>
        <div><button type="submit" class="btn btn-primary" id="changeBackupPw">Change password</button></div>
      </form>
      <style>
        .bp-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 12px; align-items: end; }
        .bp-f { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--ink); min-width: 0; }
        @media (max-width: 900px) { .bp-form { grid-template-columns: 1fr; } }
      </style>
    </div>

    <div class="card">
      <div class="card-title">About backups</div>
      <div style="font-size: 13.5px; color: var(--muted); line-height: 1.7;">
        Backups are stored as full SQL dumps in the <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">backups/</code> directory of the ProcureEase installation. They contain the complete schema (CREATE TABLE) and every row of data from every table.
        <br><br>
        <strong style="color: var(--ink);">To restore</strong>, download the backup <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">.zip</code>, extract the <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">.sql</code> file. Then in phpMyAdmin drop the existing <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">procureease</code> database, create it fresh, and import the <code style="background: var(--navy-50); padding: 1px 6px; border-radius: 4px; color: var(--ink);">.sql</code> file.
      </div>
    </div>
  </main>
</div>
</body>
</html>
