-- ExpenseFlow Part 4: user settings (savings goal, notifications, first-run setup).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- (No foreign key on purpose: it caused errno 150 on the live database before.)

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id`           INT UNSIGNED NOT NULL,
  `savings_goal`      TINYINT UNSIGNED NOT NULL DEFAULT 20,   -- percent of income, 0-100
  `notifications`     TINYINT(1)   NOT NULL DEFAULT 1,
  `setup_done`        TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
