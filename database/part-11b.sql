-- Part 11b: block / unblock users (run once in phpMyAdmin).
-- The site also adds this column by itself on the next visit, so running it is optional but safe.
ALTER TABLE `users` ADD COLUMN `blocked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;
