-- database/migrations/005_nepali_localization.sql

CREATE TABLE IF NOT EXISTS cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_popular TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed Nepali Cities
INSERT IGNORE INTO cities (name, is_popular) VALUES 
('Kathmandu', 1),
('Pokhara', 1),
('Lalitpur', 1),
('Bhaktapur', 1),
('Biratnagar', 0),
('Birgunj', 0),
('Dharan', 0),
('Butwal', 0),
('Hetauda', 0),
('Nepalgunj', 0);

-- Update vehicles to use random Nepali cities (from the seeded list)
-- For existing records, we will set them to Kathmandu/Pokhara
UPDATE vehicles SET city = 'Kathmandu' WHERE city IS NULL OR city = '';
UPDATE vehicles SET city = 'Pokhara' WHERE id % 2 = 0;
UPDATE vehicles SET city = 'Lalitpur' WHERE id % 3 = 0;
UPDATE vehicles SET city = 'Bhaktapur' WHERE id % 5 = 0;
