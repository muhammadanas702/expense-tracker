-- ExpenseFlow Part 11e: Announcements, Categories, Currencies (staff pages).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).
-- The site also creates these tables by itself on first use; running this just does it up front.

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(80)  NOT NULL,
  `body`       VARCHAR(400) NOT NULL DEFAULT '',
  `level`      VARCHAR(5)   NOT NULL DEFAULT 'info',
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `starts_at`  DATETIME     NULL DEFAULT NULL,
  `ends_at`    DATETIME     NULL DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announce_active` (`active`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_dismissed` (
  `user_id`         INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(30)  NOT NULL,
  `icon`       VARCHAR(8)   NOT NULL DEFAULT 'tag',
  `sort_order` INT          NOT NULL DEFAULT 0,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_categories` (`name`, `icon`, `sort_order`, `active`) VALUES
  ('Food', 'food', 10, 1), ('Transport', 'car', 20, 1), ('Shopping', 'bag', 30, 1), ('Entertainment', 'tag', 40, 1),
  ('Bills', 'zap', 50, 1), ('Healthcare', 'heart', 60, 1), ('Education', 'book', 70, 1), ('Other', 'more', 80, 1);

CREATE TABLE IF NOT EXISTS `app_currency_hidden` (
  `code` CHAR(3) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
