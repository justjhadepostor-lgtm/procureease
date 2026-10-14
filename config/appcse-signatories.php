<?php
/* Per-department, per-fiscal-year APP-CSE signature fields. */
require_once __DIR__ . '/db.php';
function appcse_signatory_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $columns = [
        'sig1_name' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig1_position' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig2_name' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig2_position' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig3_name' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig3_position' => "VARCHAR(180) NOT NULL DEFAULT ''",
        'sig_date_value' => "VARCHAR(80) NOT NULL DEFAULT ''",
    ];
    try { $have = $pdo->query('SHOW COLUMNS FROM appcse_header')->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { return; }
    foreach ($columns as $name => $type) {
        if (!in_array($name, $have, true)) {
            try { $pdo->exec("ALTER TABLE appcse_header ADD COLUMN `$name` $type"); } catch (Throwable $e) {}
        }
    }
}
appcse_signatory_ensure_columns();
