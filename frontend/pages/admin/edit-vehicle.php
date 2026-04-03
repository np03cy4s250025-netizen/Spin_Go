<?php

require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';
require_once '../../../backend/config/app.php';

requireAdmin('../dashboard.php');

$vehicle_id = (int)($_GET['id'] ?? 0);
$source     = $_GET['source'] ?? 'admin';
if (!in_array($source, ['admin', 'host'])) {
    $source = 'admin';
}

if (!$vehicle_id) {
    header('Location: vehicles.php');
    exit;
}

// Fetch vehicle — respect source
if ($source === 'host') {
    $stmt = $conn->prepare("SELECT *, price_per_day AS price FROM host_vehicles WHERE id = :id LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT * FROM vehicles WHERE id = :id AND deleted_at IS NULL LIMIT 1");
}
$stmt->execute([':id' => $vehicle_id]);
$vehicleData = $stmt->fetch();

if (!$vehicleData) {
    header('Location: vehicles.php?error=' . urlencode('Vehicle not found.'));
    exit;
}

$allowedTypes = ($source === 'host') ? ['car', 'bike', 'suv', 'sports', 'electric', 'motorbike'] : ['car', 'bike'];
$allowedFuels = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG'];

// Fetch all cities for localization (Nepal)
$cityStmt = $conn->query("SELECT id, name FROM cities ORDER BY name ASC");
$nepaliCities = $cityStmt->fetchAll(PDO::FETCH_ASSOC);
$cityMap = array_column($nepaliCities, 'name', 'id');

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); // CSRF protection

    $name         = trim($_POST['name']  ?? '');
    $model        = trim($_POST['model'] ?? '');
    $year         = (int)($_POST['year']  ?? 0);
    $type         = trim($_POST['type']  ?? '');
    $fuel         = trim($_POST['fuel']  ?? '');
    $transmission = trim($_POST['transmission'] ?? 'Manual');
    $seats        = (int)($_POST['seats'] ?? 0);
    $price        = (float)($_POST['price'] ?? 0);
    $city         = trim($_POST['city']  ?? '');
    $description  = trim($_POST['description'] ?? '');
    $availability = (int)($_POST['availability'] ?? 0);
    $currentYear  = (int)date('Y');

    // Whitelist + validation
    if (!$name || !$model || !$year || !$type || !$fuel || !$seats || !$price || !$city || !$transmission) {
        $error = 'All fields except image are required.';
    } elseif ($year < 1990 || $year > $currentYear) {
        $error = "Year must be between 1990 and {$currentYear}.";
    } elseif (!in_array($type, $allowedTypes)) {
        $error = 'Invalid vehicle type.';
    } elseif (!in_array($fuel, $allowedFuels)) {
        $error = 'Invalid fuel type.';
    } elseif (!in_array($transmission, ['Manual', 'Automatic'])) {
        $error = 'Invalid transmission.';
    } elseif ($seats < 1 || $seats > 20) {
        $error = 'Seats must be between 1 and 20.';
    } elseif ($price <= 0) {
        $error = 'Price must be greater than 0.';
    } elseif (!isset($cityMap[(int)$city])) {
        $error = 'Please select a valid Nepali city.';
    } else {
        $imagePath = $vehicleData['image']; // Fallback to existing

        // Check if a new image was uploaded
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $maxSize = 5 * 1024 * 1024;

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowedMimes)) {
                $error = 'Only JPG, PNG, or WebP images are accepted.';
            } elseif ($file['size'] > $maxSize) {
                $error = 'Image exceeds the 5MB limit.';
            } else {
                $uploadBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vehicles';
                $ext = 'jpg';
                if ($mime === 'image/png') $ext = 'png';
                if ($mime === 'image/webp') $ext = 'webp';

                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadBase . DIRECTORY_SEPARATOR . $filename)) {
                    $imagePath = 'uploads/vehicles/' . $filename;
                } else {
                    $error = 'Failed to save new image.';
                }
            }
        }

        if (!$error) {
            $cityId   = (int)$city;
            $cityName = $cityMap[$cityId];

            try {
                if ($source === 'host') {
                    $stmt = $conn->prepare(
                        "UPDATE host_vehicles
                         SET name=:name, model=:model, year=:year, type=:type, fuel=:fuel, seats=:seats,
                             price_per_day=:price, city=:city, city_id=:city_id, image=:image, availability=:availability,
                             description=:description, transmission=:transmission
                         WHERE id=:id"
                    );
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE vehicles
                         SET name=:name, model=:model, year=:year, type=:type, fuel=:fuel, seats=:seats,
                             price=:price, city=:city, city_id=:city_id, image=:image, availability=:availability,
                             description=:description, transmission=:transmission
                         WHERE id=:id"
                    );
                }
                $stmt->execute([
                    ':name'         => $name,
                    ':model'        => $model,
                    ':year'         => $year,
                    ':type'         => $type,
                    ':fuel'         => $fuel,
                    ':seats'        => $seats,
                    ':price'        => $price,
                    ':city'         => $cityName,
                    ':city_id'      => $cityId,
                    ':image'        => $imagePath,
                    ':availability' => $availability,
                    ':description'  => $description,
                    ':transmission' => $transmission,
                    ':id'           => $vehicle_id,
                ]);

                $success = 'Vehicle updated successfully! Redirecting…';
                header("refresh:2;url=vehicles.php?highlight=$vehicle_id&source=$source");

            } catch (PDOException $e) {
                error_log('[EditVehicle] ' . $e->getMessage());
                $error = 'Failed to update vehicle. Please try again.';
            }
        }
    }
}

