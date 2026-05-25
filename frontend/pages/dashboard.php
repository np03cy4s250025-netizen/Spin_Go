<?php
// frontend/pages/dashboard.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/Booking.php';

requireLogin();

$user_id   = $_SESSION['user_id'];
$user      = getCurrentUser();

if (($user['role'] ?? '') === 'host') {
    header("Location: /Spin_Go/frontend/pages/host_dashboard.php");
    exit;
}

$navActive = 'dashboard';

$booking  = new Booking($conn);
$bookings = $booking->getBookingsByUser($user_id);

$stats = [
    'total'     => count($bookings),
    'active'    => count(array_filter($bookings, fn($b) => in_array($b['status'], ['pending', 'confirmed']))),
    'completed' => count(array_filter($bookings, fn($b) => $b['status'] === 'completed')),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage your SpinGo bookings and rental history.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <title>SpinGo | My Bookings</title>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <?php 
    $navActive = 'dashboard';
    include '../includes/navbar.php'; 
    ?>

    <div class="dash-wrapper">

        <!-- Flash Alerts (from cancel-booking.php redirects) -->
        <?php
            $alertMsg = $_GET['msg'] ?? '';
            $alertErr = $_GET['error'] ?? '';
            if ($alertMsg === 'cancelled'): ?>
            <div class="dash-alert dash-alert-success" role="alert">
                <i class="fas fa-check-circle"></i>
                Your booking has been cancelled successfully.
            </div>
        <?php elseif (!empty($alertErr)): ?>
            <div class="dash-alert dash-alert-error" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($alertErr) ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="dash-header">
            <h1>My Bookings</h1>
            <p>Hello, <?= htmlspecialchars($user['full_name']) ?> — manage your vehicle reservations below.</p>
        </div>

        <?php if (($user['role'] ?? '') === 'host'): ?>
        <!-- Cross-dashboard contextual banner for host users -->
        <div class="dash-crosslink-banner dash-crosslink-host">
            <i class="fas fa-gauge-high"></i>
            <span>You're viewing your <strong>renter</strong> booking history.</span>
            <a href="/Spin_Go/frontend/pages/host_dashboard.php">
                Switch to Host Dashboard <i class="fas fa-arrow-right"></i>
            </a>
        </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="dash-stats-grid">
            <div class="dash-stat-card">
                <div class="dash-stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div>
                    <span class="dash-stat-label">Total Bookings</span>
                    <span class="dash-stat-value"><?= $stats['total'] ?></span>
                </div>
            </div>

            <div class="dash-stat-card dash-stat-accent-yellow">
                <div class="dash-stat-icon">
                    <i class="fas fa-car"></i>
                </div>
                <div>
                    <span class="dash-stat-label">Active / Confirmed</span>
                    <span class="dash-stat-value"><?= $stats['active'] ?></span>
                </div>
            </div>

            <div class="dash-stat-card dash-stat-accent-blue">
                <div class="dash-stat-icon">
                    <i class="fas fa-flag-checkered"></i>
                </div>
                <div>
                    <span class="dash-stat-label">Completed Trips</span>
                    <span class="dash-stat-value"><?= $stats['completed'] ?></span>
                </div>
            </div>
        </div>

        <div class="dash-visuals">
            <!-- Main Content -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3>Recent Activity</h3>
                </div>
                <div>
                    <canvas id="userBookingsTrend"></canvas>
                </div>
            </div>
            
            <!-- Type Usage -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3>Vehicle Usage</h3>
                </div>
                <div>
                    <canvas id="userTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Booking History -->
        <div class="dash-card">
            <div class="dash-card-header">
                <h3>Booking History</h3>
                <a href="fleet.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>Book a Vehicle
                </a>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="dash-empty">
                    <i class="fas fa-calendar-times"></i>
                    <p>You haven't made any bookings yet.</p>
                    <a href="fleet.php" class="dash-empty-link">Explore our fleet &rarr;</a>
                </div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vehicle</th>
                                <th>Pick-up</th>
                                <th>Drop-off</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): 
                                $hasNotice = false;
                                $noticeType = '';
                                $noticeMsg = '';
                                $noticeIcon = '';

                                if ($b['status'] === 'pending') {
                                    $hasNotice = true;
                                    $noticeType = 'warning';
                                    $noticeIcon = 'fa-clock';
                                    $noticeMsg = 'Awaiting admin confirmation. You will receive an email verification once confirmed.';
                                } elseif ($b['status'] === 'confirmed') {
                                    $hasNotice = true;
                                    if ($b['license_verified'] == 0) {
                                        $noticeType = 'warning';
                                        $noticeIcon = 'fa-clock';
                                        $noticeMsg = 'Your booking is confirmed. Your license is pending admin verification before pickup.';
                                    } else {
                                        $noticeType = 'success';
                                        $noticeIcon = 'fa-check-circle';
                                        $noticeMsg = 'License verified — your booking is ready for pickup!';
                                    }
                                } elseif ($b['status'] === 'cancelled') {
                                    if ($b['cancelled_by'] === 'admin') {
                                        $hasNotice = true;
                                        $noticeType = 'info';
                                        $noticeIcon = 'fa-info-circle';
                                        $noticeMsg = 'This booking was cancelled by SpinGo. A full refund of NPR ' . number_format($b['refund_amount'], 2) . ' has been initiated.';
                                    } elseif ($b['refund_status'] === 'full') {
                                        $hasNotice = true;
                                        $noticeType = 'success';
                                        $noticeIcon = 'fa-check-circle';
                                        $noticeMsg = 'Refund of NPR ' . number_format($b['refund_amount'], 2) . ' has been processed and will reflect in your account within 5–7 business days.';
                                    } elseif ($b['refund_status'] === 'no_refund') {
                                        $hasNotice = true;
                                        $noticeType = 'error';
                                        $noticeIcon = 'fa-exclamation-circle';
                                        $noticeMsg = 'No refund applicable — booking was cancelled within 4 days of pickup.';
                                    }
                                } ?>
                                <tr class="booking-main-row <?= $hasNotice ? 'has-notice' : '' ?>">
                                <td class="dash-td-id">#<?= htmlspecialchars($b['id']) ?></td>
                                <td class="dash-td-vehicle">
                                    <div class="dash-vehicle-name"><?= htmlspecialchars($b['name']) ?></div>
                                    <div class="dash-vehicle-type"><?= ucfirst(htmlspecialchars($b['type'] ?? '')) ?></div>
                                    <?php if ($b['transaction_id']): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="dash-tx-id">TXID: <?= htmlspecialchars($b['transaction_id']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($hasNotice): ?>
                                        <div class="dash-inline-notice dash-inline-notice-<?= $noticeType ?>">
                                            <i class="fas <?= $noticeIcon ?>"></i>
                                            <span><?= $noticeMsg ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d M Y', strtotime($b['pickup_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($b['dropoff_date'])) ?></td>
                                <td class="dash-td-price">Rs. <?= number_format($b['total_price'], 2) ?></td>
                                <td>
                                    <div class="dash-status-col">
                                        <span class="dash-status-badge dash-status-<?= htmlspecialchars($b['status']) ?>">
                                            <?= ucfirst(htmlspecialchars($b['status'])) ?>
                                        </span>
                                        <span class="dash-status-badge dash-status-<?= htmlspecialchars($b['payment_status']) ?>">
                                            <?= ucfirst(htmlspecialchars($b['payment_status'])) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="dash-actions-wrap">
                                        <a href="print-booking.php?id=<?= $b['id'] ?>" class="dash-btn-print" title="Print Confirmation">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <?php if (in_array($b['status'], ['pending', 'confirmed'])): ?>
                                            <form action="cancel-booking.php" method="POST"
                                                   onsubmit="return confirm('Cancel booking #<?= $b['id'] ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="booking_id" value="<?= htmlspecialchars($b['id']) ?>">
                                                <button type="submit" class="dash-btn-cancel btn" title="Cancel Booking">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
    // ── CHARTS ────────────────────────────────────────────────────────
    const bookings = <?= json_encode($bookings) ?>;
    
    // Process data for Type Chart
    const types = bookings.reduce((acc, b) => {
        const t = b.type || 'unknown';
        acc[t] = (acc[t] || 0) + 1;
        return acc;
    }, {});

    new Chart(document.getElementById('userTypeChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(types).map(t => t.charAt(0).toUpperCase() + t.slice(1)),
            datasets: [{
                data: Object.values(types),
                backgroundColor: ['#3F3E46', '#7B6262', '#BFC4C4']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // Process data for Trend Chart (last 6 bookings)
    const recent = [...bookings].reverse().slice(-6);
    new Chart(document.getElementById('userBookingsTrend'), {
        type: 'bar',
        data: {
            labels: recent.map(b => b.name.split(' ')[0]),
            datasets: [{
                label: 'Rental Price (Rs.)',
                data: recent.map(b => b.total_price),
                backgroundColor: '#3F3E46',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } },
            plugins: { legend: { display: false } }
        }
    });
    </script>
</body>
</html>
