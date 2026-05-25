<?php
// frontend/pages/forgot-password.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/config/mail.php';
require_once '../../backend/models/User.php';
require_once '../../backend/models/Validator.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
$error   = $flashError;
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Rate limit: 3 forgot-password requests per 10 minutes
    if (!rateLimit('forgot_pw', 3, 600)) {
        $error = 'Too many requests. Please wait 10 minutes before trying again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!Validator::validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user        = new User($conn);
            $user->email = $email;

            // Store email in session for reset step — regardless of whether email existed
            $_SESSION['reset_email'] = $email;
            session_write_close();

            // Always show success even if email not found — prevents account enumeration
            if ($user->emailExists()) {
                $otp = $user->generateOTP();
                if ($otp) {
                    $subject = 'SpinGo — Password Reset Code';
                    $body    = "
                        We received a request to reset the password for your SpinGo account.
                        If you made this request, please use the verification code below to proceed with setting a new password.
                        If you did not request a password reset, no further action is required and your account remains secure.
                    ";
                    sendEmail($email, $subject, getSmsStyleTemplate($otp, 'password reset'));
                }
            }

            header('Location: reset-password.php');
            exit;
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
    <meta name="description" content="Reset your SpinGo password.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Forgot Password</title>
    
    
    
    
    
    
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
                <h2>Forgot your password?</h2>
                <p>No problem. Enter your email and we'll send you a reset code — it only takes a minute.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-lock"></i>
                    Secure code valid 2 minutes
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-shield-alt"></i>
                    Account always protected
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

                <a href="login.php" class="auth-back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>

                <h1 class="auth-form-title">Reset Password</h1>
                <p class="auth-form-subtitle">Enter the email linked to your account</p>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form class="card"  method="POST" id="forgot-form" novalidate>
                    <?= csrf_field() ?>

                    <div class="auth-field">
                        <label for="fp-email">Email Address</label>
                        <input class="input" 
                            type="email"
                            id="fp-email"
                            name="email"
                            required
                            placeholder="your@email.com"
                            autocomplete="email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                    </div>

                    <button type="submit" class="btn-auth-submit btn" id="fp-submit-btn">
                        Send Reset Code
                    </button>
                </form>

                <p class="auth-switch">
                    Remembered it? <a href="login.php">Sign in</a>
                </p>

            </div>
        </div>

    </div>

    <script src="../js/ui.js"></script>
</body>
</html>
