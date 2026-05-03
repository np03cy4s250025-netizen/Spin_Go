<?php
// frontend/pages/host_dashboard.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';
require_once '../../backend/models/HostVehicle.php';

requireLogin();
requireHost();

$user    = getCurrentUser();
$hostApp = new HostApplication($conn);
$host    = $hostApp->getByUserId($user['id']);
$navActive = 'host_dashboard';
$isApproved = ($host && $host['status'] === 'approved');

$hostId = $host['id'] ?? $host['user_id'];
$vehicles   = $isApproved ? (new HostVehicle($conn))->getVehiclesByHost($hostId) : [];

// ── Query Host Bookings ──────────────────────────────────────────────────────
$bookings = [];
if ($isApproved) {
    $stmtB = $conn->prepare("
        SELECT hba.status AS action_status, hba.created_at AS assigned_at,
               b.id AS booking_id, b.pickup_date, b.dropoff_date, b.total_price, b.status AS booking_status,
               hv.name AS vehicle_name, hv.image AS vehicle_image,
               u.full_name AS renter_name, u.email AS renter_email
        FROM host_booking_actions hba
        JOIN bookings b ON hba.booking_id = b.id
        JOIN host_vehicles hv ON b.vehicle_id = hv.id AND b.source = 'host'
        JOIN users u ON b.user_id = u.id
        WHERE hba.host_id = :host_id
        ORDER BY hba.created_at DESC
    ");
    $stmtB->execute([':host_id' => $hostId]);
    $bookings = $stmtB->fetchAll(PDO::FETCH_ASSOC);
}

$pendingBookings = array_filter($bookings, fn($b) => $b['action_status'] === 'pending' && $b['booking_status'] === 'pending');
$historyBookings = array_filter($bookings, fn($b) => $b['action_status'] !== 'pending' || $b['booking_status'] !== 'pending');

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Host Dashboard — manage your hosted vehicles and bookings.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .hd-btn-accept {
            background: #10b981;
            color: var(--white);
        }
        .hd-btn-accept:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        .hd-btn-reject {
            background: #ef4444;
            color: var(--white);
        }
        .hd-btn-reject:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }
    </style>
    <title>SpinGo | Host Dashboard</title>
