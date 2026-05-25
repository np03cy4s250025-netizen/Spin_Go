<?php
// frontend/pages/get_notifications.php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'user';
$limit  = (int)($_GET['limit'] ?? 20);
if ($limit <= 0 || $limit > 100) $limit = 20;

try {
    if ($role === 'admin') {
        $stmt = $conn->prepare("
            SELECT id, type, title, message, link, created_at, CAST(is_read AS UNSIGNED) AS is_read
            FROM notifications
            WHERE recipient_role = 'admin' AND (recipient_id IS NULL OR recipient_id = :uid)
            ORDER BY id DESC LIMIT :limit
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        $stmt = $conn->prepare("
            SELECT id, type, title, message, link, created_at, CAST(is_read AS UNSIGNED) AS is_read
            FROM notifications
            WHERE recipient_id = :uid AND recipient_role = :role
            ORDER BY id DESC LIMIT :limit
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['is_read'] = (int)$row['is_read'];
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'notifications' => $rows]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}