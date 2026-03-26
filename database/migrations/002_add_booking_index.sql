-- 002_add_booking_index.sql
-- Composite index on bookings for availability checks (prevents full-table scan)

ALTER TABLE `bookings`
    ADD INDEX `idx_booking_availability` (`vehicle_id`, `pickup_date`, `dropoff_date`);
