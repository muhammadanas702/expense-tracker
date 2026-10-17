-- ExpenseFlow Part 6: shared expenses (who paid, how it is split).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- Amounts are stored in the smallest unit (paisa/cents) so splits add up exactly.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `shared_expenses` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`            INT UNSIGNED NOT NULL,
  `title`               VARCHAR(100) NOT NULL,
  `amount_minor`        BIGINT UNSIGNED NOT NULL,
  `currency`            VARCHAR(3)   NOT NULL DEFAULT 'PKR',
  `paid_by`             INT UNSIGNED NOT NULL,
  `created_by`          INT UNSIGNED NOT NULL,
  `split_type`          VARCHAR(8)   NOT NULL DEFAULT 'equal',
  `expense_date`        DATE         NOT NULL,
  `note`                VARCHAR(255) NULL DEFAULT NULL,
  `personal_expense_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shared_expenses_group` (`group_id`, `expense_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shared_splits` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `share_minor` BIGINT UNSIGNED NOT NULL,
  `weight`      DECIMAL(12,2) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shared_splits` (`expense_id`, `user_id`),
  KEY `idx_shared_splits_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
