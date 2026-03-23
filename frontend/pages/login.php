<?php
// frontend/pages/login.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/User.php';
require_once '../../backend/models/Validator.php';
require_once '../../backend/utils/LoginRateLimiter.php';
require_once '../../backend/models/HostApplication.php';

// Redirect already logged-in users
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin.php' : 'dashboard.php'));
    exit;
}

// Read flash error from CSRF redirect (cleared immediately after reading)
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$error = $flashError;

if (isset($_GET['expired']) && $_GET['expired'] === '1') {
    $error = 'Your session has expired due to inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ✅ Security: Rely on REMOTE_ADDR to prevent IP spoofing via X-Forwarded-For
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    // DB-backed rate limit: max 10 attempts per 5 minutes per email+IP
    $limiter = new LoginRateLimiter($conn, 10, 300);
    if (!$limiter->isAllowed($email, $clientIp)) {
        $error = 'Too many login attempts. Please wait a few minutes before trying again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!Validator::validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = new User($conn);
        $user->email = $email;

        if ($user->emailExists() && !$user->is_verified) {
            $limiter->recordAttempt($email, $clientIp);
            $error = 'Please verify your email address before logging in.';
        } elseif ($user->login($password)) {
            // Session fixation — regenerate ID after successful auth and rotate CSRF token
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $limiter->clearAttempts($email, $clientIp);

            if (getenv('ENABLE_2FA') === 'true') {
                $otp = $user->generateOTP();
                if ($otp) {
                    $subject = 'SpinGo — Login Verification Code';
                    $_SESSION['2fa_pending_user'] = [
                        'id'        => $user->id,
                        'full_name' => $user->full_name,
                        'email'     => $user->email,
                        'phone'     => $user->phone ?? '',
                        'role'      => $user->role,
                        'host_flow' => (!empty($_POST['host_flow']) || !empty($_GET['host'])) ? 1 : 0
                    ];
                    
                    // Prevent any premature session login
                    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['host_status']);
                    
                    session_write_close();
                    if (sendEmail($email, $subject, getSmsStyleTemplate($otp, 'login'))) {
                        if (session_status() === PHP_SESSION_NONE) session_start();
                        header("Location: verify-otp.php?context=login");
                        exit;
                    } else {
                        if (session_status() === PHP_SESSION_NONE) session_start();
                        $error = 'Failed to send the verification code. Please try again.';
                    }
                } else {
                    $error = 'Could not generate a verification code. Please try again.';
                }
            } else {
                $_SESSION['user_id']        = $user->id;
                $_SESSION['user_full_name'] = $user->full_name;
                $_SESSION['user_name']      = $user->full_name; // redundancy fallback
                $_SESSION['user_email']     = $user->email;
                $_SESSION['user_phone']     = $user->phone ?? '';
                $_SESSION['role']           = $user->role;

                // Fetch host status for session behavior
                $hostApp = new HostApplication($conn);
                $host    = $hostApp->getByUserId($user->id);
                $_SESSION['host_status'] = $host ? $host['status'] : null;

                $redirect = $user->role === 'admin' ? 'admin.php' : 'dashboard.php';
                // If new host signup flow, send straight to host application page
                if (!empty($_POST['host_flow']) || !empty($_GET['host'])) {
                    $redirect = 'become_host.php';
                }
                header("Location: $redirect");
                exit;
            }
        } else {
            // Record failed attempt
            $limiter->recordAttempt($email, $clientIp);
            // Generic error — don't reveal whether email or password was wrong
            $error = 'Incorrect email or password.';
        }
    }
}

$user = null; // no user for navbar
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to SpinGo to manage your rentals.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Sign In</title>
    
    
    
    
    
    
</head>
<body>

    <div class="auth-page-wrap">

        <!-- Left: Brand panel -->
        <div class="auth-brand-panel">
            <div class="auth-brand-logo">
                <div class="logo-icon"><i class="fas fa-car"></i></div>
                SpinGo
            </div>
            <div class="auth-brand-tagline">
                <h2>Your journey starts here.</h2>
                <p>Access your bookings, manage your rentals, and explore our fleet — all in one place.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-shield-alt"></i>
                    Fully insured vehicles
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-bolt"></i>
                    Confirm bookings instantly
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-headset"></i>
                    24/7 support on the road
                </div>
            </div>
        </div>

        <!-- Right: Form panel -->
        <div class="auth-form-panel">
            <div class="auth-form-inner">

                <a href="index.php" class="auth-back-link">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>

                <h1 class="auth-form-title">Welcome back</h1>
                <p class="auth-form-subtitle">Sign in to continue to your account</p>

                <?php if (!empty($_GET['host'])): ?>
                <div class="auth-alert auth-alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <span>Account verified! Sign in below to complete your <strong>host application</strong>.</span>
                </div>
                <input type="hidden" name="host_flow" value="1">
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form class="card"  method="POST" id="login-form" novalidate>
                    <?= csrf_field() ?>

                    <div class="auth-field">
                        <label for="login-email">Email Address</label>
                        <input class="input" 
                            type="email"
                            id="login-email"
                            name="email"
                            required
                            placeholder="your@email.com"
                            autocomplete="username"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="login-password">Password</label>
                        <input class="input" 
                            type="password"
                            id="login-password"
                            name="password"
                            required
                            placeholder="Enter your password"
                            autocomplete="current-password"
                        >
                    </div>

                    <div>
                        <a href="forgot-password.php" class="auth-forgot-link">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="btn-auth-submit btn" id="login-submit-btn">
                        Sign In
                    </button>
                </form>

                <p class="auth-switch">
                    New to SpinGo? <a href="register.php">Create a free account</a>
                </p>

            </div>
        </div>

    </div>

    <script src="../js/ui.js"></script>
</body>
</html>
