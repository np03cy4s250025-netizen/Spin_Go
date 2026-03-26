<?php
// frontend/pages/process_host_application.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: become_host.php');
    exit;
}

requireLogin();
csrf_verify();

$user    = getCurrentUser();
$user_id = (int)$user['id'];

// ── Helpers ──────────────────────────────────────────────────────────────────

function redirect_error(string $msg): void {
    $_SESSION['flash_error'] = $msg;
    header('Location: become_host.php');
    exit;
}

/**
 * Safely upload a file to $dest_dir.
 * Returns the stored filename on success, or calls redirect_error() on failure.
 */
function upload_host_file(array $file, string $dest_dir, string $field_label): string {
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    $max_size      = 5 * 1024 * 1024; // 5 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        redirect_error("Upload failed for {$field_label}. Please try again.");
    }

    // Re-check MIME from actual file content (don't trust $_FILES['type'])
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed_types, true)) {
        redirect_error("{$field_label}: only JPG, PNG, or WebP images are accepted.");
    }

    if ($file['size'] > $max_size) {
        redirect_error("{$field_label} exceeds the 5 MB limit.");
    }

    // Create destination directory if it does not exist
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    // Generate a unique filename using random bytes
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext      = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
    $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;

    $dest = $dest_dir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        redirect_error("Could not save {$field_label}. Please try again.");
    }

    return $filename;
}

// ── Input validation ─────────────────────────────────────────────────────────

$phone       = trim($_POST['phone']       ?? '');
$description = trim($_POST['description'] ?? '');

if (!preg_match('/^\+?[0-9\s\-]{7,20}$/', $phone)) {
    redirect_error('Please enter a valid phone number (digits only, 7–20 characters).');
}

if (mb_strlen($description) < 20) {
    redirect_error('Description must be at least 20 characters.');
}

// Check phone number uniqueness in users table (exclude current user)
$stmtPhoneCheck = $conn->prepare("SELECT id FROM users WHERE phone = :phone AND id != :uid AND deleted_at IS NULL LIMIT 1");
$stmtPhoneCheck->execute([':phone' => $phone, ':uid' => $user_id]);
if ($stmtPhoneCheck->fetch()) {
    redirect_error('This phone number is already registered by another account.');
}

// ── Check for duplicate application ─────────────────────────────────────────

$hostApp  = new HostApplication($conn);
$existing = $hostApp->getByUserId($user_id);

if ($user['role'] === 'host' || ($existing && $existing['status'] === 'approved')) {
    // Already an approved host — send them to dashboard
    header('Location: host_dashboard.php');
    exit;
}

// ── File uploads ─────────────────────────────────────────────────────────────

$upload_base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'host_docs';

if (empty($_FILES['gov_id']['tmp_name'])) {
    redirect_error('Government ID upload is required.');
}
if (empty($_FILES['license']['tmp_name'])) {
    redirect_error("Driver's License upload is required.");
}

$gov_id_file  = upload_host_file($_FILES['gov_id'],  $upload_base, 'Government ID');
$license_file = upload_host_file($_FILES['license'],  $upload_base, "Driver's License");

// ── Persist to DB ─────────────────────────────────────────────────────────────

if ($existing) {
    // Existing row (skeleton from verify-otp, or rejected reapplication) → UPDATE
    $upd = $conn->prepare(
        "UPDATE hosts SET phone = :phone, gov_id_path = :gov, license_path = :lic,
                          description = :desc, status = 'pending', reviewed_by = NULL,
                          updated_at = NOW()
         WHERE user_id = :uid"
    );
    $ok = $upd->execute([
        ':phone' => $phone, ':gov' => $gov_id_file, ':lic' => $license_file,
        ':desc'  => $description, ':uid' => $user_id
    ]);
} else {
    // No existing row → fresh INSERT
    $ok = $hostApp->create($user_id, $phone, $gov_id_file, $license_file, $description);
}

if (!$ok) {
    redirect_error('A server error occurred while saving your application. Please try again.');
}

// Sync the phone back to the users table
$updUser = $conn->prepare("UPDATE users SET phone = :phone WHERE id = :uid");
$updUser->execute([':phone' => $phone, ':uid' => $user_id]);

// ── Real-time Admin Notification (SSE) ──
$notifStmt = $conn->prepare("
    INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
    SELECT id, 'admin', 'host_application', :title, :message, :link
    FROM users WHERE role = 'admin'
");
$notifStmt->execute([
    ':title'   => "New Host Application",
    ':message' => "{$user['full_name']} ({$user['email']}) has applied to become a host.",
    ':link'    => 'approve_host.php'
]);

// ── Success ──
$_SESSION['flash_success'] = 'Your host application has been submitted successfully! We\'ll review it within 1–2 business days.';
header('Location: become_host.php');
exit;
