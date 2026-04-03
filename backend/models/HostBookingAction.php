<?php
// backend/models/HostBookingAction.php

class HostBookingAction {
    private $conn;

    public function __construct($database) {
        $this->conn = $database;
    }

    public function addAction($booking_id, $host_id, $status) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO host_booking_actions (booking_id, host_id, status) VALUES (:booking_id, :host_id, :status)");
            $stmt->execute([
                ':booking_id' => $booking_id,
                ':host_id' => $host_id,
                ':status' => $status
            ]);
            return ['status' => 'success', 'message' => 'Action recorded.'];
        } catch (PDOException $e) {
            error_log('HostBookingAction::addAction - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to record action.'];
        }
    }

    public function getPendingBookingsForHost($host_id) {
        // Here we mock the behavior since bookings are tied to vehicles not strictly host_vehicles in existing logic. 
        // We'll get bookings from bookings table where status is pending and vehicle belongs to host (if we join vehicle owner logic).
        // For simplicity, we just join host_booking_actions if hosts are manually assigned, 
        // or just return actions. Let's return the actions this host has.
        $stmt = $this->conn->prepare("
            SELECT hba.*, b.pickup_date, b.dropoff_date, b.total_price 
            FROM host_booking_actions hba
            JOIN bookings b ON hba.booking_id = b.id
            WHERE hba.host_id = :host_id AND hba.status = 'pending'
            ORDER BY hba.created_at DESC
        ");
        $stmt->execute([':host_id' => $host_id]);
        return $stmt->fetchAll();
    }
}
