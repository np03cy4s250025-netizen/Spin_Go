-- database/migrations/020_vehicles_city_fk.sql
-- Description: Align collations, add missing cities, and create city_id FKs on vehicles and host_vehicles.

-- 1. Align cities table collation to match vehicles/host_vehicles (utf8mb4_unicode_ci)
ALTER TABLE `cities` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Insert missing cities found in data
INSERT IGNORE INTO `cities` (`name`, `is_popular`) VALUES ('Chitwan', 1);

-- 3. Add city_id FK to vehicles table
ALTER TABLE `vehicles`
    ADD COLUMN `city_id` INT NULL AFTER `city`,
    ADD CONSTRAINT `fk_vehicles_city`
        FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL;

-- Backfill city_id from existing city name strings
UPDATE `vehicles` v
JOIN `cities` c ON c.name = v.city
SET v.city_id = c.id;

-- 4. Add city_id FK to host_vehicles table
ALTER TABLE `host_vehicles`
    ADD COLUMN `city_id` INT NULL AFTER `city`,
    ADD CONSTRAINT `fk_host_vehicles_city`
        FOREIGN KEY (`city_id`) REFERENCES `cities`(`id`) ON DELETE SET NULL;

-- Backfill host_vehicles
UPDATE `host_vehicles` hv
JOIN `cities` c ON c.name = hv.city
SET hv.city_id = c.id;
