<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$activeNav = 'set-year';
$pageTitle = 'Set Fiscal Year';
$pdo = db();

$currentFY = active_fy();

/* Years the Super Admin added to the list (Add a year, below) — kept in
   settings as "fy_extra_years", e.g. "2031,2035". */
function fy_extra_years(PDO $pdo): array {
    try {
        $v = (string)$pdo->query("SELECT value FROM settings WHERE key_name = 'fy_extra_years'")->fetchColumn();
    } catch (Throwable $t) { $v = ''; }
    $out = [];
    foreach (explode(',', $v) as $y) { $y = (int)trim($y); if ($y >= 2000 && $y <= 2100) $out[$y] = $y; }
    return array_values($out);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_year') {
    $y = trim((string)($_POST['new_year'] ?? ''));
    $years = fy_extra_years($pdo);
    $shown = array_merge(range($currentFY - 2, $currentFY + 3), $years);
    try { $shown = array_merge($shown, array_map('intval', $pdo->query('SELECT DISTINCT fy FROM ppmps')->fetchAll(PDO::FETCH_COLUMN))); } catch (Throwable $t) {}
    if (!preg_match('/^\d{4}$/', $y) || (int)$y < 2000 || (int)$y > 2100) {
        flash('error', 'Type a year from 2000 to 2100, e.g. ' . ($currentFY + 4) . '.');
    } elseif (in_array((int)$y, $shown, true)) {
        flash('error', "FY $y is already on the list.");
    } else {
        $years[] = (int)$y; sort($years);
        try {
            $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('fy_extra_years', ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([implode(',', $years)]);
            log_activity('Added a fiscal year', 'settings', "FY $y", 'Added to the list on Set Fiscal Year.');
            flash('success', "FY $y was added to the list. Choose it below and click Set year to make it the active year.");
        } catch (Throwable $t) {
            flash('error', 'Could not add the year. Please try again.');
        }
    }
    header('Location: set-year.php'); exit;
}
// The years around the active one, the years added here, and every year that has PPMPs
// (so moving far ahead never hides the year you came from)
$__dataYears = [];
try { $__dataYears = array_map('intval', $pdo->query('SELECT DISTINCT fy FROM ppmps')->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $t) {}
$__dataYears = array_filter($__dataYears, fn($y) => $y >= 2000 && $y <= 2100);
$fyList = array_unique(array_merge(range($currentFY - 2, $currentFY + 3), fy_extra_years($pdo), $__dataYears, [$currentFY]));
sort($fyList);

// Count what would be archived if the year were moved forward
function fy_archive_preview(PDO $pdo, int $newYear): array {
    $ppmpN = 0; $nonpsN = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM ppmps WHERE fy < ? AND id NOT IN (SELECT ppmp_id FROM archived_ppmps)");
        $s->execute([$newYear]); $ppmpN = (int)$s->fetchColumn();
    } catch (Throwable $t) {}
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM nonps_submissions WHERE fy < ? AND (archived IS NULL OR archived = 0)");
        $s->execute([$newYear]); $nonpsN = (int)$s->fetchColumn();
    } catch (Throwable $t) {}
    return [$ppmpN, $nonpsN];
}

require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Set Fiscal Year</h1>
        <p class="page-sub">Fiscal year for the whole system.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php
      // Preview counts for moving to next year
      [$ppmpNext, $nonpsNext] = fy_archive_preview($pdo, $currentFY + 1);
    ?>

    <div style="display: flex; flex-direction: column; gap: 18px;">

      <!-- TOP: current year hero -->
      <div class="card" style="width: 250px; height: 100px; padding: 16px 24px; box-sizing: border-box; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; max-width: 100%; background: linear-gradient(135deg, var(--navy-700) 0%, var(--navy-800) 100%); color: white; border: none;">
        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.12em;">Currently active</div>
        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; margin: 6px 0 0;">FY <?= $currentFY ?></div>
      </div>

      <!-- BELOW: change form -->
      <div>
        <div class="card" style="padding: 26px 30px;">
          <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--navy-800); margin-bottom: 16px;">Change the system fiscal year</div>

          <form method="POST" action="api/set-fiscal-year.php"
                data-confirm="Set the system to the selected fiscal year? All PPMPs and Non-PS lists from earlier years will be moved to the archive. You can still view or restore them from the Archive."
                data-confirm-title="Change system fiscal year?" data-confirm-label="Set year &amp; archive old" data-confirm-tone="warning">
            <label class="form-label" for="fy" style="display: block; margin-bottom: 6px;">Fiscal year</label>
            <div style="display: flex; flex-direction: row; gap: 12px; align-items: center; flex-wrap: wrap;">
              <select id="fy" name="fy" class="form-select" style="flex: 1; min-width: 200px; padding: 11px 16px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px;">
                <?php foreach ($fyList as $y): ?>
                  <option value="<?= $y ?>" <?= $y === $currentFY ? 'selected' : '' ?>>FY <?= $y ?><?= $y === $currentFY ? ' (current)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary" style="padding: 11px 26px; font-size: 14px; white-space: nowrap;">Set year</button>
            </div>
          </form>

          <!-- A year that is not on the list yet -->
          <form method="POST" action="set-year.php" id="addYearForm" style="margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border);">
            <input type="hidden" name="action" value="add_year">
            <label class="form-label" for="new_year" style="display: block; margin-bottom: 6px;">Add another year</label>
            <div style="display: flex; flex-direction: row; gap: 12px; align-items: center; flex-wrap: wrap;">
              <input type="text" id="new_year" name="new_year" class="form-input" inputmode="numeric" maxlength="4" pattern="\d{4}" required
                     placeholder="e.g. <?= max($fyList) + 1 ?>" oninput="this.value = this.value.replace(/\D/g, '')"
                     style="flex: 1; min-width: 200px; padding: 11px 16px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px;">
              <button type="submit" class="btn btn-ghost" style="padding: 11px 26px; font-size: 14px; white-space: nowrap;">+ Add year</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </main>
</div>

<style>
  @media (max-width: 820px) {
    main .main > div[style*="max-width: 640px"] { max-width: 100% !important; }
  }
</style>
</body>
</html>
