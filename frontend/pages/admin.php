<?php
// frontend/pages/admin.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/Booking.php';
require_once '../../backend/models/Vehicle.php';

requireAdmin(); // ✅ Uses isAdmin() which checks $_SESSION['role'] correctly

$flash = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        // Prevent self-deletion
        if ($uid !== (int)$_SESSION['user_id']) {
            // Soft delete — preserves booking history
            $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id AND role != 'admin'");
            $stmt->execute([':id' => $uid]);
            $flash = 'User removed.';
        }
    }
}

// ── Fetch Global Stats ──────────────────────────────────────────────────────
$totalVehiclesCount = (int)$conn->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL")->fetchColumn();

// ── Fetch Health Metrics ──────────────────────────────────────────────────────
$totalRevenue = (float)$conn->query("SELECT SUM(total_price) FROM bookings WHERE payment_status = 'paid'")->fetchColumn();
$activeRentals = (int)$conn->query(
    "SELECT COUNT(*) FROM bookings 
     WHERE status IN ('confirmed', 'completed') AND payment_status = 'paid'
     AND pickup_date <= CURDATE() 
     AND dropoff_date >= CURDATE()"
)->fetchColumn();
$pendingAppsCount = (int)$conn->query("SELECT COUNT(*) FROM hosts WHERE status = 'pending'")->fetchColumn();
$approvedHostsCount = (int)$conn->query("SELECT COUNT(*) FROM hosts WHERE status = 'approved'")->fetchColumn();
$userCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND deleted_at IS NULL")->fetchColumn();

$oldestPending = $pendingAppsCount > 0 ? $conn->query("SELECT MIN(created_at) FROM hosts WHERE status = 'pending'")->fetchColumn() : null;
$waitDays = $oldestPending ? floor((time() - strtotime($oldestPending)) / 86400) : 0;

// ── Calculate Stat Bar Percentages (Min 4%, Max 100%) ────────────────────────
function calculatePct($current, $total) {
    if ($total <= 0) return 4;
    $pct = ($current / $total) * 100;
    return max(4, min(100, $pct));
}

// 1. Total Users (Portion registered this month)
$monthUsers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND deleted_at IS NULL")->fetchColumn();
$allUsers   = (int)$conn->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$userPct    = calculatePct($monthUsers, $allUsers);

