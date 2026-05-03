<?php
// backend/models/Deposit.php

class Deposit {
    private $conn;

    public function __construct($database) {
        $this->conn = $database;
    }

    public function holdDeposit($booking_id, $amount) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO deposits (booking_id, amount, status) VALUES (:booking_id, :amount, 'held')");
            $stmt->execute([
                ':booking_id' => $booking_id,
                ':amount' => $amount
            ]);
            return ['status' => 'success', 'message' => 'Deposit held successfully.'];
        } catch (PDOException $e) {
            error_log('Deposit::holdDeposit - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to hold deposit.'];
        }
    }

    public function updateStatus($id, $status) {
        try {
            $stmt = $this->conn->prepare("UPDATE deposits SET status = :status WHERE id = :id");
            $stmt->execute([
                ':status' => $status,
                ':id' => $id
            ]);
            return ['status' => 'success', 'message' => 'Deposit status updated.'];
        } catch (PDOException $e) {
            error_log('Deposit::updateStatus - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to update deposit status.'];
        }
    }
}
