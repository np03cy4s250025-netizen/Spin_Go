<?php
// frontend/pages/view_document.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

$dir = $_GET['dir'] ?? '';
$file = $_GET['file'] ?? '';

// Sanitize filename to prevent directory traversal
$file = basename($file);

if (empty($file) || !in_array($dir, ['licenses', 'host_docs'], true)) {
    http_response_code(400);
    die('Invalid request.');
}

$isAdmin = isAdmin();
$userId  = (int)$_SESSION['user_id'];
$allowed = false;

if ($isAdmin) {
    $allowed = true;
} else {
    // Check ownership
    if ($dir === 'licenses') {
        // Must belong to a booking by this user
        $stmt = $conn->prepare("SELECT user_id FROM bookings WHERE license_file = :file OR license_back = :file LIMIT 1");
        $stmt->execute([':file' => $file]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId !== false && (int)$ownerId === $userId) {
            $allowed = true;
        }
    } elseif ($dir === 'host_docs') {
        // Must belong to this host
        $stmt = $conn->prepare("SELECT user_id FROM hosts WHERE gov_id_path = :file OR license_path = :file LIMIT 1");
        $stmt->execute([':file' => $file]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId !== false && (int)$ownerId === $userId) {
            $allowed = true;
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    die('Access denied.');
}

$filePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    die('File not found.');
}

// Set correct Content-Type header
$mimeType = mime_content_type($filePath);
if ($mimeType) {
    header('Content-Type: ' . $mimeType);
} else {
    // Fallback based on extension
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
}

header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=86400');

// Stream file
readfile($filePath);
exit;
