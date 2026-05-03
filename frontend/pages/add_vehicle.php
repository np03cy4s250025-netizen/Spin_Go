<?php
// frontend/pages/add_vehicle.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';
require_once '../../backend/models/HostVehicle.php';

requireLogin();
if (!isAdmin() && !isHost()) {
    header('Location: /Spin_Go/frontend/pages/become_host.php');
    exit;
}

$isAdmin = isAdmin();
$host = null;
if (!$isAdmin) {
    $hostApp = new HostApplication($conn);
    $host    = $hostApp->getByUserId((int)$_SESSION['user_id']);
}

$user      = getCurrentUser();
$navActive = $isAdmin ? 'admin' : 'host_dashboard';

$error   = '';
$success = '';

$allowedTypes         = ['car', 'bike', 'suv', 'sports', 'electric', 'motorbike'];
$allowedFuels         = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG'];
$allowedTransmissions = ['Manual', 'Automatic'];

// Fetch cities for the dropdown
$cityStmt     = $conn->query("SELECT id, name FROM cities ORDER BY name ASC");
$nepaliCities = $cityStmt->fetchAll(PDO::FETCH_ASSOC);
$cityMap      = array_column($nepaliCities, 'name', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name         = trim($_POST['name']         ?? '');
    $model        = trim($_POST['model']        ?? '');
    $year         = (int)($_POST['year']        ?? 0);
    $type         = trim($_POST['type']         ?? '');
    $fuel         = trim($_POST['fuel']         ?? '');
    $seats        = (int)($_POST['seats']       ?? 0);
    $city         = trim($_POST['city']         ?? '');
    $price_per_day = (float)($_POST['price_per_day'] ?? 0);
    $description  = trim($_POST['description']  ?? '');
    $transmission = trim($_POST['transmission'] ?? 'Manual');
    $currentYear  = (int)date('Y');

    if (!$name) {
        $error = 'Vehicle name is required.';
    } elseif (!$model) {
        $error = 'Vehicle model is required.';
    } elseif ($year < 1990 || $year > $currentYear) {
        $error = "Year must be between 1990 and {$currentYear}.";
    } elseif (!in_array($type, $allowedTypes)) {
        $error = 'Invalid vehicle type selected.';
    } elseif (!in_array($fuel, $allowedFuels)) {
        $error = 'Invalid fuel type selected.';
    } elseif (!in_array($transmission, $allowedTransmissions)) {
        $error = 'Invalid transmission type selected.';
    } elseif ($seats < 1 || $seats > 20) {
        $error = 'Seats must be between 1 and 20.';
    } elseif (!isset($cityMap[(int)$city])) {
        $error = 'Please select a valid city.';
    } elseif ($price_per_day <= 0) {
        $error = 'Price per day must be greater than 0.';
    } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'A vehicle image is required.';
    } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed. Please try again.';
    } else {
        $file         = $_FILES['image'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $maxSize      = 5 * 1024 * 1024; // 5MB

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes)) {
            $error = 'Only JPG, PNG, or WebP images are accepted.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Image exceeds the 5MB limit.';
        } else {
            $uploadBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vehicles';
            if (!is_dir($uploadBase)) mkdir($uploadBase, 0755, true);

            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'jpg';
            $filename   = bin2hex(random_bytes(16)) . '.' . $ext;
            $targetPath = $uploadBase . DIRECTORY_SEPARATOR . $filename;
            $dbPath     = 'uploads/vehicles/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $cityId   = (int)$city;
                $cityName = $cityMap[$cityId];

                if ($isAdmin) {
                    try {
                        $stmt = $conn->prepare(
                            "INSERT INTO vehicles (name, model, year, type, fuel, transmission, seats, price, city, city_id, image, description, availability, created_at)
                             VALUES (:name, :model, :year, :type, :fuel, :transmission, :seats, :price, :city, :city_id, :image, :description, 1, NOW())"
                        );
                        $stmt->execute([
                            ':name'         => $name,
                            ':model'        => $model,
                            ':year'         => $year,
                            ':type'         => $type,
                            ':fuel'         => $fuel,
                            ':transmission' => $transmission,
                            ':seats'        => $seats,
                            ':price'        => $price_per_day,
                            ':city'         => $cityName,
                            ':city_id'      => $cityId,
                            ':image'        => $dbPath,
                            ':description'  => $description,
                        ]);

                        $_SESSION['flash_success'] = 'Vehicle added successfully!';
                        header('Location: admin/vehicles.php');
                        exit;

                    } catch (PDOException $e) {
                        error_log('[AddVehicle] ' . $e->getMessage());
                        $error = 'Failed to add vehicle. Please try again.';
                    }
                } else {
                    $vehicleModel = new HostVehicle($conn);
                    $result = $vehicleModel->addVehicle(
                        $host['id'] ?? $host['user_id'],
                        $name, $model, $year, $type, $fuel,
                        $seats, $cityName, $cityId, $price_per_day, $dbPath,
                        1, // availability = true by default
                        $description,
                        $transmission
                    );

                    if ($result['status'] === 'success') {
                        $newVehicleId = $result['id'];

                        // Notify all admins — deep link to the new vehicle
                        $conn->prepare("
                            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
                            SELECT id, 'admin', 'new_vehicle', :title, :message, :link, :ref_id
                            FROM users WHERE role = 'admin'
                        ")->execute([
                            ':title'   => "New Vehicle Listed — Review Required",
                            ':message' => "{$_SESSION['user_name']} has listed a new vehicle: $name ($model, $year).",
                            ':link'    => "admin/vehicles.php?highlight=" . $newVehicleId . "&source=host",
                            ':ref_id'  => $newVehicleId,
                        ]);

                        // Confirm back to the host
                        $conn->prepare("
                            INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link, reference_id)
                            VALUES (:uid, 'host', 'vehicle_listed', :title, :message, 'host_dashboard.php', :ref_id)
                        ")->execute([
                            ':uid'     => $_SESSION['user_id'],
                            ':title'   => "Vehicle Listed Successfully ✓",
                            ':message' => "Your vehicle $name ($model, $year) has been listed and is now visible on SpinGo.",
                            ':ref_id'  => $newVehicleId,
                        ]);

                        $_SESSION['flash_success'] = 'Vehicle listed successfully!';
                        header('Location: host_dashboard.php');
                        exit;
                    } else {
                        $error = $result['message'];
                    }
                }
            } else {
                $error = 'Could not save vehicle image. Please check directory permissions.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="List a new vehicle on SpinGo as a host.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <title>SpinGo | List a Vehicle</title>
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

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <div class="dash-wrapper">
        <div class="dash-header-premium">
            <a href="<?= $isAdmin ? 'admin/vehicles.php' : 'host_dashboard.php' ?>" class="btn-back-fleet">
                <i class="fas fa-arrow-left"></i> Back to <?= $isAdmin ? 'Fleet' : 'Dashboard' ?>
            </a>
            <h1>List a New Vehicle</h1>
            <p>Add your vehicle to the SpinGo fleet and start earning.</p>
        </div>

        <div class="dash-card-premium">
            <?php if ($error): ?>
                <div class="alert-custom alert-custom-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="add-vehicle-form" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>

                <div class="edit-form-grid">
                    <!-- Left Column: Specs & Location -->
                    <div class="form-column">
                        <h2 class="form-section-title">
                            <i class="fas fa-car-side"></i> General Information
                        </h2>

                        <div class="form-group-custom">
                            <label for="vehicle-name">Vehicle Name *</label>
                            <input class="input-custom" type="text" id="vehicle-name" name="name" required placeholder="e.g. Toyota Fortuner" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-model">Model *</label>
                            <input class="input-custom" type="text" id="vehicle-model" name="model" required placeholder="e.g. Fortuner 2024" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>">
                        </div>

                        <div class="inline-inputs">
                            <div class="form-group-custom">
                                <label for="vehicle-year">Year *</label>
                                <input class="input-custom" type="number" id="vehicle-year" name="year" required min="1990" max="<?= date('Y') ?>" placeholder="e.g. 2023" value="<?= htmlspecialchars($_POST['year'] ?? '') ?>">
                            </div>

                            <div class="form-group-custom">
                                <label for="vehicle-seats">Seats *</label>
                                <input class="input-custom" type="number" id="vehicle-seats" name="seats" required min="1" max="20" placeholder="e.g. 5" value="<?= htmlspecialchars($_POST['seats'] ?? '') ?>">
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
                                    <option value="<?= $c['id'] ?>" <?= (int)($_POST['city'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-price">Price / Day (Rs.) *</label>
                            <input class="input-custom" type="number" id="vehicle-price" name="price_per_day" required min="1" step="0.01" placeholder="e.g. 2500" value="<?= htmlspecialchars($_POST['price_per_day'] ?? '') ?>">
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-description">Description</label>
                            <textarea class="input-custom" id="vehicle-description" name="description" rows="4" style="resize: vertical; min-height: 100px;" placeholder="Describe the vehicle condition, features, etc."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Right Column: Details & Media -->
                    <div class="form-column">
                        <h2 class="form-section-title">
                            <i class="fas fa-sliders-h"></i> Specifications
                        </h2>

                        <div class="form-group-custom">
                            <label for="vehicle-type">Vehicle Type *</label>
                            <select class="input-custom" id="vehicle-type" name="type" required>
                                <option value="">-- Select Type --</option>
                                <?php foreach ([
                                    'car' => 'Car', 'suv' => 'SUV', 'sports' => 'Sports',
                                    'electric' => 'Electric', 'motorbike' => 'Motorbike', 'bike' => 'Bike'
                                ] as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= ($_POST['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-fuel">Fuel Type *</label>
                            <select class="input-custom" id="vehicle-fuel" name="fuel" required>
                                <option value="">-- Select Fuel --</option>
                                <?php foreach ($allowedFuels as $f): ?>
                                    <option value="<?= $f ?>" <?= ($_POST['fuel'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-custom">
                            <label for="vehicle-transmission">Transmission *</label>
                            <select class="input-custom" id="vehicle-transmission" name="transmission" required>
                                <option value="Manual" <?= ($_POST['transmission'] ?? 'Manual') === 'Manual' ? 'selected' : '' ?>>Manual</option>
                                <option value="Automatic" <?= ($_POST['transmission'] ?? 'Manual') === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                            </select>
                        </div>

                        <h2 class="form-section-title" style="margin-top: 10px;">
                            <i class="fas fa-image"></i> Vehicle Media
                        </h2>

                        <div class="image-preview-card" id="image-preview-card">
                            <div class="preview-img-container">
                                <img id="preview-image" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100%25' height='100%25' viewBox='0 0 800 600'%3E%3Crect width='100%25' height='100%25' fill='%23eef1f1'/%3E%3Cpath fill='%23a8afaf' d='M330 200l-120 180h240zM470 280l-80 100h160z'/%3E%3Ccircle cx='340' cy='280' r='30' fill='%23a8afaf'/%3E%3C/svg%3E" alt="Vehicle Image">
                            </div>
                            
                            <div class="upload-btn-wrapper">
                                <label class="file-upload-label" for="file-upload-input">
                                    <i class="fas fa-cloud-upload-alt"></i> Upload Vehicle Image *
                                </label>
                                <input class="file-upload-input" type="file" id="file-upload-input" name="image" accept="image/*" required>
                            </div>
                            <div class="selected-file-name" id="selected-file-name">Drag & drop photo here or click to browse (Required, max 5MB JPG/PNG/WebP)</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit-premium">
                    <i class="fas fa-plus"></i> List Vehicle
                </button>
            </form>
        </div>
    </div>

    <script src="../js/app.js" defer></script>
    <script>
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => navbar?.classList.toggle('scrolled', window.scrollY > 20));

        (function () {
            const dropZone  = document.getElementById('image-preview-card');
            const fileInput = document.getElementById('file-upload-input');
            const fileLabel = document.getElementById('selected-file-name');
            const previewImage = document.getElementById('preview-image');

            function showFile(file) {
                if (!file) return;
                // Update file status label
                fileLabel.innerHTML = `<i class="fas fa-check-circle" style="color:#059669;"></i> ${file.name}`;
                
                // Read and update the image preview element
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }

            fileInput.addEventListener('change', () => showFile(fileInput.files[0]));

            dropZone.addEventListener('dragover', (e) => { 
                e.preventDefault(); 
                dropZone.style.borderColor = '#7B6262';
                dropZone.style.background = 'rgba(255, 255, 255, 0.85)';
            });
            dropZone.addEventListener('dragleave', ()  => { 
                dropZone.style.borderColor = 'rgba(0, 0, 0, 0.12)';
                dropZone.style.background = 'rgba(255, 255, 255, 0.6)';
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.style.borderColor = 'rgba(0, 0, 0, 0.12)';
                dropZone.style.background = 'rgba(255, 255, 255, 0.6)';
                const file = e.dataTransfer.files[0];
                if (file) {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    fileInput.files = dt.files;
                    showFile(file);
                }
            });
        })();
    </script>
</body>
</html>
