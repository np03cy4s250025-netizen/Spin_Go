<?php
// frontend/pages/register.php
require_once '../../backend/config/session.php';
require_once '../../backend/config/db.php';
require_once '../../backend/config/mail.php';
require_once '../../backend/models/User.php';
require_once '../../backend/models/Validator.php';

$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
$error   = $flashError;
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ── Role / host intent ──────────────────────────────────────
    $role_intent = ($_POST['is_host'] ?? '') === '1' ? 'host' : 'user';

    $full_name        = $_POST['full_name']        ?? '';
    $email            = $_POST['email']            ?? '';
    $phone            = $_POST['phone']            ?? '';
    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!Validator::validateFullName($full_name)) {
        $error = 'Full name must be between 3–255 characters and contain only letters, spaces, hyphens, apostrophes, or periods.';
    } elseif (!Validator::validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{10}$/', $phone)) {
        $error = 'Please enter a valid 10-digit phone number.';
    } elseif (!Validator::validatePassword($password)) {
        $error = 'Password must be at least 8 characters with uppercase, lowercase, and numbers.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match. Please try again.';
    } else {
        $user = new User($conn);
        $user->email = $email;
        $user->phone = $phone;

        if ($user->phoneExistsForOther($email)) {
            $error = 'This phone number is already registered by another account.';
        } elseif ($user->emailExists()) {
            if ($user->is_verified) {
                $error = 'This email is already registered.';
            } else {
                // Email exists but not verified — treat as a "resend and verify"
                $otp = $user->generateOTP();
                if ($otp) {
                    $subject = 'SpinGo — Complete Your Verification';
                    $body    = "
                        We noticed you previously started registering with this email but haven't verified it yet.
                        No problem — you can use the verification code below to complete your setup and start booking.
                    ";
                    $_SESSION['registration_email'] = $email;
                    $app_key = getenv('APP_KEY') ?: (getenv('APP_SECRET') ?: 'spingo-default-secret');
                    $sig = hash_hmac('sha256', $email, $app_key);
                    $token = urlencode($sig . ':' . base64_encode($email));
                    session_write_close();
                    if (sendEmail($email, $subject, getSmsStyleTemplate($otp, 'registration'))) {
                        header('Location: verify-otp.php?ref=' . $token);
                        exit;
                    } else {
                        if (session_status() === PHP_SESSION_NONE) session_start();
                        $error = 'Failed to send the verification email. Please try again.';
                    }
                }
            }
        } else {
            $user->full_name     = $full_name;
            $user->email         = $email;
            $user->phone         = $phone;
            $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
            $user->role          = 'user';
            $user->is_verified   = 0;

            if ($user->create()) {
                // Store host intent in session so verify-otp.php can act on it
                if ($role_intent === 'host') {
                    $_SESSION['host_intent']       = true;
                    $_SESSION['host_intent_email'] = $email;
                } else {
                    unset($_SESSION['host_intent'], $_SESSION['host_intent_email']);
                }
                $otp = $user->generateOTP();

                if ($otp) {
                    $subject = 'SpinGo — Verify Your Email';
                    $body    = "
                        Welcome to SpinGo! We're excited to have you on board.
                        To complete your account setup and start booking, please enter the following verification code in the window where you started registration.
                        This code is part of our commitment to keeping your account and data safe.
                    ";

                    $_SESSION['registration_email'] = $email;
                    $app_key = getenv('APP_KEY') ?: (getenv('APP_SECRET') ?: 'spingo-default-secret');
                    $sig = hash_hmac('sha256', $email, $app_key);
                    $token = urlencode($sig . ':' . base64_encode($email));
                    session_write_close();
                    if (sendEmail($email, $subject, getSmsStyleTemplate($otp, 'registration'))) {
                        header('Location: verify-otp.php?ref=' . $token);
                        exit;
                    } else {
                        if (session_status() === PHP_SESSION_NONE) session_start();
                        $error = 'Failed to send the verification email. Please try again.';
                    }
                } else {
                    $error = 'Could not generate a verification code. Please try again.';
                }
            } else {
                $error = 'Registration failed. Please try again.';
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
    <meta name="description" content="Create a free SpinGo account to start booking premium vehicles.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Create Account</title>
    
    
    
    
    
    
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
                <h2>Join thousands of happy renters.</h2>
                <p>Create a free account in seconds. No invasive forms — just the essentials to get you on the road fast.</p>
            </div>
            <div class="auth-brand-points">
                <div class="auth-brand-point">
                    <i class="fas fa-id-card"></i>
                    Only essential info collected
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-lock"></i>
                    Secure &amp; private — always
                </div>
                <div class="auth-brand-point">
                    <i class="fas fa-car-side"></i>
                    Book your first car in minutes
                </div>
            </div>
        </div>

        <!-- Right: Form panel -->
    <div class="auth-form-panel">
        <div class="auth-form-inner">

            <a href="index.php" class="auth-back-link">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>

            <h1 class="auth-form-title">Create your account</h1>
            <?php
                $role_from_url = $_GET['role'] ?? 'user';
                $hint = $role_from_url === 'host'
                    ? 'You\'ll complete your host application after verifying your email'
                    : 'Free forever — fill in the details below to get started';
            ?>
            <p class="auth-form-subtitle" id="reg-subtitle"><?= $hint ?></p>

                <?php if ($error): ?>
                    <div class="auth-alert auth-alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="auth-alert auth-alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form class="card"  method="POST" id="register-form" novalidate>
                    <?= csrf_field() ?>

                    <!-- Account-type pill toggle -->
                    <?php $host_active = (($_GET['role'] ?? '') === 'host'); ?>
                    <div class="reg-toggle" id="reg-toggle">
                        <button type="button" class="reg-toggle-tab<?= $host_active ? '' : ' active' ?>" id="tab-renter">Register as Renter</button>
                        <button type="button" class="reg-toggle-tab<?= $host_active ? ' active' : '' ?>" id="tab-host">Register as Host</button>
                    </div>
                    <input type="hidden" id="is-host-hidden" name="is_host" value="<?= $host_active ? '1' : '0' ?>">
                    <div class="auth-field">
                        <label for="reg-name">Full Name</label>
                        <input class="input" 
                            type="text"
                            id="reg-name"
                            name="full_name"
                            required
                            placeholder="Your full name"
                            autocomplete="name"
                            pattern="[a-zA-Z\s\-\'\.]+"
                            title="Full name can only contain letters, spaces, hyphens, apostrophes, and periods."
                        >
                        <span id="name-validation-msg" style="font-size: 12px; font-weight: 600; margin-top: 4px; display: block;"></span>
                    </div>

                    <div class="auth-field">
                        <label for="reg-email">Email Address</label>
                        <input class="input" 
                            type="email"
                            id="reg-email"
                            name="email"
                            required
                            placeholder="your@email.com"
                            autocomplete="username"
                            value="<?= htmlspecialchars($_GET['email'] ?? '') ?>"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="reg-phone">Phone Number</label>
                        <input class="input" 
                            type="tel"
                            id="reg-phone"
                            name="phone"
                            required
                            placeholder="98XXXXXXXX"
                            maxlength="10"
                            autocomplete="tel"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="reg-password">Password</label>
                        <input class="input" 
                            type="password"
                            id="reg-password"
                            name="password"
                            required
                            placeholder="Min. 8 characters"
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="auth-field">
                        <label for="reg-confirm-password">Confirm Password</label>
                        <input class="input" 
                            type="password"
                            id="reg-confirm-password"
                            name="confirm_password"
                            required
                            placeholder="Re-enter your password"
                            autocomplete="new-password"
                        >
                        <span id="pw-match-msg"></span>
                    </div>



                    <button type="submit" class="btn-auth-submit btn" id="register-submit-btn">
                        Create Account &rarr;
                    </button>
                </form>

                <p class="auth-switch">
                    Already have an account? <a href="login.php">Sign in</a>
                </p>

            </div>
        </div>

    </div>

    <script src="../js/ui.js"></script>
    <script>
    (function () {
        /* ── Full Name validation ── */
        const nameInput = document.getElementById('reg-name');
        const nameMsg   = document.getElementById('name-validation-msg');
        const form      = document.getElementById('register-form');

        function checkName() {
            const val = nameInput.value.trim();
            if (val === '') {
                nameMsg.textContent = '';
                return true;
            }
            // Allow letters, spaces, hyphens, apostrophes, and periods.
            const regex = /^[a-zA-Z\s\-\'\.]+$/;
            if (!regex.test(val)) {
                nameMsg.textContent = '✗ Full name can only contain letters, spaces, hyphens, apostrophes, and periods';
                nameMsg.style.color = '#dc2626';
                return false;
            } else if (val.length < 3 || val.length > 255) {
                nameMsg.textContent = '✗ Full name must be between 3 and 255 characters';
                nameMsg.style.color = '#dc2626';
                return false;
            } else {
                nameMsg.textContent = '';
                return true;
            }
        }

        nameInput.addEventListener('input', function () {
            nameMsg.textContent = '';
        });

        /* ── Dismiss PHP server-side error alert on input ── */
        const serverError = document.querySelector('.auth-alert-error');
        if (serverError) {
            form.addEventListener('input', function () {
                serverError.style.display = 'none';
            });
        }

        /* ── Password-match checker ── */
        const pw      = document.getElementById('reg-password');
        const confirm = document.getElementById('reg-confirm-password');
        const msg     = document.getElementById('pw-match-msg');
        const btn     = document.getElementById('register-submit-btn');

        function checkMatch() {
            if (confirm.value === '') {
                msg.textContent = '';
                btn.disabled = false;
                return;
            }
            if (pw.value === confirm.value) {
                msg.textContent = '✓ Passwords match';
                msg.style.color = '#059669';
                btn.disabled = false;
            } else {
                msg.textContent = '✗ Passwords do not match';
                msg.style.color = '#dc2626';
                btn.disabled = true;
            }
        }

        pw.addEventListener('input', checkMatch);
        confirm.addEventListener('input', checkMatch);

        /* ── Form Submit validation ── */
        form.addEventListener('submit', function (e) {
            const isNameValid = checkName();
            if (!isNameValid) {
                e.preventDefault();
                nameInput.focus();
                return;
            }
            if (pw.value !== confirm.value) {
                e.preventDefault();
                confirm.focus();
                return;
            }
        });

        /* ── Renter / Host tab toggle ── */
        const tabRenter  = document.getElementById('tab-renter');
        const tabHost    = document.getElementById('tab-host');
        const hiddenVal  = document.getElementById('is-host-hidden');
        const subtitle   = document.getElementById('reg-subtitle');

        function setTab(isHost) {
            hiddenVal.value = isHost ? '1' : '0';
            tabRenter.classList.toggle('active', !isHost);
            tabHost.classList.toggle('active', isHost);
            subtitle.innerHTML = isHost
                ? "You'll complete your host application after verifying your email"
                : 'Free forever — fill in the details below to get started';
        }

        tabRenter.addEventListener('click', function () { setTab(false); });
        tabHost.addEventListener('click',   function () { setTab(true);  });
    })();
    </script>
</body>
</html>
