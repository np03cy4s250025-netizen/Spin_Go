-- 018_cleanup_and_last_login.sql

-- 6. Add last_login_at to users
ALTER TABLE users ADD COLUMN last_login_at DATETIME DEFAULT NULL;

-- 8. Cleanup payments and deposits (zero rows, unused)
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS deposits;


-- 10. Clarify intent for license_number (it duplicates bookings.license_file logic)
ALTER TABLE users DROP COLUMN IF EXISTS license_number;
