-- 001_add_soft_deletes.sql
-- Adds deleted_at to users and vehicles for soft-delete support
-- Uses DATETIME (not TIMESTAMP) for MySQL 5.7 strict-mode compatibility

ALTER TABLE `users`
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `created_at`;

ALTER TABLE `vehicles`
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `created_at`;
