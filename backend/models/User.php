<?php
// backend/models/User.php

class User {
    private $conn;
    private $table_name = "users";

    public $id;
    public $full_name;
    public $email;
    public $password_hash;
    public $role;
    public $phone;
    public $is_verified;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Checking if email exists (excludes soft-deleted users)
    public function emailExists() {
        $query = "SELECT id, full_name, password_hash, role, is_verified, phone 
                  FROM " . $this->table_name . " WHERE email = ? AND deleted_at IS NULL LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->email);
        $stmt->execute();
        
        $num = $stmt->rowCount();
        if($num > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->id = $row['id'];
            $this->full_name = $row['full_name'];
            $this->password_hash = $row['password_hash'];
            $this->role = $row['role'];
            $this->is_verified = $row['is_verified'];
            $this->phone = $row['phone'];
            return true;
        }
        return false;
    }

    // Checking if phone exists (excludes soft-deleted users)
    public function phoneExists() {
        $query = "SELECT id FROM " . $this->table_name . " WHERE phone = ? AND deleted_at IS NULL LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->phone);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }

    // Checking if phone is already registered by a different account (excludes soft-deleted users)
    public function phoneExistsForOther($excludeEmail) {
        $query = "SELECT id FROM " . $this->table_name . " WHERE phone = ? AND email != ? AND deleted_at IS NULL LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->phone);
        $stmt->bindParam(2, $excludeEmail);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }

    // Creating user
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET full_name = :full_name, email = :email, phone = :phone, password_hash = :password_hash";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->full_name = htmlspecialchars(strip_tags($this->full_name));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->phone = htmlspecialchars(strip_tags($this->phone));

        $stmt->bindParam(':full_name', $this->full_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':password_hash', $this->password_hash);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Generate and store OTP — stores SHA-256 hash, returns plaintext for emailing
    public function generateOTP() {
        $otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash   = hash('sha256', $otp);                          // hash before storage
        $otp_expiry = date('Y-m-d H:i:s', strtotime('+2 minutes'));

        $query = "UPDATE " . $this->table_name . " 
                  SET otp_code = :otp, otp_expiry = :otp_expiry 
                  WHERE email = :email AND deleted_at IS NULL";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':otp',        $otp_hash);    // store hash
        $stmt->bindParam(':otp_expiry', $otp_expiry);
        $stmt->bindParam(':email',      $this->email);

        if ($stmt->execute()) {
            return $otp;   // return plaintext — caller sends this in email
        }
        return false;
    }

    // Verify OTP — compares SHA-256 hash of submitted OTP against stored hash
    // $markVerified: true for registration, false for password-reset
    public function verifyOTP($otp, bool $markVerified = true) {
        $query = "SELECT otp_code, otp_expiry FROM " . $this->table_name . " 
                  WHERE email = :email AND deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $this->email);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || empty($row['otp_code'])) {
            return false;
        }

        // Hash the submitted OTP and compare — prevents timing attacks
        $submittedHash = hash('sha256', (string)$otp);
        if (!hash_equals((string)$row['otp_code'], $submittedHash)) {
            return false;
        }

        // Check if expired
        $now    = new DateTime();
        $expiry = new DateTime($row['otp_expiry']);
        
        if ($now > $expiry) {
            return false;
        }

        // Only mark verified when it's a registration OTP
        if ($markVerified) {
            $updateQuery = "UPDATE " . $this->table_name . " 
                           SET is_verified = 1, otp_code = NULL, otp_expiry = NULL 
                           WHERE email = :email AND deleted_at IS NULL";
        } else {
            // Password-reset: just clear the OTP, don't touch is_verified
            $updateQuery = "UPDATE " . $this->table_name . " 
                           SET otp_code = NULL, otp_expiry = NULL 
                           WHERE email = :email AND deleted_at IS NULL";
        }
        
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->bindParam(':email', $this->email);
        
        return $updateStmt->execute();
    }

    // Login user - verify password
    public function login($password) {
        if (!$this->emailExists()) {
            return false;
        }

        // Check if verified
        if (!$this->is_verified) {
            return false;
        }

        // Verify password
        if (password_verify($password, $this->password_hash)) {
            return true;
        }
        return false;
    }
}
