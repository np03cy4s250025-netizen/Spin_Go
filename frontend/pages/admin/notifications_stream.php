<?php
require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';
requireAdmin();

// Clear and disable output buffering to ensure real-time stream delivery
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);
ob_implicit_flush(true);

$userId = (int)$_SESSION['user_id'];
$lastId = (int)($_GET['lastId'] ?? 0);

// Unlock session before the infinite loop so other pages can load
session_write_close();

while (true) {
    // Match both old rows (recipient_id IS NULL) and new rows (recipient_id = this admin's id)
    $stmt = $conn->prepare("
        SELECT id, type, title, message, link, created_at, CAST(is_read AS UNSIGNED) AS is_read
        FROM notifications
        WHERE id > :last_id
          AND recipient_role = 'admin'
          AND (recipient_id IS NULL OR recipient_id = :uid)
        ORDER BY id ASC LIMIT 10
    ");
    $stmt->execute([':last_id' => $lastId, ':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $row['id']      = (int)$row['id'];
        $row['is_read'] = (int)$row['is_read'];
        $lastId = $row['id'];
        echo "id: {$row['id']}\n";
        echo "data: " . json_encode($row) . "\n\n";
    }

    echo ": heartbeat\n\n";

    if (ob_get_level() > 0) ob_flush();
    flush();

    if (connection_aborted()) {
        break;
    }

    sleep(5);
}