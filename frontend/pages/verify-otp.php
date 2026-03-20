<?php
// frontend/pages/verify-otp.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/User.php';
require_once '../../backend/config/mail.php';
require_once '../../backend/models/HostApplication.php';

$error = '';
$success = '';

$context = $_GET['context'] ?? '';
$isLogin2FA = ($context === 'login' || isset($_SESSION['2fa_pending_user']));

if ($isLogin2FA) {
    if (!isset($_SESSION['2fa_pending_user'])) {
        header('Location: login.php');
        exit;
    }
    $email = $_SESSION['2fa_pending_user']['email'];
    $redirectUrl = 'verify-otp.php?context=login';
} else {
    // Check if coming from registration (support session or ref fallback for cross-browser/cross-device support)
    if (!isset($_SESSION['registration_email'])) {
        $ref = $_GET['ref'] ?? '';
        if ($ref) {
            $parts = explode(':', urldecode($ref), 2);
            if (count($parts) === 2) {
                [$sig, $b64] = $parts;
                $decodedEmail = base64_decode($b64);
                $app_key = getenv('APP_KEY') ?: (getenv('APP_SECRET') ?: 'spingo-default-secret');
                if (hash_equals(hash_hmac('sha256', $decodedEmail, $app_key), $sig)) {
                    $_SESSION['registration_email'] = $decodedEmail;
                }
            }
        }
    }

    if (!isset($_SESSION['registration_email'])) {
        header('Location: register.php');
        exit;
    }

    $email = $_SESSION['registration_email'];
    $app_key = getenv('APP_KEY') ?: (getenv('APP_SECRET') ?: 'spingo-default-secret');
    $sig = hash_hmac('sha256', $email, $app_key);
    $token = urlencode($sig . ':' . base64_encode($email));
    $redirectUrl = 'verify-otp.php?ref=' . $token;
}

// Check for flash messages from a resend redirect
if (isset($_SESSION['resend_flash'])) {
    if ($_SESSION['resend_flash']['type'] === 'success') {
        $success = $_SESSION['resend_flash']['message'];
    } else {
        $error = $_SESSION['resend_flash']['message'];
    }
    unset($_SESSION['resend_flash']);
}

