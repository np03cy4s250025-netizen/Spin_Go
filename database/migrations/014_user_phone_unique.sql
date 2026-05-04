-- database/migrations/014_user_phone_unique.sql
-- Add unique constraint to users.phone to prevent duplicate registrations with same number.

ALTER TABLE users ADD UNIQUE INDEX uq_phone (phone);

-- Log migration
INSERT IGNORE INTO schema_versions (version) VALUES ('014_user_phone_unique');