// Use POST values if re-displaying after error, otherwise DB values
$v = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) ? $_POST : $vehicleData;
$user = getCurrentUser();
$imgSrc = (str_starts_with($vehicleData['image'] ?? '', 'http')) ? $vehicleData['image'] : '../../' . $vehicleData['image'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Admin — Edit vehicle details.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/main.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <title>SpinGo | Edit Vehicle — <?= htmlspecialchars($vehicleData['name']) ?></title>
    <style>
        /* Overhaul styles for premium aesthetics */
        body {
            background: linear-gradient(135deg, #eef2f3 0%, #d5dde0 100%);
            min-height: 100vh;
        }

        .dash-wrapper {
            max-width: 1000px;
            margin: 40px auto;
            padding: 90px 24px 24px;
            animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .btn-back-fleet {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(0, 0, 0, 0.08);
            color: #3F3E46;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
            margin-bottom: 24px;
        }

        .btn-back-fleet:hover {
            background: #fff;
            transform: translateX(-4px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
            color: #1a1a1a;
        }

        .dash-header-premium {
            margin-bottom: 32px;
        }

        .dash-header-premium h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: #1a1a1a;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        .dash-header-premium p {
            color: #52525C;
            font-size: 15px;
        }

        .dash-card-premium {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.06);
            padding: 40px;
        }

        /* 2-Column Form Layout */
        .edit-form-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 40px;
            align-items: start;
        }

        @media (max-width: 850px) {
            .edit-form-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }
        }

        .form-section-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 2px solid rgba(123, 98, 98, 0.15);
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-section-title i {
            color: #7B6262;
        }

        /* Input styling */
        .form-group-custom {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 22px;
        }

        .form-group-custom label {
            font-size: 13px;
            font-weight: 600;
            color: #3F3E46;
            letter-spacing: 0.2px;
        }

        .input-custom {
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.12);
            color: #1a1a1a;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: all 0.25s ease;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
            box-sizing: border-box;
        }

        .input-custom:focus {
            border-color: #7B6262;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(123, 98, 98, 0.12), inset 0 2px 4px rgba(0,0,0,0.01);
        }

        select.input-custom {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%233F3E46' d='M6 8.5L1.5 4h9z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        /* Inline group for numbers */
        .inline-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* Image Upload Section Styles */
        .image-preview-card {
            background: rgba(255, 255, 255, 0.6);
            border-radius: 16px;
            padding: 20px;
            border: 1px dashed rgba(0, 0, 0, 0.12);
            text-align: center;
            margin-bottom: 22px;
            transition: all 0.25s ease;
        }

        .image-preview-card:hover {
            border-color: #7B6262;
            background: rgba(255, 255, 255, 0.85);
        }

        .preview-img-container {
            position: relative;
            width: 100%;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
            margin-bottom: 16px;
            background: #eef1f1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .preview-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .image-preview-card:hover .preview-img-container img {
            transform: scale(1.03);
        }

        .upload-btn-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #7B6262;
            color: #fff;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(123, 98, 98, 0.15);
        }

        .file-upload-label:hover {
            background: #5c4a4a;
            box-shadow: 0 6px 18px rgba(123, 98, 98, 0.25);
            transform: translateY(-1px);
        }

        .file-upload-input {
            position: absolute;
            font-size: 100px;
            opacity: 0;
            right: 0;
            top: 0;
            cursor: pointer;
            height: 100%;
            width: 100%;
        }

        .selected-file-name {
            font-size: 12px;
            color: #52525C;
            margin-top: 8px;
            word-break: break-all;
            font-weight: 500;
        }

        /* Save Button Overhaul */
        .btn-submit-premium {
            background: #3F3E46;
            color: #fff;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            border: none;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 24px rgba(63, 62, 70, 0.15);
            transition: all 0.25s ease;
            cursor: pointer;
            width: 100%;
            margin-top: 24px;
        }

        .btn-submit-premium:hover {
            background: #2e2d34;
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(63, 62, 70, 0.25);
        }

        .btn-submit-premium:active {
            transform: translateY(0);
        }

        /* Error/Success Flash styles */
        .alert-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
            animation: slideDown 0.35s ease;
            box-sizing: border-box;
        }

        .alert-custom-error {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            color: #991b1b;
        }

        .alert-custom-error i {
            color: #dc2626;
            font-size: 16px;
        }

        .alert-custom-success {
            background: #ecfdf5;
            border: 1px solid #d1fae5;
            color: #065f46;
        }

        .alert-custom-success i {
            color: #059669;
            font-size: 16px;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <!-- Minimal admin navbar -->
    <nav class="navbar" id="navbar">
        <div class="nav-content">
            <a href="../index.php" class="logo" id="logo-link">
                <div class="logo-icon"><i class="fas fa-car"></i></div>
                SpinGo
            </a>
            <div class="nav-actions">
                <div class="user-menu" id="user-menu">
                    <button class="user-menu-trigger" id="user-menu-trigger" aria-label="User menu">
                        <span class="user-name-label">Admin</span>
                        <div class="user-avatar">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <i class="fas fa-chevron-down chevron"></i>
                    </button>
                    
                    <div class="user-dropdown" id="user-dropdown">
                        <div class="dropdown-header">
                            <p class="dropdown-user-name"><?= htmlspecialchars($user['full_name']) ?></p>
                            <p class="dropdown-user-email"><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                        <div class="dropdown-divider"></div>
                        
                        <a href="vehicles.php" class="dropdown-item">
                            <i class="fas fa-arrow-left"></i> Back to Fleet
                        </a>
                        
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="../logout.php" class="logout-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item logout-link">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="dash-wrapper">
        <div class="dash-header-premium">
            <a href="vehicles.php" class="btn-back-fleet">
                <i class="fas fa-arrow-left"></i> Back to Fleet
            </a>
            <h1>Edit Vehicle</h1>
            <p>Update specifications and daily rates for this vehicle listing.</p>
            <div class="av-id-badge" style="margin-top: 10px; font-weight: 600; color: #7B6262; font-size: 14px;">
                <i class="fas fa-tag"></i> Vehicle ID #<?= htmlspecialchars($vehicle_id) ?> (<?= strtoupper($source) ?>) — <?= htmlspecialchars($vehicleData['name']) ?>
            </div>
        </div>

        <div class="dash-card-premium">
            <?php if ($error): ?>
                <div class="alert-custom alert-custom-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-custom alert-custom-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" id="edit-vehicle-form" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>

                <div class="edit-form-grid">
                    <!-- Left Column: Specs & Location -->
                    <div class="form-column">
                        <h2 class="form-section-title">
                            <i class="fas fa-car-side"></i> General Information
                        </h2>

                        <div class="form-group-custom">
                            <label for="vehicle-name">Vehicle Name *</label>
                            <input class="input-custom" type="text" id="vehicle-name" name="name" required value="<?= htmlspecialchars($v['name'] ?? '') ?>">
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-model">Model *</label>
                            <input class="input-custom" type="text" id="vehicle-model" name="model" required placeholder="e.g. Fortuner 2024" value="<?= htmlspecialchars($v['model'] ?? '') ?>">
                        </div>

                        <div class="inline-inputs">
                            <div class="form-group-custom">
                                <label for="vehicle-year">Year *</label>
                                <input class="input-custom" type="number" id="vehicle-year" name="year" required min="1990" max="<?= date('Y') ?>" value="<?= htmlspecialchars($v['year'] ?? '') ?>">
                            </div>

                            <div class="form-group-custom">
                                <label for="vehicle-seats">Seats *</label>
                                <input class="input-custom" type="number" id="vehicle-seats" name="seats" required min="1" max="20" value="<?= htmlspecialchars($v['seats'] ?? '') ?>">
                            </div>
                        </div>

                        <h2 class="form-section-title" style="margin-top: 10px;">
                            <i class="fas fa-wallet"></i> Price & Location
                        </h2>

                        <div class="form-group-custom">
                            <label for="vehicle-city">City Location *</label>
                            <select class="input-custom" id="vehicle-city" name="city" required>
                                <option value="">Select City</option>
                                <?php foreach ($nepaliCities as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= (int)($v['city_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-price">Price / Day (Rs.) *</label>
                            <input class="input-custom" type="number" id="vehicle-price" name="price" required min="1" step="0.01" value="<?= htmlspecialchars($v['price'] ?? '') ?>">
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-description">Description</label>
                            <textarea class="input-custom" id="vehicle-description" name="description" rows="4" style="resize: vertical; min-height: 100px;"><?= htmlspecialchars($v['description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Right Column: Details & Media -->
                    <div class="form-column">
                        <h2 class="form-section-title">
                            <i class="fas fa-sliders-h"></i> Specifications & Status
                        </h2>

                        <div class="form-group-custom">
                            <label for="vehicle-type">Vehicle Type *</label>
                            <select class="input-custom" id="vehicle-type" name="type" required>
                                <?php foreach ($allowedTypes as $t): ?>
                                    <option value="<?= $t ?>" <?= ($v['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-fuel">Fuel Type *</label>
                            <select class="input-custom" id="vehicle-fuel" name="fuel" required>
                                <?php foreach ($allowedFuels as $f): ?>
                                    <option value="<?= $f ?>" <?= (strtolower($v['fuel'] ?? '') === strtolower($f)) ? 'selected' : '' ?>><?= $f ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-transmission">Transmission *</label>
                            <select class="input-custom" id="vehicle-transmission" name="transmission" required>
                                <option value="Manual" <?= ($v['transmission'] ?? 'Manual') === 'Manual' ? 'selected' : '' ?>>Manual</option>
                                <option value="Automatic" <?= ($v['transmission'] ?? 'Manual') === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-avail">Availability</label>
                            <select class="input-custom" id="vehicle-avail" name="availability">
                                <option value="1" <?= ($v['availability'] ?? 1) ? 'selected' : '' ?>>Available</option>
                                <option value="0" <?= !($v['availability'] ?? 1) ? 'selected' : '' ?>>Unavailable</option>
                            </select>
                        </div>

                        <h2 class="form-section-title" style="margin-top: 10px;">
                            <i class="fas fa-image"></i> Vehicle Media
                        </h2>

                        <div class="image-preview-card">
                            <div class="preview-img-container">
                                <img id="preview-image" src="<?= htmlspecialchars($imgSrc) ?>" alt="Vehicle Image">
                            </div>
                            
                            <div class="upload-btn-wrapper">
                                <label class="file-upload-label" for="file-upload-input">
                                    <i class="fas fa-cloud-upload-alt"></i> Upload New Image
                                </label>
                                <input class="file-upload-input" type="file" id="file-upload-input" name="image" accept="image/*">
                            </div>
                            <div class="selected-file-name" id="selected-file-name">No file selected (Optional, max 5MB JPG/PNG/WebP)</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit-premium">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="../../js/app.js" defer></script>
    <script>
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => navbar?.classList.toggle('scrolled', window.scrollY > 20));

        // Interactive live file selection preview
        document.getElementById('file-upload-input').addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                // Update file status label
                document.getElementById('selected-file-name').innerHTML = `<i class="fas fa-check-circle" style="color:#059669;"></i> ${file.name}`;
                
                // Read and update the image preview element
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-image').src = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                document.getElementById('selected-file-name').textContent = 'No file selected (Optional, max 5MB JPG/PNG/WebP)';
            }
        });
    </script>
</body>
</html>
