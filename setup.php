<?php
/**
 * ProcureEase — one-time setup / repair script
 * --------------------------------------------
 * Re-hashes every seed account's password so `password123` always works,
 * and creates any missing seed accounts. Idempotent — safe to run any time
 * login stops working. Visit /procureease/setup.php in the browser after
 * importing schema.sql, or any time you suspect the hashes are corrupt.
 */
require_once __DIR__ . '/config/compat.php';
require_once __DIR__ . '/config/db.php';

// v80: only while ProcureEase is being installed (config/setup-lock.php)
require_once __DIR__ . '/config/setup-lock.php';
if (setup_is_locked()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Setup is turned off — ProcureEase</title><link rel="stylesheet" href="assets/style.css"></head>'
       . '<body style="background: var(--page); display: grid; place-items: center; min-height: 100vh; padding: 24px; margin: 0;">'
       . '<div class="card" id="setupLocked" style="max-width: 520px; padding: 28px 30px; line-height: 1.6; color: var(--ink);">'
       . '<h1 style="font-family: \'Space Grotesk\', sans-serif; font-size: 20px; margin: 0 0 10px;">Setup is turned off</h1>'
       . '<p style="margin: 0 0 10px; font-size: 14px;">ProcureEase is already in use, so the setup page — which puts the seed accounts\' passwords back to the default — no longer runs.</p>'
       . '<p style="margin: 0 0 16px; font-size: 13px; color: var(--muted);">If it really has to run again (for example, no one can sign in any more), the person in charge of the server creates an empty file named <code>setup-unlock.txt</code> in the <code>procureease/config</code> folder, opens this page again, then deletes that file.</p>'
       . '<a href="index.php" class="btn btn-primary" style="text-decoration: none; padding: 9px 18px; font-size: 13px;">← Back to sign in</a>'
       . '</div></body></html>';
    exit;
}

require_once __DIR__ . '/config/admin-schema.php';

ensure_admin_tables();
$pdo = db();

$defaultPassword = 'password123';
$freshHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

// [username, full_name, position, email, contact, role, dept_code]
$seed = [
    ['pu-99-001',     'George Wilson A. Uy',      'Head, Procurement Unit',   'procurement@ascot.edu.com', '+63 917 555 0100', 'admin',  'PU'],
    ['budget-01-001', 'Sherwin Serrano',          'Budget Officer',           'budget@ascot.edu.com',      '+63 917 555 0101', 'budget', 'BUDGET-OFC'],
    ['ictu-01-001',   'Maria Reyes',              'Unit Head, ICTU',          'ictu@ascot.edu.com',        '+63 917 555 0142', 'user',   'ICTU'],
    ['soe-02-001',    'Engr. Juan dela Cruz',     'Dean, SOE',                'soe@ascot.edu.com',         '+63 917 555 0143', 'user',   'SOE'],
    ['soit-03-001',   'Prof. Nelson Ramos',       'Program Chair, SoIT',      'soit@ascot.edu.com',        '+63 917 555 0144', 'user',   'SoIT'],
    ['soas-04-001',   'Malou C. Angara',          'Dean, SOAS',               'soas@ascot.edu.com',        '+63 917 555 0145', 'user',   'SOAS'],
    ['soed-05-001',   'Dr. Cristina Villanueva',  'Dean, SOED',               'soed@ascot.edu.com',        '+63 917 555 0146', 'user',   'SOED'],
    ['gsu-06-001',    'Miguel Torres',            'Unit Head, GSU',           'gsu@ascot.edu.com',         '+63 917 555 0147', 'user',   'GSU'],
    ['hrmu-07-001',   'Rico Bautista',            'HR Head',                  'hrmu@ascot.edu.com',        '+63 917 555 0148', 'user',   'HRMU'],
    ['lib-08-001',    'Elena Ramos',              'Chief Librarian',          'library@ascot.edu.com',     '+63 917 555 0149', 'user',   'LIB'],
    ['reg-09-001',    'Ligaya Bautista',          'Registrar',                'registrar@ascot.edu.com',   '+63 917 555 0150', 'user',   'REG'],
];

$rehashed = 0; $created = 0; $errors = [];

