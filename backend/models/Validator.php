<?php
// backend/models/Validator.php

class Validator {
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validatePassword($password) {
        // Min 8 chars, at least one uppercase, one lowercase, one number
        return strlen($password) >= 8 && 
               preg_match('/[A-Z]/', $password) && 
               preg_match('/[a-z]/', $password) && 
               preg_match('/[0-9]/', $password);
    }

    public static function validateFullName($name) {
        $name = trim($name);
        return strlen($name) >= 3 && 
               strlen($name) <= 255 && 
               preg_match("/^[a-zA-Z\s\-\'\.]+$/", $name);
    }

    public static function sanitizeInput($input) {
        return htmlspecialchars(strip_tags(trim($input)));
    }
}
