-- ================================================================
--  SpinGo — Host Features Schema
--  Run ONCE after schema.sql to add host marketplace tables.
-- ================================================================

-- Host applications submitted by users
CREATE TABLE IF NOT EXISTS hosts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL UNIQUE,
    phone        VARCHAR(20)  NOT NULL,
    gov_id_path  VARCHAR(255) DEFAULT NULL,
    license_path VARCHAR(255) DEFAULT NULL,
    description  TEXT         DEFAULT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by  INT          DEFAULT NULL,   -- admin user_id who acted
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Host-controlled booking approval actions (extends existing bookings)
CREATE TABLE IF NOT EXISTS host_booking_actions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    host_id    INT NOT NULL,
    status     ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (host_id)    REFERENCES hosts(id)    ON DELETE CASCADE
);

-- Refundable security deposits
CREATE TABLE IF NOT EXISTS deposits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT            NOT NULL UNIQUE,
    amount     DECIMAL(10,2)  NOT NULL,
    status     ENUM('held','refunded','deducted') NOT NULL DEFAULT 'held',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- Vehicles listed by hosts (separate from admin fleet)
CREATE TABLE IF NOT EXISTS host_vehicles (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    host_id       INT            NOT NULL,
    name          VARCHAR(255)   NOT NULL,
    model         VARCHAR(255)   NOT NULL,
    year          SMALLINT       NOT NULL,
    price_per_day DECIMAL(10,2)  NOT NULL,
    availability  TINYINT(1)     NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
);
