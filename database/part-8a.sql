-- ExpenseFlow Part 8a: Activity feed + Notifications.
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `activity_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`     INT UNSIGNED NOT NULL,
  `actor_id`     INT UNSIGNED NOT NULL,
  `kind`         VARCHAR(20)  NOT NULL,
  `title`        VARCHAR(100) NULL DEFAULT NULL,
  `amount_minor` BIGINT UNSIGNED NULL DEFAULT NULL,
  `currency`     VARCHAR(3)   NULL DEFAULT NULL,
  `target_id`    INT UNSIGNED NULL DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_events_group` (`group_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `event_id`   INT UNSIGNED NOT NULL,
  `read_at`    TIMESTAMP    NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_notifications` (`user_id`, `event_id`),
  KEY `idx_activity_notifications_user` (`user_id`, `read_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