</head>
<body>
    <?php 
    $navActive = 'host_dashboard';
    include '../includes/navbar.php'; 
    ?>

    <div class="dash-wrapper">

        <?php if ($flash): ?>
            <div class="dash-flash">
                <i class="fas fa-check-circle"></i><?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="dash-header">
            <h1>Host Dashboard</h1>
            <p>Welcome, <?= htmlspecialchars($user['full_name']) ?> — manage your hosted vehicles and booking requests here.</p>
        </div>





        <!-- ── Quick Actions ────────────────────────────────────── -->
        <div style="margin-bottom: 24px;">
            <a href="add_vehicle.php" class="btn btn-primary" style="margin-right:8px;">
                <i class="fas fa-plus"></i> Add Vehicle
            </a>
            <a href="manage_vehicles.php" class="btn btn-outline">
                <i class="fas fa-list"></i> All My Vehicles
            </a>
        </div>

        <!-- ── Booking Requests Section ───────────────────────────── -->
        <div class="dash-card" style="margin-bottom: 24px;">
            <div class="dash-card-header">
                <h2>Pending Booking Requests</h2>
            </div>

            <?php if (empty($pendingBookings)): ?>
                <div class="dash-empty" style="padding: 30px;">
                    <i class="fas fa-calendar-check" style="font-size: 28px; margin-bottom: 10px;"></i>
                    <p>No pending booking requests at this time.</p>
                </div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Renter</th>
                                <th>Pickup Date</th>
                                <th>Dropoff Date</th>
                                <th>Total Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingBookings as $b): ?>
                            <tr>
                                <td>
                                    <div class="dash-vehicle-name"><?= htmlspecialchars($b['vehicle_name']) ?></div>
                                    <span style="font-size:11px; color:#9ca3af;">Booking #SPG-<?= $b['booking_id'] ?></span>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($b['renter_name']) ?></div>
                                    <span style="font-size:11px; color:#9ca3af;"><?= htmlspecialchars($b['renter_email']) ?></span>
                                </td>
                                <td><?= date('d M Y', strtotime($b['pickup_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($b['dropoff_date'])) ?></td>
                                <td class="dash-td-price">Rs. <?= number_format($b['total_price'], 0) ?></td>
                                <td>
                                    <span class="dash-status-badge" style="background:#fef3c7; color:#92400e; margin: 0;">Pending</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── My Fleet ───────────────────────────────────────── -->
        <div class="dash-card" style="margin-bottom: 24px;">
            <div class="dash-card-header">
                <h2>My Fleet</h2>
                <a href="add_vehicle.php" class="hd-btn-sm hd-btn-primary">
                    <i class="fas fa-plus"></i> Add Vehicle
                </a>
            </div>

            <?php if (empty($vehicles)): ?>
                <div class="dash-empty">
                    <i class="fas fa-car-side"></i>
                    <p>You haven't listed any vehicles yet.</p>
                    <a href="add_vehicle.php" class="dash-empty-link">List your first vehicle &rarr;</a>
                </div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>Year</th>
                                <th>Price/Day</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $i => $v): ?>
                            <tr>
                                <td class="dash-td-id"><?= $i + 1 ?></td>
                                <td class="dash-vehicle-name"><?= htmlspecialchars($v['name']) ?></td>
                                <td><?= htmlspecialchars($v['model']) ?></td>
                                <td><?= htmlspecialchars($v['year']) ?></td>
                                <td class="dash-td-price">Rs. <?= number_format($v['price_per_day'], 0) ?></td>
                                <td>
                                    <?php if ($v['availability']): ?>
                                        <span class="dash-status-badge dash-status-confirmed hd-avail-yes">Available</span>
                                    <?php else: ?>
                                        <span class="dash-status-badge dash-status-cancelled hd-avail-no">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Booking History Section ────────────────────────────── -->
        <div class="dash-card">
            <div class="dash-card-header">
                <h2>Booking History &amp; Actions Archive</h2>
            </div>

            <?php if (empty($historyBookings)): ?>
                <div class="dash-empty" style="padding: 30px;">
                    <i class="fas fa-history" style="font-size: 28px; margin-bottom: 10px;"></i>
                    <p>No historical booking requests recorded.</p>
                </div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Renter</th>
                                <th>Pickup Date</th>
                                <th>Dropoff Date</th>
                                <th>Total Price</th>
                                <th>Your Action</th>
                                <th>Booking Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyBookings as $b): ?>
                            <tr>
                                <td>
                                    <div class="dash-vehicle-name"><?= htmlspecialchars($b['vehicle_name']) ?></div>
                                    <span style="font-size:11px; color:#9ca3af;">Booking #SPG-<?= $b['booking_id'] ?></span>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($b['renter_name']) ?></div>
                                    <span style="font-size:11px; color:#9ca3af;"><?= htmlspecialchars($b['renter_email']) ?></span>
                                </td>
                                <td><?= date('d M Y', strtotime($b['pickup_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($b['dropoff_date'])) ?></td>
                                <td class="dash-td-price">Rs. <?= number_format($b['total_price'], 0) ?></td>
                                <td>
                                    <?php if ($b['action_status'] === 'accepted'): ?>
                                        <span class="dash-status-badge dash-status-confirmed" style="background:#def7ec; color:#03543f;">Accepted</span>
                                    <?php else: ?>
                                        <span class="dash-status-badge dash-status-cancelled" style="background:#fde8e8; color:#9b1c1c;">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="dash-status-badge dash-status-<?= htmlspecialchars($b['booking_status']) ?>">
                                        <?= ucfirst(htmlspecialchars($b['booking_status'])) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.dash-wrapper -->

    <!-- Actions JavaScript removed (actions are managed by admin) -->
</body>
</html>