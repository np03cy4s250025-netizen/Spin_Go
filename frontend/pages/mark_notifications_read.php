<?php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? null;

if (!$role) {
    try {
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$userId]);
        $role = $roleStmt->fetchColumn();
    } catch (PDOException $e) {
        $role = 'user';
    }
}
if (!$role) {
    $role = 'user';
}

try {
    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ? AND recipient_role = ?");
        $stmt->execute([$id, $userId, $role]);
        $affected = $stmt->rowCount();
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND recipient_role = ? AND is_read = 0");
        $stmt->execute([$userId, $role]);
        $affected = $stmt->rowCount();
    }
    echo json_encode(['success' => true, 'affected' => $affected]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
