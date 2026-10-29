# Database

MySQL / MariaDB through PDO (utf8mb4, InnoDB). `schema.sql` is the source of truth for a **fresh install**; running code upgrades an **existing** database by itself (see [ARCHITECTURE §6](ARCHITECTURE.md#6-self-healing-database-schema)).

## 1. Conventions

- Table and column names are `snake_case`; every table has an `id` primary key unless noted.
- **Money**: personal tables use `DECIMAL(15,2)` plus a `currency` code; shared tables store **whole minor units** (`*_minor` BIGINT, paisa/cents) so splits always add up exactly.
- Personal entries also store `amount_pkr`: the PKR value at the moment of entry, so history does not change when rates move.
- Dates are `DATETIME`/`DATE` in the server timezone (`Asia/Karachi` by default, `EXPENSEFLOW_TIMEZONE` overrides).
- **Foreign keys** exist on the original tables (`income`, `expenses`, `user_monthly_currency`, `user_logs`). Newer tables intentionally have none (they caused `errno 150` on the live host); cleanup is done by `<module>_on_*_deleted()` hooks instead. Do not rely on `ON DELETE CASCADE` for new tables.
- Never run destructive SQL in an `ensure_schema` function.

## 2. Tables by area

### Accounts and personal money

| Table | Key columns | Notes |
| --- | --- | --- |
| `users` | `id`, `name`, `email`, `password` (hash), `is_admin`, `role` (`super/admin/support/user`), `blocked`, `created_at` | `is_admin = 1` for all staff; use `role` for decisions |
| `income` | `user_id`, `title`, `amount`, `currency`, `amount_pkr`, `transaction_date`, `payment_method`, `note` | |
| `expenses` | `user_id`, `title`, `amount`, `currency`, `amount_pkr`, `category` (name text), `transaction_date`, `payment_method`, `note` | Category is stored as text on purpose |
| `recurring_items` | `user_id`, `kind`, `title`, `amount`, `currency`, `category`, `day_of_month`, `next_run`, `active` | Monthly repeating items; processed when the user opens the dashboard |
| `user_monthly_currency` | `user_id`, `year_month`, `base_currency` | Base currency per user per month |
| `user_settings` | `user_id` (PK), `savings_goal`, `notifications`, `setup_done` | Profile preferences |

### Shared mode

| Table | Key columns | Notes |
| --- | --- | --- |
| `shared_groups` | `name`, `icon`, `owner_id`, `invite_code` | Max 30 groups per user, 50 members per group |
| `shared_members` | `group_id`, `user_id`, `role`, `joined_at` | |
| `shared_expenses` | `group_id`, `title`, `amount_minor`, `currency`, `paid_by`, `created_by`, `split_type`, `expense_date`, `note`, `personal_expense_id` | `split_type`: `equal`, `exact`, `percent`, `shares` |
| `shared_splits` | `expense_id`, `user_id`, `share_minor`, `weight` | Sum of `share_minor` equals `amount_minor` |
| `shared_settlements` | `group_id`, `from_user`, `to_user`, `amount_minor`, `currency`, `settled_on` | A payment between two members |
| `friend_links` | `requester_id`, `addressee_id`, `status`, `created_at` | Direct friendships added by e-mail (besides people met through groups) |
| `activity_events` | `group_id`, `actor_id`, `kind`, `title`, `amount_minor`, `currency`, `target_id` | Feed entries |
| `activity_notifications` | `user_id`, `event_id`, `read_at` | One row per recipient |
| `comments_items` | `group_id`, `expense_id`, `user_id`, `body` | |
| `receipts_files` | `group_id`, `expense_id`, `stored`, `mime`, `size_bytes`, `created_by` | File itself in `storage/receipts/` |

### Support, content and administration

| Table | Key columns | Notes |
| --- | --- | --- |
| `support_tickets` | `user_id`, `subject`, `category`, `status`, `updated_at` | `open` / `pending` / `closed` |
| `support_messages` | `ticket_id`, `user_id`, `is_staff`, `body` | |
| `announcements` | `title`, `body`, `level`, `active`, `starts_at`, `ends_at`, `created_by` | |
| `announcement_dismissed` | `user_id`, `announcement_id` | |
| `app_categories` | `name`, `icon`, `sort_order`, `active` | |
| `app_currency_hidden` | `code` | |
| `app_settings` | `k`, `v`, `updated_at` | Key/value (sign-ups open, maintenance mode, message) |
| `admin_perms` | `role`, `perm`, `allowed` | Permission matrix, see [MODULES](MODULES.md#4-admin-panel) |
| `security_events` | `kind`, `ip`, `who`, `created_at` | Failed logins, lock-outs |
| `ip_blocks` | `ip`, `reason`, `created_by`, `expires_at` | |
| `user_logs` | `user_id`, `action`, `details`, `ip_address`, `created_at` | User activity **and** the staff audit trail (`action` starts with `admin_`) |
| `rate_limit_hits` | `bucket`, `key_hash`, `created_at` | Sliding-window rate limits, see [SECURITY](SECURITY.md) |

Additional tables may be created by modules at run time; the list of `CREATE TABLE` statements lives in each module's `*_ensure_schema()`.

## 3. Relationships (simplified)

```
users 1─∞ income / expenses / recurring_items / user_monthly_currency / user_settings / user_logs
users ∞─∞ shared_groups   (through shared_members)
shared_groups 1─∞ shared_expenses 1─∞ shared_splits
shared_groups 1─∞ shared_settlements
shared_expenses 1─∞ comments_items, 0..1─1 receipts_files
shared_groups 1─∞ activity_events 1─∞ activity_notifications
users ∞─∞ users   (through friend_links)
users 1─∞ support_tickets 1─∞ support_messages
```

## 4. Privacy rules built into the data model

- Staff see **counts only** for shared groups, never expense details.
- Deleting an account runs every module's `on_user_deleted` hook (personal data, memberships, comments, notifications, friend links ...). Test: `tests/account_delete_test.php`.
- Passwords are hashes (`password_hash`). Backups contain every hash; the `backup` permission is for trusted staff only.
- Logs mask e-mail addresses in the Security page.

## 5. Upgrading and resetting

| Task | How |
| --- | --- |
| Fresh install | Import `schema.sql` into an empty database |
| Existing database | Deploy the code; each module creates/adds what it needs on first use |
| Manual SQL for a given part | Run `database/part-*.sql` in phpMyAdmin (safe to run twice) |
| Force all schema checks to run again | Delete the `storage/schema/` folder |
| Wipe a **test** database | Drop tables, then delete `storage/schema/` |
| Restore a backup | Import the `.sql` from `storage/backups` in phpMyAdmin (receipt pictures are not included) |
| Promote a user to Super Admin | `UPDATE users SET is_admin = 1, role = 'super' WHERE email = '...';` |
