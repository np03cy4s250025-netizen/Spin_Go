<?php
// backend/models/Booking.php

class Booking {
    private $conn;
    private $table = 'bookings';

    public function __construct($database) {
        $this->conn = $database;
    }

    /**
     * Create a new booking — wrapped in a transaction with SELECT FOR UPDATE
     * to prevent race conditions / double-booking.
     * Default status is 'pending' (requires admin confirmation).
     */
    public function createBooking($user_id, $vehicle_id, $pickup_date, $dropoff_date, $license_file = null, $license_back = null, $source = 'admin') {
        // Ensure host-related tables exist (outside transaction to avoid implicit commits)
        if ($source === 'host') {
            try {
                $this->conn->exec("
                    CREATE TABLE IF NOT EXISTS `hosts` (
                        `user_id`      INT          NOT NULL,
                        `phone`        VARCHAR(20)  NOT NULL,
                        `gov_id_path`  VARCHAR(255) DEFAULT NULL,
                        `license_path` VARCHAR(255) DEFAULT NULL,
                        `license_file` VARCHAR(255) DEFAULT NULL,
                        `description`  TEXT         DEFAULT NULL,
                        `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                        `reviewed_by`  INT          DEFAULT NULL,
                        `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`user_id`),
                        FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
                        FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $this->conn->exec("
                    CREATE TABLE IF NOT EXISTS `host_booking_actions` (
                        `id`         INT AUTO_INCREMENT PRIMARY KEY,
                        `booking_id` INT NOT NULL,
                        `host_id`    INT NOT NULL,
                        `status`     ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
                        FOREIGN KEY (`host_id`)    REFERENCES `hosts`(`user_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (PDOException $e) {
                error_log('Booking::createBooking DDL — ' . $e->getMessage());
            }
        }

        try {
            $this->conn->beginTransaction();

            // Lock the correct vehicle row depending on source (admin vs host fleet)
            if ($source === 'host') {
                $vehicleStmt = $this->conn->prepare(
                    "SELECT id, host_id, price_per_day AS price FROM host_vehicles WHERE id = :id FOR UPDATE"
                );
            } else {
                $vehicleStmt = $this->conn->prepare(
                    "SELECT id, price FROM vehicles WHERE id = :id AND deleted_at IS NULL FOR UPDATE"
                );
            }
            $vehicleStmt->execute([':id' => $vehicle_id]);
            $vehicle = $vehicleStmt->fetch();

            if (!$vehicle) {
                $this->conn->rollBack();
                return ['status' => 'error', 'message' => 'Vehicle not found'];
            }

            // ── Fix 4: Reserve, Don't Block ─────────────────────────────────
            // Only CONFIRMED bookings hard-block dates. A pending booking is a
            // soft reservation — multiple users can attempt the same dates.
            // The first to complete payment (status→confirmed) wins.
            // Stale pending bookings are swept by the cron cleanup script.
            $checkStmt = $this->conn->prepare(
                "SELECT id FROM bookings
                 WHERE vehicle_id = :vid AND source = :src AND status = 'confirmed'
                 AND (pickup_date <= DATE_ADD(:end_date, INTERVAL 2 DAY) AND DATE_ADD(dropoff_date, INTERVAL 2 DAY) >= :start_date)"
            );
            $checkStmt->execute([
                ':vid'        => $vehicle_id,
                ':src'        => in_array($source, ['admin', 'host']) ? $source : 'admin',
                ':start_date' => $pickup_date,
                ':end_date'   => $dropoff_date,
            ]);

            if ($checkStmt->rowCount() > 0) {
                $this->conn->rollBack();
                return ['status' => 'error', 'message' => 'Vehicle already booked for these dates'];
            }

            // Calculate price
            $start       = strtotime($pickup_date);
            $end         = strtotime($dropoff_date);
            $days        = max(1, ceil(($end - $start) / (60 * 60 * 24)));

            // ── MAX DURATION CHECK ──────────────────────────────────────────
            if ($days > 90) {
                $this->conn->rollBack();
                return [
                    'status'  => 'error',
                    'message' => 'Booking duration cannot exceed 90 days (3 months).'
                ];
            }
            // ───────────────────────────────────────────────────────────────

            $total_price = $days * $vehicle['price'];

            // Insert — default status is 'pending' (admin confirms)
            $stmt = $this->conn->prepare(
                "INSERT INTO bookings (user_id, vehicle_id, source, pickup_date, dropoff_date, total_price, license_file, license_back, status, payment_status, created_at)
                 VALUES (:uid, :vid, :source, :pickup, :dropoff, :price, :license, :license_back, 'pending', 'pending', NOW())"
            );

            $stmt->execute([
                ':uid'          => $user_id,
                ':vid'          => $vehicle_id,
                ':source'       => in_array($source, ['admin', 'host']) ? $source : 'admin',
                ':pickup'       => $pickup_date,
                ':dropoff'      => $dropoff_date,
                ':price'        => $total_price,
                ':license'      => $license_file,
                ':license_back' => $license_back,
            ]);

            $booking_id = $this->conn->lastInsertId();

            if ($source === 'host') {
                // Fetch the vehicle host_id
                $host_id = $vehicle['host_id'];

                // Verify that host_id actually exists in the hosts table to prevent FK constraint violation
                $checkHostStmt = $this->conn->prepare("SELECT user_id FROM hosts WHERE user_id = :hid LIMIT 1");
                $checkHostStmt->execute([':hid' => $host_id]);
                if ($checkHostStmt->fetchColumn()) {
                    // Insert a pending host booking action row
                    $stmtAction = $this->conn->prepare(
                        "INSERT INTO host_booking_actions (booking_id, host_id, status)
                         VALUES (:bid, :hid, 'pending')"
                    );
                    $stmtAction->execute([
                        ':bid' => $booking_id,
                        ':hid' => $host_id
                    ]);
                } else {
                    // Log the inconsistency but do not block the booking
                    error_log("[Booking-Inconsistency] Vehicle host_id {$host_id} not found in hosts table for booking_id: {$booking_id}. Skipping host_booking_actions insert.");
                }
            }

            $this->conn->commit();

            return [
                'status'      => 'success',
                'message'     => 'Booking submitted — pending confirmation',
                'booking_id'  => $booking_id,
                'total_price' => $total_price,
                'days'        => $days,
            ];

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Booking::createBooking — ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'A server error occurred. Please try again.'];
        }
    }

    /**
     * Get all bookings for a user (excludes soft-deleted vehicles/users)
     */
    public function getBookingsByUser($user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT b.id, b.pickup_date, b.dropoff_date, b.total_price, b.status, b.payment_status,
                        b.transaction_id, b.created_at, b.license_verified, b.refund_status, b.refund_amount,
                        b.cancelled_by, b.cancelled_at, b.amount_paid, b.source,
                        COALESCE(v.id,   hv.id)    AS vehicle_id,
                        COALESCE(v.name, hv.name)  AS name,
                        COALESCE(v.image,hv.image) AS image,
                        COALESCE(v.type, hv.type)  AS type
                 FROM bookings b
                 LEFT JOIN vehicles      v  ON v.id  = b.vehicle_id AND b.source = 'admin' AND v.deleted_at IS NULL
                 LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
                 WHERE b.user_id = :uid
                 ORDER BY b.created_at DESC"
            );
            $stmt->execute([':uid' => $user_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Booking::getBookingsByUser — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking($booking_id, $user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT b.id, b.user_id, b.status, b.payment_status, b.amount_paid, b.pickup_date, u.email, u.full_name 
                 FROM bookings b 
                 JOIN users u ON b.user_id = u.id
                 WHERE b.id = :bid"
            );
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                return ['status' => 'error', 'message' => 'Booking not found'];
            }

            if ($booking['user_id'] != $user_id) {
                return ['status' => 'error', 'message' => 'Unauthorized'];
            }

            if ($booking['status'] === 'cancelled' || $booking['status'] === 'completed') {
                return ['status' => 'error', 'message' => 'Cannot cancel this booking'];
            }

            // Calculate days until pickup
            $pickupDate = new DateTime($booking['pickup_date']);
            $today      = new DateTime();
            $interval   = $today->diff($pickupDate);
            $daysUntil  = (int)$interval->format('%r%a');

            $refundStatus = 'none';
            $refundAmount = 0.00;
            $message      = 'Booking cancelled successfully.';

            if ($booking['payment_status'] === 'paid' && $booking['amount_paid'] > 0) {
                if ($daysUntil > 4) {
                    $refundStatus = 'full';
                    $refundAmount = $booking['amount_paid'];
                    $message      = 'Booking cancelled. A full refund of NPR ' . number_format($refundAmount, 2) . ' has been initiated.';
                } elseif ($daysUntil >= 1 && $daysUntil <= 4) {
                    $refundStatus = 'partial';
                    $refundAmount = $booking['amount_paid'] * 0.5;
                    $message      = 'Booking cancelled. A 50% refund of NPR ' . number_format($refundAmount, 2) . ' has been initiated (1-4 days notice).';
                } else {
                    $refundStatus = 'no_refund';
                    $refundAmount = 0.00;
                    $message      = 'Booking cancelled. No refund applicable as cancellation occurred on pickup day.';
                }
            } else {
                $refundStatus = 'none';
                $refundAmount = 0.00;
                $message      = 'Booking cancelled successfully.';
            }

            $updateStmt = $this->conn->prepare("
                UPDATE bookings 
                SET status = 'cancelled', 
                    refund_status = :rs, 
                    refund_amount = :ra, 
                    cancelled_by = 'user', 
                    cancelled_at = NOW() 
                WHERE id = :bid
            ");
            $updateStmt->execute([
                ':rs'  => $refundStatus,
                ':ra'  => $refundAmount,
                ':bid' => $booking_id
            ]);

            // Send Email Notification
            require_once __DIR__ . '/../config/mail.php';
            $subject = "Booking Cancellation Confirmation - SpinGo";
            $emailBody = "Hello {$booking['full_name']},<br><br>";
            $emailBody .= "Your booking #SPG-{$booking_id} has been cancelled.<br><br>";
            $emailBody .= "<b>Refund Status:</b> " . ($refundStatus === 'full' ? "Full Refund (NPR " . number_format($refundAmount, 2) . ")" : ($refundStatus === 'partial' ? "50% Refund (NPR " . number_format($refundAmount, 2) . ")" : ($refundStatus === 'no_refund' ? "No Refund" : "N/A"))) . "<br>";
            $emailBody .= "If a refund was initiated, it will reflect in your account within 5-7 business days.<br><br>";
            $emailBody .= "We hope to see you again soon!";
            
            sendEmail($booking['email'], $subject, getAdminEmailTemplate($subject, $emailBody));

            return ['status' => 'success', 'message' => $message];
        } catch (Exception $e) {
            error_log('Booking::cancelBooking — ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'An error occurred while cancelling the booking.'];
        }
    }

    /**
     * Get all bookings (admin — excludes soft-deleted users/vehicles)
     */
    public function getAllBookings() {
        try {
            $stmt = $this->conn->prepare(
                "SELECT b.id, b.pickup_date, b.dropoff_date, b.total_price, b.status, b.payment_status, 
                        b.transaction_id, b.created_at, b.license_verified, b.refund_status, b.refund_amount, 
                        b.cancelled_by, b.cancelled_at, b.amount_paid,
                        v.name AS vehicle_name, v.type AS vehicle_type,
                        u.full_name, u.email
                 FROM bookings b
                 JOIN vehicles v ON b.vehicle_id = v.id AND v.deleted_at IS NULL
                 JOIN users u ON b.user_id = u.id AND u.deleted_at IS NULL
                 ORDER BY b.created_at DESC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Booking::getAllBookings — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update booking status (admin)
     */
    public function updateBookingStatus($booking_id, $status) {
        try {
            $allowed = ['pending', 'confirmed', 'cancelled', 'completed'];
            if (!in_array($status, $allowed, true)) {
                return ['status' => 'error', 'message' => 'Invalid status'];
            }

            // Special handling for Admin Cancellation
            if ($status === 'cancelled') {
                $stmt = $this->conn->prepare(
                    "SELECT b.amount_paid, u.email, u.full_name FROM bookings b 
                     JOIN users u ON b.user_id = u.id WHERE b.id = :bid"
                );
                $stmt->execute([':bid' => $booking_id]);
                $booking = $stmt->fetch();

                if ($booking) {
                    $refundAmount = $booking['amount_paid'] ?? 0;
                    $refundStatus = $refundAmount > 0 ? 'full' : 'none';
                    $updateStmt = $this->conn->prepare("
                        UPDATE bookings 
                        SET status = 'cancelled', 
                            refund_status = :rs, 
                            refund_amount = :ra, 
                            cancelled_by = 'admin', 
                            cancelled_at = NOW() 
                        WHERE id = :bid
                    ");
                    $updateStmt->execute([':rs' => $refundStatus, ':ra' => $refundAmount, ':bid' => $booking_id]);

                    // Sync host action to rejected if pending
                    $hbaStmt = $this->conn->prepare("
                        UPDATE host_booking_actions 
                        SET status = 'rejected' 
                        WHERE booking_id = :bid AND status = 'pending'
                    ");
                    $hbaStmt->execute([':bid' => $booking_id]);

                    // Send Email Notification
                    require_once __DIR__ . '/../config/mail.php';
                    $subject = "Your Booking has been Cancelled by SpinGo";
                    $emailBody = "Hello {$booking['full_name']},<br><br>";
                    $emailBody .= "We regret to inform you that your booking #SPG-{$booking_id} has been cancelled by our administration.<br><br>";
                    if ($refundAmount > 0) {
                        $emailBody .= "<b>Refund Status:</b> Full Refund (NPR " . number_format($refundAmount, 2) . ") has been initiated.<br>";
                        $emailBody .= "The amount will be credited back to your original payment method within 5-7 business days.<br><br>";
                    } else {
                        $emailBody .= "<b>Refund Status:</b> N/A (No payment was collected).<br><br>";
                    }
                    $emailBody .= "We apologize for any inconvenience caused and suggest you try booking another vehicle from our fleet.";
                    
                    sendEmail($booking['email'], $subject, getAdminEmailTemplate($subject, $emailBody));

                    return ['status' => 'success', 'message' => 'Booking cancelled by Admin and refund initiated'];
                }
            }

            if ($status === 'completed') {
                $stmt = $this->conn->prepare("UPDATE bookings SET status = :status, completed_at = NOW() WHERE id = :bid AND license_verified = 1");
                $stmt->execute([':status' => $status, ':bid' => $booking_id]);
                if ($stmt->rowCount() === 0) {
                    return ['status' => 'error', 'message' => 'Cannot complete booking: license must be verified first.'];
                }
            } elseif ($status === 'confirmed') {
                $stmt = $this->conn->prepare("UPDATE bookings SET status = :status WHERE id = :bid");
                $stmt->execute([':status' => $status, ':bid' => $booking_id]);

                // Sync host action to accepted if pending
                $hbaStmt = $this->conn->prepare("
                    UPDATE host_booking_actions 
                    SET status = 'accepted' 
                    WHERE booking_id = :bid AND status = 'pending'
                ");
                $hbaStmt->execute([':bid' => $booking_id]);

                // Automatically send email & notification to user on admin confirmation
                try {
                    $detailsStmt = $this->conn->prepare("
                        SELECT b.*, u.email, u.full_name,
                               COALESCE(v.name, hv.name) AS vehicle_name,
                               hv.host_id
                        FROM bookings b
                        JOIN users u ON b.user_id = u.id
                        LEFT JOIN vehicles v ON v.id = b.vehicle_id AND b.source = 'admin'
                        LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
                        WHERE b.id = :bid
                        LIMIT 1
                    ");
                    $detailsStmt->execute([':bid' => $booking_id]);
                    $bookingData = $detailsStmt->fetch();

                    if ($bookingData) {
                        require_once __DIR__ . '/../config/mail.php';
                        $subject = "SpinGo — Your Booking #$booking_id Has Been Confirmed";
                        sendEmail($bookingData['email'], $subject, getBookingEmailTemplate($bookingData));

                        // Insert Notification
                        $notifStmt = $this->conn->prepare("
                            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
                            VALUES (:uid, 'user', 'booking_confirmed', :title, :message, 'dashboard.php', :bid)
                        ");
                        $notifStmt->execute([
                            ':uid' => $bookingData['user_id'],
                            ':title' => "Booking Confirmed — #$booking_id",
                            ':message' => "Your booking for {$bookingData['vehicle_name']} has been confirmed by the admin.",
                            ':bid' => $booking_id
                        ]);

                        // Insert Notification for Host (if it's a host vehicle booking)
                        if ($bookingData['source'] === 'host' && !empty($bookingData['host_id'])) {
                            $notifHostStmt = $this->conn->prepare("
                                INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
                                VALUES (:hid, 'host', 'booking_confirmed', :title, :message, 'host_dashboard.php', :bid)
                            ");
                            $notifHostStmt->execute([
                                ':hid' => $bookingData['host_id'],
                                ':title' => "Vehicle Booking Confirmed — #$booking_id",
                                ':message' => "The booking for your vehicle {$bookingData['vehicle_name']} by {$bookingData['full_name']} has been approved/confirmed by the admin.",
                                ':bid' => $booking_id
                            ]);
                        }
                    }
                } catch (Exception $ex) {
                    error_log('Booking::updateBookingStatus [Confirmed Notification Failed] — ' . $ex->getMessage());
                }
            } else {
                $stmt = $this->conn->prepare("UPDATE bookings SET status = :status WHERE id = :bid");
                $stmt->execute([':status' => $status, ':bid' => $booking_id]);
            }
            return ['status' => 'success', 'message' => 'Status updated'];
        } catch (Exception $e) {
            error_log('Booking::updateBookingStatus — ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
