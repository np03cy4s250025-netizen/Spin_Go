-- database/migrations/007_host_vehicles_full.sql
-- Adds type, fuel, seats, city, image columns to host_vehicles
-- to match the richness of the admin vehicles table.

ALTER TABLE host_vehicles
    ADD COLUMN type  ENUM('car','bike') NOT NULL DEFAULT 'car'  AFTER name,
    ADD COLUMN fuel  VARCHAR(50)        NOT NULL DEFAULT 'Petrol' AFTER type,
    ADD COLUMN seats INT                NOT NULL DEFAULT 4       AFTER fuel,
    ADD COLUMN city  VARCHAR(100)       NOT NULL DEFAULT 'Kathmandu' AFTER seats,
    ADD COLUMN image VARCHAR(500)       DEFAULT NULL             AFTER city;

INSERT IGNORE INTO schema_versions (version) VALUES ('007_host_vehicles_full');
