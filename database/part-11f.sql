-- ExpenseFlow Part 11f: Security (events, IP blocks) and System settings.
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).
-- The site also creates these tables by itself on first use; running this just does it up front.

CREATE TABLE IF NOT EXISTS `security_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`       VARCHAR(14)  NOT NULL,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `who`        VARCHAR(60)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sec_time` (`created_at`),
  KEY `idx_sec_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_blocks` (
  `ip`         VARCHAR(45)  NOT NULL,
  `reason`     VARCHAR(100) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  `expires_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `k`          VARCHAR(30)  NOT NULL,
  `v`          VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
