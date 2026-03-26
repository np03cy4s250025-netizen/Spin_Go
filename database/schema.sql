-- database/schema.sql
-- SpinGo — Production-ready schema
-- ⚠️  SECURITY: Do NOT include admin seed data with plaintext passwords in production.
--               Run the first-run setup script (database/seed_admin.php) after deployment.

-- Create database
CREATE DATABASE IF NOT EXISTS `spingo_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spingo_db`;

-- Drop in dependency order (host tables first — they FK to core tables)
DROP TABLE IF EXISTS `host_vehicles`;
DROP TABLE IF EXISTS `deposits`;
DROP TABLE IF EXISTS `host_booking_actions`;
DROP TABLE IF EXISTS `hosts`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `users`;

-- ── 1. Users ──────────────────────────────────────────────────────────────────
CREATE TABLE `users` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `full_name`     varchar(255)  NOT NULL,
  `email`         varchar(255)  NOT NULL,
  `password_hash` varchar(255)  NOT NULL,
  `phone`         varchar(30)   DEFAULT NULL,
  `license_number` varchar(60)  DEFAULT NULL,
  `role`          enum('user','admin','host') DEFAULT 'user',
  `is_verified`   tinyint(1)    DEFAULT 0,
  `otp_code`      varchar(255)   DEFAULT NULL,
  `otp_expiry`    datetime      DEFAULT NULL,
  `created_at`    timestamp     DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`    datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Vehicles ────────────────────────────────────────────────────────────────
CREATE TABLE `vehicles` (
  `id`           int(11)         NOT NULL AUTO_INCREMENT,
  `name`         varchar(255)    NOT NULL,
  `model`        varchar(255)    DEFAULT NULL,
  `year`         int(11)         DEFAULT NULL,
  `type`         enum('car','bike','suv','sports','electric','motorbike') NOT NULL,
  `price`        decimal(10,2)   NOT NULL,
  `fuel`         varchar(50)     NOT NULL,
  `seats`        int(11)         NOT NULL,
  `city`         varchar(100)    NOT NULL,
  `image`        varchar(500)    DEFAULT NULL,
  `availability` tinyint(1)      NOT NULL DEFAULT 1,
  `created_at`   timestamp       DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`   datetime        DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Bookings ────────────────────────────────────────────────────────────────
