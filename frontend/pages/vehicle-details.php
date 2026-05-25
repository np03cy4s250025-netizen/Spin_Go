<?php
// frontend/pages/vehicle-details.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/config/mail.php';
require_once '../../backend/models/Vehicle.php';
require_once '../../backend/models/Booking.php';

// Runtime column guards — idempotent, safe to run on every request
try { $conn->exec("ALTER TABLE bookings ADD COLUMN license_back VARCHAR(255) NULL AFTER license_file"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE bookings ADD COLUMN source ENUM('admin','host') NOT NULL DEFAULT 'admin' AFTER vehicle_id"); } catch (PDOException $e) {}

$vehicleId = (int)($_GET['id'] ?? 0);
$source    = in_array($_GET['source'] ?? '', ['admin', 'host']) ? $_GET['source'] : null;
$error   = '';
$success = '';

if (!$vehicleId) {
    header('Location: fleet.php');
    exit;
}

// Get vehicle details
$vehicleObj  = new Vehicle($conn);
$vehicleData = $vehicleObj->readOne($vehicleId, $source);

if (!$vehicleData) {
    header('Location: fleet.php');
    exit;
}

// ── Image source resolution ───────────────────────────────────────────────────
// External URLs → routed through server-side proxy to bypass hotlink blocking.
// Local uploads (e.g. "uploads/vehicles/file.jpg") → direct relative path.
// vehicle-details.php lives at frontend/pages/, so "../" = frontend/.
$_rawImg = $vehicleData['image'] ?? '';
if (empty($_rawImg)) {
    $imgSrc = '';
} elseif (str_starts_with($_rawImg, 'http://') || str_starts_with($_rawImg, 'https://')) {
    $imgSrc = '../img_proxy.php?url=' . urlencode($_rawImg);
} else {
    $imgSrc = '../' . ltrim($_rawImg, '/');
}


// ── Handle booking submission ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    csrf_verify();

    // Fix 2 — Hosts are providers, not renters. Block any booking attempt.
    if (($_SESSION['role'] ?? 'user') === 'host') {
        $error = 'As a host, you list vehicles — you cannot book them. Please use a separate renter account if you wish to rent.';
    } else {

    $ownerCheck = $conn->prepare(
        "SELECT id FROM host_vehicles
         WHERE id = :vid AND host_id = :uid LIMIT 1"
    );
    $ownerCheck->execute([':vid' => $vehicleId, ':uid' => $_SESSION['user_id']]);
    if ($ownerCheck->fetchColumn()) {
        $error = 'You cannot book a vehicle that you own.'; // keep existing owner check
    } else {
        $pickup_date  = $_POST['pickup_date']  ?? '';
        $dropoff_date = $_POST['dropoff_date'] ?? '';
        $license_file = $_FILES['license']      ?? null;

        if (empty($pickup_date) || empty($dropoff_date)) {
            $error = 'Please select both pickup and drop-off dates.';
        } elseif (!$license_file || $license_file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Driving license document is required.';
        } else {
            // --- Improved Date Validation ---
            $pickupTimestamp  = strtotime($pickup_date);
            $dropoffTimestamp = strtotime($dropoff_date);
            $todayTimestamp   = strtotime('today');

            // Validate date format and logic
            if (!$pickupTimestamp || !$dropoffTimestamp) {
                $error = 'Invalid date format provided.';
            } elseif ($pickupTimestamp < $todayTimestamp) {
                $error = 'Pickup date cannot be in the past.';
            } elseif ($dropoffTimestamp <= $pickupTimestamp) {
                $error = 'Drop-off date must be after pickup date.';
            } elseif (($dropoffTimestamp - $pickupTimestamp) > (90 * 24 * 60 * 60)) {
                $error = 'Booking duration cannot exceed 90 days (3 months). Please contact us for long-term rentals.';
            } elseif ($pickupTimestamp > strtotime('+3 months')) {
                $error = 'Pickup date cannot be more than 3 months in advance.';
            } else {
                // --- Secure File Upload ---
                // 1. Server-side MIME validation (do NOT trust $_FILES['type'])
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($license_file['tmp_name']);

                // 2. Strict type check (images only as per requirement)
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                $maxSize      = 5 * 1024 * 1024; // 5MB

                if (!in_array($mime, $allowedMimes)) {
                    $error = 'Invalid file type. Please upload a clear JPG, PNG, or WebP scan of your license.';
                } elseif ($license_file['size'] > $maxSize) {
                    $error = 'File size exceeds the 5MB limit.';
                } else {
                    $uploadDir = '../../uploads/licenses/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    // 3. Randomize filename and prevent execution
                    $ext = pathinfo($license_file['name'], PATHINFO_EXTENSION);
                    $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));

                    // Map MIME to extension for extra safety
                    $mimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];
                    $safeExt = $mimeToExt[$mime] ?? $ext;

                    $newFileName = 'lic_' . bin2hex(random_bytes(16)) . '_' . time() . '.' . $safeExt;
                    $uploadPath  = $uploadDir . $newFileName;

                    $license_back = $_FILES['license_back'] ?? null;
                    $backFileName = null;

                    $uploadOk = move_uploaded_file($license_file['tmp_name'], $uploadPath);

                    if ($uploadOk && $license_back && $license_back['error'] === UPLOAD_ERR_OK) {
                        $finfoBack = new finfo(FILEINFO_MIME_TYPE);
                        $mimeBack  = $finfoBack->file($license_back['tmp_name']);
                        if (in_array($mimeBack, $allowedMimes) && $license_back['size'] <= $maxSize) {
                            $extBack = pathinfo($license_back['name'], PATHINFO_EXTENSION);
                            $extBack = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $extBack));
                            $safeExtBack = $mimeToExt[$mimeBack] ?? $extBack;
                            $backFileName = 'lic_back_' . bin2hex(random_bytes(16)) . '_' . time() . '.' . $safeExtBack;
                            move_uploaded_file($license_back['tmp_name'], $uploadDir . $backFileName);
                        }
                    }

                    if ($uploadOk) {
                        $booking       = new Booking($conn);
                        $bookingResult = $booking->createBooking(
                            $_SESSION['user_id'],
                            $vehicleId,
                            $pickup_date,
                            $dropoff_date,
                            $newFileName,
                            $backFileName,
                            $vehicleData['source']  // 'admin' or 'host' — resolved from database vehicle lookup
                        );

                        if ($bookingResult['status'] === 'success') {
                            $bookingId = $bookingResult['booking_id'];
                            $success   = "Booking submitted! Redirecting to payment…";
                            header("refresh:2;url=payment.php?booking_id=$bookingId");
                        } else {
                            $error = $bookingResult['message'] ?? 'Booking failed.';
                        }
                    } else {
                        $error = 'Failed to upload license document securely.';
                    }
                }
            }
        }
    } // end else (not own vehicle)

    } // end else (not a host — host guard from Fix 2)
}

