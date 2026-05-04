<?php
/**
 * frontend/pages/cancel_pending_booking_beacon.php
 *
 * Called by navigator.sendBeacon() from payment.php when the user navigates
 * away (back button, tab close, page change) without completing payment.
 *
 * Accepts POST with multipart/form-data (sendBeacon + FormData).
 * Does NOT redirect — just exits silently (beacon ignores the response).
 *
 * Security: validates booking_id ownership via DB lookup using user_id
 * stored against the booking, cross-checked with the CSRF token that was
 * embedded in the page at render time.
 */

// POST only — ignore preflight/GET probes
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

// Require an active session — sendBeacon fires while session is still valid
// (back button / navigation away, not crash). Cron is the fallback for crashes.
if (!isLoggedIn()) {
    http_response_code(204);
    exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$csrf_token = $_POST['csrf'] ?? '';

// Validate CSRF token
if (!$csrf_token || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    http_response_code(204);
    exit;
}

if ($booking_id <= 0) {
    http_response_code(204);
    exit;
}

// Cancel the booking — only if it still belongs to this user and is still pending
$stmt = $conn->prepare("
    UPDATE bookings
    SET status         = 'cancelled',
        payment_status = 'failed',
        cancelled_by   = 'system',
        cancelled_at   = NOW()
    WHERE id           = :id
      AND user_id      = :uid
      AND status       = 'pending'
      AND payment_status = 'pending'
");
$stmt->execute([':id' => $booking_id, ':uid' => $_SESSION['user_id']]);

// sendBeacon ignores the response body — just return 204
http_response_code(204);
exit;
