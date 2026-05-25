<?php
// frontend/pages/manage_vehicles.php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';
require_once '../../backend/models/HostVehicle.php';

requireLogin();
requireHost();

$hostApp = new HostApplication($conn);
$host    = $hostApp->getByUserId((int)$_SESSION['user_id']);

$hostId = $host['id'] ?? $host['user_id'];
$vehicleModel = new HostVehicle($conn);

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

// ── Handle Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    $vid = (int)($_POST['id'] ?? 0);
    
    if ($vid > 0) {
        if ($action === 'delete') {
            $res = $vehicleModel->deleteVehicle($vid, $hostId);
            if ($res['status'] === 'success') {
                $_SESSION['flash_success'] = 'Vehicle deleted successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to delete vehicle.';
            }
        } elseif ($action === 'toggle') {
            $res = $vehicleModel->toggleAvailability($vid, $hostId);
            if ($res['status'] === 'success') {
                $_SESSION['flash_success'] = 'Vehicle availability status updated successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to update availability.';
            }
        }
        header('Location: manage_vehicles.php');
        exit;
    }
}

$vehicles = $vehicleModel->getVehiclesByHost($hostId);

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
    <title>Manage Vehicles | SpinGo</title>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <div class="dash-wrapper">
        <!-- Page Header -->
        <div class="dash-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h1>Manage Your Fleet</h1>
                <p>Add, edit, toggle availability, or delete listed vehicles.</p>
            </div>
            <div>
                <a href="host_dashboard.php" class="btn btn-outline" style="margin-right:10px;">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="add_vehicle.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Vehicle
                </a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="dash-flash">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="dash-flash dash-flash-error" style="background:#fef2f2; border:1px solid #fee2e2; color:#b91c1c; padding:12px; border-radius:6px; margin-bottom:20px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <!-- Vehicles Grid Card -->
        <div class="dash-card">
            <div class="dash-card-header">
                <h2>All Listed Vehicles</h2>
            </div>
            
            <div class="dash-table-wrap">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Model</th>
                            <th>Year</th>
                            <th>Price/Day</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicles)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 40px 0; color: #6b7280;">
                                <i class="fas fa-car-crash" style="font-size:32px; display:block; margin-bottom:12px;"></i>
                                No vehicles found.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($vehicles as $v): 
                                $imgSrc = (str_starts_with($v['image'] ?? '', 'http')) ? $v['image'] : '../../' . $v['image'];
                            ?>
                            <tr>
                                <td>
                                    <?php if ($v['image']): ?>
                                        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="Vehicle" style="width:70px; height:45px; object-fit:cover; border-radius:6px; border: 1px solid var(--border-light);">
                                    <?php else: ?>
                                        <span style="font-size:11px; color:#9ca3af;">No image</span>
                                    <?php endif; ?>
                                </td>
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
                                <td>
                                    <div style="display:flex; gap:8px; justify-content:flex-end; align-items:center;">
                                        <!-- Edit -->
                                        <a href="edit_vehicle.php?id=<?= (int)$v['id'] ?>" class="btn btn-outline btn-sm" style="padding: 6px 12px; font-size:12px;">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>

                                        <!-- Toggle Availability -->
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Toggle availability for this vehicle?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="padding: 6px 12px; font-size:12px;">
                                                <i class="fas fa-sync-alt"></i> <?= $v['availability'] ? 'Make Unavailable' : 'Make Available' ?>
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Are you absolutely sure you want to delete this vehicle listing? This action cannot be undone.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                            <button type="submit" class="btn btn-sm" style="padding: 6px 12px; font-size:12px; background:#ef4444; color:#fff; border:none; border-radius:6px; cursor:pointer;">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
