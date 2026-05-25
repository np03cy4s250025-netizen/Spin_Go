-- database/migrations/013_payment_columns.sql
-- Simplified version for PDO compatibility.

-- Update existing column
ALTER TABLE `bookings` 
    MODIFY COLUMN `payment_status` ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending';

-- Add new columns with IF NOT EXISTS (supported in MariaDB 10.2.26+)
ALTER TABLE `bookings` 
    ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) DEFAULT NULL AFTER `payment_status`;

ALTER TABLE `bookings` 
    ADD COLUMN IF NOT EXISTS `transaction_id` VARCHAR(100) DEFAULT NULL AFTER `payment_method`;
