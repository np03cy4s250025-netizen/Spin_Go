-- 004_add_schema_versions.sql
-- Migration tracking table

CREATE TABLE IF NOT EXISTS `schema_versions` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `version`      VARCHAR(100) NOT NULL,
    `applied_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record existing migrations as applied
INSERT IGNORE INTO `schema_versions` (`version`) VALUES
    ('001_add_soft_deletes'),
    ('002_add_booking_index'),
    ('003_add_login_attempts'),
    ('004_add_schema_versions');
