-- ExpenseFlow Part 5: shared groups (groups, members, invite link).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `shared_groups` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(60)  NOT NULL,
  `icon`        VARCHAR(12)  NOT NULL DEFAULT 'users',
  `owner_id`    INT UNSIGNED NOT NULL,
  `invite_code` CHAR(32)     NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shared_groups_code` (`invite_code`),
  KEY `idx_shared_groups_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shared_members` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`  INT UNSIGNED NOT NULL,
  `user_id`   INT UNSIGNED NOT NULL,
  `role`      VARCHAR(10)  NOT NULL DEFAULT 'member',
  `joined_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shared_members` (`group_id`, `user_id`),
  KEY `idx_shared_members_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
