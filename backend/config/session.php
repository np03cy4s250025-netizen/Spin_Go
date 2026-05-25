<?php
// backend/config/session.php — Centralized session management with CSRF & security hardening

if (session_status() === PHP_SESSION_NONE) {
    // Enable strict mode to prevent session fixation via uninitialized session IDs
    ini_set('session.use_strict_mode', '1');
    // Secure session cookie settings
    session_set_cookie_params([
        'lifetime' => 0, // Cookie expires when browser closes (server handles inactivity)
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─── Sliding Expiry Logic ───────────────────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    $now = time();
    $expire_after = 30 * 60; // 30 minutes in seconds

    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $expire_after) {
        // Session expired due to inactivity
        session_unset();
        session_destroy();
        
        // Redirect to login if not already on an auth page (prevents redirect loops)
        $authPages = ['login.php', 'register.php', 'verify-otp.php', 'forgot-password.php', 'reset-password.php'];
        if (!in_array(basename($_SERVER['PHP_SELF']), $authPages)) {
            header('Location: /Spin_Go/frontend/pages/login.php?expired=1');
            exit;
        }
    }
    
    $_SESSION['last_activity'] = $now;

    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = $now;
    }
}

// ─── CSRF Token ───────────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if (!$expected || !hash_equals($expected, $submitted)) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
        // Use HTTP_REFERER if available and safe; otherwise fall back to login
        $back = filter_var($_SERVER['HTTP_REFERER'] ?? '', FILTER_VALIDATE_URL)
            ? $_SERVER['HTTP_REFERER']
            : '/Spin_Go/frontend/pages/login.php';
        header('Location: ' . $back);
        exit;
    }
}

// ─── Auth Helpers ─────────────────────────────────────────────────────────────

function isLoggedIn(): bool {
    static $is_checked = null;
    static $logged_in = null;

    if ($is_checked !== null) {
        return $logged_in;
    }

    $logged_in = false;
    if (!empty($_SESSION['user_id'])) {
        $logged_in = true;
        global $conn;
        if (isset($conn)) {
            try {
                // Single JOIN — syncs role AND host approval status on every request.
                // This means an admin approving a host takes effect on the next page load,
                // no re-login required.
                $freshRow = $conn->prepare(
                    'SELECT u.role, h.status AS host_status
                     FROM users u
                     LEFT JOIN hosts h ON h.user_id = u.id
                     WHERE u.id = :id LIMIT 1'
                );
                $freshRow->execute([':id' => $_SESSION['user_id']]);
                $row = $freshRow->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $role_changed = ($row['role'] !== ($_SESSION['role'] ?? null));
                    $status_changed = ($row['host_status'] !== ($_SESSION['host_status'] ?? null));

                    if ($role_changed || $status_changed) {
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['host_status'] = $row['host_status'];
                        // Privilege elevation safety: regenerate session ID & CSRF on privilege level changes
                        session_regenerate_id(true);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                }
                $is_checked = true;
            } catch (Exception $e) {
                // Ignore DB errors silently — session values stay as-is
            }
        }
    } else {
        $is_checked = true;
    }

    return $logged_in;
}

function isAdmin(): bool {
    return !empty($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin(string $redirect = '/Spin_Go/frontend/pages/login.php'): void {
    // Prevent browser from caching any protected page
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if (!isLoggedIn()) {
        header("Location: $redirect");
        exit;
    }
}

function requireAdmin(string $redirect = '/Spin_Go/frontend/pages/login.php'): void {
    // Prevent browser from caching any admin page
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if (!isLoggedIn() || !isAdmin()) {
        header("Location: $redirect");
        exit;
    }
}

function isHost(): bool {
    return !empty($_SESSION['role']) && $_SESSION['role'] === 'host';
}

function requireHost(string $redirect = '/Spin_Go/frontend/pages/become_host.php'): void {
    // Prevent browser from caching any host page
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if (!isLoggedIn()) {
        header("Location: /Spin_Go/frontend/pages/login.php");
        exit;
    }

    if (!isHost()) {
        header("Location: $redirect");
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'full_name' => $_SESSION['user_full_name'] ?? ($_SESSION['user_name'] ?? 'User'),
        'email'     => $_SESSION['user_email'] ?? '',
        'phone'     => $_SESSION['user_phone'] ?? '',
        'role'      => $_SESSION['role']        ?? 'user',
    ];
}

// ─── Rate Limiting (session-based) ───────────────────────────────────────────

function rateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    $now = time();
    $attempts = $_SESSION["rl_{$key}"] ?? [];
    // Remove old attempts outside the window
    $attempts = array_filter($attempts, fn($t) => ($now - $t) < $windowSeconds);
    if (count($attempts) >= $maxAttempts) {
        return false; // Rate limited
    }
    $attempts[] = $now;
    $_SESSION["rl_{$key}"] = array_values($attempts);
    return true;
}

function clearRateLimit(string $key): void {
    unset($_SESSION["rl_{$key}"]);
}

// ─── Guard for Revoked/Rejected Hosts ─────────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    if (isset($conn)) {
        isLoggedIn();
    }
    
    if (isset($_SESSION['host_status']) && $_SESSION['host_status'] === 'rejected') {
        $current_page = basename($_SERVER['PHP_SELF']);
        $allowed_pages = [
            'host_revoked.php',
            'logout.php',
            'get_notifications.php',
            'mark_notifications_read.php',
            'notifications_stream.php',
            'cancel_pending_booking_beacon.php',
            'vehicle_availability.php',
            'cities.php'
        ];
        
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        if (!in_array($current_page, $allowed_pages, true) && !$is_ajax) {
            header('Location: /Spin_Go/frontend/pages/host_revoked.php');
            exit;
        }
    }
}
