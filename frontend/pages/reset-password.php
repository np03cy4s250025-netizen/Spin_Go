<?php
// frontend/pages/reset-password.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/User.php';
require_once '../../backend/models/Validator.php';
require_once '../../backend/config/mail.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Must come from forgot-password.php
if (empty($_SESSION['reset_email'])) {
    header('Location: forgot-password.php');
    exit;
}

$email   = $_SESSION['reset_email'];
$error   = '';
$success = '';

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
    if (!rateLimit('resend_reset_' . md5($email), 1, 60)) {
        $_SESSION['resend_flash'] = ['type' => 'error', 'message' => 'You can request a new code only once per minute.'];
        header('Location: reset-password.php');
        exit;
    } else {
        $otp = $user->generateOTP();
        if ($otp) {
            $subject = 'SpinGo — Your New Password Reset Code';
            
            // Clear rate limit first so it unsets from the active session
            clearRateLimit('reset_pw_' . md5($email));
            
            $_SESSION['resend_flash'] = ['type' => 'success', 'message' => 'A new password reset code has been sent to your email.'];
            
            if (isset($_SESSION)) {
                session_write_close(); // Unlock session before sending email
            }
            
            if (sendEmail($email, $subject, getSmsStyleTemplate($otp, 'password reset'))) {
                header('Location: reset-password.php');
                exit;
            } else {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['resend_flash'] = ['type' => 'error', 'message' => 'Failed to send the email. Please try again later.'];
                header('Location: reset-password.php');
                exit;
            }
        } else {
            $_SESSION['resend_flash'] = ['type' => 'error', 'message' => 'Could not generate a new code. Please try again.'];
            header('Location: reset-password.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Rate limit: 5 attempts per 15 minutes
    if (!rateLimit('reset_pw_' . md5($email), 5, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $otp          = trim($_POST['otp']          ?? '');
        $new_password = $_POST['new_password']      ?? '';
        $confirm_pass = $_POST['confirm_password']  ?? '';

        if (empty($otp) || empty($new_password) || empty($confirm_pass)) {
            $error = 'All fields are required.';
        } elseif (!Validator::validatePassword($new_password)) {
            $error = 'Password must be at least 8 characters with uppercase, lowercase, and a number.';
        } elseif ($new_password !== $confirm_pass) {
            $error = 'Passwords do not match.';
        } else {
            $user        = new User($conn);
            $user->email = $email;

            if ($user->verifyOTP($otp, false)) { // false = don't mark verified (this is password reset)
                // OTP verified — update password
                $hash  = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt  = $conn->prepare("UPDATE users SET password_hash = :hash WHERE email = :email");
                $stmt->execute([':hash' => $hash, ':email' => $email]);

                clearRateLimit('reset_pw_' . md5($email));
                unset($_SESSION['reset_email']);

                $success = 'Password reset successfully! Redirecting to login…';
                header('refresh:2;url=login.php');
            } else {
                $error = 'Invalid or expired code. Please try again or request a new code.';
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
    <meta name="description" content="Set a new SpinGo password.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Reset Password</title>
    
    
    
    
    
    
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
                <h2>Create a new password.</h2>
                <p>Enter the 6-digit code sent to <strong><?= htmlspecialchars($email) ?></strong> and choose a new password.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-clock"></i>
                    Code valid for 2 minutes
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-key"></i>
                    Min 8 chars — mix of case &amp; numbers
                </div>
            </div>
        </div>

        <!-- Right: Form -->
        <div class="auth-form-panel">
            <div class="auth-form-inner">

                <a href="forgot-password.php" class="auth-back-link">
                    <i class="fas fa-arrow-left"></i> Request a new code
                </a>

                <h1 class="auth-form-title">Set New Password</h1>
                <p class="auth-form-subtitle">Code sent to <?= htmlspecialchars($email) ?></p>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="auth-alert auth-alert-success" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form class="card"  method="POST" id="reset-form" novalidate>
                    <?= csrf_field() ?>

                    <div class="auth-field">
                        <label for="rp-otp">Reset Code</label>
                        <input class="input" 
                            type="text"
                            id="rp-otp"
                            name="otp"
                            required
                            placeholder="6-digit code"
                            maxlength="6"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            autocomplete="one-time-code"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="rp-password">New Password</label>
                        <input class="input" 
                            type="password"
                            id="rp-password"
                            name="new_password"
                            required
                            placeholder="Min. 8 characters"
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="rp-confirm">Confirm Password</label>
                        <input class="input" 
                            type="password"
                            id="rp-confirm"
                            name="confirm_password"
                            required
                            placeholder="Repeat new password"
                            autocomplete="new-password"
                        >
                    </div>

                    <button type="submit" class="btn-auth-submit btn" id="rp-submit-btn">
                        Reset Password
                    </button>
                </form>
                <?php endif; ?>

                <p class="auth-switch" style="margin-top:20px; text-align:center; font-size:14px; color:var(--text-muted);">
                    Didn't receive code? <a href="?resend=1" style="font-weight:600; color:var(--primary); text-decoration:none;">Resend Code</a>
                </p>

            </div>
        </div>

    </div>

    <script src="../js/ui.js"></script>
</body>
</html>
