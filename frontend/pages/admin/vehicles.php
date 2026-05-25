<?php
// frontend/pages/admin/vehicles.php

require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';

requireAdmin();

$flash = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';
    $vid    = (int)($_POST['vehicle_id'] ?? 0);
    $source = $_POST['source'] ?? 'admin';

    if ($action === 'toggle_availability' && $vid) {
        if ($source === 'host') {
            $stmt = $conn->prepare("UPDATE host_vehicles SET availability = NOT availability WHERE id = :id");
        } else {
            $stmt = $conn->prepare("UPDATE vehicles SET availability = NOT availability WHERE id = :id");
        }
        $stmt->execute([':id' => $vid]);
        $flash = 'Vehicle availability updated.';
    } elseif ($action === 'delete' && $vid) {
        if ($source === 'host') {
            $stmt = $conn->prepare("DELETE FROM host_vehicles WHERE id = :id");
        } else {
            $stmt = $conn->prepare("UPDATE vehicles SET deleted_at = NOW() WHERE id = :id");
        }
        $stmt->execute([':id' => $vid]);
        $flash = 'Vehicle removed from fleet.';
    }

    if ($flash) {
        $_SESSION['flash_msg'] = $flash;
        header("Location: vehicles.php");
        exit;
    }
}

$flash = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

// ── Highlight details ─────────────────────────────────────────────────────────
$highlightId     = (int)($_GET['highlight'] ?? 0);
$highlightSource = $_GET['source'] ?? '';