// ── Resend logic ─────────────────────────────────────────────────────────────
if (isset($_GET['resend'])) {
    $user = new User($conn);
    $user->email = $email;
    
    // Rate limit resends: 1 per minute
    if (!rateLimit('resend_' . md5($email), 1, 60)) {
        $_SESSION['resend_flash'] = ['type' => 'error', 'message' => 'You can request a new code only once per minute.'];
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        $otp = $user->generateOTP();
        if ($otp) {
            $subject = $isLogin2FA ? 'SpinGo — Your Login Verification Code' : 'SpinGo — Your New Verification Code';
            
            // Clear rate limit first so it unsets from the active session
            clearRateLimit('otp_' . md5($email));
            
            $_SESSION['resend_flash'] = ['type' => 'success', 'message' => 'A new code has been sent to your email.'];
            
            if (isset($_SESSION)) {
                session_write_close(); // Unlock session before sending email
            }
            
            $mailContext = $isLogin2FA ? 'login' : 'registration';
            if (sendEmail($email, $subject, getSmsStyleTemplate($otp, $mailContext))) {
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['resend_flash'] = ['type' => 'error', 'message' => 'Failed to send the email. Please try again later.'];
                header('Location: ' . $redirectUrl);
                exit;
            }
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Rate limit: 5 OTP attempts per 15 minutes
    if (!rateLimit('otp_' . md5($email), 5, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes or request a new code.';
    } else {
        $otp = trim($_POST['otp'] ?? '');

        if (empty($otp)) {
            $error = 'Please enter the OTP code';
        } else {
            $user = new User($conn);
            $user->email = $email;

            $markVerified = !$isLogin2FA;
            if ($user->verifyOTP($otp, $markVerified)) {
                $success = 'Verification successful! Redirecting...';
                clearRateLimit('otp_' . md5($email));

                if ($isLogin2FA) {
                    $pending = $_SESSION['2fa_pending_user'];
                    unset($_SESSION['2fa_pending_user']);

                    // Auto-login: initialize user session and rotate CSRF token
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['user_id']        = $pending['id'];
                    $_SESSION['user_full_name'] = $pending['full_name'];
                    $_SESSION['user_name']      = $pending['full_name'];
                    $_SESSION['user_email']     = $pending['email'];
                    $_SESSION['user_phone']     = $pending['phone'];
                    $_SESSION['role']           = $pending['role'];

                    // Fetch host status for session behavior
                    $hostApp = new HostApplication($conn);
                    $host    = $hostApp->getByUserId($pending['id']);
                    $_SESSION['host_status'] = $host ? $host['status'] : null;

                    $redirect = $pending['role'] === 'admin' ? 'admin.php' : 'dashboard.php';
                    if ($pending['host_flow']) {
                        $redirect = 'become_host.php';
                    }
                    header("Location: $redirect");
                    exit;
                } else {
                    unset($_SESSION['registration_email']);

                    // Fetch details to auto-login the user
                    $user->emailExists();

                    // Auto-login: initialize user session and rotate CSRF token
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['user_id']        = $user->id;
                    $_SESSION['user_full_name'] = $user->full_name;
                    $_SESSION['user_name']      = $user->full_name;
                    $_SESSION['user_email']     = $user->email;
                    $_SESSION['user_phone']     = $user->phone ?? '';
                    $_SESSION['role']           = $user->role;
                    $_SESSION['host_status']    = null; // No host row yet

                    // Check host intent to redirect to become_host.php or standard dashboard.php
                    if (!empty($_SESSION['host_intent']) && $_SESSION['host_intent_email'] === $email) {
                        unset($_SESSION['host_intent'], $_SESSION['host_intent_email']);
                        header('Location: become_host.php');
                        exit;
                    }

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid or expired OTP. Please try again.';
            }
        }
    }
}

$user = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Verify your SpinGo email address with the OTP code.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Verify OTP</title>
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
                <h2><?= $isLogin2FA ? 'Sign In Security Check' : 'Check your inbox.' ?></h2>
                <p>Enter the 6-digit code we sent to <strong><?= htmlspecialchars($email) ?></strong> to <?= $isLogin2FA ? 'securely sign in' : 'verify your account' ?>.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-clock"></i>
                    Code expires in 2 minutes
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-shield-alt"></i>
                    Your account stays secure
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-envelope"></i>
                    Check your spam folder too
                </div>
            </div>
        </div>

        <!-- Right: Form -->
        <div class="auth-form-panel">
            <div class="auth-form-inner">

                <?php if ($isLogin2FA): ?>
                    <a href="login.php" class="auth-back-link">
                        <i class="fas fa-arrow-left"></i> Back to Sign In
                    </a>
                <?php else: ?>
                    <a href="register.php" class="auth-back-link">
                        <i class="fas fa-arrow-left"></i> Back to Register
                    </a>
                <?php endif; ?>

                <h1 class="auth-form-title"><?= $isLogin2FA ? 'Sign In Verification' : 'Verify Your Email' ?></h1>
                <p class="auth-form-subtitle"><?= $isLogin2FA ? 'Enter the login verification code sent to' : 'Enter the OTP code sent to' ?> <?php echo htmlspecialchars($email); ?></p>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="auth-alert auth-alert-success" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="auth-field">
                        <label for="otp-input">OTP Code (6 digits)</label>
                        <input class="input" type="text" id="otp-input" name="otp" required
                               placeholder="000000" maxlength="6"
                               inputmode="numeric" pattern="[0-9]{6}"
                               autocomplete="one-time-code">
                    </div>

                    <button type="submit" class="btn-auth-submit btn" id="otp-submit-btn">
                        Verify &amp; Continue
                    </button>
                </form>

                <p class="auth-switch">
                    Didn't receive code? 
                    <?php if ($isLogin2FA): ?>
                        <a href="?resend=1&context=login">Resend Code</a><br>
                        <small><a href="login.php">Sign in to another account</a></small>
                    <?php else: ?>
                        <a href="?resend=1&ref=<?= $token ?>">Resend Code</a><br>
                        <small><a href="register.php">Register with another email</a></small>
                    <?php endif; ?>
                </p>

            </div>
        </div>

    </div>

    <script src="../js/ui.js"></script>
</body>
</html>
