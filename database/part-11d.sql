-- ExpenseFlow Part 11d: Support (help requests from users + staff inbox).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `subject`    VARCHAR(100) NOT NULL,
  `category`   VARCHAR(12)  NOT NULL DEFAULT 'other',
  `status`     VARCHAR(8)   NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_user` (`user_id`, `id`),
  KEY `idx_support_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `is_staff`   TINYINT(1)   NOT NULL DEFAULT 0,
  `body`       VARCHAR(2000) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_msg_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