CREATE TABLE `bookings` (
  `id`             int(11)         NOT NULL AUTO_INCREMENT,
  `user_id`        int(11)         NOT NULL,
  `vehicle_id`     int(11)         NOT NULL,
  `source`         enum('admin','host') NOT NULL DEFAULT 'admin',
  `pickup_date`    date            NOT NULL,
  `dropoff_date`   date            NOT NULL,
  `total_price`    decimal(10,2)   NOT NULL,
  `license_file`   varchar(255)    DEFAULT NULL,
  `license_back`   varchar(255)    DEFAULT NULL,
  `license_verified` tinyint(1)    DEFAULT 0,
  `license_verified_at` datetime   DEFAULT NULL,
  `license_verified_by` int(11)    DEFAULT NULL,
  `status`         enum('pending','confirmed','cancelled','completed') DEFAULT 'pending',
  `payment_status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(50)     DEFAULT NULL,
  `transaction_id` varchar(100)    DEFAULT NULL,
  `amount_paid`    decimal(10,2)   DEFAULT 0.00,
  `refund_status`  enum('none','full','partial','no_refund') DEFAULT 'none',
  `refund_amount`  decimal(10,2)   DEFAULT 0.00,
  `cancelled_by`   enum('user','admin','host','system') DEFAULT NULL,
  `cancelled_at`   datetime        DEFAULT NULL,
  `created_at`     timestamp       DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_vehicle` (`vehicle_id`),
  KEY `idx_booking_availability` (`vehicle_id`, `pickup_date`, `dropoff_date`),
  CONSTRAINT `fk_booking_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_booking_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Payments (Phase 2 placeholder) ─────────────────────────────────────────
CREATE TABLE `payments` (
  `id`             int(11)         NOT NULL AUTO_INCREMENT,
  `booking_id`     int(11)         NOT NULL,
  `user_id`        int(11)         NOT NULL,
  `amount`         decimal(10,2)   NOT NULL,
  `payment_method` enum('credit_card','paypal','mock') DEFAULT 'mock',
  `transaction_id` varchar(100)    DEFAULT NULL,
  `status`         enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `created_at`     timestamp       DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Login Attempts (DB-backed rate limiting) ───────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(255) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `attempted_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email` (`email`),
  KEY `idx_login_attempts_ip`    (`ip_address`),
  KEY `idx_login_attempts_time`  (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Schema Versions (migration tracking) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `schema_versions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(100) NOT NULL,
  `applied_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Hosts (host applications) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hosts` (
  `user_id`      INT(11)       NOT NULL,
  `phone`        VARCHAR(20)   NOT NULL,
  `gov_id_path`  VARCHAR(255)  DEFAULT NULL,
  `license_path` VARCHAR(255)  DEFAULT NULL,
  `license_file` VARCHAR(255)  DEFAULT NULL,
  `description`  TEXT          DEFAULT NULL,
  `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`  INT(11)       DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_hosts_user`        FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hosts_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Host Booking Actions ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `host_booking_actions` (
  `id`         INT(11)  NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11)  NOT NULL,
  `host_id`    INT(11)  NOT NULL,
  `status`     ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_hba_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hba_host`    FOREIGN KEY (`host_id`)    REFERENCES `hosts`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Deposits ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `deposits` (
  `id`         INT(11)        NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11)        NOT NULL UNIQUE,
  `amount`     DECIMAL(10,2)  NOT NULL,
  `status`     ENUM('held','refunded','deducted') NOT NULL DEFAULT 'held',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_deposit_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Host Vehicles ─────────────────────────────────────────────────────────
-- Note: includes all columns from migrations 007, 008, 009 (type/fuel/seats/city/image)
CREATE TABLE IF NOT EXISTS `host_vehicles` (
  `id`            INT(11)        NOT NULL AUTO_INCREMENT,
  `host_id`       INT(11)        NOT NULL,
  `name`          VARCHAR(255)   NOT NULL,
  `type`          ENUM('car','bike','suv','sports','electric','motorbike') NOT NULL DEFAULT 'car',
  `fuel`          VARCHAR(50)    NOT NULL DEFAULT 'Petrol',
  `seats`         INT            NOT NULL DEFAULT 4,
  `city`          VARCHAR(100)   NOT NULL DEFAULT 'Kathmandu',
  `image`         VARCHAR(500)   DEFAULT NULL,
  `model`         VARCHAR(255)   NOT NULL,
  `year`          SMALLINT       NOT NULL,
  `price_per_day` DECIMAL(10,2)  NOT NULL,
  `availability`  TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_hv_host` FOREIGN KEY (`host_id`) REFERENCES `hosts`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. Notifications (real-time SSE alerts for admin, host, user) ─────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `recipient_id`   INT(11)       DEFAULT NULL,
  `recipient_role` ENUM('user','host','admin') NOT NULL DEFAULT 'admin',
  `type`           VARCHAR(50)   NOT NULL,
  `title`          VARCHAR(255)  NOT NULL,
  `message`        TEXT          NOT NULL,
  `link`           VARCHAR(255)  DEFAULT NULL,
  `is_read`        TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_recipient` (`recipient_id`, `recipient_role`),
  KEY `idx_notif_unread`    (`recipient_role`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark all migrations as applied on fresh install
-- (schema.sql already includes all columns — migrations are skipped on fresh import)
INSERT IGNORE INTO `schema_versions` (`version`) VALUES
  ('001_add_soft_deletes'),
  ('002_add_booking_index'),
  ('003_add_login_attempts'),
  ('004_add_schema_versions'),
  ('005_nepali_localization'),
  ('006_add_license_upload'),
  ('007_host_vehicles_full'),
  ('008_unify_vehicle_schema'),
  ('009_expand_vehicle_types'),
  ('010_payment_refund_columns'),
  ('011_license_verification'),
  ('012_cancellation_refund_logic'),
  ('013_payment_columns'),
  ('014_user_phone_unique'),
  ('015_bookings_cancelled_by_system'),
  ('016_add_source_to_bookings'),
  ('017_notifications_table');

-- ── Sample Vehicle Data ────────────────────────────────────────────────────────
INSERT INTO `vehicles` (`name`, `type`, `price`, `fuel`, `seats`, `city`, `image`, `availability`) VALUES
('Tesla Model 3',         'car',  85.00, 'Electric', 5, 'Kathmandu', 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=600&h=400&fit=crop', 1),
('Yamaha R15',            'bike', 25.00, 'Petrol',   2, 'Pokhara',   'https://images.unsplash.com/photo-1558618047-f4e90c2c2b11?w=600&h=400&fit=crop', 1),
('Honda Civic',           'car',  45.00, 'Petrol',   5, 'Lalitpur',      'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=600&h=400&fit=crop', 1),
('Royal Enfield Classic', 'bike', 35.00, 'Petrol',   2, 'Bhaktapur',       'https://images.unsplash.com/photo-1558981806-ec527fa84c39?w=600&h=400&fit=crop', 1);

-- ── ⚠️  NO ADMIN SEED DATA HERE ───────────────────────────────────────────────
-- Admin account must be created via: database/seed_admin.php (first-run setup script)
-- This prevents hardcoded credentials from being committed to version control.
-- Run: php database/seed_admin.php --email=admin@yourdomain.com --password=YourSecurePass
