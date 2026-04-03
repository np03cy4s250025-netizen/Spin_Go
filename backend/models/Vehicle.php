<?php
// backend/models/Vehicle.php

class Vehicle {
    private $conn;
    private $table_name = "vehicles";

    public $id;
    public $name;
    public $model;
    public $year;
    public $type;
    public $price;
    public $fuel;
    public $seats;
    public $city;
    public $city_id;
    public $image;
    public $availability;
    public $source;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Method to read all available vehicles with optional filter (excludes soft-deleted)
    public function readAll($type = null, $city = null) {
        $query = "SELECT id, name, model, year, type, fuel, seats, city, city_id, price_per_day AS price, image, availability, 'host' AS source 
                  FROM host_vehicles 
                  WHERE availability = 1 
                  UNION ALL 
                  SELECT id, name, model, year, type, fuel, seats, city, city_id, price AS price, image, availability, 'admin' AS source 
                  FROM vehicles 
                  WHERE availability = 1 AND deleted_at IS NULL";
        
        $params = [];
        if ($type || $city) {
            $query = "SELECT * FROM ($query) AS combined WHERE 1=1";
            if ($type) {
                $query .= " AND type = :type";
                $params[':type'] = $type;
            }
            if ($city) {
                if (is_numeric($city)) {
                    $query .= " AND city_id = :city";
                } else {
                    $query .= " AND city = :city";
                }
                $params[':city'] = $city;
            }
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    // Get all vehicles as array
    public function getAll($type = null, $city = null) {
        $stmt = $this->readAll($type, $city);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Method to get a single vehicle details (excludes soft-deleted)
    public function readOne($id = null, $source = null) {
        $vid = $id ?: $this->id;
        
        if ($source === 'admin') {
            $query = "SELECT *, 'admin' AS source FROM vehicles WHERE id = ? AND deleted_at IS NULL LIMIT 1";
        } elseif ($source === 'host') {
            $query = "SELECT *, 'host' AS source, price_per_day AS price FROM host_vehicles WHERE id = ? LIMIT 1";
        } else {
            // Fallback logic
            $query = "SELECT *, 'admin' AS source FROM vehicles WHERE id = ? AND deleted_at IS NULL LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$vid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                $query = "SELECT *, 'host' AS source, price_per_day AS price FROM host_vehicles WHERE id = ? LIMIT 1";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$vid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            goto process_row;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute([$vid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        process_row:
        if($row) {
            $this->id = $row['id'];
            $this->name = $row['name'];
            $this->model = $row['model'] ?? null;
            $this->year = $row['year'] ?? null;
            $this->type = $row['type'];
            $this->price = $row['price'];
            $this->fuel = $row['fuel'];
            $this->seats = $row['seats'];
            $this->city = $row['city'] ?? null;
            $this->city_id = $row['city_id'] ?? null;
            $this->image = $row['image'];
            $this->source = $row['source'];
        }
        return $row;
    }
}
