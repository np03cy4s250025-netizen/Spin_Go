-- database/migrations/011_license_verification.sql

ALTER TABLE bookings 
ADD COLUMN license_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER license_file, 
ADD COLUMN license_verified_at DATETIME DEFAULT NULL AFTER license_verified, 
ADD COLUMN license_verified_by INT(11) DEFAULT NULL AFTER license_verified_at;

-- Track migration
INSERT IGNORE INTO schema_versions (version) VALUES ('011_license_verification');
