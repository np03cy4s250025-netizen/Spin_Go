<?php
// backend/models/HostVehicle.php

class HostVehicle {
    private $conn;

    public function __construct($database) {
        $this->conn = $database;
        // Defensively ensure the host_vehicles type column ENUM is expanded to allow all types
        try {
            $this->conn->exec("ALTER TABLE host_vehicles MODIFY COLUMN type ENUM('car','bike','suv','sports','electric','motorbike') NOT NULL DEFAULT 'car'");
        } catch (PDOException $e) {}

        // Idempotent column guards — add description and transmission if missing
        try { $this->conn->exec("ALTER TABLE host_vehicles ADD COLUMN description TEXT NULL AFTER availability"); } catch (PDOException $e) {}
        try { $this->conn->exec("ALTER TABLE host_vehicles ADD COLUMN transmission ENUM('Manual','Automatic') NOT NULL DEFAULT 'Manual' AFTER description"); } catch (PDOException $e) {}
    }

    public function addVehicle($host_id, $name, $model, $year, $type, $fuel, $seats, $city, $city_id, $price_per_day, $image, $availability, $description = '', $transmission = 'Manual') {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO host_vehicles (host_id, name, model, year, type, fuel, seats, city, city_id, price_per_day, image, availability, description, transmission)
                 VALUES (:host_id, :name, :model, :year, :type, :fuel, :seats, :city, :city_id, :price, :image, :availability, :description, :transmission)"
            );
            $stmt->execute([
                ':host_id'      => $host_id,
                ':name'         => $name,
                ':model'        => $model,
                ':year'         => $year,
                ':type'         => $type,
                ':fuel'         => $fuel,
                ':seats'        => $seats,
                ':city'         => $city,
                ':city_id'      => $city_id,
                ':price'        => $price_per_day,
                ':image'        => $image,
                ':availability' => $availability ? 1 : 0,
                ':description'  => $description,
                ':transmission' => $transmission,
            ]);
            return ['status' => 'success', 'message' => 'Vehicle added successfully.', 'id' => (int)$this->conn->lastInsertId()];
        } catch (PDOException $e) {
            error_log('HostVehicle::addVehicle - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to add vehicle.'];
        }
    }

    public function getVehiclesByHost($host_id) {
        $stmt = $this->conn->prepare("SELECT * FROM host_vehicles WHERE host_id = :host_id ORDER BY created_at DESC");
        $stmt->execute([':host_id' => $host_id]);
        return $stmt->fetchAll();
    }

    public function getVehicleById($id, $host_id) {
        $stmt = $this->conn->prepare("SELECT * FROM host_vehicles WHERE id = :id AND host_id = :host_id LIMIT 1");
        $stmt->execute([':id' => $id, ':host_id' => $host_id]);
        return $stmt->fetch() ?: null;
    }

    public function updateVehicle($id, $host_id, $name, $model, $year, $type, $fuel, $seats, $city, $city_id, $price_per_day, $image, $description = '', $transmission = 'Manual') {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE host_vehicles 
                 SET name = :name, model = :model, year = :year, type = :type, fuel = :fuel, 
                     seats = :seats, city = :city, city_id = :city_id, price_per_day = :price, image = :image,
                     description = :description, transmission = :transmission
                 WHERE id = :id AND host_id = :host_id"
            );
            $stmt->execute([
                ':id'           => $id,
                ':host_id'      => $host_id,
                ':name'         => $name,
                ':model'        => $model,
                ':year'         => $year,
                ':type'         => $type,
                ':fuel'         => $fuel,
                ':seats'        => $seats,
                ':city'         => $city,
                ':city_id'      => $city_id,
                ':price'        => $price_per_day,
                ':image'        => $image,
                ':description'  => $description,
                ':transmission' => $transmission,
            ]);
            return ['status' => 'success', 'message' => 'Vehicle updated successfully.'];
        } catch (PDOException $e) {
            error_log('HostVehicle::updateVehicle - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to update vehicle.'];
        }
    }

    public function deleteVehicle($id, $host_id) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM host_vehicles WHERE id = :id AND host_id = :host_id");
            $stmt->execute([':id' => $id, ':host_id' => $host_id]);
            return ['status' => 'success', 'message' => 'Vehicle deleted successfully.'];
        } catch (PDOException $e) {
            error_log('HostVehicle::deleteVehicle - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to delete vehicle.'];
        }
    }

    public function toggleAvailability($id, $host_id) {
        try {
            $stmt = $this->conn->prepare("UPDATE host_vehicles SET availability = 1 - availability WHERE id = :id AND host_id = :host_id");
            $stmt->execute([':id' => $id, ':host_id' => $host_id]);
            return ['status' => 'success', 'message' => 'Availability updated successfully.'];
        } catch (PDOException $e) {
            error_log('HostVehicle::toggleAvailability - ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to update availability.'];
        }
    }
}
