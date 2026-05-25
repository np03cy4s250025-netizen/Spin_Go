<?php
// frontend/pages/admin/bookings.php

require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';

try {
    $conn->exec("ALTER TABLE bookings ADD COLUMN license_back VARCHAR(255) NULL AFTER license_file");
} catch (PDOException $e) {
    // Ignore error if column already exists
}

requireAdmin();

$flash_success = '';

// Helper for initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    // Handle License Verification
    if (isset($_POST['action']) && $_POST['action'] === 'verify_license') {
        $bid = (int)$_POST['booking_id'];
        $admin_id = $_SESSION['user_id'];
        
        $stmt = $conn->prepare("UPDATE bookings SET license_verified = 1, license_verified_at = NOW(), license_verified_by = :admin_id WHERE id = :bid");
        $stmt->execute([':admin_id' => $admin_id, ':bid' => $bid]);

        // Set the flash message *before* closing the session
        $_SESSION['flash_success'] = "License approved — booking #$bid is ready for pickup.";

        // Fetch user and vehicle details for email (handles both fleet sources)
        $emailStmt = $conn->prepare("
            SELECT u.email, u.full_name, b.user_id,
                   COALESCE(v.name, hv.name) AS vehicle_name,
                   b.pickup_date
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            LEFT JOIN vehicles      v  ON v.id  = b.vehicle_id AND b.source = 'admin'
            LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
            WHERE b.id = :id
        ");
        $emailStmt->execute([':id' => $bid]);
        $details = $emailStmt->fetch();

        if ($details) {
            session_write_close(); // Unlock session before sending email

            require_once '../../../backend/config/mail.php';
            $subject = "SpinGo — Your License Has Been Verified";
            $body = "Great news! Your driving license for booking #$bid (" . htmlspecialchars($details['vehicle_name']) . ") has been verified and approved. Your booking is confirmed and ready for pickup on " . date('d M Y', strtotime($details['pickup_date'])) . ".";
            sendEmail($details['email'], $subject, getAdminEmailTemplate($subject, $body));

            // Real-time notification for user
            $conn->prepare("
                INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
                VALUES (:uid, 'user', 'license_verified', 'License Verified ✓', 
                        'Your driving license for booking #$bid has been verified. Your vehicle is ready for pickup!', 
                        'dashboard.php')
            ")->execute([':uid' => $details['user_id']]);
        }

        header("Location: bookings.php?verified=1");
        exit;
    }

    // Handle Admin Cancellation (Full Refund)
    if (isset($_POST['action']) && $_POST['action'] === 'admin_cancel') {
        $bid = (int)$_POST['booking_id'];
        
        require_once '../../../backend/models/Booking.php';
        $bookingModel = new Booking($conn);
        $result = $bookingModel->updateBookingStatus($bid, 'cancelled');

        if ($result['status'] === 'success') {
            // ── Lookup: user_id, vehicle name, refund info, and host_id ──
            $adminCancelInfo = $conn->prepare("
                SELECT
                    b.user_id,
                    b.refund_amount,
                    b.source,
                    COALESCE(v.name, hv.name) AS vehicle_name,
                    hv.host_id
                FROM bookings b
                LEFT JOIN vehicles     v  ON v.id  = b.vehicle_id AND b.source = 'admin'
                LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
                WHERE b.id = :bid
                LIMIT 1
            ");
            $adminCancelInfo->execute([':bid' => $bid]);
            $aInfo = $adminCancelInfo->fetch(PDO::FETCH_ASSOC);

            $cancelUserId    = $aInfo['user_id']      ?? null;
            $cancelVehicle   = $aInfo['vehicle_name'] ?? 'the vehicle';
            $cancelRefund    = (float)($aInfo['refund_amount'] ?? 0);
            $cancelHostId    = $aInfo['host_id']      ?? null;

            $refundNote = $cancelRefund > 0
                ? 'A full refund of NPR ' . number_format($cancelRefund, 2) . ' has been initiated.'
                : 'No payment was collected, so no refund is required.';

            // ── Notification 3 (User): their booking was cancelled by admin ──
            if ($cancelUserId) {
                $conn->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
                    VALUES (:uid, 'user', 'cancellation', :title, :message, 'dashboard.php')
                ")->execute([
                    ':uid'     => $cancelUserId,
                    ':title'   => "Your Booking #$bid Was Cancelled",
                    ':message' => "Admin has cancelled your booking for $cancelVehicle. $refundNote",
                ]);
            }

            // ── Notification 4 (Host): admin cancelled a booking on their vehicle ──
            if ($cancelHostId) {
                $conn->prepare("
                    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
                    VALUES (:hid, 'host', 'cancellation', :title, :message, 'host_dashboard.php')
                ")->execute([
                    ':hid'     => $cancelHostId,
                    ':title'   => "Booking Cancelled by Admin on Your Vehicle",
                    ':message' => "Admin has cancelled booking #$bid for $cancelVehicle. Those dates are now available.",
                ]);
            }

            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
        header("Location: bookings.php?cancelled=1");
        exit;
    }

    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';

    if ($booking_id && $new_status) {
        require_once '../../../backend/models/Booking.php';
        $bookingModel = new Booking($conn);
        $result = $bookingModel->updateBookingStatus($booking_id, $new_status);
        
        if ($result['status'] === 'success') {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
        header('Location: bookings.php');
        exit;
    }
}

$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

// Fetch Summary Counts
$summary_stmt = $conn->query("SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status");
$counts = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
while ($row = $summary_stmt->fetch()) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['cnt'];
        $counts['total'] += (int)$row['cnt'];
    }
}

