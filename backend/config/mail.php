<?php
// backend/config/mail.php

// Use Composer autoloader if available (after running: composer install from project root)
// Falls back to bundled PHPMailer for environments without Composer.
$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Template 1: Admin Notification Template
 * Used for admin alerts, host applications, and internal notifications.
 * Features dark #3F3E46 header and SpinGo branding.
 */
function getAdminEmailTemplate($subject, $body) {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Space+Grotesk:wght@700&display=swap');
        </style>
    </head>
    <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: \"Inter\", -apple-system, sans-serif; color: #334155;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0'>
            <tr>
                <td align='center' style='padding: 40px 20px;'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                        <!-- Header -->
                        <tr>
                            <td style='padding: 32px; background: #3F3E46; text-align: center;'>
                                <div style='font-family: \"Space Grotesk\", sans-serif; font-size: 32px; font-weight: 800; color: #ffffff;'>
                                    SpinGo<span style='color: #7B6262;'>.</span>
                                </div>
                            </td>
                        </tr>
                        <!-- Body -->
                        <tr>
                            <td style='padding: 48px 32px;'>
                                <h1 style='margin: 0 0 24px; font-size: 24px; font-weight: 700; color: #1e293b; line-height: 1.2;'>$subject</h1>
                                <div style='font-size: 16px; line-height: 1.6; color: #475569;'>
                                    $body
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style='padding: 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;'>
                                <p style='margin: 0; font-size: 14px; color: #64748b;'>
                                    &copy; " . date('Y') . " SpinGo Admin Portal. Internal Use Only.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

/**
 * Template 2: SMS-Style OTP Template
 * Minimalist design for payment and verification OTPs.
 */
function getSmsStyleTemplate($otp, $context) {
    $contextLabel = "";
    $expiryText = "Valid for 2 minutes.";
    if ($context === 'esewa') {
        $contextLabel = "eSewa payment verification";
    }
    else if ($context === 'khalti') {
        $contextLabel = "Khalti payment verification";
    }
    else if ($context === 'registration') {
        $contextLabel = "Registration verification";
    }
    else {
        $contextLabel = ucfirst($context) . " verification";
    }

    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:60px 20px; background-color:#ffffff; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
        <div style='max-width:400px; margin:0 auto; text-align:center;'>
            <div style='font-family:\"Courier New\", Courier, monospace; font-size:12px; color:#9ca3af; margin-bottom:40px; letter-spacing:3px; font-weight:700;'>SPINGO</div>
            <div style='font-family:\"Courier New\", Courier, monospace; font-size:48px; font-weight:800; color:#111827; margin-bottom:40px; letter-spacing:10px;'>$otp</div>
            <div style='font-size:13px; color:#6b7280; line-height:1.6;'>
                <p style='margin:0 0 4px;'>$expiryText</p>
                <p style='margin:0 0 24px;'>Do not share this code with anyone.</p>
                <p style='margin:0; font-size:11px; color:#9ca3af; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;'>$contextLabel</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Template 3: Customer Booking Confirmation Template
 * Friendly, branded confirmation for successful rentals.
 * Features green #10b981 accent color.
 */
function getBookingEmailTemplate($bookingData) {
    $pickupDate  = date('d M Y', strtotime($bookingData['pickup_date']));
    $dropoffDate = date('d M Y', strtotime($bookingData['dropoff_date']));
    $totalAmount = number_format($bookingData['total_price']);
    $vehicle     = $bookingData['vehicle_name'];
    $txnId       = $bookingData['transaction_id'];
    $bookingId   = $bookingData['id'];
    
    $status      = $bookingData['status'] ?? 'pending';
    $method      = $bookingData['payment_method'] ?? 'card';
    $isCash      = ($method === 'cash');

    if ($status === 'confirmed') {
        $headerColor = "#10b981"; // green
        $headerTitle = "Booking Confirmed!";
        $subTitle = "Get ready for your adventure with SpinGo.";
        $introText = "Hi there! Your booking has been confirmed by the administration. Here are your booking details:";
        $amountLabel = $isCash ? "Total Amount (Cash on Pickup)" : "Total Paid";
    } else {
        $headerColor = "#3F3E46"; // sleek dark charcoal
        $headerTitle = "Booking Requested!";
        $subTitle = "Your booking request is pending admin confirmation.";
        if ($isCash) {
            $introText = "Hi there! Your booking request has been submitted. Payment will be collected in cash upon pickup. Admin verification is required before pickup.";
            $amountLabel = "Total Amount (Pay on Pickup)";
        } else {
            $introText = "Hi there! Your payment was successful and your booking request has been submitted for admin confirmation. Here are your booking details:";
            $amountLabel = "Total Paid";
        }
    }

    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Space+Grotesk:wght@700&display=swap');
        </style>
    </head>
    <body style='margin: 0; padding: 0; background-color: #f8fafc; font-family: \"Inter\", sans-serif; color: #334155;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0'>
            <tr>
                <td align='center' style='padding: 40px 20px;'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
                        <!-- Header -->
                        <tr>
                            <td style='padding: 40px; background: $headerColor; text-align: center; color: #ffffff;'>
                                <div style='font-family: \"Space Grotesk\", sans-serif; font-size: 28px; font-weight: 800; margin-bottom: 8px;'>$headerTitle</div>
                                <div style='font-size: 16px; opacity: 0.9;'>$subTitle</div>
                            </td>
                        </tr>
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px 32px;'>
                                <p style='font-size: 16px; color: #475569; margin-bottom: 32px;'>$introText</p>
                                
                                <table width='100%' style='background: #f1f5f9; border-radius: 12px; padding: 24px; border-collapse: separate;'>
                                    <tr>
                                        <td style='padding-bottom: 12px; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 700;'>Vehicle</td>
                                        <td style='padding-bottom: 12px; font-size: 15px; color: #1e293b; font-weight: 700; text-align: right;'>$vehicle</td>
                                    </tr>
                                    <tr>
                                        <td style='padding-bottom: 12px; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 700;'>Pickup Date</td>
                                        <td style='padding-bottom: 12px; font-size: 15px; color: #1e293b; font-weight: 700; text-align: right;'>$pickupDate</td>
                                    </tr>
                                    <tr>
                                        <td style='padding-bottom: 12px; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 700;'>Return Date</td>
                                        <td style='padding-bottom: 12px; font-size: 15px; color: #1e293b; font-weight: 700; text-align: right;'>$dropoffDate</td>
                                    </tr>
                                    <tr>
                                        <td style='padding-top: 12px; border-top: 1px solid #cbd5e1; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 700;'>$amountLabel</td>
                                        <td style='padding-top: 12px; border-top: 1px solid #cbd5e1; font-size: 18px; color: #10b981; font-weight: 800; text-align: right;'>NPR $totalAmount</td>
                                    </tr>
                                </table>
 
                                <div style='margin-top: 12px; font-size: 11px; color: #94a3b8; text-align: center;'>Transaction ID: $txnId</div>
 
                                <div style='margin-top: 40px; text-align: center;'>
                                    <a href='" . (getenv('APP_URL') ?: 'http://localhost/Spin_Go') . "/frontend/pages/dashboard.php' 
                                       style='display: inline-block; padding: 14px 32px; background: $headerColor; color: #ffffff; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 15px;'>
                                        View My Booking →
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style='padding: 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;'>
                                <p style='margin: 0; font-size: 14px; color: #64748b;'>
                                    Need help? Contact us at support@spingo.com<br>
                                    &copy; " . date('Y') . " SpinGo Rental Service.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

function sendEmail($toEmail, $subject, $htmlBody) {
    // Load environment variables before anything else
    require_once __DIR__ . '/env.php';

    $mail = new PHPMailer(true);

    try {
        // ── Route ALL SMTP debug output through error_log, never stdout ──────
        // SMTPDebug=2 (DEBUG_SERVER) captures the full handshake transcript.
        // The Debugoutput closure MUST be set before SMTPDebug so PHPMailer
        // never falls back to the default echo behaviour.
        $mail->Debugoutput = function($msg, $level) {
            error_log('[SpinGo SMTP] ' . trim($msg));
        };
        $mail->SMTPDebug = 2; // SMTP::DEBUG_SERVER — output goes to closure above

        // Server settings
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = getenv('MAIL_HOST')    ?: 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME') ?: '';
        $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);

        // ── Fix STARTTLS peer-verification on XAMPP (no CA bundle) ──────────
        // This is the #1 cause of silent SMTP failure on localhost.
        // Safe to leave in place on production servers with real certs.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
        // ────────────────────────────────────────────────────────────────────

        // Recipients
        $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@spingo.com';
        $fromName  = getenv('MAIL_FROM_NAME')    ?: 'SpinGo';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[SpinGo Mail Error] SMTP ErrorInfo: ' . $mail->ErrorInfo);
        error_log('[SpinGo Mail Exception] ' . $e->getMessage());
        return false;
    }
}
