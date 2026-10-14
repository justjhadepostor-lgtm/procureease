<?php
/* ═══════════════════════════════════════════════════════════════
   Database configuration.
   Edit these constants to match your local MySQL setup (XAMPP defaults).
   =============================================================== */

// Set PHP timezone to Philippine time so time() and date() match MySQL NOW()
date_default_timezone_set('Asia/Manila');

const DB_HOST = 'sql309.infinityfree.com';
const DB_NAME = 'if0_43155362_procureease';
const DB_USER = 'if0_43155362';
const DB_PASS = 'Yca080308'; 
const DB_PORT = 3306;

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Force MySQL session timezone to Philippine time (+08:00)
            // so NOW() and TIMESTAMP columns line up with PHP's date() output.
            try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $t) {}
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed: ' . htmlspecialchars($e->getMessage())
                . '<br><br>Please check config/db.php and make sure MySQL is running and the "'
                . DB_NAME . '" database exists (import sql/schema.sql).');
        }
    }
    return $pdo;
}

/** Best guess at the URL this app is reachable on, for links inside emails. */
function app_base_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return "$scheme://$host$dir";
}
