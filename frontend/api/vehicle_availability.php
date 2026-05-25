<?php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';

header('Content-Type: application/json');

$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
if (!$vehicle_id) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT pickup_date, dropoff_date, status
    FROM bookings
    WHERE vehicle_id = :vid
    AND status = 'confirmed'
    AND dropoff_date >= CURDATE()
");
$stmt->execute([':vid' => $vehicle_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($bookings as $b) {
    $results[] = $b;
    // Add 2-day buffer
    $bufferStart = date('Y-m-d', strtotime($b['dropoff_date'] . ' +1 day'));
    $bufferEnd   = date('Y-m-d', strtotime($b['dropoff_date'] . ' +2 days'));
    $results[] = [
        'pickup_date'  => $bufferStart,
        'dropoff_date' => $bufferEnd,
        'status'       => 'buffer'
    ];
}
echo json_encode($results);