// ── Fetch Summary Counts (combining vehicles and host_vehicles) ────────────────
$totalCount = (int)$conn->query("
    SELECT (SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL) + 
           (SELECT COUNT(*) FROM host_vehicles)
")->fetchColumn();

$activeCount = (int)$conn->query("
    SELECT (SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL AND availability = 1) + 
           (SELECT COUNT(*) FROM host_vehicles WHERE availability = 1)
")->fetchColumn();

$carCount = (int)$conn->query("
    SELECT (SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL AND type IN ('car', 'suv', 'sports', 'electric')) + 
           (SELECT COUNT(*) FROM host_vehicles WHERE type IN ('car', 'suv', 'sports', 'electric'))
")->fetchColumn();

$bikeCount = (int)$conn->query("
    SELECT (SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL AND type IN ('bike', 'motorbike')) + 
           (SELECT COUNT(*) FROM host_vehicles WHERE type IN ('bike', 'motorbike'))
")->fetchColumn();

// ── Fetch All Vehicles (UNION of vehicles and host_vehicles) ──────────────────
$stmt = $conn->query("
    SELECT id, name, model, year, type, fuel, seats, city, price, image, availability, created_at, 'admin' AS source 
    FROM vehicles 
    WHERE deleted_at IS NULL 
    UNION ALL
    SELECT id, name, model, year, type, fuel, seats, city, price_per_day AS price, image, availability, created_at, 'host' AS source 
    FROM host_vehicles 
    ORDER BY created_at DESC
");
$vehicles = $stmt->fetchAll();

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
    <link rel="stylesheet" href="../../css/main.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <title>SpinGo | Manage Fleet</title>
    <style>
        .summary-chips { display: flex; gap: 16px; margin-bottom: 24px; }
        .summary-chip { 
            font-size: 12px; font-weight: 700; color: var(--text-muted); 
            background: var(--white); padding: 6px 14px; border-radius: 8px; 
            border: 1px solid var(--border-light); box-shadow: var(--shadow-sm);
        }
        .summary-chip span { color: var(--primary); margin-left: 6px; }

        .v-thumb { width: 48px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border-light); }
        .v-type-badge { font-size: 10px; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; font-weight: 800; background: var(--bg-light); color: var(--text-main); }
        
        .v-status-active { background: #ecfdf5; color: #065f46; }
        .v-status-inactive { background: #fef2f2; color: #991b1b; }

        .v-source-badge { font-size: 9px; padding: 2px 6px; border-radius: 4px; font-weight: 700; display: inline-block; margin-top: 4px; text-transform: uppercase; }
        .host-badge { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .admin-badge { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

        .actions-cell { display: flex; gap: 8px; align-items: center; justify-content: center; }
        .btn-toggle { background: var(--bg-light); color: var(--text-main); border: 1px solid var(--border-light); padding: 5px; border-radius: 4px; cursor: pointer; transition: all 0.2s; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; }
        .btn-toggle:hover { border-color: var(--primary); color: var(--primary); }
        .btn-delete { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; padding: 5px; border-radius: 4px; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; }
        .btn-delete:hover { background: #fecaca; }
        
        .dash-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .dash-header-row h1 { margin: 0; }

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
    <?php 
    $navActive = 'admin';
    include '../../includes/navbar.php'; 
    ?>

    <div class="dash-wrapper">
        <div class="dash-header-row">
            <div>
                <h1>Fleet Management</h1>
                <p>Add, edit, and control availability of all vehicles.</p>
            </div>
            <a href="add-vehicle.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Vehicle
            </a>
        </div>

        <?php if ($flash): ?>
            <div class="dash-alert" style="background: #ecfdf5; color: #065f46; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #d1fae5;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Summary Bar -->
        <div class="summary-chips">
            <div class="summary-chip">Total<span><?= $totalCount ?></span></div>
            <div class="summary-chip">Active<span><?= $activeCount ?></span></div>
            <div class="summary-chip">Cars<span><?= $carCount ?></span></div>
            <div class="summary-chip">Bikes<span><?= $bikeCount ?></span></div>
        </div>

        <div class="dash-card">
            <div class="dash-card-header">
                <h3>All Vehicles</h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Fuel</th>
                            <th>Seats</th>
                            <th>City</th>
                            <th>Price/Day</th>
                            <th>Availability</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehicles as $v): 
                            $isHighlighted = ($highlightId === (int)$v['id'] && $highlightSource === $v['source']);
                        ?>
                        <tr class="<?= $isHighlighted ? 'highlighted-row' : '' ?>">
                            <td style="font-size: 12px; color: var(--text-muted);">#<?= $v['id'] ?></td>
                            <td>
                                <?php
                                $_raw = $v['image'] ?? '';
                                if (empty($_raw)) {
                                    $vImg = '';
                                } elseif (str_starts_with($_raw, 'http://') || str_starts_with($_raw, 'https://')) {
                                    $vImg = '../../img_proxy.php?url=' . urlencode($_raw);
                                } else {
                                    $vImg = '../../' . ltrim($_raw, '/');
                                }
                                ?>
                                <img src="<?= htmlspecialchars($vImg) ?>" alt="<?= htmlspecialchars($v['name']) ?>" class="v-thumb">
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($v['name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($v['model']) ?> (<?= $v['year'] ?>)</div>
                                <?php if ($v['source'] === 'host'): ?>
                                    <span class="v-source-badge host-badge">Host Listing</span>
                                <?php else: ?>
                                    <span class="v-source-badge admin-badge">Fleet</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="v-type-badge"><?= strtoupper($v['type']) ?></span></td>
                            <td style="font-size: 13px;"><?= htmlspecialchars($v['fuel']) ?></td>
                            <td><?= $v['seats'] ?></td>
                            <td style="font-size: 13px;"><?= htmlspecialchars($v['city']) ?></td>
                            <td style="font-weight: 700;">Rs. <?= number_format($v['price'], 0) ?></td>
                            <td>
                                <?php if ($v['availability']): ?>
                                    <span class="v-type-badge v-status-active">Active</span>
                                <?php else: ?>
                                    <span class="v-type-badge v-status-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <!-- Toggle Availability -->
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_availability">
                                        <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                        <input type="hidden" name="source" value="<?= $v['source'] ?>">
                                        <button type="submit" class="btn-toggle" title="Toggle Availability">
                                            <i class="fas <?= $v['availability'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Edit -->
                                    <a href="edit-vehicle.php?id=<?= $v['id'] ?>&source=<?= $v['source'] ?>" class="btn-toggle" title="Edit Vehicle">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <!-- Delete -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this vehicle?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                        <input type="hidden" name="source" value="<?= $v['source'] ?>">
                                        <button type="submit" class="btn-delete" title="Delete Vehicle">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../../js/app.js"></script>
    <script>
        // Scroll highlighted row into view on load
        window.addEventListener('DOMContentLoaded', () => {
            const highlighted = document.querySelector('.highlighted-row');
            if (highlighted) {
                highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>
</body>
</html>
