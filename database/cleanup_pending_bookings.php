<?php
/**
 * database/cleanup_pending_bookings.php — SpinGo stale-booking cleanup
 *
 * Cancels all bookings where:
 *   status = 'pending' AND payment_status = 'pending'
 *   AND created_at < NOW() - INTERVAL 15 MINUTE
 *
 * ── Usage ──────────────────────────────────────────────────────────────────
 *   CLI only:   php database/cleanup_pending_bookings.php
 *
 * ── Windows Task Scheduler (XAMPP) ─────────────────────────────────────────
 *   Program : C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Spin_Go\database\cleanup_pending_bookings.php
 *   Trigger  : Every 15 minutes
 *
 * ── Linux / Mac cron ───────────────────────────────────────────────────────
 *   Add to crontab (crontab -e):
 *   *\/15 * * * * php /var/www/html/Spin_Go/database/cleanup_pending_bookings.php >> /var/www/html/Spin_Go/logs/cleanup.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Cleanup script must be executed via CLI only.\n");
}

require_once __DIR__ . '/../backend/config/db.php';

$timestamp = date('Y-m-d H:i:s');

try {
    // Cancel all pending bookings older than 15 minutes
    $stmt = $conn->prepare("
        UPDATE bookings
        SET status         = 'cancelled',
            payment_status = 'failed',
            cancelled_by   = 'system',
            cancelled_at   = NOW()
        WHERE status         = 'pending'
          AND payment_status = 'pending'
          AND created_at     < NOW() - INTERVAL 15 MINUTE
    ");
    $stmt->execute();
    $count = $stmt->rowCount();

    echo "[{$timestamp}] Cleanup complete — {$count} stale pending booking(s) cancelled.\n";

} catch (PDOException $e) {
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
