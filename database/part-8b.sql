-- ExpenseFlow Part 8b: comments on shared expenses + receipt photos.
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `comments_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`   INT UNSIGNED NOT NULL,
  `expense_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `body`       VARCHAR(500) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_expense` (`expense_id`, `id`),
  KEY `idx_comments_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipts_files` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`   INT UNSIGNED NOT NULL,
  `expense_id` INT UNSIGNED NOT NULL,
  `stored`     CHAR(32)     NOT NULL,
  `mime`       VARCHAR(20)  NOT NULL,
  `size_bytes` INT UNSIGNED  NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipts_expense` (`expense_id`),
  KEY `idx_receipts_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
