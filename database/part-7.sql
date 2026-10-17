-- ExpenseFlow Part 7: settle up (payments between group members).
-- Run once in InfinityFree > phpMyAdmin > your database > SQL tab. Safe to run twice.
-- No foreign keys on purpose (they caused errno 150 on the live database before).

CREATE TABLE IF NOT EXISTS `shared_settlements` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`     INT UNSIGNED NOT NULL,
  `from_user`    INT UNSIGNED NOT NULL,
  `to_user`      INT UNSIGNED NOT NULL,
  `amount_minor` BIGINT UNSIGNED NOT NULL,
  `currency`     VARCHAR(3)   NOT NULL DEFAULT 'PKR',
  `settled_on`   DATE         NOT NULL,
  `note`         VARCHAR(255) NULL DEFAULT NULL,
  `created_by`   INT UNSIGNED NOT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shared_settlements_group` (`group_id`, `settled_on`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
