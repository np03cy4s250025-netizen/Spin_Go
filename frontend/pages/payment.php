<?php
// frontend/pages/payment.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

if (getenv('ENABLE_PAYMENT') === 'false') {
    header('Location: dashboard.php');
    exit;
}

$booking_id = (int)($_GET['booking_id'] ?? 0);
$error_type = $_GET['error'] ?? '';

// Fetch booking with vehicle details including image (supports both admin and host fleets)
$stmt = $conn->prepare("
    SELECT b.*,
           COALESCE(v.name,  hv.name)          AS vehicle_name,
           COALESCE(v.price, hv.price_per_day)  AS daily_rate,
           COALESCE(v.image, hv.image)          AS vehicle_image
    FROM bookings b
    LEFT JOIN vehicles     v  ON v.id  = b.vehicle_id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
    WHERE b.id = :id AND b.user_id = :uid AND b.payment_status = 'pending'
    LIMIT 1
");
$stmt->execute([':id' => $booking_id, ':uid' => $_SESSION['user_id']]);
$booking = $stmt->fetch();

$phoneStmt = $conn->prepare("SELECT phone FROM users WHERE id = :id");
$phoneStmt->execute([':id' => $_SESSION['user_id']]);
$userPhone = $phoneStmt->fetchColumn() ?? '';

if (!$booking || !$booking['vehicle_name']) {
    header('Location: dashboard.php');
    exit;
}

$navActive = 'dashboard';

// Calculate details for summary
$start   = strtotime($booking['pickup_date']);
$end     = strtotime($booking['dropoff_date']);
$days    = max(1, ceil(($end - $start) / (60 * 60 * 24)));

// ── Image source resolution ───────────────────────────────────────────────────
$image = $booking['vehicle_image'] ?? '';
if (empty($image)) {
    $imgSrc = '';
} elseif (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
    $imgSrc = '../img_proxy.php?url=' . urlencode($image);
} else {
    $imgSrc = '../' . ltrim($image, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/payment.css">
    <title>SpinGo | Secure Payment</title>
    <!-- Expose live CSRF token to JS (read at click-time, never stale after token rotation) -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <!-- ── SpinAlert: lightweight modal alert / confirm library ── -->
    <style>
        #spin-alert-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); backdrop-filter: blur(4px);
            z-index: 9999; align-items: center; justify-content: center;
        }
        #spin-alert-backdrop.active { display: flex; }
        #spin-alert-box {
            background: #fff; border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.22);
            padding: 36px 32px 28px;
            max-width: 420px; width: 90%; text-align: center;
            animation: spinAlertIn 0.25s cubic-bezier(0.16,1,0.3,1);
        }
        @keyframes spinAlertIn {
            from { opacity:0; transform: scale(0.88) translateY(16px); }
            to   { opacity:1; transform: scale(1) translateY(0); }
        }
        #spin-alert-icon { font-size: 40px; margin-bottom: 14px; }
        #spin-alert-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px; font-weight: 800;
            color: #1a1a1a; margin-bottom: 10px;
        }
        #spin-alert-msg {
            font-size: 14px; color: #52525C; line-height: 1.6;
            margin-bottom: 24px;
        }
        #spin-alert-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .spin-alert-btn {
            padding: 10px 24px; border-radius: 50px; font-size: 14px;
            font-weight: 700; cursor: pointer; border: none;
            font-family: 'Space Grotesk', sans-serif;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .spin-alert-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,0.14); }
        .spin-alert-btn-ok     { background: #3F3E46; color: #fff; }
        .spin-alert-btn-cancel { background: #f3f4f6; color: #3a3a3a; }
        .spin-alert-btn-ok.danger { background: #dc2626; }
        .spin-alert-btn-ok.warning-btn { background: #d97706; }
    </style>
    <div id="spin-alert-backdrop">
        <div id="spin-alert-box">
            <div id="spin-alert-icon"></div>
            <div id="spin-alert-title"></div>
            <div id="spin-alert-msg"></div>
            <div id="spin-alert-btns"></div>
        </div>
    </div>
    <script>
    const SpinAlert = (() => {
        const backdrop = () => document.getElementById('spin-alert-backdrop');
        const box      = () => document.getElementById('spin-alert-box');

        function open({ icon, title, message, buttons }) {
            document.getElementById('spin-alert-icon').textContent  = icon  || '';
            document.getElementById('spin-alert-title').textContent = title || '';
            document.getElementById('spin-alert-msg').textContent   = message || '';
            const btns = document.getElementById('spin-alert-btns');
            btns.innerHTML = '';
            buttons.forEach(b => {
                const el = document.createElement('button');
                el.className = 'spin-alert-btn ' + (b.cls || 'spin-alert-btn-cancel');
                el.textContent = b.text;
                el.addEventListener('click', () => { close(); b.cb && b.cb(); });
                btns.appendChild(el);
            });
            backdrop().classList.add('active');
        }

        function close() { backdrop().classList.remove('active'); }

        backdrop()?.addEventListener('click', e => { if (e.target === backdrop()) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

        return {
            confirm({ title, message, okText='OK', cancelText='Cancel' }) {
                return new Promise(resolve => {
                    open({
                        icon: '⚠️', title, message,
                        buttons: [
                            { text: cancelText, cls: 'spin-alert-btn spin-alert-btn-cancel', cb: () => resolve(false) },
                            { text: okText,     cls: 'spin-alert-btn spin-alert-btn-ok danger', cb: () => resolve(true) }
                        ]
                    });
                });
            },
            error(title, message) {
                open({ icon: '❌', title, message, buttons: [{ text: 'OK', cls: 'spin-alert-btn spin-alert-btn-ok danger', cb: null }] });
            },
            warning(title, message) {
                open({ icon: '⚠️', title, message, buttons: [{ text: 'OK', cls: 'spin-alert-btn spin-alert-btn-ok warning-btn', cb: null }] });
            },
            info(title, message) {
                open({ icon: 'ℹ️', title, message, buttons: [{ text: 'OK', cls: 'spin-alert-btn spin-alert-btn-ok', cb: null }] });
            }
        };
    })();
    </script>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Processing Overlay -->
    <div id="payment-overlay">
        <i class="fas fa-spinner fa-spin spinner"></i>
        <p>Processing payment...</p>
    </div>

    <div class="payment-page">
        <!-- Page Heading -->
        <div class="payment-heading" style="grid-column: 1 / -1;">
            <h1>Secure Checkout</h1>
            <p>Complete your payment to finalize your booking.</p>
        </div>

        <?php if ($error_type): ?>
            <div style="grid-column: 1 / -1; background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #fecaca; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-exclamation-circle"></i>
                <span>
                    <?php 
                        if ($error_type === 'otp') echo "Invalid OTP. Please check the code sent to your email.";
                        elseif ($error_type === 'expired') echo "OTP has expired. Please request a new one.";
                        else echo "Payment verification failed. Please try again.";
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Left: Payment Form -->
        <div class="payment-card">
            <h3 class="card-section-title">Choose Payment Method</h3>
            
            <div class="pay-tab-bar">
                <button class="pay-tab active" data-tab="esewa">
                    <span class="tab-dot esewa-dot"></span>eSewa
                </button>
                <button class="pay-tab" data-tab="khalti">
                    <span class="tab-dot khalti-dot"></span>Khalti
                </button>
                <button class="pay-tab" data-tab="bank">
                    <i class="fas fa-university"></i> Bank Transfer
                </button>
            </div>

            <!-- eSewa Panel -->
            <div class="pay-panel" id="panel-esewa">
                <div style="color: #60BB47; font-family: 'Space Grotesk'; font-size: 22px; font-weight: 800; margin-bottom: 20px;">eSewa</div>
                <div class="pay-field">
                    <label>Step 1: Phone Number</label>
                    <input type="tel" id="esewa-phone" value="<?= htmlspecialchars($userPhone) ?>" placeholder="98XXXXXXXX" maxlength="10" class="pay-input">
                    <button type="button" class="pay-btn-otp" id="send-otp-esewa">Send OTP &rarr;</button>
                </div>

                <div class="otp-step" id="otp-step-esewa" style="display:none;">
                    <div class="pay-field">
                        <label>Step 2: Verification Code</label>
                        <input type="text" id="esewa-otp" maxlength="6" placeholder="000000" class="pay-input otp-input">
                        <span id="countdown-esewa" class="otp-countdown">Resend in 2:00</span>
                        <button type="button" id="resend-otp-esewa" class="pay-btn-otp" style="display:none; margin-top:8px;">Resend OTP &rarr;</button>
                        <div id="resend-success-esewa" class="otp-success-msg" style="display:none; color: #16a34a; font-size: 14px; margin-top: 8px; font-weight: 500;"><i class="fas fa-check-circle"></i> OTP sent successfully!</div>
                    </div>
                    <form id="form-esewa" method="POST" action="payment_process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                        <input type="hidden" name="method" value="esewa">
                        <input type="hidden" name="otp" id="hidden-otp-esewa">
                        <button type="submit" class="pay-btn">Verify &amp; Pay NPR <?= number_format($booking['total_price'], 0) ?> &rarr;</button>
                    </form>
                </div>
            </div>

            <!-- Khalti Panel -->
            <div class="pay-panel" id="panel-khalti" style="display:none;">
                <div style="color: #5C2D91; font-family: 'Space Grotesk'; font-size: 22px; font-weight: 800; margin-bottom: 20px;">Khalti</div>
                <div class="pay-field">
                    <label>Step 1: Phone Number</label>
                    <input type="tel" id="khalti-phone" value="<?= htmlspecialchars($userPhone) ?>" placeholder="98XXXXXXXX" maxlength="10" class="pay-input">
                    <button type="button" class="pay-btn-otp" id="send-otp-khalti">Send OTP &rarr;</button>
                </div>

                <div class="otp-step" id="otp-step-khalti" style="display:none;">
                    <div class="pay-field">
                        <label>Step 2: Verification Code</label>
                        <input type="text" id="khalti-otp" maxlength="6" placeholder="000000" class="pay-input otp-input">
                        <span id="countdown-khalti" class="otp-countdown">Resend in 2:00</span>
                        <button type="button" id="resend-otp-khalti" class="pay-btn-otp" style="display:none; margin-top:8px;">Resend OTP &rarr;</button>
                        <div id="resend-success-khalti" class="otp-success-msg" style="display:none; color: #16a34a; font-size: 14px; margin-top: 8px; font-weight: 500;"><i class="fas fa-check-circle"></i> OTP sent successfully!</div>
                    </div>
                    <form id="form-khalti" method="POST" action="payment_process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                        <input type="hidden" name="method" value="khalti">
                        <input type="hidden" name="otp" id="hidden-otp-khalti">
                        <button type="submit" class="pay-btn">Verify &amp; Pay NPR <?= number_format($booking['total_price'], 0) ?> &rarr;</button>
                    </form>
                </div>
            </div>

            <!-- Bank Transfer Panel -->
            <div class="pay-panel" id="panel-bank" style="display:none;">
                <div style="color: #2563eb; font-family: 'Space Grotesk'; font-size: 22px; font-weight: 800; margin-bottom: 20px;">Bank Transfer</div>
                <div class="pay-field">
                    <label>Bank Name</label>
                    <select id="bank-name" name="bank_name" class="pay-input" style="margin-bottom: 12px; width: 100%; padding: 12px; border: 1px solid var(--border-light); border-radius: 8px;">
                        <option value="">Select Bank</option>
                        <option value="Global IME">Global IME Bank</option>
                        <option value="Nabil Bank">Nabil Bank</option>
                        <option value="NIC Asia">NIC Asia Bank</option>
                        <option value="Standard Chartered">Standard Chartered</option>
                    </select>
                    
                    <label>Account Holder Name</label>
                    <input type="text" id="bank-account-name" name="bank_account_name" placeholder="John Doe" class="pay-input" style="margin-bottom: 12px;">

                    <label>Account Number</label>
                    <input type="text" id="bank-account-number" name="bank_account_number" placeholder="1234567890123456" maxlength="16" class="pay-input" style="margin-bottom: 12px;">

                    <label>Step 1: Phone Number</label>
                    <input type="tel" id="bank-phone" value="<?= htmlspecialchars($userPhone) ?>" placeholder="98XXXXXXXX" maxlength="10" class="pay-input">
                    <button type="button" class="pay-btn-otp" id="send-otp-bank">Send OTP &rarr;</button>
                </div>

                <div class="otp-step" id="otp-step-bank" style="display:none;">
                    <div class="pay-field">
                        <label>Step 2: Verification Code</label>
                        <input type="text" id="bank-otp" maxlength="6" placeholder="000000" class="pay-input otp-input">
                        <span id="countdown-bank" class="otp-countdown">Resend in 2:00</span>
                        <button type="button" id="resend-otp-bank" class="pay-btn-otp" style="display:none; margin-top:8px;">Resend OTP &rarr;</button>
                        <div id="resend-success-bank" class="otp-success-msg" style="display:none; color: #16a34a; font-size: 14px; margin-top: 8px; font-weight: 500;"><i class="fas fa-check-circle"></i> OTP sent successfully!</div>
                    </div>
                    <form id="form-bank" method="POST" action="payment_process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                        <input type="hidden" name="method" value="bank">
                        <input type="hidden" name="otp" id="hidden-otp-bank">
                        <button type="submit" class="pay-btn">Verify &amp; Pay NPR <?= number_format($booking['total_price'], 0) ?> &rarr;</button>
                    </form>
                </div>
            </div>

            <div style="margin-top: 24px; text-align: center;">
                <a href="#"
                class="cancel-booking-link"
                id="cancel-booking-link"
                data-booking-id="<?= $booking_id ?>">
                &larr; Cancel booking
                </a>
            </div>
        </div>

        <!-- Right: Order Summary -->
        <div class="summary-card">
            <?php if ($imgSrc): ?>
                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="Vehicle" class="summary-banner">
            <?php endif; ?>
            
            <div class="summary-content">
                <h3 class="card-section-title">Order Summary</h3>
                
                <div class="summary-row">
                    <span class="label">Vehicle</span>
                    <span class="value"><?= htmlspecialchars($booking['vehicle_name']) ?></span>
                </div>
                <div class="summary-row">
                    <span class="label">Pickup Date</span>
                    <span class="value"><?= date('D, d M Y', strtotime($booking['pickup_date'])) ?></span>
                </div>
                <div class="summary-row">
                    <span class="label">Return Date</span>
                    <span class="value"><?= date('D, d M Y', strtotime($booking['dropoff_date'])) ?></span>
                </div>
                <div class="summary-row">
                    <span class="label">Duration</span>
                    <span class="value"><?= $days ?> Day<?= $days > 1 ? 's' : '' ?></span>
                </div>
                <div class="summary-row">
                    <span class="label">Daily Rate</span>
                    <span class="value">NPR <?= number_format($booking['daily_rate'], 0) ?></span>
                </div>
                
                <div class="summary-total">
                    <span>Total Amount</span>
                    <span>NPR <?= number_format($booking['total_price'], 0) ?></span>
                </div>
                
                <!-- DUMMY: Remove SSL badge or replace with real cert notice in production -->
                <div class="ssl-badge">
                    <i class="fas fa-lock"></i>
                    <span>256-bit SSL Encrypted Payment</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ── Tracks whether the user submitted a payment form intentionally ──
        // Used to suppress the beforeunload beacon on real form submissions.
        let _paymentSubmitted = false;

        // Tab switching
        document.querySelectorAll('.pay-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.pay-panel').forEach(p => p.style.display = 'none');
                this.classList.add('active');
                document.getElementById('panel-' + this.dataset.tab).style.display = 'block';
            });
        });

        // ── Shared OTP fetch — called by initial send and resend ────────────────
        function sendOtp(provider, phone, onSuccess, onError) {
            fetch('payment_otp_send.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'booking_id=<?= $booking_id ?>&method=' + provider +
                      '&phone=' + encodeURIComponent(phone) +
                      '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
            }).then(r => r.json()).then(onSuccess).catch(onError);
        }

        // OTP Send handler — reused for both eSewa, Khalti, and Bank Transfer
        function setupOtp(provider) {
            const sendBtn    = document.getElementById('send-otp-'   + provider);
            const resendBtn  = document.getElementById('resend-otp-' + provider);
            const otpStep    = document.getElementById('otp-step-'   + provider);
            const phoneInput = document.getElementById(provider + '-phone');
            const countdownEl = document.getElementById('countdown-' + provider);
            const hiddenOtp  = document.getElementById('hidden-otp-' + provider);
            const otpInput   = document.getElementById(provider + '-otp');
            const successMsg = document.getElementById('resend-success-' + provider);

            // ── Helper: handle a successful OTP send response ─────────────────
            function handleOtpSuccess(data) {
                if (data.success) {
                    otpStep.style.display = 'block';
                    if (successMsg) successMsg.style.display = 'none';
                    
                    startCountdown(120, countdownEl, resendBtn, provider, () => {
                        const phone = phoneInput.value.trim();
                        sendOtp(provider, phone, handleResendSuccess, handleResendError);
                    });

                    // ── DEV MODE: auto-fill OTP ──
                    if (data.dev_otp) {
                        console.warn('[SpinGo DEV] OTP generated. Remove APP_ENV=development before going live. OTP Code: ', data.dev_otp);
                    }
                } else {
                    SpinAlert.error('OTP Not Sent', 'Failed to send OTP — ' + (data.message || 'Please try again.'));
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send OTP &rarr;';
                }
            }

            function handleOtpError() {
                SpinAlert.error('Connection Error', 'Something went wrong while sending the OTP. Please check your connection and try again.');
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send OTP &rarr;';
            }

            // ── Helpers: handle a successful and failed OTP resend response ───
            function handleResendSuccess(data) {
                if (data.success) {
                    resendBtn.style.display = 'none';
                    resendBtn.disabled = true;
                    resendBtn.innerHTML = 'Resend OTP &rarr;';
                    
                    if (successMsg) successMsg.style.display = 'block';

                    startCountdown(120, countdownEl, resendBtn, provider, () => {
                        const phone = phoneInput.value.trim();
                        sendOtp(provider, phone, handleResendSuccess, handleResendError);
                    });

                    if (data.dev_otp) {
                        console.warn('[SpinGo DEV] OTP generated. Remove APP_ENV=development before going live. OTP Code: ', data.dev_otp);
                    }
                } else {
                    SpinAlert.error('OTP Not Sent', 'Failed to send OTP — ' + (data.message || 'Please try again.'));
                    resendBtn.style.display = 'block';
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = 'Resend OTP &rarr;';
                    
                    // Re-bind the one-time click handler since it failed
                    resendBtn.addEventListener('click', () => {
                        resendBtn.disabled = true;
                        resendBtn.innerHTML = 'Sending...';
                        if (successMsg) successMsg.style.display = 'none';
                        const phone = phoneInput.value.trim();
                        sendOtp(provider, phone, handleResendSuccess, handleResendError);
                    }, { once: true });
                }
            }

            function handleResendError() {
                SpinAlert.error('Connection Error', 'Something went wrong while sending the OTP. Please check your connection and try again.');
                resendBtn.style.display = 'block';
                resendBtn.disabled = false;
                resendBtn.innerHTML = 'Resend OTP &rarr;';

                // Re-bind the one-time click handler since it failed
                resendBtn.addEventListener('click', () => {
                    resendBtn.disabled = true;
                    resendBtn.innerHTML = 'Sending...';
                    if (successMsg) successMsg.style.display = 'none';
                    const phone = phoneInput.value.trim();
                    sendOtp(provider, phone, handleResendSuccess, handleResendError);
                }, { once: true });
            }

            // ── Initial Send OTP button (Step 1) ─────────────────────────────
            sendBtn.addEventListener('click', function() {
                const phone = phoneInput.value.trim();
                if (!/^\d{10}$/.test(phone)) {
                    SpinAlert.warning('Invalid Number', 'Please enter a valid 10-digit phone number before requesting an OTP.'); return;
                }
                sendBtn.disabled = true;
                sendBtn.textContent = 'Sending...';
                sendOtp(provider, phone, handleOtpSuccess, handleOtpError);
            });

            // ── Re-enable send button on phone number change ──────────────────
            phoneInput.addEventListener('input', function() {
                sendBtn.disabled = false;
                sendBtn.innerHTML = 'Send OTP &rarr;';
                otpStep.style.display = 'none';
                if (successMsg) successMsg.style.display = 'none';
            });


            // ── Copy OTP to hidden field on form submit ───────────────────────
            document.getElementById('form-' + provider).addEventListener('submit', function(e) {
                hiddenOtp.value = otpInput.value.trim();
                if (hiddenOtp.value.length !== 6) {
                    e.preventDefault();
                    SpinAlert.warning('OTP Required', 'Please enter the 6-digit OTP sent to your phone before proceeding.');
                    return;
                }
                document.getElementById('payment-overlay').style.display = 'flex';
            });
        }

        // ── Countdown timer ───────────────────────────────────────────────────
        // resendCb: called when the user clicks the resend button
        function startCountdown(seconds, el, resendBtn, provider, resendCb) {
            // Reset visual state for a fresh countdown (handles repeated resends)
            el.style.color = '';
            el.textContent = `Resend in 2:00`;
            resendBtn.style.display = 'none';
            resendBtn.disabled = true;
            resendBtn.innerHTML = 'Resend OTP &rarr;';

            // Clear any OTP input so the user starts fresh with the new code
            document.getElementById(provider + '-otp').value = '';
            document.getElementById('hidden-otp-' + provider).value = '';

            let remaining = seconds;
            const interval = setInterval(() => {
                remaining--;
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                el.textContent = `Resend in ${m}:${s.toString().padStart(2,'0')}`;

                if (remaining <= 0) {
                    clearInterval(interval);
                    el.textContent = 'OTP expired';
                    el.style.color  = '#ef4444';

                    // Show the resend button INSIDE the OTP step (Step 1 button is hidden)
                    resendBtn.style.display = 'block';
                    resendBtn.disabled = false;
                    
                    // Hide success message on OTP expiry
                    const successMsg = document.getElementById('resend-success-' + provider);
                    if (successMsg) successMsg.style.display = 'none';

                    // Wire click — {once:true} prevents listener stacking on repeated resends
                    resendBtn.addEventListener('click', () => {
                        resendBtn.disabled = true;
                        resendBtn.innerHTML = 'Sending...';
                        if (successMsg) successMsg.style.display = 'none';
                        if (resendCb) resendCb();
                    }, { once: true });
                }
            }, 1000);
        }

        setupOtp('esewa');
        setupOtp('khalti');
        setupOtp('bank');

        // ── Cancel booking — SpinAlert confirm instead of native confirm() ──
        const cancelLink = document.getElementById('cancel-booking-link');
        if (cancelLink) {
            cancelLink.addEventListener('click', function(e) {
            e.preventDefault();
            const bookingId = this.dataset.bookingId;
            // Read CSRF live from meta tag — always fresh, even after OTP rotates it
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const dest = `cancel_pending_booking.php?booking_id=${bookingId}&csrf=${encodeURIComponent(csrf)}`;
        SpinAlert.confirm({
            title:      'Cancel This Booking?',
            message:    'Your booking will be permanently cancelled. If you already paid, a full refund will be processed.',
            okText:     '✕ Yes, Cancel It',
            cancelText: 'Keep Booking'
        }).then(confirmed => {
            if (confirmed) window.location.href = dest;
        });
    });
}

        // ── Fix 2: Mark all payment forms as intentional on submit ────────────
        document.querySelectorAll('form').forEach(f =>
            f.addEventListener('submit', () => { _paymentSubmitted = true; })
        );

        // ── sendBeacon cancel on page abandon ─────────────────────────────────
        // Must NOT fire on page reload (F5/Ctrl+R) — only on true navigation away.
        // Strategy: set a sessionStorage flag just before unload; on reload the flag
        // is already set from the previous load (sessionStorage survives same-tab
        // reloads), so we skip the beacon. Clear the flag on pageshow so fresh
        // arrivals start clean.
        window.addEventListener('pageshow', () => {
            sessionStorage.removeItem('_spg_reloading');
        });

        window.addEventListener('beforeunload', () => {
            if (_paymentSubmitted) return; // Intentional payment — never cancel

            // If the flag is already set, this is a reload — suppress beacon
            if (sessionStorage.getItem('_spg_reloading')) return;

            // Set flag so that IF this turns out to be a reload (same tab),
            // the next beforeunload will see it and skip. Also send the beacon
            // for genuine navigations away.
            sessionStorage.setItem('_spg_reloading', '1');

            const data = new FormData();
            data.append('booking_id', '<?= $booking_id ?>');
            data.append('csrf', document.querySelector('meta[name="csrf-token"]').content);
            navigator.sendBeacon('cancel_pending_booking_beacon.php', data);
        });
    </script>
</body>
</html>
