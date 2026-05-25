-- database/migrations/006_add_license_upload.sql

-- Add license_file column to bookings table
ALTER TABLE bookings ADD COLUMN license_file VARCHAR(255) DEFAULT NULL;

-- Create uploads directory if it doesn't exist (handled by PHP, but good to note)
-- The application will store licenses in /uploads/licenses/