// 2. Total Revenue (Portion earned this month)
$monthRev = (float)$conn->query("SELECT SUM(total_price) FROM bookings WHERE payment_status = 'paid' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();
$allRev   = (float)$conn->query("SELECT SUM(total_price) FROM bookings WHERE payment_status = 'paid'")->fetchColumn();
$revPct   = calculatePct($monthRev, $allRev);

// 3. Active Rentals (Fleet utilization)
$totalFleet = (int)$conn->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL AND availability = 1")->fetchColumn();
$activePct  = calculatePct($activeRentals, $totalFleet);

$activeLabel = $activeRentals > 0 ? 'Active' : 'None Out';
$activeClass = $activeRentals > 0 ? 'growth-ok' : 'growth-neutral';

// 4. Pending Apps (Density of pending tasks)
$allHostApps = (int)$conn->query("SELECT COUNT(*) FROM hosts")->fetchColumn();
$pendingPct  = calculatePct($pendingAppsCount, $allHostApps);

// ── Recent Fleet Activity (both admin fleet and host vehicles) ─────────────────
$recentBookingsStmt = $conn->prepare("
    SELECT b.*, u.full_name,
           COALESCE(v.name, hv.name) AS vehicle_name
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN vehicles      v  ON v.id  = b.vehicle_id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
    ORDER BY b.created_at DESC
    LIMIT 4
");
$recentBookingsStmt->execute();
$recentBookings = $recentBookingsStmt->fetchAll();

// ── Pending Host Applications ─────────────────────────────────────────────────
$pendingHostAppsStmt = $conn->prepare("
    SELECT h.*, u.full_name, u.email 
    FROM hosts h 
    JOIN users u ON h.user_id = u.id 
    WHERE h.status = 'pending' 
    ORDER BY h.created_at DESC 
    LIMIT 3
");
$pendingHostAppsStmt->execute();
$pendingHostApps = $pendingHostAppsStmt->fetchAll();

// ── Existing Data for Charts/Tables ───────────────────────────────────────────
$recentFailedLogins = $conn->query("
    SELECT email, ip_address, COUNT(*) as attempts, MAX(attempted_at) as last_attempt
    FROM login_attempts
    WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY email, ip_address
    ORDER BY attempts DESC
    LIMIT 10
")->fetchAll();
$bookingModel = new Booking($conn);
$vehicleModel = new Vehicle($conn);


// Fetch 5 Most Recent Vehicles for Preview
$recentVehicles = $conn->query("SELECT id, name, type, price, availability FROM vehicles WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5")->fetchAll();
$users    = $conn->query("SELECT id, full_name, email, role, is_verified, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC")->fetchAll();

$user      = getCurrentUser();
$navActive = 'admin';



function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    return substr($initials, 0, 2);
}

// ── Feature 2: Admin Login Popup for Pending Items ──
$newBookings = (int)$conn->query("
    SELECT COUNT(*) FROM bookings 
    WHERE status = 'pending' 
    AND created_at >= NOW() - INTERVAL 24 HOUR
")->fetchColumn();

$unverifiedLicenses = (int)$conn->query("
    SELECT COUNT(*) FROM bookings 
    WHERE status IN ('pending', 'confirmed') 
    AND license_verified = 0
")->fetchColumn();

$showAdminAlert = ($newBookings > 0 || $unverifiedLicenses > 0);
$showPopup = $showAdminAlert && !isset($_SESSION['admin_alert_shown']);
if ($showPopup) $_SESSION['admin_alert_shown'] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Admin Console — manage fleet, bookings, and users.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/Spin_Go/frontend/css/main.css">
    <link rel="stylesheet" href="/Spin_Go/frontend/css/dashboard.css">
    <title>SpinGo | Admin Console</title>

</head>
<body>
    <?php if ($showPopup): ?>
    <div id="admin-alert-overlay" style="
        position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
        display: flex; align-items: center; justify-content: center;
    ">
        <div style="
            background: #fff; border-radius: 20px;
            padding: 40px; max-width: 460px; width: 90%;
            box-shadow: 0 24px 60px rgba(0,0,0,0.2);
            animation: popIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
        ">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="
                    width:64px; height:64px; border-radius:16px;
                    background:#fef3c7; margin:0 auto 16px;
                    display:flex; align-items:center; justify-content:center;
                    font-size:28px;
                ">⚠️</div>
                <h2 style="font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:800; color:#1f1f2e; margin-bottom:8px;">
                    Action Required
                </h2>
                <p style="color:#6b7280; font-size:14px; line-height:1.6;">
                    You have pending items that need your attention before customers can pick up their vehicles.
                </p>
            </div>

            <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:28px;">
                <?php if ($newBookings > 0): ?>
                <div style="
                    display:flex; align-items:center; gap:14px;
                    background:#f0fdf4; border:1px solid #bbf7d0;
                    border-radius:12px; padding:14px 16px;
                ">
                    <i class="fas fa-calendar-check" style="color:#16a34a; font-size:20px; width:24px;"></i>
                    <div>
                        <div style="font-weight:700; color:#15803d; font-size:14px;">
                            <?= $newBookings ?> new booking<?= $newBookings > 1 ? 's' : '' ?> in last 24 hours
                        </div>
                        <div style="font-size:12px; color:#6b7280;">Payment confirmed — awaiting your review</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($unverifiedLicenses > 0): ?>
                <div style="
                    display:flex; align-items:center; gap:14px;
                    background:#fef3c7; border:1px solid #fde68a;
                    border-radius:12px; padding:14px 16px;
                ">
                    <i class="fas fa-id-card" style="color:#d97706; font-size:20px; width:24px;"></i>
                    <div>
                        <div style="font-weight:700; color:#b45309; font-size:14px;">
                            <?= $unverifiedLicenses ?> license<?= $unverifiedLicenses > 1 ? 's' : '' ?> pending verification
                        </div>
                        <div style="font-size:12px; color:#6b7280;">Customers cannot pick up until verified</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; gap:12px;">
                <a href="admin/bookings.php" style="
                    flex:1; text-align:center; padding:13px;
                    background:#3F3E46; color:#fff; border-radius:50px;
                    text-decoration:none; font-weight:700; font-size:14px;
                    font-family:'Space Grotesk',sans-serif;
                ">Review Now →</a>
                <button onclick="document.getElementById('admin-alert-overlay').style.display='none'" style="
                    flex:1; padding:13px; background:#f3f4f6;
                    color:#6b7280; border:none; border-radius:50px;
                    font-weight:700; font-size:14px; cursor:pointer;
                    font-family:'Space Grotesk',sans-serif;
                ">Dismiss</button>
            </div>
        </div>
    </div>

    <style>
    @keyframes popIn {
        from { transform: scale(0.85); opacity: 0; }
        to   { transform: scale(1);    opacity: 1; }
    }
    </style>
    <?php endif; ?>
    <?php 
    $navActive = 'admin';
    include '../includes/navbar.php'; 
    ?>
    <div class="dash-wrapper">

        <!-- Platform Health Header -->
        <div class="health-header">
            <div class="health-header-content">
                <h1>Platform Health</h1>
                <p>Real-time overview of the SpinGo ecosystem operations, vehicle fleet utilization, and host onboarding funnel.</p>
            </div>
            <a href="admin/export_report.php" class="btn btn-outline">
                <i class="fas fa-download"></i> Export Report
            </a>
        </div>

        <?php if ($flash): ?>
            <div class="dash-flash">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="health-stats-row">
            <div class="health-stat-card">
                <div class="h-stat-top">
                    <div class="h-stat-icon"><i class="fas fa-users"></i></div>
                    <span class="h-stat-growth growth-up">+12%</span>
                </div>
                <span class="h-stat-label">Total Users</span>
                <span class="h-stat-value"><?= number_format($userCount) ?></span>
                <div class="h-stat-bar"><div class="h-stat-progress" style="width: <?= $userPct ?>%"></div></div>
            </div>

            <div class="health-stat-card">
                <div class="h-stat-top">
                    <div class="h-stat-icon"><i class="fas fa-wallet"></i></div>
                    <span class="h-stat-growth growth-up">+8.4%</span>
                </div>
                <span class="h-stat-label">Total Revenue</span>
                <span class="h-stat-value">Rs. <?= number_format($totalRevenue) ?></span>
                <div class="h-stat-bar"><div class="h-stat-progress" style="width: <?= $revPct ?>%"></div></div>
            </div>

            <div class="health-stat-card">
                <div class="h-stat-top">
                    <div class="h-stat-icon"><i class="fas fa-car"></i></div>
                    <span class="h-stat-growth <?= $activeClass ?>"><?= $activeLabel ?></span>
                </div>
                <span class="h-stat-label">Active Rentals</span>
                <span class="h-stat-value"><?= $activeRentals ?></span>
                <div class="h-stat-bar"><div class="h-stat-progress" style="width: <?= $activePct ?>%"></div></div>
            </div>

            <div class="health-stat-card">
                <div class="h-stat-top">
                    <div class="h-stat-icon"><i class="fas fa-user-check"></i></div>
                    <?= $pendingAppsCount > 0 ? '<span class="h-stat-growth growth-action">Action Required</span>' : '<span class="h-stat-growth growth-ok">All Clear</span>' ?>
                </div>
                <span class="h-stat-label">Pending Applications</span>
                <span class="h-stat-value"><?= $pendingAppsCount ?></span>
                
                <?php if ($pendingAppsCount > 0): ?>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 6px;">Oldest waiting: <?= $waitDays ?> day<?= $waitDays !== 1 ? 's' : '' ?></p>
                <?php endif; ?>
                
                <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><?= $approvedHostsCount ?> approved partner<?= $approvedHostsCount !== 1 ? 's' : '' ?></p>
                
                <div class="h-stat-bar">
                    <div class="h-stat-progress" style="width: <?= $pendingPct ?>%; background: <?= $pendingAppsCount > 0 ? '#dc2626' : '#10b981' ?>;"></div>
                </div>

                <?php if ($pendingAppsCount > 0): ?>
                    <a href="approve_host.php" class="btn btn-primary" style="margin-top: 14px; font-size: 13px; padding: 8px 18px; display: inline-block;">Review Now →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-charts-grid">
            
            <!-- LEFT — main column -->
            <div class="admin-col-main">
                <!-- Recent Fleet Activity -->
                <div class="activity-card">
                    <div class="activity-card-header">
                        <div>
                            <h2>Recent Fleet Activity</h2>
                            <p>Monitoring the latest booking movements</p>
                        </div>
                        <a href="admin/bookings.php" class="view-all-link">View All <i class="fas fa-chevron-right"></i></a>
                    </div>

                    <div class="dash-table-wrap">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Vehicle</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBookings as $rb): ?>
                                <tr>
                                    <td>
                                        <div class="customer-cell">
                                            <div class="activity-avatar"><?= getInitials($rb['full_name']) ?></div>
                                            <div class="customer-info">
                                                <h4><?= htmlspecialchars($rb['full_name']) ?></h4>
                                            </div>
                                        </div>
                                    </td>
                                    <td><div class="vehicle-info"><?= htmlspecialchars($rb['vehicle_name']) ?></div></td>
                                    <td><div class="date-info"><?= date('M d', strtotime($rb['created_at'])) ?></div></td>
                                    <td>
                                        <span class="dash-status-badge dash-status-<?= $rb['status'] ?>" style="font-size: 10px;">
                                            <?= strtoupper($rb['status']) ?>
                                        </span>
                                    </td>
                                    <td><div class="amount-info" style="font-weight: 700;">Rs. <?= number_format($rb['total_price']) ?></div></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Vehicles Preview -->
                <div class="dash-card">
                    <div class="dash-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h2>Recently Added Fleet</h2>
                            <p>Newest additions to the SpinGo marketplace</p>
                        </div>
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <a href="admin/vehicles.php" style="font-size: 13px; font-weight: 600; color: var(--accent); text-decoration: none;">Manage All →</a>
                        </div>
                    </div>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Price/Day</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentVehicles)): ?>
                                    <?php foreach ($recentVehicles as $v): ?>
                                    <tr>
                                        <td>
                                            <div class="dash-vehicle-name" style="font-size: 14px;"><?= htmlspecialchars($v['name']) ?></div>
                                            <div style="font-size: 11px; color: var(--text-muted);">ID: #V-<?= $v['id'] ?></div>
                                        </td>
                                        <td><span class="dash-status-badge" style="background: var(--bg-light); color: var(--text-main);"><?= strtoupper($v['type']) ?></span></td>
                                        <td class="dash-td-price" style="font-weight: 600;">Rs. <?= number_format($v['price'], 0) ?></td>
                                        <td>
                                            <?php if ($v['availability']): ?>
                                                <span class="dash-status-badge dash-status-confirmed" style="font-size: 10px;">Active</span>
                                            <?php else: ?>
                                                <span class="dash-status-badge dash-status-cancelled" style="font-size: 10px;">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Registered Users Preview -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h2>Recent User Signups</h2>
                    </div>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $previewUsers = array_slice($users, 0, 5);
                                foreach ($previewUsers as $u): 
                                ?>
                                <tr>
                                    <td>
                                        <div class="dash-vehicle-name" style="font-size: 14px;"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                                    </td>
                                    <td>
                                        <span class="dash-status-badge" style="background: var(--bg-light); color: var(--text-main);"><?= strtoupper($u['role']) ?></span>
                                    </td>
                                    <td style="font-size: 12px;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /LEFT COLUMN -->

            <!-- RIGHT — side panel -->
            <div class="side-panel">
                
                <!-- Host Applications Panel -->
                <div class="host-apps-card" style="margin-bottom: 0;">
                    <h2>Host Applications</h2>
                    <p>Review pending partner applications.</p>

                    <?php if (empty($pendingHostApps)): ?>
                        <div style="opacity: 0.5; font-size: 13px; padding: 20px 0;">No pending applications.</div>
                    <?php endif; ?>

                    <?php foreach ($pendingHostApps as $ha): ?>
                    <div class="host-app-item">
                        <div class="h-app-top">
                            <div class="h-app-avatar">
                                <i class="fas fa-user" style="color: rgba(255,255,255,0.7)"></i>
                            </div>
                            <div class="h-app-info">
                                <h4><?= htmlspecialchars($ha['full_name']) ?></h4>
                                <p><?= htmlspecialchars($ha['email']) ?></p>
                            </div>
                        </div>
                        <div class="h-app-actions">
                            <form method="POST" action="approve_host.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= $ha['user_id'] ?>">
                                <button type="submit" class="btn-h-approve btn">Approve</button>
                            </form>
                            <form method="POST" action="approve_host.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="user_id" value="<?= $ha['user_id'] ?>">
                                <button type="submit" class="btn-h-reject btn">Reject</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <a href="approve_host.php" style="color: var(--white); font-size: 13px; font-weight: 600; text-decoration: none; display: block; text-align: center; margin-top: 10px;">
                        Manage All Applications &rarr;
                    </a>
                </div>

                <!-- Infrastructure Stats -->
                <div class="chart-card">
                    <div class="infra-title">
                        <i class="fas fa-server"></i> Infrastructure
                    </div>
                    
                    <div class="infra-stat">
                        <div class="infra-label-row">
                            <span>Server Uptime</span>
                            <span>99.9%</span>
                        </div>
                        <div class="infra-bar"><div class="infra-progress" style="width: 99.9%"></div></div>
                    </div>

                    <div class="infra-stat">
                        <div class="infra-label-row">
                            <span>API Response</span>
                            <span>142ms</span>
                        </div>
                        <div class="infra-bar"><div class="infra-progress blue" style="width: 70%"></div></div>
                    </div>
                </div>

                <!-- Security & Logins -->
                <div class="chart-card" style="margin-top: 24px;">
                    <h3 style="font-size: 14px; margin-bottom: 12px; font-weight: 700;">
                        <i class="fas fa-shield-alt" style="color: var(--primary);"></i> Failed Logins (24h)
                    </h3>
                    <?php if (empty($recentFailedLogins)): ?>
                        <p style="font-size: 12px; color: var(--text-muted);">No failed attempts recorded.</p>
                    <?php else: ?>
                        <div style="max-height: 200px; overflow-y: auto;">
                            <table style="width: 100%; font-size: 11px; border-collapse: collapse; text-align: left;">
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <th style="padding: 6px 0;">Email / IP</th>
                                    <th style="padding: 6px 0; text-align: right;">Attempts</th>
                                </tr>
                                <?php foreach ($recentFailedLogins as $fail): ?>
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <td style="padding: 6px 0;">
                                        <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($fail['email']) ?></div>
                                        <div style="color: var(--text-muted);"><?= htmlspecialchars($fail['ip_address']) ?></div>
                                        <div style="color: var(--text-muted); font-size: 10px;"><?= date('H:i', strtotime($fail['last_attempt'])) ?></div>
                                    </td>
                                    <td style="padding: 6px 0; text-align: right; font-weight: 700; color: #dc2626;">
                                        <?= $fail['attempts'] ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>


            </div><!-- /RIGHT COLUMN -->
        </div><!-- /admin-charts-grid -->

        <div style="margin-top: 40px;"></div>



    </div><!-- /.dash-wrapper -->

    <script src="../js/app.js" defer></script>


</body>
</html>
