-- backend/database/migrations/017_add_partial_refund_status.sql

ALTER TABLE bookings MODIFY COLUMN refund_status ENUM('none','full','no_refund','partial') NOT NULL DEFAULT 'none';

INSERT IGNORE INTO schema_versions (version) VALUES ('017_add_partial_refund_status');
