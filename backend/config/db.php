<?php
// backend/config/db.php — Secure database connection (errors logged, never echoed)

// Never display PHP errors to the browser — log them instead
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: 'localhost';
$db_name  = getenv('DB_NAME') ?: 'spingo_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Use real prepared statements
        ]
    );
    // Runtime schema guard for notifications reference_id
    try {
        $conn->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER link");
    } catch (PDOException $ex) {}
    // Runtime schema guards for vehicles table: description and transmission
    try {
        $conn->exec("ALTER TABLE vehicles ADD COLUMN description TEXT NULL AFTER availability");
    } catch (PDOException $ex) {}
    try {
        $conn->exec("ALTER TABLE vehicles ADD COLUMN transmission ENUM('Manual','Automatic') NOT NULL DEFAULT 'Manual' AFTER description");
    } catch (PDOException $ex) {}
    try {
        $conn->exec("ALTER TABLE vehicles MODIFY COLUMN type ENUM('car','bike','suv','sports','electric','motorbike') NOT NULL");
    } catch (PDOException $ex) {}
} catch (PDOException $e) {
    // Log to file — NEVER expose to browser
    $logDir  = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/db_errors.log';
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] DB Error: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    http_response_code(503);
    // Generic user-facing error — no internal details
    die(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SpinGo | Service Unavailable</title>
  <style>
    body { font-family: Inter, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; background:#EAEFD9; }
    .box { background:#fff; padding:40px; border-radius:16px; text-align:center; max-width:420px; }
    h2  { color:#8C77A5; margin-bottom:10px; }
    p   { color:#555; font-size:14px; }
    a   { color:#8C77A5; font-weight:600; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Service Temporarily Unavailable</h2>
    <p>We're having trouble connecting to our database. Please try again in a moment.</p>
    <a href="javascript:history.back()">← Go back</a>
  </div>
</body>
</html>
HTML
    );
}
