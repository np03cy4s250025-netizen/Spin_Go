<?php
// frontend/pages/cancel_pending_booking.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

$booking_id = (int)($_GET['booking_id'] ?? 0);
$token = $_GET['csrf'] ?? '';

// Verify CSRF manually for GET link
if (!$token || $token !== ($_SESSION['csrf_token'] ?? '')) {
    header('Location: dashboard.php');
    exit;
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Fetch booking to verify ownership and pending status
$stmt = $conn->prepare("
    SELECT id FROM bookings 
    WHERE id = :id AND user_id = :uid AND payment_status = 'pending'
    LIMIT 1
");
$stmt->execute([':id' => $booking_id, ':uid' => $_SESSION['user_id']]);
$booking = $stmt->fetch();

if ($booking) {
    // Set to cancelled and payment failed
    $updateStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'cancelled', payment_status = 'failed' 
        WHERE id = :id
    ");
    $updateStmt->execute([':id' => $booking_id]);
}

// Redirect back to fleet to start over
header('Location: fleet.php');
exit;
