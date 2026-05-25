<?php
// frontend/pages/admin/export_report.php

require_once '../../../backend/config/db.php';
require_once '../../../backend/config/session.php';

// Ensure only admin can export
requireAdmin();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="spingo_report_' . date('Y-m-d') . '.csv"');

// Open php://output for writing
$output = fopen('php://output', 'w');

// ── SECTION 1: BOOKINGS SUMMARY ──────────────────────────────────────────────
fputcsv($output, ['BOOKINGS SUMMARY']);
fputcsv($output, ['Booking ID', 'Customer', 'Email', 'Vehicle', 'Pickup Date', 'Return Date', 'Total Price', 'Amount Paid', 'Status', 'Payment Status', 'Transaction ID', 'Created At']);

$bookingsQuery = $conn->query("
    SELECT b.id, u.full_name, u.email,
           COALESCE(v.name, hv.name) AS vehicle,
           b.pickup_date, b.dropoff_date, b.total_price,
           b.amount_paid,
           b.status, b.payment_status, b.transaction_id, b.created_at
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN vehicles      v  ON v.id  = b.vehicle_id AND b.source = 'admin'
    LEFT JOIN host_vehicles hv ON hv.id = b.vehicle_id AND b.source = 'host'
    WHERE u.deleted_at IS NULL
    ORDER BY b.created_at DESC
");

while ($row = $bookingsQuery->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}

// Blank row
fputcsv($output, []);

// ── SECTION 2: REVENUE SUMMARY ───────────────────────────────────────────────
fputcsv($output, ['REVENUE SUMMARY']);
fputcsv($output, ['Payment Status', 'Total Bookings', 'Total Revenue']);

$revenueQuery = $conn->query("
    SELECT payment_status, COUNT(*) as count, SUM(total_price) as total 
    FROM bookings 
    GROUP BY payment_status
");

while ($row = $revenueQuery->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}

// Blank row
fputcsv($output, []);

// ── SECTION 3: FLEET SUMMARY ─────────────────────────────────────────────────
fputcsv($output, ['FLEET SUMMARY']);
fputcsv($output, ['Vehicle', 'Type', 'City', 'Price/Day', 'Availability']);

$fleetQuery = $conn->query("
    SELECT name, type, city, price, availability 
    FROM vehicles 
    WHERE deleted_at IS NULL 
    ORDER BY name
");

while ($row = $fleetQuery->fetch(PDO::FETCH_ASSOC)) {
    // Format availability for CSV
    $row['availability'] = $row['availability'] ? 'Available' : 'Unavailable';
    fputcsv($output, $row);
}

fclose($output);
exit;
