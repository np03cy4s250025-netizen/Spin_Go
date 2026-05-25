<?php
// frontend/pages/privacy.php
require_once '../../backend/config/session.php';
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Privacy Policy — How we collect, use, and protect your data.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/pages.css">
    <title>SpinGo | Privacy Policy</title>
    <style>
        .back-btn {
            position: fixed;
            top: 24px;
            left: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: #fff;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: #444;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
            text-decoration: none;
            z-index: 999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .back-btn:hover {
            background: #f5f5f5;
            border-color: #999;
            color: #111;
        }
        .back-btn i {
            font-size: 13px;
        }
    </style>
</head>
<body>

    <div class="legal-wrap">

        <button class="back-btn" onclick="history.back()">
            <i class="fas fa-arrow-left"></i> Back
        </button>

        <h1 class="legal-title">Privacy Policy</h1>
        <p class="legal-date">Last updated: March 2026</p>

        <div class="legal-highlight">
            <i class="fas fa-lock"></i>
            SpinGo collects only the minimum information needed to operate the rental service. We do not sell your data.
        </div>

        <div class="legal-section">
            <h2>1. Information We Collect</h2>
            <table class="data-table">
                <thead>
                    <tr><th>Data Point</th><th>Why We Collect It</th><th>Required?</th></tr>
                </thead>
                <tbody>
                    <tr><td>Full name</td><td>Rental agreement, identity verification</td><td>Yes</td></tr>
                    <tr><td>Email address</td><td>Account creation, booking confirmations</td><td>Yes</td></tr>
                    <tr><td>Phone number</td><td>Emergency contact, booking coordination</td><td>Yes (at booking)</td></tr>
                    <tr><td>Driver's license number</td><td>Legal eligibility verification</td><td>Yes (at booking)</td></tr>
                    <tr><td>Booking history</td><td>Rental management, dispute resolution</td><td>Auto-collected</td></tr>
                </tbody>
            </table>
            <p>We do <strong>not</strong> collect passport numbers, credit scores, biometrics, or social media profiles.</p>
        </div>

        <div class="legal-section">
            <h2>2. How We Use Your Data</h2>
            <ul>
                <li>To verify your identity before and during rental.</li>
                <li>To send booking confirmation and updates via email.</li>
                <li>To contact you in case of vehicle emergencies or disputes.</li>
                <li>To comply with local transportation and legal requirements.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Data Sharing</h2>
            <p>We do not sell, rent, or trade your personal information to third parties. Your data may be shared only:</p>
            <ul>
                <li>With vehicle hosts when a booking is confirmed (name and phone only).</li>
                <li>With law enforcement when required by a valid legal order.</li>
                <li>With our email provider (Mailtrap/SMTP) solely for sending transactional emails.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Data Retention</h2>
            <ul>
                <li>Account data is retained for as long as the account is active.</li>
                <li>Booking records are retained for 3 years for legal and tax compliance.</li>
                <li>OTP codes are cleared immediately after verification or expiry.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>5. Your Rights</h2>
            <ul>
                <li><strong>Access:</strong> You can request a copy of all data we hold about you.</li>
                <li><strong>Correction:</strong> You can update your profile at any time.</li>
                <li><strong>Deletion:</strong> You can request account deletion. Booking records required for legal compliance will be retained.</li>
            </ul>
            <p>To exercise your rights, email <a href="mailto:privacy@spingo.com">privacy@spingo.com</a>.</p>
        </div>

        <div class="legal-section">
            <h2>6. Cookies</h2>
            <p>SpinGo uses a single session cookie for authentication. No tracking or analytics cookies are placed without consent. The session cookie is HttpOnly, SameSite=Lax, and expires after 30 minutes of inactivity.</p>
        </div>

        <div class="legal-section">
            <h2>7. Security</h2>
            <p>Passwords are hashed using bcrypt. Sessions are protected with token validation. Database connections use prepared statements. All communication should happen over HTTPS in production.</p>
        </div>

        <div class="legal-section">
            <h2>8. Contact</h2>
            <p>Privacy concerns? Contact us at <a href="mailto:privacy@spingo.com">privacy@spingo.com</a>.</p>
        </div>

    </div>
</body>
</html>