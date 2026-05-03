<?php
// frontend/pages/payment_process.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

// Extract all necessary session variables before any session_write_close()
$userId    = (int)$_SESSION['user_id'];
$userName  = $_SESSION['user_name'] ?? '';
$userEmail = $_SESSION['user_email'] ?? '';

csrf_verify();

$booking_id = (int)($_POST['booking_id'] ?? 0);
$method = $_POST['method'] ?? 'card';

if (getenv('ENABLE_PAYMENT') === 'false') {
    header('Location: dashboard.php');
    exit;
}

// Fetch booking and verify ownership and status
$stmt = $conn->prepare("
    SELECT id, user_id, payment_status, total_price 
    FROM bookings 
    WHERE id = :id AND user_id = :uid AND payment_status = 'pending'
    LIMIT 1
");
$stmt->execute([':id' => $booking_id, ':uid' => $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: dashboard.php');
    exit;
}

// Cash on Pickup Logic moved down to race-condition check

// eSewa / Khalti OTP Verification
if (in_array($method, ['esewa', 'khalti'])) {
    if (!isset($_SESSION['payment_otp']) || !hash_equals((string)$_SESSION['payment_otp'], (string)$_POST['otp'])) {
        header("Location: payment.php?booking_id=$booking_id&error=otp");
        exit;
    }
    if (time() > $_SESSION['payment_otp_expiry']) {
        header("Location: payment.php?booking_id=$booking_id&error=expired");
        exit;
    }
    if ($_SESSION['payment_otp_booking'] !== $booking_id) {
        header("Location: dashboard.php");
        exit;
    }

    // Clear OTP from session
    unset($_SESSION['payment_otp'], $_SESSION['payment_otp_expiry'], $_SESSION['payment_otp_booking']);
}

// Proceed with payment success
$txn = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

// Self-healing schema: add amount_paid column if it doesn't exist yet
try {
    $conn->exec("ALTER TABLE bookings ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0.00 AFTER transaction_id");
} catch (Exception $e) { /* Ignore if it already exists */ }

// ── BLOCK 1: Payment transaction — the ONLY block that can redirect to error ──
try {
    $conn->beginTransaction();

    // ── Race-condition re-check ──────────────────────────────────────────────
    // Because pending bookings no longer block dates, two users may reach
    // payment simultaneously. Whoever commits first wins; the second must
    // be cancelled here before we charge them.
    $raceStmt = $conn->prepare("
        SELECT b.id FROM bookings b
        WHERE b.vehicle_id = (SELECT vehicle_id FROM bookings WHERE id = :bid1)
          AND b.status      = 'confirmed'
          AND b.id         != :bid2
          AND b.pickup_date  <= (SELECT dropoff_date FROM bookings WHERE id = :bid3)
          AND b.dropoff_date >= (SELECT pickup_date  FROM bookings WHERE id = :bid4)
        LIMIT 1
    ");
    $raceStmt->execute([
        ':bid1' => $booking_id, 
        ':bid2' => $booking_id, 
        ':bid3' => $booking_id, 
        ':bid4' => $booking_id
    ]);

    if ($raceStmt->fetch()) {
        // Someone else confirmed first — cancel this booking and inform user
        $conn->rollBack();
        $conn->prepare("
            UPDATE bookings
            SET status = 'cancelled', payment_status = 'failed',
                cancelled_by = 'system', cancelled_at = NOW()
            WHERE id = :id
        ")->execute([':id' => $booking_id]);
        header("Location: fleet.php?error=dates_taken");
        exit;
    }

    if ($method === 'cash') {
        $txn = 'CASH-' . strtoupper(bin2hex(random_bytes(4)));
        $conn->prepare("
            UPDATE bookings
            SET payment_status = 'pending',
                status = 'pending',
                payment_method = 'cash',
                transaction_id = :txn
            WHERE id = :id AND user_id = :uid
        ")->execute([':txn' => $txn, ':id' => $booking_id, ':uid' => $userId]);
    } else {
        // eSewa / Khalti — record full amount paid
        $amount_paid = round($booking['total_price'], 2);

        $conn->prepare("
            UPDATE bookings
            SET payment_status = 'paid',
                status = 'pending',
                payment_method = :method,
                transaction_id = :txn,
                amount_paid = :amount
            WHERE id = :id AND user_id = :uid
        ")->execute([
            ':method' => $method,
            ':txn'    => $txn,
            ':amount' => $amount_paid,
            ':id'     => $booking_id,
            ':uid'    => $userId,
        ]);
    }

    $conn->commit();

    // Reset admin alert flag so the popup shows again on admin.php
    unset($_SESSION['admin_alert_shown']);
    
    // Unlock session early so other tabs/pages (like dashboard) can load
    // while the emails send in the background (prevents PHP session locking)
    session_write_close();

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[PaymentProcess-Transaction] ' . $e->getMessage());
    header("Location: payment.php?booking_id=$booking_id&error=failed");
    exit;
}

// ── BLOCK 2: Notifications & emails — completely isolated ─────────────────────
// A failure here NEVER redirects to error. The payment is already committed.
// Any exception is silently logged and the user proceeds to confirmation.
try {
    require_once '../../backend/config/mail.php';

    // Fetch full booking details — covers both admin-fleet and host-fleet vehicles
    $detailsStmt = $conn->prepare("
        SELECT b.*,
               COALESCE(v.name, hv.name) AS vehicle_name
        FROM bookings b
        LEFT JOIN vehicles     v  ON v.id  = b.vehicle_id AND b.source = 'admin'
        LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
        WHERE b.id = :id
    ");
    $detailsStmt->execute([':id' => $booking_id]);
    $bookingData = $detailsStmt->fetch();

    if ($bookingData) {
        $vehicleName = $bookingData['vehicle_name'];
        $pickupDate  = date('d M Y', strtotime($bookingData['pickup_date']));
        $dropoffDate = date('d M Y', strtotime($bookingData['dropoff_date']));

        // 1. Notify Admin by email (Template 1)
        $adminEmail = $conn->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($adminEmail) {
            $adminBody = "
                <p>A new booking request has been submitted on SpinGo and is awaiting admin confirmation.</p>
                <table style='width:100%; border-collapse:collapse; margin:20px 0;'>
                    <tr><td style='padding:8px; color:#666;'>Booking ID</td><td style='padding:8px; font-weight:700;'>#$booking_id</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Customer</td><td style='padding:8px; font-weight:700;'>$userName ($userEmail)</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Vehicle</td><td style='padding:8px; font-weight:700;'>$vehicleName</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Pickup Date</td><td style='padding:8px; font-weight:700;'>$pickupDate</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Return Date</td><td style='padding:8px; font-weight:700;'>$dropoffDate</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Total Amount</td><td style='padding:8px; font-weight:700;'>NPR " . number_format($bookingData['total_price']) . "</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Payment</td><td style='padding:8px; font-weight:700;'>" . ($method === 'cash' ? 'Pending Cash Payment' : 'PAID via ' . ucfirst($method)) . " ($txn)</td></tr>
                </table>
                <p>⚠️ The customer's driving license requires your verification before they can pick up the vehicle.</p>
                <div style='margin-top: 32px;'>
                    <a href='" . (getenv('APP_URL') ?: 'http://localhost/Spin_Go') . "/frontend/pages/admin/bookings.php'
                       style='display:inline-block; padding:12px 28px; background:#3F3E46; color:#ffffff; border-radius:50px; text-decoration:none; font-weight:700;'>
                       Review Booking &rarr;
                    </a>
                </div>
            ";
            sendEmail($adminEmail, "🚗 New Booking Request #$booking_id — Action Required", getAdminEmailTemplate("New Booking Request Alert", $adminBody));
        }

        // 2. Notify Customer by email (Template 3)
        sendEmail($userEmail, "Booking Requested — #SPG-$booking_id", getBookingEmailTemplate($bookingData));

        // 3. Real-time notifications (SSE) — uses CREATE TABLE IF NOT EXISTS guard
        // so missing table never crashes even if this migration hasn't been run.
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `notifications` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `recipient_id`   INT DEFAULT NULL,
                `recipient_role` ENUM('user','host','admin') NOT NULL DEFAULT 'admin',
                `type`           VARCHAR(50) NOT NULL,
                `title`          VARCHAR(255) NOT NULL,
                `message`        TEXT NOT NULL,
                `link`           VARCHAR(255) DEFAULT NULL,
                `is_read`        TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Self-healing: ensure reference_id column exists (added in a later migration)
        try {
            $conn->exec("ALTER TABLE notifications ADD COLUMN reference_id INT DEFAULT NULL");
        } catch (Exception $e) { /* Already exists — safe to ignore */ }

        // 3a. Notify Admin
        $conn->prepare("
            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
            SELECT id, 'admin', 'booking', :title, :message, :link, :ref_id
            FROM users WHERE role = 'admin'
        ")->execute([
            ':title'   => "New Booking #$booking_id — Action Required",
            ':message' => "$userName booked $vehicleName · " .
                          date('d M', strtotime($bookingData['pickup_date'])) . " → " .
                          date('d M', strtotime($bookingData['dropoff_date'])) . " · NPR " .
                          number_format($bookingData['total_price']),
            ':link'    => 'admin/bookings.php?highlight=' . $booking_id,
            ':ref_id'  => $booking_id,
        ]);

        // 3b. Notify User
        $conn->prepare("
            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
            VALUES (:uid, 'user', 'booking', :title, :message, :link, :ref_id)
        ")->execute([
            ':uid'     => $userId,
            ':title'   => "Booking Requested — #$booking_id",
            ':message' => "Your booking request for $vehicleName is submitted and awaiting admin confirmation.",
            ':link'    => 'dashboard.php',
            ':ref_id'  => $booking_id,
        ]);

        // 3c. Notify Host (if vehicle is from host_vehicles)
        $isHostVehicle = false;
        if (isset($bookingData['source']) && $bookingData['source'] === 'host') {
            $isHostVehicle = true;
        } else {
            // Fallback: check if the vehicle_id exists in host_vehicles table
            $fallbackCheck = $conn->prepare("SELECT host_id FROM host_vehicles WHERE id = :vid LIMIT 1");
            $fallbackCheck->execute([':vid' => $bookingData['vehicle_id']]);
            if ($fallbackCheck->fetchColumn()) {
                $isHostVehicle = true;
            }
        }

        if ($isHostVehicle) {
            $hostStmt = $conn->prepare("
                SELECT hv.host_id, u.full_name, u.phone
                FROM host_vehicles hv
                JOIN users u ON u.id = hv.host_id
                WHERE hv.id = :vid
            ");
            $hostStmt->execute([':vid' => $bookingData['vehicle_id']]);
            $hostData = $hostStmt->fetch(PDO::FETCH_ASSOC);

            if ($hostData) {
                $conn->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
                    VALUES (:hid, 'host', 'new_booking', :title, :message, :link, :ref_id)
                ")->execute([
                    ':hid'     => $hostData['host_id'],
                    ':title'   => "New Booking on Your Vehicle",
                    ':message' => "Your vehicle $vehicleName has been booked · " .
                                  date('d M', strtotime($bookingData['pickup_date'])) . " → " .
                                  date('d M', strtotime($bookingData['dropoff_date'])) .
                                  " · NPR " . number_format($bookingData['total_price']) .
                                  " · Renter details are managed by SpinGo admin.",
                    ':link'    => 'host_dashboard.php',
                    ':ref_id'  => $booking_id
                ]);
                error_log("[PaymentProcess] Host notification processed for host_id: " . $hostData['host_id'] . " for booking_id: " . $booking_id);
            } else {
                error_log("[PaymentProcess-Error] Host data NOT found for vehicle_id: " . $bookingData['vehicle_id'] . " and booking_id: " . $booking_id);
            }
        }
    }
} catch (Exception $notifEx) {
    error_log('[Notification-Fail] ' . $notifEx->getMessage());
    // Silently logged — never redirects to error. Payment is already confirmed.
}

// ── Always redirect to confirmation — payment is committed regardless of above ──
header("Location: booking_confirmation.php?booking_id=$booking_id");
exit;


