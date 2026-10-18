-- Part 11a: admin roles and permissions (run once in phpMyAdmin, database "if0_..._expenseflow").
-- The site also adds these by itself on the next login, so running it is optional but safe.

-- 1) a role for every user: user, support, admin or super (Super Admin)
ALTER TABLE `users` ADD COLUMN `role` VARCHAR(10) NOT NULL DEFAULT 'user' AFTER `is_admin`;

-- 2) everybody who is an admin today becomes Super Admin (you can change this later in the panel)
UPDATE `users` SET `role` = 'super' WHERE `is_admin` = 1;

-- 3) what the Admin and Support roles are allowed to do (Super Admin can always do everything)
CREATE TABLE IF NOT EXISTS `admin_perms` (
  `role`    VARCHAR(10) NOT NULL,
  `perm`    VARCHAR(12) NOT NULL,
  `allowed` TINYINT(1)  NOT NULL DEFAULT 0,
  PRIMARY KEY (`role`, `perm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
