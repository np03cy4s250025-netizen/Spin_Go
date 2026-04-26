<?php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
requireLogin();

// Clear and disable output buffering to ensure real-time stream delivery
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); 
set_time_limit(0);
ob_implicit_flush(true);

$userId   = (int)$_SESSION['user_id'];
$role     = $_SESSION['role'] ?? 'user';
$lastId   = (int)($_GET['lastId'] ?? 0);

// ── FIX: Unlock session before infinite loop! ──
// If the session stays locked during an infinite SSE stream, 
// no other PHP pages can load for this user because they wait for the lock.
session_write_close();

while (true) {
    // Users and hosts get their own specific notifications
    $stmt = $conn->prepare("
        SELECT id, type, title, message, link, created_at, is_read 
        FROM notifications 
        WHERE id > :last_id 
        AND recipient_id = :uid AND recipient_role = :role
        ORDER BY id ASC LIMIT 10
    ");
    $stmt->execute([':last_id' => $lastId, ':uid' => $userId, ':role' => $role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['is_read'] = (int)$row['is_read'];
        $lastId = $row['id'];
        echo "id: {$row['id']}\n";
        echo "data: " . json_encode($row) . "\n\n";
    }

    // Send heartbeat
    echo ": heartbeat\n\n";
    
    if (ob_get_level() > 0) ob_flush();
    flush();
    
    if (connection_aborted()) {
        break;
    }
    
    sleep(5); 
}
