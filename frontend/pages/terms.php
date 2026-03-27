<?php
// frontend/pages/terms.php
require_once '../../backend/config/session.php';
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Terms of Service — Read our rental terms before booking.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/pages.css">
    <title>SpinGo | Terms of Service</title>
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

        <h1 class="legal-title">Terms of Service</h1>
        <p class="legal-date">Last updated: March 2026</p>

        <div class="legal-highlight">
            <i class="fas fa-info-circle"></i>
            Please read these terms carefully before making a booking. By completing a reservation, you agree to be bound by these terms.
        </div>

        <div class="legal-section">
            <h2>1. Eligibility</h2>
            <ul>
                <li>You must be at least <strong>18 years old</strong> to rent a vehicle.</li>
                <li>You must hold a valid driver's license applicable to the vehicle category.</li>
                <li>Foreign nationals must present a valid passport and an International Driving Permit (IDP) where required by local law.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>2. Booking & Confirmation</h2>
            <ul>
                <li>Bookings are confirmed instantly upon submission.</li>
                <li>A confirmation email will be sent to your registered email address.</li>
                <li>SpinGo reserves the right to cancel a booking if the renter's documents cannot be verified.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>3. Cancellation Policy</h2>
            <ul>
                <li><strong>More than 4 days before pickup:</strong> Full refund.</li>
                <li><strong>1–4 days before pickup:</strong> 50% refund.</li>
                <li><strong>Pickup day (0 days left):</strong> No refund.</li>
                <li>Cancellations must be processed through your dashboard.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>4. Renter Responsibilities</h2>
            <ul>
                <li>The renter is responsible for any damage, traffic violations, or fines incurred during the rental period.</li>
                <li>Vehicles must be returned in the same condition as received, with the agreed fuel level.</li>
                <li>Smoking, illegal substances, and unauthorized passengers are prohibited.</li>
                <li>Vehicles may not be taken outside the agreed geographic area without written consent.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>5. Insurance</h2>
            <p>All vehicles listed on SpinGo are covered by basic third-party liability insurance. Renters are advised to review the specific coverage details provided at the time of booking. Personal accident coverage is optional and must be requested at the time of rental.</p>
        </div>

        <div class="legal-section">
            <h2>6. Payment</h2>
            <ul>
                <li>All prices are shown in Rupees (NPR) and are inclusive of taxes unless stated otherwise.</li>
                <li>We support secure online payments via eSewa, Khalti, and direct Bank Transfer.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2>7. Data & Privacy</h2>
            <p>Information collected from renters (ID, license, phone) is used solely for identity verification and booking management. See our <a href="privacy.php">Privacy Policy</a> for full details.</p>
        </div>

        <div class="legal-section">
            <h2>8. Governing Law</h2>
            <p>These terms are governed by the laws of the jurisdiction in which the vehicle is rented. Any disputes shall be settled through arbitration before escalation to court.</p>
        </div>

        <div class="legal-section">
            <h2>9. Contact</h2>
            <p>Questions? Email us at <a href="mailto:support@spingo.com">support@spingo.com</a> or visit our Help Center.</p>
        </div>

    </div>

</body>
</html>