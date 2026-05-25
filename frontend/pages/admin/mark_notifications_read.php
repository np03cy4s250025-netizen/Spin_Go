<?php
require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    if (!empty($_POST['id'])) {
        // Mark a single notification as read — matches both NULL and scoped recipient_id
        $id   = (int)$_POST['id'];
        $stmt = $conn->prepare("
            UPDATE notifications SET is_read = 1
            WHERE id = ?
              AND recipient_role = 'admin'
              AND (recipient_id IS NULL OR recipient_id = ?)
        ");
        $stmt->execute([$id, $userId]);
        $affected = $stmt->rowCount();
    } else {
        // Mark all admin notifications as read for this admin
        $stmt = $conn->prepare("
            UPDATE notifications SET is_read = 1
            WHERE recipient_role = 'admin'
              AND (recipient_id IS NULL OR recipient_id = ?)
              AND is_read = 0
        ");
        $stmt->execute([$userId]);
        $affected = $stmt->rowCount();
    }
    echo json_encode(['success' => true, 'affected' => $affected]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error']);
}