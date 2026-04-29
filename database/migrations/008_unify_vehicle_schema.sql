-- database/migrations/008_unify_vehicle_schema.sql
-- Simplified version for PDO compatibility.

ALTER TABLE `vehicles` 
    ADD COLUMN IF NOT EXISTS `model` VARCHAR(255) DEFAULT NULL AFTER `name`;

ALTER TABLE `vehicles` 
    ADD COLUMN IF NOT EXISTS `year` INT DEFAULT NULL AFTER `model`;
