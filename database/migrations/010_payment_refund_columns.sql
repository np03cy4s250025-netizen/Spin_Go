-- database/migrations/010_payment_refund_columns.sql
-- Description: Adds columns for payment and refund logic tracking.

ALTER TABLE bookings 
ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_price,
ADD COLUMN refund_status ENUM('none','full','no_refund') NOT NULL DEFAULT 'none' AFTER payment_status,
ADD COLUMN refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER refund_status,
ADD COLUMN cancelled_by ENUM('user','admin') DEFAULT NULL AFTER refund_amount,
ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER cancelled_by;

-- Insert migration record
CREATE TABLE IF NOT EXISTS schema_versions (version VARCHAR(255) PRIMARY KEY);
INSERT IGNORE INTO schema_versions (version) VALUES ('010_payment_refund_columns');

-- One-time backfill for existing paid bookings (50% upfront policy)
UPDATE bookings SET amount_paid = total_price / 2 WHERE payment_status = 'paid' AND amount_paid = 0;
