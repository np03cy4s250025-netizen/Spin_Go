<?php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';


header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 1. CSRF Protection
// We allow manually checking CSRF for JSON endpoints if needed, but here we use the session helper
$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken  = $_SESSION['csrf_token'] ?? '';
if (!$expectedToken || !hash_equals($expectedToken, $submittedToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh.']);
    exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$status = $_POST['status'] ?? null;

if (!$booking_id || !in_array($status, ['accepted', 'rejected'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

// 2. Authorization: Verify host ownership
// First, find the host_id for the current user
$stmtHost = $conn->prepare("SELECT * FROM hosts WHERE user_id = :uid AND status = 'approved' LIMIT 1");
$stmtHost->execute([':uid' => $user['id']]);
$host = $stmtHost->fetch();

if (!$host) {
    echo json_encode(['status' => 'error', 'message' => 'You are not an authorized host.']);
    exit;
}

$host_id = $host['id'] ?? $host['user_id'];

// Verify this host is assigned to this specific booking action
$stmtVerify = $conn->prepare("SELECT 1 FROM host_booking_actions WHERE booking_id = :bid AND host_id = :hid");
$stmtVerify->execute([':bid' => $booking_id, ':hid' => $host_id]);
if (!$stmtVerify->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Action unauthorized for this booking.']);
    exit;
}

// 3. Database Transaction with Generic Error Messages
// 3. Database Transaction with Generic Error Messages
try {
    $conn->beginTransaction();
    
    // Update host_booking_actions only
    $stmt = $conn->prepare("UPDATE host_booking_actions SET status = :status WHERE booking_id = :bid AND host_id = :hid");
    $stmt->execute([':status' => $status, ':bid' => $booking_id, ':hid' => $host_id]);
    
    // Only confirm the booking if accepted — do NOT cancel it if rejected
    if ($status === 'accepted') {
        $stmt2 = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = :bid");
        $stmt2->execute([':bid' => $booking_id]);
    }
    // If rejected: booking stays 'pending' — user/admin decides what happens next
    
    $conn->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("[BookingAction Error] User " . $user['id'] . ": " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred. Please try again later.']);
}
if ($status === 'rejected') {
    $conn->prepare("
        INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
        SELECT b.user_id, 'user', 'cancellation', 'Booking Request Declined', 
               CONCAT('Your booking request #SPG-', b.id, ' was declined by the host. You may rebook another vehicle.'),
               'fleet.php'
        FROM bookings b WHERE b.id = :bid
    ")->execute([':bid' => $booking_id]);
}
?>
