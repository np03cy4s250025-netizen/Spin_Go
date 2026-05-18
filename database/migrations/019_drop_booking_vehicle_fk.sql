-- database/migrations/019_drop_booking_vehicle_fk.sql
-- Description: Drops the foreign key constraint bookings.fk_booking_vehicle to allow polymorphic vehicle IDs.

ALTER TABLE bookings DROP FOREIGN KEY fk_booking_vehicle;

-- Track migration
INSERT IGNORE INTO schema_versions (version) VALUES ('019_drop_booking_vehicle_fk');