$isLoggedIn = isLoggedIn();
$user       = getCurrentUser();
$navActive  = 'fleet';

// Derive some display-ready values
$vehicleType     = ucfirst(htmlspecialchars($vehicleData['type'] ?? 'Car'));
$vehicleCity     = strtoupper(htmlspecialchars($vehicleData['city'] ?? ''));
$vehicleCapacity = htmlspecialchars($vehicleData['seats'] ?? '—') . ' Seats';
$vehicleFuel     = htmlspecialchars($vehicleData['fuel'] ?? '—');
$vehicleTransmission = htmlspecialchars($vehicleData['transmission'] ?? 'Manual');
$vehicleYear     = htmlspecialchars($vehicleData['year'] ?? '—');
$vehicleDesc     = htmlspecialchars($vehicleData['description'] ?? '');

// ── Fetch booked dates for Flatpickr ─────────────────────────────────────────
$bookedRanges = $conn->prepare("
    SELECT pickup_date, dropoff_date, status 
    FROM bookings 
    WHERE vehicle_id = :vid 
    AND status IN ('confirmed','pending') 
    AND dropoff_date >= CURDATE()
");
$bookedRanges->execute([':vid' => $vehicleId]);
$bookedDatesRaw = $bookedRanges->fetchAll(PDO::FETCH_ASSOC);

$disabledDates = [];
foreach ($bookedDatesRaw as $range) {
    $start = new DateTime($range['pickup_date']);
    $end   = new DateTime($range['dropoff_date']);
    if ($range['status'] === 'confirmed') {
        $end->modify('+2 days');
    }
    while ($start <= $end) {
        $disabledDates[] = $start->format('Y-m-d');
        $start->modify('+1 day');
    }
}
$disabledDatesJson = json_encode(array_values(array_unique($disabledDates)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Book <?= htmlspecialchars($vehicleData['name']) ?> on SpinGo — fast, insured, hassle-free.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../css/vehicle-details.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../css/pages.css?v=<?= time() ?>">
    <style>
        /* Terms & Conditions Checkbox */
        .terms-checkbox-wrapper {
            background: #f8fafc;
            border: 1.5px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin-bottom: 14px;
            transition: border-color 0.2s;
        }
        .terms-checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
        }
        .terms-checkbox-label input[type="checkbox"] {
            display: none;
        }
        .terms-checkbox-custom {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-light);
            border-radius: 5px;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
            transition: background 0.2s, border-color 0.2s;
        }
        .terms-checkbox-label input[type="checkbox"]:checked + .terms-checkbox-custom {
            background: var(--primary, #0f4c81);
            border-color: var(--primary, #0f4c81);
        }
        .terms-checkbox-label input[type="checkbox"]:checked + .terms-checkbox-custom::after {
            content: '';
            display: block;
            width: 5px;
            height: 9px;
            border: 2px solid #fff;
            border-top: none;
            border-left: none;
            transform: rotate(45deg) translate(-1px, -1px);
        }
        .terms-checkbox-text {
            font-size: 13px;
            color: var(--text-sub, #555);
            line-height: 1.5;
        }
        .terms-checkbox-text a {
            color: var(--primary, #0f4c81);
            text-decoration: underline;
            font-weight: 600;
        }

        .custom-calendar {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 16px;
            background: var(--white);
            margin-bottom: 8px;
            user-select: none;
        }
        .cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            font-weight: 700;
            font-size: 15px;
            color: var(--text-main);
        }
        .cal-header button {
            background: none; border: none; font-size: 14px; cursor: pointer; color: var(--text-muted);
            padding: 4px 8px;
        }
        .cal-header button:hover { color: var(--text-main); }
        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            justify-items: center;
        }
        .cal-day { 
            width: 100%; max-width: 44px; aspect-ratio: 1; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all 0.2s ease;
        }
        .cal-day.available  { background: #d1fae5; color: #065f46; }
        .cal-day.available:hover { background: #a7f3d0; }
        .cal-day.booked     { background: #fee2e2; color: #991b1b; cursor: not-allowed; }
        .cal-day.buffer     { background: #fef9c3; color: #854d0e; cursor: not-allowed; }
        .cal-day.past       { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; opacity: 0.6; }
        .cal-day.selected   { background: var(--primary); color: #fff; box-shadow: 0 4px 10px rgba(99,102,241,0.3); transform: scale(1.05); }
        .cal-day.in-range   { background: #e0e7ff; color: #3730a3; border-radius: 4px; }

        .booking-note {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .booking-note i { font-size: 13px; }

        input[type="file"] {
            display: none;
        }

        /* Premium Modal Styles */
        .legal-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .legal-modal-overlay.active {
            opacity: 1;
        }

        .legal-modal-container {
            background: #ffffff;
            width: 90%;
            max-width: 750px;
            max-height: 85vh;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateY(30px) scale(0.96);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 255, 255, 0.7);
        }

        .legal-modal-overlay.active .legal-modal-container {
            transform: translateY(0) scale(1);
        }

        .legal-modal-close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            color: #475569;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .legal-modal-close-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
            transform: rotate(90deg);
        }

        .legal-modal-body {
            padding: 40px 32px;
            overflow-y: auto;
            flex: 1;
        }

        /* Legal text modal specific overrides */
        .legal-modal-body .legal-wrap {
            padding: 0 !important;
            max-width: 100% !important;
            min-height: auto !important;
            background: transparent !important;
        }

        .legal-modal-body .back-btn {
            display: none !important;
        }

        /* Spinner Styles */
        .legal-spinner-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 0;
            gap: 16px;
            color: #475569;
        }

        .legal-spinner {
            width: 40px;
            height: 40px;
            border: 3.5px solid #cbd5e1;
            border-top-color: var(--primary, #0f4c81);
            border-radius: 50%;
            animation: legal-spin 0.8s linear infinite;
        }

        @keyframes legal-spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 640px) {
            .legal-modal-body {
                padding: 32px 20px 24px;
            }
            .legal-modal-container {
                max-height: 90vh;
                border-radius: 16px;
            }
        }
    </style>
    <title>SpinGo | <?= htmlspecialchars($vehicleData['name']) ?></title>
</head>
<body>

    <?php 
    $navActive = 'fleet';
    include '../includes/navbar.php'; 
    ?>

    <div class="details-page-wrapper">

        <!-- ← Return to Fleet (above the grid) -->
        <a href="fleet.php" class="details-back-link">
            <i class="fas fa-arrow-left"></i> Return to Fleet
        </a>

        <div class="container">
            <div class="details-wrapper">

                <!-- ══ LEFT COLUMN ══════════════════════════════ -->
                <div class="details-left details-content">

                    <!-- Vehicle Image -->
                    <div class="details-image">
                        <?php if ($imgSrc): ?>
                            <img
                                src="<?= htmlspecialchars($imgSrc) ?>"
                                alt="<?= htmlspecialchars($vehicleData['name']) ?>"
                                class="details-vehicle-photo"
                                loading="lazy"
                                onerror="this.style.display='none'; document.getElementById('dv-icon').style.display='flex';"
                            >
                            <i id="dv-icon" class="fas <?= $vehicleData['type'] === 'bike' ? 'fa-motorcycle' : 'fa-car-side' ?> details-image-icon" style="display:none;"></i>
                        <?php else: ?>
                            <i class="fas <?= $vehicleData['type'] === 'bike' ? 'fa-motorcycle' : 'fa-car-side' ?> details-image-icon"></i>
                        <?php endif; ?>
                    </div>

                    <!-- Category + City badges -->
                    <div class="details-badge-row">
                        <span class="details-badge"><?= $vehicleType ?></span>
                        <span class="details-badge"><i class="fas fa-map-marker-alt" style="font-size:9px;"></i> <?= $vehicleCity ?></span>
                    </div>

                    <!-- Name + Price -->
                    <div class="details-name-price">
                        <h1><?= htmlspecialchars($vehicleData['name']) ?></h1>
                        <div class="details-price-block">
                            <div class="details-price-label">Starting From</div>
                            <div class="vehicle-price">
                                Rs. <?= number_format($vehicleData['price'], 0) ?>
                                <span class="vehicle-price-period">/ day</span>
                            </div>
                        </div>
                    </div>

                    <!-- 4-card spec row -->
                    <div class="vehicle-specs">
                        <div class="spec-item">
                            <i class="fas fa-chair"></i>
                            <span class="spec-label">Capacity</span>
                            <span class="spec-value"><?= htmlspecialchars($vehicleData['seats'] ?? '—') ?> Seats</span>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-gas-pump"></i>
                            <span class="spec-label">Fuel Type</span>
                            <span class="spec-value"><?= $vehicleFuel ?></span>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-cog"></i>
                            <span class="spec-label">Transmission</span>
                            <span class="spec-value"><?= $vehicleTransmission ?></span>
                        </div>
                        <div class="spec-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="spec-label">Model Year</span>
                            <span class="spec-value"><?= $vehicleYear ?></span>
                        </div>
                    </div>

                    <!-- Description + Features -->
                    <div class="details-description">
                        <?php if (!empty($vehicleData['tagline'])): ?>
                            <h2><?= htmlspecialchars($vehicleData['tagline']) ?></h2>
                        <?php else: ?>
                            <h2><?= $vehicleType === 'Bike' ? 'Ride Ready' : 'Drive Ready' ?></h2>
                        <?php endif; ?>

                        <?php if (!empty($vehicleDesc)): ?>
                            <p><?= $vehicleDesc ?></p>
                        <?php else: ?>
                            <p>
                                This <?= strtolower($vehicleType) ?> is available for rental in <?= htmlspecialchars($vehicleData['city'] ?? 'Nepal') ?>.
                                Enjoy a comfortable, reliable, and fully insured journey with SpinGo.
                                Book today and hit the road with confidence.
                            </p>
                        <?php endif; ?>

                        <!-- Feature checklist — 2 columns -->
                        <div class="details-features">
                            <div class="details-feature-item">
                                <i class="fas fa-check-circle"></i> Fully Insured
                            </div>
                            <div class="details-feature-item">
                                <i class="fas fa-check-circle"></i> Instant Booking
                            </div>
                            <div class="details-feature-item">
                                <i class="fas fa-check-circle"></i> 24/7 Support
                            </div>
                        </div>
                    </div>

                </div><!-- /details-left -->

                <!-- ══ RIGHT COLUMN — Booking card ══════════════ -->
                <div class="details-right">

                    <?php if ($isLoggedIn): ?>
                        <?php if (($user['role'] ?? 'user') === 'host'): ?>
                        <!-- Host info panel — hosts cannot rent -->
                        <div class="booking-form-card booking-host-blocked">
                            <div class="host-blocked-icon">
                                <i class="fas fa-house-user"></i>
                            </div>
                            <h3>You're a SpinGo Host</h3>
                            <p class="host-blocked-desc">
                                Host accounts are set up to list and manage vehicles — not to make bookings.
                                If you'd like to rent a vehicle, please sign in with a separate renter account.
                            </p>
                            <a href="/Spin_Go/frontend/pages/register.php" class="btn btn-primary" style="width:100%; text-align:center; margin-top:8px;">
                                <i class="fas fa-user-plus"></i> Create a Renter Account
                            </a>
                            <a href="/Spin_Go/frontend/pages/host_dashboard.php" class="btn btn-outline" style="width:100%; text-align:center; margin-top:8px;">
                                <i class="fas fa-gauge-high"></i> Go to Host Dashboard
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="booking-form-card">
                            <h3>Book This Vehicle</h3>

                            <?php if ($error): ?>
                                <div class="booking-alert booking-alert-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($success): ?>
                                <div class="booking-alert booking-alert-success">
                                    <i class="fas fa-check-circle"></i>
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$success): ?>
                            <form method="POST" id="booking-form" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" id="price-per-day" value="<?= (float)$vehicleData['price'] ?>">

                                <div class="booking-form-group">
                                    <label>Select Dates (Pickup &amp; Drop-off)</label>
                                    <div class="custom-calendar">
                                        <div class="cal-header">
                                            <button type="button" id="cal-prev"><i class="fas fa-chevron-left"></i></button>
                                            <span id="cal-month-year"></span>
                                            <button type="button" id="cal-next"><i class="fas fa-chevron-right"></i></button>
                                        </div>
                                        <div class="cal-weekdays">
                                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                        </div>
                                        <div class="cal-grid" id="cal-grid"></div>
                                    </div>
                                    <input type="date" id="pickup_date" name="pickup_date" class="input" min="<?= date('Y-m-d') ?>" style="position:absolute; opacity:0; pointer-events:none;" tabindex="-1" value="<?= htmlspecialchars($_POST['pickup_date'] ?? '') ?>">
                                    <input type="date" id="dropoff_date" name="dropoff_date" class="input" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" style="position:absolute; opacity:0; pointer-events:none;" tabindex="-1" value="<?= htmlspecialchars($_POST['dropoff_date'] ?? '') ?>">
                                    <p id="dates-error-msg" style="display:none; color:#dc2626; font-size:12px; font-weight:600; margin-top:8px;">Please select both pickup and drop-off dates before confirming.</p>
                                </div>

                                <p class="booking-note">
                                    <i class="fas fa-info-circle"></i> 
                                    Dates shown in the system as unavailable will be rejected at confirmation.
                                </p>

                                <div class="booking-form-group">
                                    <label>Driving License (Front &amp; Back)</label>
                                    <label class="booking-file-label" for="license">
                                        <div class="booking-file-input" id="file-drop-zone">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <span class="file-hint" id="file-hint-text">FRONT OF LICENSE — CLICK TO UPLOAD OR DRAG &amp; DROP</span>
                                        </div>
                                        <input type="file" id="license" name="license" accept=".jpg,.jpeg,.png,.webp">
                                    </label>
                                    <p id="license-error-msg" style="display:none; color:#dc2626; font-size:12px; font-weight:600; margin-top:8px;">Please upload the front of your driving license before confirming.</p>
                                    
                                    <!-- Second zone, hidden initially -->
                                    <div id="license-back-zone" style="display:none; margin-top:12px;">
                                        <label class="booking-file-label" for="license_back">
                                            <div class="booking-file-input" id="drop-zone-back">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span class="file-hint">BACK OF LICENSE — CLICK TO UPLOAD OR DRAG &amp; DROP</span>
                                            </div>
                                            <input type="file" id="license_back" name="license_back" accept=".jpg,.jpeg,.png,.webp">
                                        </label>
                                    </div>
                                </div>

                                <!-- Live price breakdown -->
                                <div class="booking-price-breakdown" id="price-preview">
                                    <div class="breakdown-row">
                                        <span id="preview-days-label">Rs. — × — days</span>
                                        <span class="breakdown-amount" id="preview-subtotal">Rs. 0</span>
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Insurance &amp; Protection</span>
                                        <span class="breakdown-amount">Rs. 0</span>
                                    </div>
                                    <hr class="breakdown-divider">
                                    <div class="breakdown-total">
                                        <span>Total Amount</span>
                                        <span class="total-amount" id="preview-total">Rs. 0</span>
                                    </div>
                                </div>

                                <!-- Terms & Conditions Checkbox -->
                                <div class="terms-checkbox-wrapper" id="terms-wrapper">
                                    <label class="terms-checkbox-label">
                                        <input type="checkbox" id="terms-checkbox" name="terms_agreed" value="1">
                                        <span class="terms-checkbox-custom"></span>
                                        <span class="terms-checkbox-text">
                                            I have read and agree to SpinGo's
                                            <a href="terms.php" target="_blank">Rental Agreement</a>
                                            and <a href="privacy.php" target="_blank">Data Privacy Policy</a>.
                                            I understand that no charges will be applied until my identity is verified.
                                        </span>
                                    </label>
                                    <p id="terms-error-msg" style="display:none; color:#dc2626; font-size:12px; font-weight:600; margin-top:6px;">
                                        Please agree to the Rental Agreement and Privacy Policy before confirming.
                                    </p>
                                </div>

                                <button type="submit" class="btn btn-primary btn-confirm-booking" id="submit-booking-btn">
                                    Confirm Booking
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?> <!-- end host/renter role split -->

                    <!-- Guest CTA (not logged in) -->
                    <?php else: ?>
                        <div class="details-guest-cta booking-form-card">
                            <h3>Book This Vehicle</h3>
                            <p>
                                <i class="fas fa-lock"></i>
                                Create a free account or sign in to book this vehicle.
                            </p>
                            <a href="login.php?redirect=<?= urlencode("vehicle-details.php?id=$vehicleId&source=" . ($vehicleData['source'] ?? '')) ?>" class="btn btn-primary btn-login-to-book">
                                Login to Book
                            </a>
                            <span class="register-link">
                                New here? <a href="register.php">Create a free account →</a>
                            </span>
                        </div>
                    <?php endif; ?>

                </div><!-- /details-right -->

            </div><!-- /details-wrapper -->
        </div><!-- /container -->
    </div><!-- /details-page-wrapper -->

    <script src="../js/home.js"></script>
    <script>
    (function () {
        'use strict';

        const pickup   = document.getElementById('pickup_date');
        const dropoff  = document.getElementById('dropoff_date');
        const preview  = document.getElementById('price-preview');
        const subtotal = document.getElementById('preview-subtotal');
        const total    = document.getElementById('preview-total');
        const daysLbl  = document.getElementById('preview-days-label');
        const ppd      = parseFloat(document.getElementById('price-per-day')?.value || 0);

        function updatePreview() {
            if (!pickup || !dropoff || !pickup.value || !dropoff.value) {
                preview?.classList.remove('visible');
                return;
            }
            const p = new Date(pickup.value);
            const d = new Date(dropoff.value);
            const days = Math.ceil((d - p) / 86400000);
            if (days <= 0) { preview?.classList.remove('visible'); return; }

            const sub = days * ppd;
            daysLbl.textContent   = `Rs. ${ppd.toLocaleString()} × ${days} day${days !== 1 ? 's' : ''}`;
            subtotal.textContent  = `Rs. ${sub.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            total.textContent     = `Rs. ${sub.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            preview?.classList.add('visible');
        }

        // ── Custom Visual Calendar ───────────────────────────────────────────────
        const vehicleId = <?= $vehicleId ?>;
        
        let currentMonth = new Date();
        currentMonth.setDate(1);
        currentMonth.setHours(0,0,0,0);
        
        let blockedRanges = [];
        let calPickupDate = null;
        let calDropoffDate = null;

        const calGrid = document.getElementById('cal-grid');
        const calMonthYear = document.getElementById('cal-month-year');
        const calPrev = document.getElementById('cal-prev');
        const calNext = document.getElementById('cal-next');
        
        // Render immediately with no blocked ranges so user sees the calendar
        renderCalendar();

        // Fetch blocked dates from API
        fetch(`../api/vehicle_availability.php?vehicle_id=${vehicleId}`)
            .then(r => r.json())
            .then(data => {
                blockedRanges = data.map(r => {
                    const [py, pm, pd] = r.pickup_date.split('-');
                    const [dy, dm, dd] = r.dropoff_date.split('-');
                    return {
                        pickup: new Date(py, pm - 1, pd),
                        dropoff: new Date(dy, dm - 1, dd),
                        status: r.status
                    };
                });
                // Restore selection if form failed validation but had dates
                if (pickup.value) calPickupDate = new Date(pickup.value);
                if (dropoff.value) calDropoffDate = new Date(dropoff.value);
                
                advanceToAvailableMonth();
                renderCalendar(); // re-render now with real booked dates
            })
            .catch(err => console.error("Availability fetch failed:", err));

        function getDayStatus(date) {
            const today = new Date();
            today.setHours(0,0,0,0);
            if (date < today) return 'past';

            // ── ABSOLUTE CAP: grey out dates beyond 3 months from today ──
            const maxFutureDate = new Date(today);
            maxFutureDate.setMonth(maxFutureDate.getMonth() + 3);
            if (date > maxFutureDate) return 'past';

            // ── MAX DURATION: grey out anything beyond 90 days from pickup ──
            if (calPickupDate) {
                const maxDropoff = new Date(calPickupDate);
                maxDropoff.setDate(maxDropoff.getDate() + 90);
                if (date > maxDropoff) return 'past'; // reuse grey/disabled styling
            }
            // ────────────────────────────────────────────────────────────────

            for (const range of blockedRanges) {
                const p = new Date(range.pickup.getTime()); p.setHours(0,0,0,0);
                const d = new Date(range.dropoff.getTime()); d.setHours(0,0,0,0);
                if (date >= p && date <= d) {
                    return range.status === 'buffer' ? 'buffer' : 'booked';
                }
            }
            return 'available';
        }

        function advanceToAvailableMonth() {
            const today = new Date();
            today.setHours(0,0,0,0);
            
            // Advance to today's month if we're in the past
            const endOfMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0);
            if (endOfMonth < today) {
                currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            }
            
            // Look for a month that has at least one available day
            for (let m = 0; m < 3; m++) { // limit search to 3 months ahead
                let hasAvail = false;
                const startDay = new Date(Math.max(currentMonth.getTime(), today.getTime()));
                const eom = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0);
                
                for (let d = new Date(startDay); d <= eom; d.setDate(d.getDate()+1)) {
                    if (getDayStatus(new Date(d)) === 'available') {
                        hasAvail = true;
                        break;
                    }
                }
                if (hasAvail) break;
                currentMonth.setMonth(currentMonth.getMonth() + 1);
            }
        }

        function renderCalendar() {
            if (!calGrid) return;
            calGrid.innerHTML = '';
            
            const year = currentMonth.getFullYear();
            const month = currentMonth.getMonth();
            calMonthYear.textContent = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(currentMonth);
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
            for (let i = 0; i < firstDay; i++) {
                const empty = document.createElement('div');
                calGrid.appendChild(empty);
            }
            
            for (let i = 1; i <= daysInMonth; i++) {
                const date = new Date(year, month, i);
                date.setHours(0,0,0,0);
                
                const tile = document.createElement('div');
                tile.className = 'cal-day';
                tile.textContent = i;
                
                const status = getDayStatus(date);
                tile.classList.add(status);
                
                if (status === 'booked' || status === 'buffer') {
                    tile.title = status === 'booked' ? "Already booked" : "Maintenance period";
                }
                
                if (calPickupDate && date.getTime() === calPickupDate.getTime()) {
                    tile.classList.add('selected');
                } else if (calDropoffDate && date.getTime() === calDropoffDate.getTime()) {
                    tile.classList.add('selected');
                } else if (calPickupDate && calDropoffDate && date > calPickupDate && date < calDropoffDate) {
                    tile.classList.add('in-range');
                }
                
                tile.addEventListener('click', () => onDateClick(date, status));
                calGrid.appendChild(tile);
            }
        }

        function showCalError(msg) {
            let el = document.getElementById('cal-error-msg');
            if (!el) {
                el = document.createElement('p');
                el.id = 'cal-error-msg';
                el.style.cssText = 'color:#dc2626;font-size:12px;font-weight:600;margin:6px 0 0;';
                calGrid.parentElement.appendChild(el);
            }
            el.textContent = msg;
            setTimeout(() => { if (el) el.textContent = ''; }, 4000);
        }

        function onDateClick(date, status) {
            if (status !== 'available') return;

            if (!calPickupDate || (calPickupDate && calDropoffDate)) {
                // Starting a fresh selection
                calPickupDate = date;
                calDropoffDate = null;
                pickup.value = formatDate(date);
                dropoff.value = '';
            } else {
                if (date < calPickupDate) {
                    // Clicked before pickup — restart from new date
                    calPickupDate = date;
                    pickup.value = formatDate(date);
                    dropoff.value = '';
                } else {
                    // ── ENFORCE 90-DAY MAX on dropoff selection ──
                    const maxDropoff = new Date(calPickupDate);
                    maxDropoff.setDate(maxDropoff.getDate() + 90);
                    if (date > maxDropoff) {
                        showCalError('Maximum booking duration is 90 days (3 months).');
                        return;
                    }
                    // ─────────────────────────────────────────────

                    let hasBlocked = false;
                    for (let d = new Date(calPickupDate); d <= date; d.setDate(d.getDate()+1)) {
                        if (getDayStatus(new Date(d)) !== 'available') {
                            hasBlocked = true;
                            break;
                        }
                    }
                    if (hasBlocked) {
                        calPickupDate = date;
                        pickup.value = formatDate(date);
                        dropoff.value = '';
                    } else {
                        calDropoffDate = date;
                        dropoff.value = formatDate(date);
                    }
                }
            }
            clearDatesError();
            renderCalendar();
            updatePreview();
        }

        function formatDate(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        calPrev?.addEventListener('click', () => {
            currentMonth.setMonth(currentMonth.getMonth() - 1);
            renderCalendar();
        });
        
        calNext?.addEventListener('click', () => {
            const today = new Date();
            today.setHours(0,0,0,0);
            const maxMonth = new Date(today.getFullYear(), today.getMonth() + 3, 1);
            
            const nextMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
            if (nextMonth <= maxMonth) {
                currentMonth.setMonth(currentMonth.getMonth() + 1);
                renderCalendar();
            }
        });

        // ── File input label update ─────────────────────────────────────────────
        const fileInput = document.getElementById('license');
        const dropZone  = document.getElementById('file-drop-zone');
        const fileInputBack = document.getElementById('license_back');
        const dropZoneBack  = document.getElementById('drop-zone-back');
        const licenseBackZone = document.getElementById('license-back-zone');

        function clearLicenseError() {
            const errorMsg = document.getElementById('license-error-msg');
            if (errorMsg) errorMsg.style.display = 'none';
            if (dropZone) dropZone.style.borderColor = ''; // reset to default
        }

        const bookingForm = document.getElementById('booking-form');
        const calendarBox = document.querySelector('.custom-calendar');

        function clearDatesError() {
            const errorMsg = document.getElementById('dates-error-msg');
            if (errorMsg) errorMsg.style.display = 'none';
            if (calendarBox) calendarBox.style.borderColor = '';
        }

        bookingForm?.addEventListener('submit', function(e) {
            let blocked = false;

            // 1. Check dates first (appears higher on the page)
            if (!pickup?.value || !dropoff?.value) {
                e.preventDefault();
                blocked = true;
                const datesErr = document.getElementById('dates-error-msg');
                if (datesErr) {
                    datesErr.style.display = 'block';
                    datesErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                if (calendarBox) calendarBox.style.borderColor = '#dc2626';
            } else {
                clearDatesError();
            }

            // 2. Check license upload
            if (fileInput && fileInput.files.length === 0) {
                e.preventDefault();
                const licErr = document.getElementById('license-error-msg');
                if (licErr) {
                    licErr.style.display = 'block';
                    // Only scroll here if dates were fine (otherwise keep scroll on dates)
                    if (!blocked) licErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                if (dropZone) dropZone.style.borderColor = '#dc2626';
                blocked = true;
            }

            // 3. Check terms checkbox
            const termsCheckbox = document.getElementById('terms-checkbox');
            const termsErr = document.getElementById('terms-error-msg');
            if (termsCheckbox && !termsCheckbox.checked) {
                e.preventDefault();
                if (termsErr) {
                    termsErr.style.display = 'block';
                    if (!blocked) termsErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                const termsWrapper = document.getElementById('terms-wrapper');
                if (termsWrapper) termsWrapper.style.borderColor = '#dc2626';
                blocked = true;
            } else if (termsErr) {
                termsErr.style.display = 'none';
            }

            // Auto-hide terms error when checked
            termsCheckbox?.addEventListener('change', function() {
                if (this.checked && termsErr) termsErr.style.display = 'none';
            });
        });

        fileInput?.addEventListener('change', function() {
            clearLicenseError();
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = e => {
                    dropZone.innerHTML = `<img src="${e.target.result}" 
                        style="max-height:120px; border-radius:8px; object-fit:contain;">`;
                    dropZone.style.borderColor = 'var(--primary)';
                    dropZone.style.background  = 'var(--bg-light)';
                    licenseBackZone.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });

        fileInputBack?.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = e => {
                    dropZoneBack.innerHTML = `<img src="${e.target.result}" 
                        style="max-height:120px; border-radius:8px; object-fit:contain;">`;
                    dropZoneBack.style.borderColor = 'var(--primary)';
                    dropZoneBack.style.background  = 'var(--bg-light)';
                };
                reader.readAsDataURL(file);
            }
        });

        // ── Hamburger menu ──────────────────────────────────────────────────────
        const burger   = document.getElementById('hamburger-btn');
        const navLinks = document.getElementById('nav-links');
        burger?.addEventListener('click', () => navLinks?.classList.toggle('mobile-open'));

    })();
    </script>

    <?php require_once __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>