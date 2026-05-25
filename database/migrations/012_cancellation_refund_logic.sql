-- backend/database/migrations/012_cancellation_refund_logic.sql

ALTER TABLE bookings 
ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_price,
ADD COLUMN refund_status ENUM('none','full','no_refund') NOT NULL DEFAULT 'none' AFTER payment_status,
ADD COLUMN refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER refund_status,
ADD COLUMN cancelled_by ENUM('user','admin') DEFAULT NULL AFTER refund_amount,
ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER cancelled_by;

-- Update schema versions
-- Assuming the table is schema_versions
INSERT IGNORE INTO schema_versions (version) VALUES ('012_cancellation_refund_logic');
