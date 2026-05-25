-- database/migrations/015_bookings_cancelled_by_system.sql
-- Extends cancelled_by to include 'system' for cron-auto-cancelled bookings.

ALTER TABLE `bookings`
    MODIFY COLUMN `cancelled_by` ENUM('user','admin','system') DEFAULT NULL;

INSERT IGNORE INTO schema_versions (version) VALUES ('015_bookings_cancelled_by_system');
