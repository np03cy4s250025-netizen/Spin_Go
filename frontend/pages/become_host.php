<?php
// frontend/pages/become_host.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/HostApplication.php';

requireLogin();

// ── Sync role & phone from DB in case they changed or were not in session ──
$freshUser = $conn->prepare('SELECT role, phone FROM users WHERE id = :id LIMIT 1');
$freshUser->execute([':id' => $_SESSION['user_id']]);
$dbUser = $freshUser->fetch(PDO::FETCH_ASSOC);
if ($dbUser) {
    if ($dbUser['role'] !== ($_SESSION['role'] ?? '')) {
        $_SESSION['role'] = $dbUser['role'];
    }
    if (!empty($dbUser['phone'])) {
        $_SESSION['user_phone'] = $dbUser['phone'];
    }
}

$user    = getCurrentUser();
$hostApp = new HostApplication($conn);
$existing = $hostApp->getByUserId($user['id']);

// Sync host_status into session for navbar visibility
$_SESSION['host_status'] = $existing['status'] ?? null;

// Already a host or approved → go directly to host dashboard
if ($user['role'] === 'host' || ($existing && $existing['status'] === 'approved')) {
    header('Location: host_dashboard.php');
    exit;
}

$navActive = 'become_host';
$flash     = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Become a SpinGo host — list your vehicle and start earning.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Become a Host</title>
</head>
<body>
    <?php 
    $navActive = 'become_host';
    include '../includes/navbar.php'; 
    ?>

    <div class="host-page-wrap">

        <!-- Hero Banner -->
        <div class="host-hero">
            <h1><i class="fas fa-key"></i>Partner with SpinGo</h1>
            <p>Share your vehicle and start earning. Our onboarding process is designed for precision and clarity.</p>
            <div class="host-hero-perks">
                <span class="host-perk"><i class="fas fa-wallet"></i>Flexible earnings</span>
                <span class="host-perk"><i class="fas fa-shield-alt"></i>You stay in control</span>
                <span class="host-perk"><i class="fas fa-bolt"></i>Quick approval</span>
            </div>
        </div>

        <?php if ($existing && $existing['status'] === 'pending' && !empty($existing['gov_id_path'])): ?>
            <!-- ── Pending State ─────────────────────────────────────── -->
            <div class="host-status-card">
                <div class="host-status-icon host-status-pending">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <h2>Application Under Review</h2>
                <p>
                    We have received your host application and our team is reviewing it.
                    This usually takes <strong>1–2 business days</strong>.
                    We'll notify you by email once a decision is made.
                </p>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>Back to My Bookings
                </a>
            </div>

        <?php elseif ($existing && $existing['status'] === 'rejected'): ?>
            <!-- ── Rejected State ────────────────────────────────────── -->
            <div class="host-status-card">
                <div class="host-status-icon host-status-rejected">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h2>Application Not Approved</h2>
                <p>
                    Unfortunately your previous host application was not approved.
                    You may submit a new application with updated documents below.
                </p>
                <!-- Fall through to the form below — overwrite existing rejected entry -->
                <a href="#apply-form" class="btn btn-primary">
                    <i class="fas fa-redo"></i>Apply Again
                </a>
            </div>

        <?php else: ?>
            <!-- ── 3-Step Wizard ──────────────────────────────────────── -->
            <div class="host-form-wrap" id="apply-form">

                <?php if ($flash): ?>
                    <div class="host-flash host-flash-success">
                        <i class="fas fa-check-circle"></i><?= htmlspecialchars($flash) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="host-flash host-flash-error">
                        <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($flashError) ?>
                    </div>
                <?php endif; ?>

                <div class="wizard-layout">

                    <!-- ── Left: Step Indicator ───────────────────────── -->
                    <div class="wizard-sidebar">
                        <div class="wizard-step-item active" data-step="1">
                            <div class="wizard-step-icon"><i class="fas fa-user"></i></div>
                            <div class="wizard-step-text">
                                <span class="wizard-step-num">STEP 01</span>
                                <span class="wizard-step-label">Personal Info</span>
                            </div>
                        </div>
                        <div class="wizard-step-item" data-step="2">
                            <div class="wizard-step-icon"><i class="fas fa-id-card"></i></div>
                            <div class="wizard-step-text">
                                <span class="wizard-step-num">STEP 02</span>
                                <span class="wizard-step-label">Document Upload</span>
                            </div>
                        </div>
                        <div class="wizard-step-item" data-step="3">
                            <div class="wizard-step-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="wizard-step-text">
                                <span class="wizard-step-num">STEP 03</span>
                                <span class="wizard-step-label">About You</span>
                            </div>
                        </div>

                        <!-- Decorative card (visual only) -->
                        <div class="wizard-sidebar-card">
                            <h3>Tactical Precision.</h3>
                            <p>We handle the logistics so you can focus on the destination.</p>
                        </div>
                    </div>

                    <!-- ── Right: Form Card ───────────────────────────── -->
                    <div class="host-form-card">

                        <form method="POST" action="process_host_application.php"
                              enctype="multipart/form-data" id="host-apply-form" novalidate>
                            <?= csrf_field() ?>

                            <!-- ═══ STEP 1 — Personal Info ═══ -->
                            <div class="wizard-panel" id="wizard-step-1">
                                <h2>Personal Information</h2>
                                <p>Please provide your legal contact details as they appear on your government ID.</p>

                                <div class="hf-row">
                                    <div class="hf-group hf-half">
                                        <label for="hf-name">Full Legal Name</label>
                                        <input type="text" id="hf-name" class="hf-input input" value="<?= htmlspecialchars($user['full_name']) ?>" readonly>
                                    </div>
                                    <div class="hf-group hf-half">
                                        <label for="hf-phone">Phone Number <span>*</span></label>
                                        <input type="tel" id="hf-phone" name="phone" class="hf-input input"
                                               required placeholder="98XXXXXXXX"
                                               maxlength="10"
                                               value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                               <?= !empty($user['phone']) ? 'readonly title="Phone number from your registered account"' : '' ?>>
                                        <?php if (!empty($user['phone'])): ?>
                                            <small style="font-size:11px; color:#6b7280; margin-top:4px; display:block;">
                                                <i class="fas fa-info-circle"></i> From your registered account
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="hf-group">
                                    <label for="hf-email">Email Address</label>
                                    <input type="email" id="hf-email" class="hf-input input" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                                </div>

                                <div class="wizard-actions">
                                    <a href="dashboard.php" class="wizard-btn-back">
                                        <i class="fas fa-arrow-left"></i> Cancel
                                    </a>
                                    <button type="button" class="btn-host-submit btn" id="btn-next-1">
                                        Continue to Documents &nbsp;<i class="fas fa-arrow-right"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- ═══ STEP 2 — Document Upload ═══ -->
                            <div class="wizard-panel" id="wizard-step-2" style="display:none">
                                <h2>Document Upload</h2>
                                <p>Upload clear, legible copies of your identification documents.</p>

                                <div class="hf-group">
                                    <label>Government-Issued ID <span>*</span></label>
                                    <label class="hf-file-label" for="hf-gov-id" id="gov-id-label">
                                        <i class="fas fa-id-card"></i>
                                        <span id="gov-id-text">Upload Citizenship / Passport / National ID</span>
                                    </label>
                                    <input type="file" id="hf-gov-id" name="gov_id"
                                           accept="image/jpeg,image/png,image/webp" required>
                                    <p class="hf-file-hint">JPG, PNG, or WebP — max 5 MB</p>
                                </div>

                                <div class="hf-group">
                                    <label>Driver's License <span>*</span></label>
                                    <label class="hf-file-label" for="hf-license" id="license-label">
                                        <i class="fas fa-car"></i>
                                        <span id="license-text">Upload Driver's License (front side)</span>
                                    </label>
                                    <input type="file" id="hf-license" name="license"
                                           accept="image/jpeg,image/png,image/webp" required>
                                    <p class="hf-file-hint">JPG, PNG, or WebP — max 5 MB</p>
                                </div>

                                <div class="wizard-actions">
                                    <button type="button" class="wizard-btn-back" id="btn-back-2">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </button>
                                    <button type="button" class="btn-host-submit btn" id="btn-next-2">
                                        Continue to About You &nbsp;<i class="fas fa-arrow-right"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- ═══ STEP 3 — About You ═══ -->
                            <div class="wizard-panel" id="wizard-step-3" style="display:none">
                                <h2>About You</h2>
                                <p>Tell us why you'd like to host and what vehicle(s) you plan to list.</p>

                                <div class="hf-group">
                                    <label for="hf-desc">Why do you want to host? <span>*</span></label>
                                    <textarea id="hf-desc" name="description" class="hf-input" required
                                              placeholder="Tell us a bit about yourself and the vehicle(s) you plan to list..."></textarea>
                                    <p class="hf-file-hint" id="desc-counter">Minimum 20 characters</p>
                                </div>

                                <div class="wizard-actions">
                                    <button type="button" class="wizard-btn-back" id="btn-back-3">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </button>
                                    <button type="submit" class="btn-host-submit btn" id="host-submit-btn">
                                        <i class="fas fa-paper-plane"></i>Submit Application
                                    </button>
                                </div>
                            </div>

                        </form>
                    </div><!-- /.host-form-card -->
                </div><!-- /.wizard-layout -->

                <!-- Trust badge -->
                <div class="wizard-trust-badge">
                    <i class="fas fa-shield-alt"></i>
                    VERIFIED &amp; SECURE DATA PROCESSING
                </div>

            </div>
        <?php endif; ?>

    </div><!-- /.host-page-wrap -->

    <script>
    // ── File-input label updates ──────────────────────────────────
    function bindFileLabel(inputId, labelTextId) {
        const input = document.getElementById(inputId);
        const span  = document.getElementById(labelTextId);
        if (!input || !span) return;
        input.addEventListener('change', function () {
            span.textContent = this.files[0] ? this.files[0].name : span.dataset.default;
        });
        span.dataset.default = span.textContent;
    }
    bindFileLabel('hf-gov-id',  'gov-id-text');
    bindFileLabel('hf-license', 'license-text');

    // ── Wizard step navigation ───────────────────────────────────
    (function () {
        let currentStep = 1;
        const totalSteps = 3;

        const panels    = [null, document.getElementById('wizard-step-1'),
                                 document.getElementById('wizard-step-2'),
                                 document.getElementById('wizard-step-3')];
        const sideItems = document.querySelectorAll('.wizard-step-item');

        function showStep(n) {
            for (let i = 1; i <= totalSteps; i++) {
                if (panels[i]) panels[i].style.display = (i === n) ? '' : 'none';
            }
            sideItems.forEach(function (el) {
                const s = parseInt(el.getAttribute('data-step'));
                el.classList.toggle('active',    s === n);
                el.classList.toggle('completed', s < n);
            });
            currentStep = n;
            // Scroll form card into view on mobile
            document.querySelector('.host-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function showError(msg) {
            alert(msg);
        }

        // ── Step 1 → 2 : validate phone ──────────────────────────
        var btnNext1 = document.getElementById('btn-next-1');
        if (btnNext1) {
            btnNext1.addEventListener('click', function () {
                var phone = document.getElementById('hf-phone').value.trim();
                if (!/^\d{10}$/.test(phone)) {
                    showError('Please enter a valid 10-digit phone number.');
                    return;
                }
                showStep(2);
            });
        }

        // ── Step 2 → 3 : validate files ──────────────────────────
        var btnNext2 = document.getElementById('btn-next-2');
        if (btnNext2) {
            btnNext2.addEventListener('click', function () {
                var govFile = document.getElementById('hf-gov-id').files[0];
                var licFile = document.getElementById('hf-license').files[0];
                if (!govFile) { showError('Please upload your Government ID.'); return; }
                if (!licFile) { showError("Please upload your Driver's License."); return; }
                if (govFile.size > 5 * 1024 * 1024) { showError('Government ID file must be under 5 MB.'); return; }
                if (licFile.size > 5 * 1024 * 1024) { showError('License file must be under 5 MB.'); return; }
                showStep(3);
            });
        }

        // ── Back buttons ─────────────────────────────────────────
        var btnBack2 = document.getElementById('btn-back-2');
        if (btnBack2) btnBack2.addEventListener('click', function () { showStep(1); });
        var btnBack3 = document.getElementById('btn-back-3');
        if (btnBack3) btnBack3.addEventListener('click', function () { showStep(2); });

        // ── Final submit validation ──────────────────────────────
        var form = document.getElementById('host-apply-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                var phone   = document.getElementById('hf-phone').value.trim();
                var govFile = document.getElementById('hf-gov-id').files[0];
                var licFile = document.getElementById('hf-license').files[0];
                var desc    = document.getElementById('hf-desc').value.trim();

                if (!/^\d{10}$/.test(phone)) {
                    e.preventDefault(); showError('Please enter a valid 10-digit phone number.'); showStep(1); return;
                }
                if (!govFile) { e.preventDefault(); showError('Please upload your Government ID.'); showStep(2); return; }
                if (!licFile) { e.preventDefault(); showError("Please upload your Driver's License."); showStep(2); return; }
                if (govFile.size > 5 * 1024 * 1024) { e.preventDefault(); showError('Government ID file must be under 5 MB.'); showStep(2); return; }
                if (licFile.size > 5 * 1024 * 1024) { e.preventDefault(); showError('License file must be under 5 MB.'); showStep(2); return; }
                if (desc.length < 20) { e.preventDefault(); showError('Please write at least 20 characters in your description.'); return; }

                document.getElementById('host-submit-btn').innerHTML = '<i class="fas fa-spinner fa-spin"></i>Submitting…';
                document.getElementById('host-submit-btn').disabled = true;
            });
        }

        // ── Description character counter ────────────────────────
        var descEl   = document.getElementById('hf-desc');
        var counterEl = document.getElementById('desc-counter');
        if (descEl && counterEl) {
            descEl.addEventListener('input', function () {
                var len = this.value.trim().length;
                if (len < 20) {
                    counterEl.textContent = len + '/20 characters (minimum 20)';
                    counterEl.style.color = '';
                } else {
                    counterEl.textContent = len + ' characters ✓';
                    counterEl.style.color = '#059669';
                }
            });
        }
    })();
    </script>
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
