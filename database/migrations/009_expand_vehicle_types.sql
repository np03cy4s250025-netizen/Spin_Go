-- database/migrations/009_expand_vehicle_types.sql

-- 1. Expand type column for main vehicles table
ALTER TABLE vehicles MODIFY COLUMN type ENUM('car','bike','suv','sports','electric','motorbike') NOT NULL;

-- 2. Expand type column for host applications table
ALTER TABLE host_vehicles MODIFY COLUMN type ENUM('car','bike','suv','sports','electric','motorbike') NOT NULL DEFAULT 'car';

-- 3. Track migration
INSERT IGNORE INTO schema_versions (version) VALUES ('009_expand_vehicle_types');
