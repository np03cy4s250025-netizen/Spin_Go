<?php
// backend/models/City.php

class City {
    private $conn;
    private $table = 'cities';

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all cities
     */
    public function getAll() {
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table . " ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get popular cities
     */
    public function getPopular() {
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->table . " WHERE is_popular = 1 ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search cities (for autocomplete)
     */
    public function search($query) {
        $stmt = $this->conn->prepare("SELECT id, name FROM " . $this->table . " WHERE name LIKE ? LIMIT 5");
        $search = "%$query%";
        $stmt->execute([$search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
