-- 003_add_login_attempts.sql
-- Database-backed rate limiting for login (replaces session-based approach)

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45)  NOT NULL,
    `attempted_at` TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_email` (`email`),
    KEY `idx_login_attempts_ip`    (`ip_address`),
    KEY `idx_login_attempts_time`  (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
