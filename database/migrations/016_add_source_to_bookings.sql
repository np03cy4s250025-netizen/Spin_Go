-- Migration 016: Add source column to bookings table
-- Tracks whether a booking is for an admin-fleet vehicle ('admin')
-- or a host-listed vehicle ('host'). Required for all queries that
-- JOIN bookings to either the `vehicles` or `host_vehicles` table.

ALTER TABLE `bookings`
    ADD COLUMN `source` ENUM('admin', 'host') NOT NULL DEFAULT 'admin'
    AFTER `vehicle_id`;

-- Back-fill existing rows: if vehicle_id matches a host_vehicles id, mark as 'host'
UPDATE `bookings` b
SET b.source = 'host'
WHERE EXISTS (
    SELECT 1 FROM `host_vehicles` hv WHERE hv.id = b.vehicle_id
);

INSERT IGNORE INTO `schema_versions` (`version`) VALUES ('016_add_source_to_bookings');
