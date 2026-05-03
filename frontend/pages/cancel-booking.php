<?php
// frontend/pages/cancel-booking.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/Booking.php';

requireLogin();
csrf_verify();  // ✅ CSRF protection

$booking_id = (int)($_POST['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

if (!$booking_id) {
    header('Location: dashboard.php');
    exit;
}

$booking = new Booking($conn);
$result  = $booking->cancelBooking($booking_id, $user_id);

if ($result['status'] === 'success') {
    // ── Lookup: vehicle name, refund amount, and host_id for this booking ──
    // Covers both admin-fleet (source='admin') and host-fleet (source='host') vehicles.
    $cancelDetails = $conn->prepare("
        SELECT
            COALESCE(v.name, hv.name)  AS vehicle_name,
            b.refund_amount,
            b.source,
            hv.host_id
        FROM bookings b
        LEFT JOIN vehicles    v  ON v.id  = b.vehicle_id AND b.source = 'admin'
        LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
        WHERE b.id = :bid
        LIMIT 1
    ");
    $cancelDetails->execute([':bid' => $booking_id]);
    $cancelInfo = $cancelDetails->fetch(PDO::FETCH_ASSOC);

    $vehicleName  = $cancelInfo['vehicle_name']  ?? 'your vehicle';
    $refundAmount = (float)($cancelInfo['refund_amount'] ?? 0);
    $hostId       = $cancelInfo['host_id']        ?? null;

    $refundNote = $refundAmount > 0
        ? 'A refund of NPR ' . number_format($refundAmount, 2) . ' has been initiated.'
        : 'No refund applicable.';

    // ── Notification 1 (Admin): booking cancelled ──
    $conn->prepare("
        INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
        SELECT id, 'admin', 'cancellation', :title, :message, :link
        FROM users WHERE role = 'admin'
    ")->execute([
        ':title'   => "Booking Cancelled #$booking_id",
        ':message' => "User {$_SESSION['user_name']} has cancelled their reservation for $vehicleName.",
        ':link'    => 'admin/bookings.php'
    ]);

    // ── Notification 2 (User): confirmation of their own cancellation ──
    $conn->prepare("
        INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
        VALUES (:uid, 'user', 'cancellation', :title, :message, 'dashboard.php')
    ")->execute([
        ':uid'     => $user_id,
        ':title'   => "Booking #$booking_id Cancelled",
        ':message' => "Your booking for $vehicleName has been cancelled. $refundNote",
    ]);

    // ── Notification 3 (Host): their vehicle dates just freed up ──
    if ($hostId) {
        $conn->prepare("
            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
            VALUES (:hid, 'host', 'cancellation', :title, :message, 'host_dashboard.php')
        ")->execute([
            ':hid'     => $hostId,
            ':title'   => "Booking Cancelled on Your Vehicle",
            ':message' => "{$_SESSION['user_name']} has cancelled their booking for $vehicleName. Those dates are now available.",
        ]);
    }

    header('Location: dashboard.php?msg=cancelled');
} else {
    // Redirect with a URL-encoded error message — no raw echo
    header('Location: dashboard.php?error=' . urlencode($result['message'] ?? 'Could not cancel booking.'));
}
exit;
?>
