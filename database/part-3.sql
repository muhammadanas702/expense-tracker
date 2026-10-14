-- ExpenseFlow Part 3: payment method + note on transactions, and repeating (monthly) items.
-- Run this once in InfinityFree > phpMyAdmin > your database > SQL tab.
-- Safe to run twice.
-- run the four ALTER lines one by one and ignore the "Duplicate column" error.)

ALTER TABLE `income`   ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(20)  NULL DEFAULT NULL;
ALTER TABLE `income`   ADD COLUMN IF NOT EXISTS `note`           VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `expenses` ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(20)  NULL DEFAULT NULL;
ALTER TABLE `expenses` ADD COLUMN IF NOT EXISTS `note`           VARCHAR(255) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `recurring_items` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED  NOT NULL,
  `kind`           VARCHAR(7)    NOT NULL,                -- 'income' or 'expense'
  `title`          VARCHAR(100)  NOT NULL,
  `amount`         DECIMAL(15,2) NOT NULL,
  `currency`       VARCHAR(3)    NOT NULL DEFAULT 'PKR',
  `category`       VARCHAR(50)   NULL DEFAULT NULL,
  `payment_method` VARCHAR(20)   NULL DEFAULT NULL,
  `note`           VARCHAR(255)  NULL DEFAULT NULL,
  `day_of_month`   TINYINT UNSIGNED NOT NULL,
  `next_run`       DATE          NOT NULL,
  `active`         TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recurring_user` (`user_id`, `active`, `next_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
