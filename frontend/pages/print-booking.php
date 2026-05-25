<?php
// frontend/pages/print-booking.php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

requireLogin();

$user_id    = $_SESSION['user_id'];
$booking_id = (int)($_GET['id'] ?? $_GET['booking_id'] ?? 0);

if (!$booking_id) {
    die("Invalid booking ID.");
}

$stmt = $conn->prepare("
    SELECT b.*,
           COALESCE(v.name,          hv.name)          AS vehicle_name,
           COALESCE(v.price,         hv.price_per_day) AS price_per_day,
           COALESCE(v.type,          hv.type)          AS vehicle_type,
           COALESCE(v.city,          hv.city)          AS pickup_city,
           u.full_name AS user_name, u.email AS user_email,
           hv.host_id
    FROM bookings b
    LEFT JOIN vehicles      v  ON v.id  = b.vehicle_id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
    JOIN users u ON b.user_id = u.id
    WHERE b.id = :bid
");
$stmt->execute([':bid' => $booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    die("Booking not found.");
}

// Access check: User must be the buyer, an admin, or the host of the vehicle.
$is_owner = ((int)$booking['user_id'] === (int)$user_id);
$is_admin = isAdmin();
$is_host  = ($booking['source'] === 'host' && (int)$booking['host_id'] === (int)$user_id);

if (!$is_owner && !$is_admin && !$is_host) {
    die("Access denied.");
}

$pickup  = new DateTime($booking['pickup_date']);
$dropoff = new DateTime($booking['dropoff_date']);
$days    = $pickup->diff($dropoff)->days ?: 1;

$status = strtolower($booking['status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?> — SpinGo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary:        #3F3E46;
            --primary-dark:   #2e2d34;
            --secondary:      #52525C;
            --accent:         #7B6262;
            --accent-dark:    #5c4a4a;
            --accent-glow:    rgba(123, 98, 98, 0.22);
            --accent-light:   rgba(123, 98, 98, 0.10);
            --bg-main:        #BFC4C4;
            --bg-light:       #d0d5d5;
            --white:          #ffffff;
            --glass:          rgba(255,255,255,0.65);
            --glass-border:   rgba(255,255,255,0.40);
            --text-main:      #1a1a1a;
            --text-muted:     #52525C;
            --border-solid:   rgba(0,0,0,0.12);
            --status-active:    #059669;
            --status-completed: #0284c7;
            --status-cancelled: #dc2626;
            --status-pending:   #d97706;
            --radius-lg: 20px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --radius-pill: 50px;
            --shadow-float: 0 20px 50px rgba(0,0,0,0.18);
            --font-display: 'Space Grotesk', sans-serif;
            --font-body:    'Inter', sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-body);
            background: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 48px 16px 80px;
            -webkit-font-smoothing: antialiased;
        }
        .toolbar {
            width: 100%; max-width: 760px;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px;
        }
        .toolbar-back {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: var(--font-display); font-size: 13px; font-weight: 500;
            color: var(--primary-dark); text-decoration: none;
            background: var(--glass); border: 1px solid var(--glass-border);
            backdrop-filter: blur(8px); padding: 9px 16px;
            border-radius: var(--radius-pill); transition: background .2s, color .2s;
        }
        .toolbar-back:hover { background: var(--white); color: var(--accent-dark); }
        .btn-print {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--primary); color: var(--white); border: none;
            border-radius: var(--radius-pill); font-family: var(--font-display);
            font-size: 13px; font-weight: 600; letter-spacing: .02em;
            padding: 10px 22px; cursor: pointer;
            box-shadow: 0 4px 14px rgba(63,62,70,0.35);
            transition: background .2s, transform .1s, box-shadow .2s;
        }
        .btn-print:hover { background: var(--primary-dark); box-shadow: 0 6px 20px rgba(63,62,70,0.45); }
        .btn-print:active { transform: scale(0.97); }

        .receipt {
            width: 100%; max-width: 760px;
            background: var(--white); border-radius: var(--radius-lg);
            overflow: hidden; box-shadow: var(--shadow-float); position: relative;
        }

        /* Header */
        .receipt-header {
            background: var(--primary-dark);
            padding: 36px 48px 32px;
            display: flex; align-items: flex-end; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        .receipt-header::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(circle at 20% 50%, rgba(123,98,98,0.18) 0%, transparent 60%),
                        radial-gradient(circle at 80% 20%, rgba(123,98,98,0.10) 0%, transparent 50%);
            pointer-events: none;
        }
        .brand { position: relative; display: flex; align-items: center; gap: 14px; }
        .brand-icon {
            width: 48px; height: 48px; background: var(--accent); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--white); font-size: 20px; flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(123,98,98,0.45);
        }
        .brand-name {
            font-family: var(--font-display); font-size: 28px; font-weight: 800;
            color: var(--white); letter-spacing: -.02em; line-height: 1;
        }
        .brand-name span { color: var(--accent); }
        .brand-tagline {
            font-size: 11px; letter-spacing: .12em; text-transform: uppercase;
            color: rgba(255,255,255,0.40); margin-top: 3px;
        }
        .header-meta { position: relative; text-align: right; }
        .receipt-label {
            font-size: 10px; letter-spacing: .14em; text-transform: uppercase;
            color: rgba(255,255,255,0.35); margin-bottom: 4px;
        }
        .receipt-number {
            font-family: var(--font-display); font-size: 26px; font-weight: 700;
            color: var(--white); letter-spacing: -.01em; line-height: 1;
        }
        .receipt-issued { font-size: 12px; color: rgba(255,255,255,0.35); margin-top: 6px; }

        /* Status bar */
        .status-bar {
            background: var(--bg-light); padding: 12px 48px;
            display: flex; align-items: center; gap: 10px;
            border-bottom: 1px solid var(--border-solid);
        }
        .status-pill {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--white); border: 1px solid var(--border-solid);
            border-radius: var(--radius-pill); padding: 5px 14px 5px 10px;
            font-family: var(--font-display); font-size: 12px; font-weight: 600;
            letter-spacing: .04em; color: var(--text-main);
        }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .status-dot.confirmed, .status-dot.approved { background: var(--status-active); }
        .status-dot.completed { background: var(--status-completed); }
        .status-dot.cancelled, .status-dot.rejected { background: var(--status-cancelled); }
        .status-dot.pending { background: var(--status-pending); }
        .status-bar-meta { font-size: 12px; color: var(--text-muted); margin-left: auto; }

        /* Body */
        .receipt-body { padding: 0 48px 40px; background: var(--white); }
        .section-label {
            font-family: var(--font-display); font-size: 10px; font-weight: 700;
            letter-spacing: .16em; text-transform: uppercase; color: var(--accent);
            margin-bottom: 16px;
        }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid var(--border-solid); }
        .info-col { padding: 28px 0; }
        .info-col:first-child { padding-right: 36px; border-right: 1px solid var(--border-solid); }
        .info-col:last-child { padding-left: 36px; }
        .data-row {
            display: flex; justify-content: space-between; align-items: baseline;
            gap: 12px; padding: 6px 0;
            border-bottom: 1px dashed rgba(0,0,0,0.06);
        }
        .data-row:last-child { border-bottom: none; }
        .data-key { font-size: 12px; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; }
        .data-val { font-family: var(--font-display); font-size: 13px; font-weight: 600; color: var(--text-main); text-align: right; }

        /* Rental */
        .rental-section { padding: 28px 0; border-bottom: 1px solid var(--border-solid); }
        .rental-timeline { display: flex; align-items: flex-start; gap: 0; margin-top: 18px; }
        .timeline-node { text-align: center; }
        .timeline-node-icon {
            width: 40px; height: 40px; background: var(--primary); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--white); font-size: 14px; margin: 0 auto 8px;
        }
        .timeline-node-label { font-size: 10px; letter-spacing: .10em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .timeline-node-date { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; }
        .timeline-node-day { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .timeline-connector {
            flex: 1; display: flex; flex-direction: column; align-items: center;
            justify-content: flex-start; padding-top: 12px; gap: 6px;
        }
        .timeline-line-seg { width: 100%; height: 2px; background: linear-gradient(90deg, var(--primary) 0%, var(--accent) 100%); border-radius: 2px; }
        .timeline-duration-badge {
            background: var(--accent-light); border: 1px solid var(--accent-glow);
            border-radius: var(--radius-pill); padding: 3px 12px;
            font-family: var(--font-display); font-size: 11px; font-weight: 600;
            color: var(--accent-dark); white-space: nowrap;
        }

        /* Payment */
        .payment-section { padding: 28px 0 0; }
        .payment-table { margin-top: 16px; border: 1px solid var(--border-solid); border-radius: var(--radius-md); overflow: hidden; }
        .payment-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 13px 18px; border-bottom: 1px solid var(--border-solid); font-size: 13px;
        }
        .payment-row:last-child { border-bottom: none; }
        .payment-row.total { background: var(--primary); color: var(--white); }
        .p-key { color: var(--text-muted); }
        .payment-row.total .p-key { color: rgba(255,255,255,0.55); }
        .p-val { font-family: var(--font-display); font-weight: 600; font-size: 14px; color: var(--text-main); }
        .payment-row.total .p-val { font-size: 17px; color: var(--white); }
        .payment-status-row {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            border-top: 1px solid var(--border-solid);
        }
        .payment-status-text { font-size: 12px; font-weight: 500; color: var(--text-muted); }

        /* Footer */
        .receipt-footer {
            padding: 20px 48px 28px; border-top: 2px solid var(--bg-main);
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; background: var(--bg-light);
        }
        .footer-brand { font-family: var(--font-display); font-size: 14px; font-weight: 700; color: var(--primary-dark); margin-bottom: 4px; }
        .footer-brand span { color: var(--accent); }
        .footer-copy { font-size: 11px; color: var(--text-muted); line-height: 1.7; }
        .footer-qr {
            width: 60px; height: 60px; background: var(--white);
            border: 1px solid var(--border-solid); border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .footer-qr svg { width: 44px; height: 44px; opacity: 0.45; color: var(--primary); }

        /* Cancelled Watermark */
        <?php if ($status === 'cancelled'): ?>
        .receipt::after {
            content: 'CANCELLED';
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-family: var(--font-display);
            font-size: 100px;
            font-weight: 800;
            color: rgba(220, 38, 38, 0.08);
            pointer-events: none;
            white-space: nowrap;
            z-index: 0;
        }
        <?php endif; ?>

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .receipt { box-shadow: none; border-radius: 0; max-width: 100%; }
            .receipt-header, .status-bar, .payment-row.total, .receipt-footer {
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <?php
    $back_link = 'dashboard.php';
    if (isAdmin()) {
        $back_link = 'admin.php';
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'host') {
        $back_link = 'host_dashboard.php';
    }
    ?>
    <div class="toolbar">
        <a class="toolbar-back" href="<?= $back_link ?>">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i>
            Print Receipt
        </button>
    </div>

    <div class="receipt">

        <div class="receipt-header">
            <div class="brand">
                <div class="brand-icon"><i class="fas fa-car"></i></div>
                <div>
                    <div class="brand-name">Spin<span>Go</span></div>
                    <div class="brand-tagline">Premium Vehicle Rentals</div>
                </div>
            </div>
            <div class="header-meta">
                <div class="receipt-label">Booking Receipt</div>
                <div class="receipt-number">#<?= str_pad($booking['id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div class="receipt-issued">Issued <?= date('d M Y', strtotime($booking['created_at'])) ?></div>
            </div>
        </div>

        <div class="status-bar">
            <div class="status-pill">
                <div class="status-dot <?= htmlspecialchars($status) ?>"></div>
                <?= ucfirst(htmlspecialchars($status)) ?>
            </div>
            <div class="status-bar-meta">
                Paid payment &nbsp;·&nbsp; <?= strtoupper(htmlspecialchars($booking['source'])) ?> fleet
            </div>
        </div>

        <div class="receipt-body">

            <div class="info-grid">
                <div class="info-col">
                    <div class="section-label">Customer</div>
                    <div class="data-row">
                        <span class="data-key">Name</span>
                        <span class="data-val"><?= htmlspecialchars($booking['user_name']) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-key">Email</span>
                        <span class="data-val"><?= htmlspecialchars($booking['user_email']) ?></span>
                    </div>
                </div>
                <div class="info-col">
                    <div class="section-label">Vehicle</div>
                    <div class="data-row">
                        <span class="data-key">Name</span>
                        <span class="data-val"><?= htmlspecialchars($booking['vehicle_name']) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-key">Type</span>
                        <span class="data-val"><?= ucfirst(htmlspecialchars($booking['vehicle_type'])) ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-key">Pickup City</span>
                        <span class="data-val"><?= htmlspecialchars($booking['pickup_city']) ?></span>
                    </div>
                </div>
            </div>

            <div class="rental-section">
                <div class="section-label">Rental Period</div>
                <div class="rental-timeline">
                    <div class="timeline-node">
                        <div class="timeline-node-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="timeline-node-label">Pickup</div>
                        <div class="timeline-node-date"><?= $pickup->format('d M Y') ?></div>
                        <div class="timeline-node-day"><?= $pickup->format('l') ?></div>
                    </div>
                    <div class="timeline-connector">
                        <div class="timeline-line-seg"></div>
                        <div class="timeline-duration-badge">
                            <i class="fas fa-clock" style="font-size:10px;margin-right:4px;"></i>
                            <?= $days ?> day<?= $days > 1 ? 's' : '' ?>
                        </div>
                    </div>
                    <div class="timeline-node">
                        <div class="timeline-node-icon"><i class="fas fa-flag-checkered"></i></div>
                        <div class="timeline-node-label">Drop-off</div>
                        <div class="timeline-node-date"><?= $dropoff->format('d M Y') ?></div>
                        <div class="timeline-node-day"><?= $dropoff->format('l') ?></div>
                    </div>
                </div>
            </div>

            <div class="payment-section">
                <div class="section-label">Payment Summary</div>
                <div class="payment-table">
                    <div class="payment-row">
                        <span class="p-key">Daily Rate</span>
                        <span class="p-val">Rs. <?= number_format($booking['price_per_day'], 2) ?></span>
                    </div>
                    <div class="payment-row">
                        <span class="p-key">Duration</span>
                        <span class="p-val"><?= $days ?> day<?= $days > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="payment-row">
                        <span class="p-key">Subtotal</span>
                        <span class="p-val">Rs. <?= number_format($booking['total_price'], 2) ?></span>
                    </div>
                    <div class="payment-row total">
                        <span class="p-key">Total Paid</span>
                        <span class="p-val">Rs. <?= number_format($booking['total_price'], 2) ?></span>
                    </div>
                    
                    <?php if ($status === 'cancelled'): ?>
                        <div class="payment-status-row" style="background: #fef2f2; color: var(--status-cancelled);">
                            <span><i class="fas fa-times-circle"></i></span>
                            <span class="payment-status-text" style="color:var(--status-cancelled)">Booking cancelled.</span>
                        </div>
                    <?php elseif ($status === 'pending'): ?>
                        <div class="payment-status-row" style="background: #fffbeb; color: var(--status-pending);">
                            <span><i class="fas fa-hourglass-half"></i></span>
                            <span class="payment-status-text" style="color:var(--status-pending)">Payment pending approval.</span>
                        </div>
                    <?php else: ?>
                        <div class="payment-status-row" style="background: rgba(191,196,196,0.25); color: var(--status-active);">
                            <span><i class="fas fa-check-circle"></i></span>
                            <span class="payment-status-text" style="color:var(--status-active)">Payment received — Thank you!</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="receipt-footer">
            <div>
                <div class="footer-brand">Spin<span>Go</span> — Premium Vehicle Rentals</div>
                <div class="footer-copy">
                    This is a computer-generated receipt. No signature required.<br>
                    Support: hello@spingo.com &nbsp;·&nbsp; www.spingo.com &nbsp;·&nbsp; © <?= date('Y') ?> SpinGo
                </div>
            </div>
            <div class="footer-qr">
                <svg viewBox="0 0 40 40" fill="currentColor">
                    <rect x="0" y="0" width="18" height="18" rx="1"/>
                    <rect x="22" y="0" width="18" height="18" rx="1"/>
                    <rect x="0" y="22" width="18" height="18" rx="1"/>
                    <rect x="4" y="4" width="10" height="10" rx="0.5" fill="white"/>
                    <rect x="26" y="4" width="10" height="10" rx="0.5" fill="white"/>
                    <rect x="4" y="26" width="10" height="10" rx="0.5" fill="white"/>
                    <rect x="6" y="6" width="6" height="6"/>
                    <rect x="28" y="6" width="6" height="6"/>
                    <rect x="6" y="28" width="6" height="6"/>
                    <rect x="22" y="22" width="4" height="4"/>
                    <rect x="28" y="22" width="4" height="4"/>
                    <rect x="34" y="22" width="4" height="4"/>
                    <rect x="22" y="28" width="4" height="4"/>
                    <rect x="34" y="28" width="4" height="4"/>
                    <rect x="28" y="34" width="4" height="4"/>
                    <rect x="22" y="34" width="4" height="4"/>
                </svg>
            </div>
        </div>

    </div>

</body>
</html>
