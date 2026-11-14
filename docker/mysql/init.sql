-- Separate schema for the test suite so tests never touch demo data.
CREATE DATABASE IF NOT EXISTS notifications_test;
GRANT ALL PRIVILEGES ON notifications_test.* TO 'notifications'@'%';
