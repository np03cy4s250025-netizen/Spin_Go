<?php
/**
 * database/seed_admin.php
 * ─────────────────────────────────────────────────────
 * First-run admin account creation script.
 * Run ONCE from CLI after fresh database import:
 *
 *   php database/seed_admin.php
 *
 * ⚠️  DO NOT run this script twice — it will fail if the
 *     email already exists (duplicate key constraint).
 * ⚠️  DO NOT expose this file via HTTP. Protect with .htaccess
 *     or delete after use.
 */

// CLI only — block web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line only.');
}

require_once __DIR__ . '/../backend/config/db.php';

// ── Collect credentials interactively ─────────────────────────────────────────
echo "\n=== SpinGo — Admin Account Setup ===\n\n";

echo "Full Name:   ";
$fullName = trim(fgets(STDIN));

echo "Email:       ";
$email = trim(fgets(STDIN));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email address.\n");
}

// Read password (hide input if stty is available, otherwise show it)
echo "Password:    ";
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
if (!$isWindows) {
    @system('stty -echo');
}

$password = trim(fgets(STDIN));

if (!$isWindows) {
    @system('stty echo');
    echo "\n";
}

echo "Confirm:     ";
if (!$isWindows) {
    @system('stty -echo');
}

$confirm = trim(fgets(STDIN));

if (!$isWindows) {
    @system('stty echo');
    echo "\n";
}

if ($password !== $confirm) {
    die("Error: Passwords do not match.\n");
}

if (strlen($password) < 10) {
    die("Error: Password must be at least 10 characters.\n");
}

if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
    die("Error: Password must contain uppercase, a number, and a special character.\n");
}

// ── Check for existing admin ───────────────────────────────────────────────────
$check = $conn->prepare("SELECT id FROM users WHERE email = :email OR role = 'admin' LIMIT 1");
$check->execute([':email' => $email]);
if ($check->fetch()) {
    die("Error: An admin account already exists or this email is taken.\n");
}

// ── Insert ─────────────────────────────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $conn->prepare(
    "INSERT INTO users (full_name, email, password_hash, role, is_verified, created_at)
     VALUES (:name, :email, :hash, 'admin', 1, NOW())"
);
$stmt->execute([':name' => $fullName, ':email' => $email, ':hash' => $hash]);

echo "\n✅  Admin account created successfully!\n";
echo "    Email: $email\n";
echo "    Name:  $fullName\n\n";
echo "⚠️  Store the password securely. This script should now be deleted or protected.\n\n";
