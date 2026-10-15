-- ExpenseFlow database schema (MySQL 5.7+ / MariaDB 10.3+)
--
-- FRESH INSTALL
--   1. Create an empty database and a user for it.
--   2. Import this file (phpMyAdmin > Import, or: mysql -u USER -p DBNAME < schema.sql).
--   3. Put the connection details in config/db.local.php (see config/db.php).
--   4. Register an account in the app, then make it an admin once:
--        UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
--
-- EXISTING (OLDER) DATABASE
--   Do NOT import this file over live data. The app upgrades an older database by
--   itself on first use (includes/schema.php adds `rate_limit_hits`, `income.currency`
--   and `expenses.amount_pkr` when they are missing). Optional hardening you can run
--   by hand in phpMyAdmin once you have a backup:
--     ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);   -- fails if duplicate emails exist
--
-- Amounts are stored twice on purpose: the value the user typed (amount + currency) and
-- its PKR value at the time it was saved (amount_pkr), so totals do not drift when
-- exchange rates change.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `is_admin`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `income` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED  NOT NULL,
  `title`            VARCHAR(100)  NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `currency`         VARCHAR(3)    NOT NULL DEFAULT 'PKR',
  `amount_pkr`       DECIMAL(15,2) NOT NULL,
  `transaction_date` DATETIME      NOT NULL,
  `payment_method`   VARCHAR(20)   NULL DEFAULT NULL,
  `note`             VARCHAR(255)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_income_user_date` (`user_id`, `transaction_date`),
  CONSTRAINT `fk_income_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED  NOT NULL,
  `title`            VARCHAR(100)  NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `currency`         VARCHAR(3)    NOT NULL DEFAULT 'PKR',
  `amount_pkr`       DECIMAL(15,2) NULL DEFAULT NULL,
  `category`         VARCHAR(50)   NOT NULL,
  `transaction_date` DATETIME      NOT NULL,
  `payment_method`   VARCHAR(20)   NULL DEFAULT NULL,
  `note`             VARCHAR(255)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_user_date` (`user_id`, `transaction_date`),
  CONSTRAINT `fk_expenses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One base (display) currency per user per month, e.g. year_month = '2026-10'.
CREATE TABLE IF NOT EXISTS `user_monthly_currency` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `year_month`    CHAR(7)      NOT NULL,
  `base_currency` VARCHAR(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_month` (`user_id`, `year_month`),
  CONSTRAINT `fk_umc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity log shown in the admin panel. Entries are kept per user and removed with the user.
CREATE TABLE IF NOT EXISTS `user_logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `action`     VARCHAR(50)  NOT NULL,
  `details`    VARCHAR(500) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_created` (`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting for login, registration and password-reset attempts (hashed keys only).
CREATE TABLE IF NOT EXISTS `rate_limit_hits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`     VARCHAR(40)     NOT NULL,
  `key_hash`   CHAR(64)        NOT NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bucket_key_time` (`bucket`, `key_hash`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part 3: monthly repeating items
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

-- Part 4: per-user settings (savings goal, notifications, first-run setup)
CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id`       INT UNSIGNED NOT NULL,
  `savings_goal`  TINYINT UNSIGNED NOT NULL DEFAULT 20,
  `notifications` TINYINT(1)   NOT NULL DEFAULT 1,
  `setup_done`    TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
