<?php
// backend/config/app.php — App-wide configuration constants
require_once __DIR__ . '/env.php';

define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Auto-detect base URL for both local and production
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Get the directory of the current script (e.g., /Spin_Go/frontend/pages/fleet.php)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
// We want to find the position of "Spin_Go" and set the root there
$projectName = 'Spin_Go';
$pos = strpos($scriptName, '/' . $projectName);

if ($pos !== false) {
    $projectRoot = substr($scriptName, 0, $pos + strlen($projectName) + 1);
    $projectRoot = rtrim($projectRoot, '/');
} else {
    // Fallback if not in a Spin_Go folder
    $projectRoot = '';
}

define('APP_URL', $protocol . '://' . $host . $projectRoot);
define('API_URL', APP_URL . '/backend/api');
