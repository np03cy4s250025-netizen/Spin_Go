<?php
// frontend/pages/booking_confirmation.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

$booking_id = (int)($_GET['booking_id'] ?? 0);

// Fetch booking joined with vehicle name.
$stmt = $conn->prepare("
    SELECT b.*, 
           COALESCE(v.name, hv.name) as vehicle_name, 
           COALESCE(v.price, hv.price_per_day) as daily_rate
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON b.vehicle_id = hv.id AND b.source = 'host'
    WHERE b.id = :id
      AND b.user_id = :uid
      AND b.status IN ('pending', 'confirmed')
      AND b.payment_status IN ('paid', 'pending')
    LIMIT 1
");
$stmt->execute([':id' => $booking_id, ':uid' => $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: dashboard.php');
    exit;
}

$pickup  = new DateTime($booking['pickup_date']);
$dropoff = new DateTime($booking['dropoff_date']);
$days    = $pickup->diff($dropoff)->days ?: 1;
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
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/payment.css">
    <title>SpinGo | Booking Confirmed</title>
    <style>
        .conf-wrapper { padding: 60px 20px; display: flex; justify-content: center; background: var(--bg-body); min-height: calc(100vh - 80px); }
        .conf-card { background: var(--bg-card); padding: 40px; border-radius: 20px; max-width: 600px; width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; }
        
        .tracker-wrap { display: flex; flex-direction: column; gap: 16px; margin: 32px 0; text-align: left; background: var(--bg-body); padding: 24px; border-radius: 12px; }
        .tracker-step { display: flex; align-items: center; gap: 16px; }
        .tracker-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #fff; }
        .tracker-icon.done { background: #10b981; }
        .tracker-icon.wait { background: #f59e0b; }
        .tracker-text { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 600; color: var(--text-main); }
        
        .tracker-note { font-size: 14px; color: var(--text-muted); margin-top: 8px; line-height: 1.5; border-top: 1px solid var(--border-light); padding-top: 16px; }

        .summary-block { background: var(--bg-body); border: 1px solid var(--border-light); border-radius: 12px; text-align: left; margin-bottom: 32px; overflow: hidden; }
        .summary-row { display: flex; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid var(--border-light); }
        .summary-row:last-child { border-bottom: none; }
        .summary-label { color: var(--text-muted); font-size: 14px; }
        .summary-value { font-weight: 600; font-size: 14px; color: var(--text-main); }
        .summary-value.highlight { color: var(--primary); font-weight: 800; }
        
        .conf-actions { display: flex; gap: 16px; justify-content: center; }
        .conf-actions .btn { flex: 1; justify-content: center; text-decoration: none; }
        
        @media (max-width: 600px) {
            .conf-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="navbar-wrapper">
        <?php include '../includes/navbar.php'; ?>
    </div>

    <div class="conf-wrapper">
        <div class="conf-card" id="conf-card">
            <h1 style="font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 32px; margin-bottom: 8px;">Booking Requested!</h1>
            <p style="color: var(--text-muted);">Your booking request has been submitted successfully.</p>

            <div class="tracker-wrap">
                <div class="tracker-step">
                    <div class="tracker-icon done"><i class="fas fa-check"></i></div>
                    <div class="tracker-text">Payment Received</div>
                </div>
                <div class="tracker-step">
                    <div class="tracker-icon wait"><i class="fas fa-hourglass-half"></i></div>
                    <div class="tracker-text">Awaiting Admin Verification</div>
                </div>
                <div class="tracker-step">
                    <div class="tracker-icon wait"><i class="fas fa-hourglass-half"></i></div>
                    <div class="tracker-text">License Being Reviewed</div>
                </div>
                <div class="tracker-step">
                    <div class="tracker-icon wait"><i class="fas fa-hourglass-half"></i></div>
                    <div class="tracker-text">Pickup Ready</div>
                </div>
                <div class="tracker-note">
                    Our team will review your booking and driving license within 24 hours. You'll receive an email confirmation once approved.
                </div>
            </div>

            <div class="summary-block">
                <div class="summary-row">
                    <span class="summary-label">Booking Reference</span>
                    <span class="summary-value">#SPG-<?= $booking_id ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Transaction ID</span>
                    <span class="summary-value"><?= htmlspecialchars($booking['transaction_id'] ?? 'N/A') ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Vehicle</span>
                    <span class="summary-value"><?= htmlspecialchars($booking['vehicle_name']) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Pickup Date</span>
                    <span class="summary-value"><?= date('M d, Y', strtotime($booking['pickup_date'])) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Drop-off Date</span>
                    <span class="summary-value"><?= date('M d, Y', strtotime($booking['dropoff_date'])) ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Total Paid</span>
                    <span class="summary-value highlight">NPR <?= number_format($booking['total_price'], 0) ?></span>
                </div>
            </div>

            <div class="conf-actions">
                <a href="dashboard.php" class="btn btn-outline">
                    View My Booking &rarr;
                </a>
                <a href="print-booking.php?id=<?= $booking_id ?>" class="btn btn-primary" target="_blank">
                    Print / Save Confirmation &rarr;
                </a>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>
    <script src="../js/ui.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof SpinAlert !== 'undefined') {
                SpinAlert.success('Success!', 'Your payment was successful and your booking has been requested.');
            }
        });
    </script>
</body>
</html>