foreach ($seed as [$u, $fn, $pos, $em, $ct, $role, $dc]) {
    try {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$u]);
        $existing = $check->fetch();

        if ($existing) {
            // Force-refresh the password hash to a known-good value
            $upd = $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, position = ?, email = ?, contact = ?, role = ?, dept_code = ?, status = "active" WHERE username = ?');
            $upd->execute([$freshHash, $fn, $pos, $em, $ct, $role, $dc, $u]);
            $rehashed++;
        } else {
            $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, position, email, contact, role, dept_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")');
            $ins->execute([$u, $freshHash, $fn, $pos, $em, $ct, $role, $dc]);
            $created++;
        }
    } catch (Throwable $t) {
        $errors[] = "$u: " . $t->getMessage();
    }
}

// Also make sure the BUDGET-OFC and PU departments exist
try {
    $pdo->exec("INSERT IGNORE INTO departments (code, name, dept_group) VALUES ('PU','Procurement Unit','Executive Offices')");
    $pdo->exec("INSERT IGNORE INTO departments (code, name, dept_group) VALUES ('BUDGET-OFC','Budget Unit','Executive Offices')");
} catch (Throwable $t) {}

// Verify a login works now
$verifyOk = false;
try {
    $q = $pdo->prepare('SELECT password_hash FROM users WHERE username = ?');
    $q->execute(['pu-99-001']);
    $r = $q->fetch();
    if ($r) $verifyOk = password_verify($defaultPassword, $r['password_hash']);
} catch (Throwable $t) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ProcureEase Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Inter', sans-serif; background: var(--navy-50); margin: 0; padding: 40px 20px; color: var(--ink); }
  .card { max-width: 640px; margin: 0 auto; background: white; border-radius: 14px; padding: 32px 36px; box-shadow: 0 4px 20px rgba(41, 54, 129, 0.1); }
  h1 { font-family: 'Space Grotesk', sans-serif; margin: 0 0 6px; color: var(--navy-900); font-size: 24px; }
  .sub { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
  .box { padding: 14px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 13.5px; line-height: 1.55; }
  .ok { background: #dcfce7; border-left: 4px solid #15803d; color: #14532d; }
  .warn { background: #fff0e4; border-left: 4px solid var(--orange-50); color: #7c2d12; }
  .info { background: var(--navy-50); border-left: 4px solid #232f72; color: var(--ink); }
  .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
  .row:last-child { border-bottom: none; }
  code { background: var(--navy-50); padding: 2px 6px; border-radius: 4px; font-family: 'Monaco', monospace; font-size: 12px; }
  .btn { display: inline-block; margin-top: 20px; padding: 12px 20px; background: #121358; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
  .btn:hover { background: #093c5d; }
</style>
</head>
<body>
<div class="card">
  <h1>ProcureEase — Account Setup</h1>
  <div class="sub">Repairs seed account password hashes so <code>password123</code> always signs in.</div>

  <?php if ($verifyOk): ?>
    <div class="box ok">
      <strong>✓ All good.</strong> Seed accounts are ready. You can sign in with any of them using the password <code>password123</code>.
    </div>
  <?php else: ?>
    <div class="box warn">
      <strong>⚠ Login verification failed.</strong> The hash was rewritten but a test verify against the admin account still failed. Check that the <code>users</code> table exists and MySQL is up.
    </div>
  <?php endif; ?>

  <div class="box info">
    <strong>Results</strong><br>
    Accounts re-hashed: <strong><?= $rehashed ?></strong> · Accounts created: <strong><?= $created ?></strong> · Default password: <code>password123</code>
  </div>

  <?php if ($errors): ?>
    <div class="box warn">
      <strong>Errors during setup:</strong>
      <ul style="margin: 6px 0 0 20px;">
        <?php foreach ($errors as $er): ?>
          <li><?= htmlspecialchars($er) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div style="margin-top: 20px;">
    <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--navy-900); margin-bottom: 10px;">Seed accounts (all use password: <code>password123</code>)</div>
    <?php foreach ($seed as [$u, $fn, $pos, $em, $ct, $role, $dc]): ?>
      <div class="row">
        <span><code><?= htmlspecialchars($u) ?></code> — <?= htmlspecialchars($fn) ?></span>
        <span style="color: var(--muted);"><?= htmlspecialchars($role) ?> · <?= htmlspecialchars($dc) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <a href="index.php" class="btn">Go to Sign In →</a>
</div>
</body>
</html>