// Fetch All Bookings (Handles both Fleet and Host vehicles)
$stmt = $conn->query("
    SELECT b.*, u.full_name, u.email, 
           COALESCE(v.name, hv.name) AS vehicle_name
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN vehicles v ON b.vehicle_id = v.id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON b.vehicle_id = hv.id AND b.source = 'host'
    ORDER BY b.created_at DESC
");
$bookings = $stmt->fetchAll();

$highlightId = (int)($_GET['highlight'] ?? 0);

$navActive = 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/Spin_Go/frontend/css/main.css">
    <link rel="stylesheet" href="/Spin_Go/frontend/css/dashboard.css">
    <title>SpinGo | Manage Bookings</title>
    <style>
        /* ── Filter pills ───────────────────────────────────── */
        .filter-pills { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
        .filter-pill {
            padding: 8px 18px; border-radius: 50px; background: var(--white);
            border: 1px solid var(--border-light); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; color: var(--text-muted);
        }
        .filter-pill:hover { border-color: var(--primary); color: var(--primary); }
        .filter-pill.active { background: var(--primary); color: var(--white); border-color: var(--primary); }

        /* ── Summary chips ──────────────────────────────────── */
        .summary-chips { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .summary-chip { font-size: 12px; font-weight: 700; color: var(--text-muted); background: var(--white); padding: 4px 12px; border-radius: 6px; border: 1px solid var(--border); }
        .summary-chip span { color: var(--primary); margin-left: 4px; }

        /* ── Payment badge ──────────────────────────────────── */
        .status-badge-payment { font-size: 10px; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; font-weight: 800; }
        .payment-paid    { background: #ecfdf5; color: #065f46; }
        .payment-pending { background: #fffbeb; color: #92400e; }
        .payment-failed  { background: #fef2f2; color: #991b1b; }

        /* ── Customer cell ──────────────────────────────────── */
        .customer-info h4 { margin: 0; font-size: 14px; color: var(--text-main); }
        .customer-info p  { margin: 0; font-size: 11px; color: var(--text-muted); }
        .activity-avatar {
            width: 32px; height: 32px; background: var(--bg-light);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 11px; font-weight: 800; color: var(--primary);
        }

        /* ── Unified table column widths ────────────────────── */
        .license-col { min-width: 150px; vertical-align: top; padding-top: 10px; }
        .actions-col { min-width: 170px; vertical-align: top; padding-top: 10px; text-align: left; }

        /* ── Button group (stacked column) ──────────────────── */
        .btn-group-col {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: flex-start;
        }

        /* ── View buttons (License column) ──────────────────── */
        .btn-view-lic {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
            background: var(--bg-light); color: var(--text-main);
            border: 1px solid var(--border); cursor: pointer;
            white-space: nowrap;
        }
        .btn-view-lic:hover { border-color: var(--primary); color: var(--primary); }

        /* ── License status badges ───────────────────────────── */
        .lic-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 700; margin-top: 4px;
        }
        .lic-badge-verified { color: #10b981; }
        .lic-badge-pending  { color: #f59e0b; }
        .lic-badge-none     { color: var(--text-muted); font-style: italic; }

        /* ── Action buttons (Update column) ─────────────────── */
        /* Shared pill base — matches btn-reject-host exactly */
        .btn-tbl {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 700;
            cursor: pointer; white-space: nowrap;
            transition: background 0.2s; font-family: inherit;
        }
        /* Confirm (green) */
        .btn-tbl-confirm  { background: #dcfce7; color: #166534; border: 1px solid rgba(5,150,105,.25); }
        .btn-tbl-confirm:hover  { background: #bbf7d0; }
        /* Approve license (blue) */
        .btn-tbl-approve  { background: #dbeafe; color: #1e40af; border: 1px solid rgba(37,99,235,.2); }
        .btn-tbl-approve:hover  { background: #bfdbfe; }
        /* Complete (indigo) */
        .btn-tbl-complete { background: #ede9fe; color: #4c1d95; border: 1px solid rgba(109,40,217,.2); }
        .btn-tbl-complete:hover { background: #ddd6fe; }
        /* Cancel (red) */
        .btn-tbl-cancel   { background: #fef2f2; color: #991b1b; border: 1px solid rgba(220,38,38,.25); }
        .btn-tbl-cancel:hover   { background: #fee2e2; }

        .highlighted-row {
            background-color: rgba(234, 179, 8, 0.15) !important;
            border-left: 4px solid var(--primary) !important;
            animation: pulse-highlight 2s infinite alternate;
        }
        @keyframes pulse-highlight {
            0% { background-color: rgba(234, 179, 8, 0.08); }
            100% { background-color: rgba(234, 179, 8, 0.22); }
        }
    </style>
</head>
<body>
    <?php include '../../includes/navbar.php'; ?>

    <div class="dash-wrapper">
        <div class="dash-header">
            <h1>Booking Management</h1>
            <p>Monitor and update all vehicle reservations across the platform.</p>
        </div>

        <?php if ($flash_success): ?>
            <div class="dash-alert" style="background: #ecfdf5; color: #065f46; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #d1fae5;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>

        <?php 
        $flash_error = $_SESSION['flash_error'] ?? '';
        unset($_SESSION['flash_error']);
        if ($flash_error): ?>
            <div class="dash-alert" style="background: #fef2f2; color: #991b1b; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #fecaca;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>

        <!-- Summary Bar -->
        <div class="summary-chips">
            <div class="summary-chip">Total<span><?= $counts['total'] ?></span></div>
            <div class="summary-chip">Pending<span><?= $counts['pending'] ?></span></div>
            <div class="summary-chip">Confirmed<span><?= $counts['confirmed'] ?></span></div>
            <div class="summary-chip">Completed<span><?= $counts['completed'] ?></span></div>
            <div class="summary-chip">Cancelled<span><?= $counts['cancelled'] ?></span></div>
        </div>

        <!-- Filter Pills -->
        <div class="filter-pills">
            <button class="filter-pill active" onclick="filterBookings('all', this)">All</button>
            <button class="filter-pill" onclick="filterBookings('pending', this)">Pending</button>
            <button class="filter-pill" onclick="filterBookings('confirmed', this)">Confirmed</button>
            <button class="filter-pill" onclick="filterBookings('completed', this)">Completed</button>
            <button class="filter-pill" onclick="filterBookings('cancelled', this)">Cancelled</button>
        </div>

        <div class="dash-card">
            <div class="dash-card-header">
                <h3>All Bookings</h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="table" id="bookings-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Vehicle</th>
                            <th>Dates</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>License</th>
                            <th style="text-align: center;">Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): 
                            $isHighlighted = ($highlightId === (int)$b['id']);
                        ?>
                        <tr class="booking-row <?= $isHighlighted ? 'highlighted-row' : '' ?>" data-status="<?= $b['status'] ?>">
                            <td style="font-size: 12px; color: var(--text-muted);">#<?= $b['id'] ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="activity-avatar"><?= getInitials($b['full_name']) ?></div>
                                    <div class="customer-info">
                                        <h4><?= htmlspecialchars($b['full_name']) ?></h4>
                                        <p><?= htmlspecialchars($b['email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($b['vehicle_name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);">ID: #V-<?= $b['vehicle_id'] ?></div>
                            </td>
                            <td>
                                <div style="font-size: 13px;"><?= date('d M', strtotime($b['pickup_date'])) ?> - <?= date('d M', strtotime($b['dropoff_date'])) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= date('Y', strtotime($b['pickup_date'])) ?></div>
                            </td>
                            <td style="font-weight: 700;">Rs. <?= number_format($b['total_price'], 0) ?></td>
                            <td>
                                <span class="badge badge-<?= $b['status'] ?>">
                                    <?= ucfirst($b['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <span class="status-badge-payment payment-<?= $b['payment_status'] ?>">
                                        <?= strtoupper($b['payment_status']) ?>
                                    </span>
                                    <?php if ($b['payment_status'] === 'paid' && $b['transaction_id']): ?>
                                        <span style="font-size: 9px; color: var(--text-muted); font-family: monospace;">
                                            ID: <?= htmlspecialchars($b['transaction_id']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <!-- ── License column — view + status badge ONLY ─────────── -->
                            <td class="license-col">
                                <?php if ($b['license_file']): ?>
                                    <div class="btn-group-col">
                                        <button type="button"
                                                onclick="openLightbox('/Spin_Go/frontend/pages/view_document.php?dir=licenses&file=<?= htmlspecialchars($b['license_file']) ?>')"
                                                class="btn-view-lic">
                                            <i class="fas fa-eye"></i> Front
                                        </button>
                                        <?php if (!empty($b['license_back'])): ?>
                                            <button type="button"
                                                    onclick="openLightbox('/Spin_Go/frontend/pages/view_document.php?dir=licenses&file=<?= htmlspecialchars($b['license_back']) ?>')"
                                                    class="btn-view-lic">
                                                <i class="fas fa-eye"></i> Back
                                            </button>
                                        <?php else: ?>
                                            <span class="lic-badge lic-badge-none">Back not provided</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($b['license_verified'] == 1): ?>
                                        <span class="lic-badge lic-badge-verified">
                                            <i class="fas fa-check-circle"></i> Verified
                                        </span>
                                    <?php elseif (!in_array($b['status'], ['cancelled', 'completed'])): ?>
                                        <span class="lic-badge lic-badge-pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="lic-badge lic-badge-none">No license</span>
                                <?php endif; ?>
                            </td>

                            <!-- ── Update column — ALL actions via PHP conditions ───── -->
                            <td class="actions-col">
                                <?php if (in_array($b['status'], ['pending', 'confirmed'])): ?>
                                    <div class="btn-group-col">

                                        <?php if ($b['status'] === 'pending'): ?>
                                            <!-- Confirm booking — pending only -->
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                                <input type="hidden" name="status" value="confirmed">
                                                <button type="submit" class="btn-tbl btn-tbl-confirm">
                                                    <i class="fas fa-check"></i> Confirm
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!$b['license_verified'] && $b['license_file']): ?>
                                            <!-- Approve license — any unverified active booking -->
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="verify_license">
                                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                                <button type="submit" class="btn-tbl btn-tbl-approve">
                                                    <i class="fas fa-id-card"></i> Approve Lic.
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($b['status'] === 'confirmed' && $b['license_verified'] == 1): ?>
                                            <!-- Mark complete — confirmed and license verified only -->
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <button type="submit" class="btn-tbl btn-tbl-complete">
                                                    <i class="fas fa-flag-checkered"></i> Complete
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Cancel — always available for pending/confirmed -->
                                        <form method="POST" id="cancel-form-<?= $b['id'] ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="admin_cancel">
                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                            <button type="button" class="btn-tbl btn-tbl-cancel" onclick="openCancelModal(<?= $b['id'] ?>)">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </form>

                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 11px; color: var(--text-muted); font-style: italic;">No actions</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Lightbox Modal -->
    <div id="license-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center;">
        <div style="position:relative; max-width:90%; max-height:90%;">
            <button onclick="document.getElementById('license-modal').style.display='none'" style="position:absolute; top:-40px; right:0; background:none; border:none; color:white; font-size:30px; cursor:pointer;">&times;</button>
            <img id="license-img" src="" style="max-width:100%; max-height:90vh; border-radius:8px;">
        </div>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div id="cancel-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:#fff; padding:24px; border-radius:12px; width:100%; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0; font-family:'Space Grotesk',sans-serif; color:var(--text-main);">Cancel Booking?</h3>
            <p id="cancel-modal-text" style="color:var(--text-muted); font-size:14px; margin-bottom:24px; line-height:1.5;"></p>
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeCancelModal()" class="btn-tbl" style="background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb;">Never mind</button>
                <button type="button" id="cancel-modal-confirm" class="btn-tbl btn-tbl-cancel">Yes, cancel booking</button>
            </div>
        </div>
    </div>

    <script>
        let currentCancelFormId = null;

        function openCancelModal(bookingId) {
            currentCancelFormId = 'cancel-form-' + bookingId;
            document.getElementById('cancel-modal-text').innerText = 'Cancel booking #' + bookingId + '? This will trigger a full refund to the customer.';
            document.getElementById('cancel-modal').style.display = 'flex';
        }

        function closeCancelModal() {
            document.getElementById('cancel-modal').style.display = 'none';
            currentCancelFormId = null;
        }

        document.getElementById('cancel-modal-confirm').addEventListener('click', function() {
            if (currentCancelFormId) {
                document.getElementById(currentCancelFormId).submit();
            }
        });

        function openLightbox(path) {
            const modal = document.getElementById('license-modal');
            document.getElementById('license-img').src = path;
            modal.style.display = 'flex';
        }

        function filterBookings(status, el) {
            // Update UI
            document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
            el.classList.add('active');

            // Filter Table
            const rows = document.querySelectorAll('.booking-row');
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Scroll highlighted booking into view
        window.addEventListener('DOMContentLoaded', () => {
            const highlighted = document.querySelector('.highlighted-row');
            if (highlighted) {
                highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>
</body>
</html>
