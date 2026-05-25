<?php
// frontend/pages/payment_otp_send.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/config/mail.php';

requireLogin();

// Extract all necessary session variables before any session_write_close()
$userEmail = $_SESSION['user_email'] ?? '';
$csrfTokenInSession = $_SESSION['csrf_token'] ?? '';

header('Content-Type: application/json');

// CSRF Verification — field name must match csrf_field() → 'csrf_token'
if (!isset($_POST['csrf_token']) || !hash_equals($csrfTokenInSession, $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

if (empty($userEmail)) {
    echo json_encode(['success' => false, 'message' => 'User email session not found. Please log in again.']);
    exit;
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$method = in_array($_POST['method'] ?? '', ['esewa', 'khalti', 'bank']) ? $_POST['method'] : null;
$phone = $_POST['phone'] ?? '';

if (!$method || $bookingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Generate cryptographically secure 6-digit OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Store OTP in session BEFORE releasing the session lock
$_SESSION['payment_otp']         = $otp;
$_SESSION['payment_otp_expiry']  = time() + 120; // 2 minutes
$_SESSION['payment_otp_booking'] = $bookingId;

// Release session lock BEFORE the blocking SMTP call.
// Without this, the session stays locked for the entire email send duration,
// which would freeze all other browser tabs for this user.
session_write_close();

// Send via email
$subject  = "SpinGo Payment OTP";
$htmlBody = getSmsStyleTemplate($otp, $method);

// Production behavior universally applied: email must succeed
if (sendEmail($userEmail, $subject, $htmlBody)) {
    echo json_encode(['success' => true]);
} else {
    // Email failed — reopen session and clear the stored OTP so it can't be used
    session_start();
    unset($_SESSION['payment_otp'], $_SESSION['payment_otp_expiry'], $_SESSION['payment_otp_booking']);
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
}
