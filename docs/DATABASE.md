# ExpenseFlow database

MySQL / MariaDB, `utf8mb4`, InnoDB, accessed only through PDO prepared statements. Full definitions are in [`/schema.sql`](../schema.sql); the same tables are also created by each module's `*_ensure_schema()` (see [`ARCHITECTURE.md`](ARCHITECTURE.md) section 6).

## 1. How the schema is managed

- **Fresh install:** import `schema.sql` into an empty database. (Or just open the site: the self-healing code creates what is missing.)
- **Existing database:** upgrades are automatic. Each module's `ensure_schema` adds missing tables/columns the first time its code runs after a deploy.
- **Add a table or column:** put it in (1) the module's `ensure_schema()`, (2) `schema.sql`, (3) this document. Never rely on running SQL by hand in production.
- **Markers:** `storage/schema/` holds one tiny file per ensure function. If you drop/recreate a database, **delete `storage/schema/`** or the code will think everything exists.
- `database/part-*.sql` are old manual upgrade scripts (up to Part 11f) kept for history only. Newer tables (for example `friend_links`) exist only in `ensure_schema` and `schema.sql`; do not create new `part-N.sql` files.
- Connection: `config/db.php` reads `config/db.local.php` (keys `local` / `production`) or `EXPENSEFLOW_DB_HOST/NAME/USERNAME/PASSWORD`. The tests use a database called `expenseflow_test`.

## 2. Tables by area

### Core (Personal)

| Table | Purpose | Key columns / notes |
| --- | --- | --- |
| `users` | Accounts | `id`, `name`, `email` (unique), `password` (bcrypt hash via `password_hash`), `is_admin` (legacy flag, kept in sync), `role` (`user`/`support`/`admin`/`super`), `blocked`, `created_at` |
| `income` | Income entries | `user_id`, `title`, `amount` + `currency`, `amount_pkr` (value at entry time), `transaction_date`, `payment_method`, `note` |
| `expenses` | Expense entries | same plus `category` (a name from `app_categories`) |
| `user_monthly_currency` | Display currency per user per month | `year_month` (`2026-10`), `base_currency`; unique per user+month |
| `recurring_items` | Monthly repeating income/expenses | `kind`, `day_of_month`, `next_run`, `active`. Items that became due are created when the dashboard opens |
| `user_settings` | Savings goal %, notification switch, first-run flag | one row per user |
| `user_logs` | What users did (login, add, edit, delete, reset...) | `action`, `details`, `ip_address`, `created_at`; shown in admin "User logs" |
| `rate_limit_hits` | Rate-limiter hits | `bucket`, `key_hash` (SHA-256 of the key), `created_at`; rows older than 1 day are pruned automatically |

**Money rules (Personal):** `amount` stays in the currency the user typed. `amount_pkr` is saved when the row is created, so past totals never change when exchange rates move. Reports show everything in the chosen base currency using the stored conversion.

### Shared (groups and balances)

| Table | Purpose | Notes |
| --- | --- | --- |
| `shared_groups` | A group (trip, rent, family) | `owner_id`, `invite_code` (32 chars, unique), `icon` |
| `shared_members` | Membership | unique (`group_id`,`user_id`), `role` `owner`/`member` (owner can edit/delete any expense in the group) |
| `shared_expenses` | A bill added to a group | `amount_minor` (integer paisa/cents), `currency`, `paid_by`, `created_by`, `split_type` (`equal`/`exact`/`percent`/`shares`), `expense_date`, `personal_expense_id` (link to the member's own Personal copy) |
| `shared_splits` | Each person's share of an expense | `share_minor`, `weight`; shares always add up exactly to the total |
| `shared_settlements` | "X paid Y" payments | `from_user`, `to_user`, `amount_minor`, `currency`, `settled_on` |
| `user_identities` | Links a login provider to an account | `user_id`, `provider` (`google`), `subject` (Google's stable id), `email`; unique (`provider`,`subject`); removed by `oauth_on_user_deleted()` (no foreign key) |
| `friend_links` | Direct friend requests by e-mail | `requester_id`, `addressee_id`, `status` `pending`/`accepted`; unique pair. Friends are also *derived* from shared groups |

**Money rules (Shared):** all amounts are **integers in minor units** (`amount_minor`). Never use floats. Balances are calculated per person and per currency from expenses, splits and settlements (`shared_group_pairs`, `shared_group_nets`); only two people who owe each other cancel out.

### Shared add-ons

| Table | Purpose |
| --- | --- |
| `activity_events` | Feed of what happened in a group (`kind`: `expense_add`, `expense_edit`, `expense_delete`, `group_create`, `group_rename`, `member_join`, `member_leave`, `member_remove`, `settle`, `settle_delete`) |
| `activity_notifications` | Who must be told about which event; `read_at` marks it read (bell count) |
| `comments_items` | Comments under a shared expense (max 500 chars) |
| `receipts_files` | One receipt photo per expense: `stored` random file name inside `storage/receipts/`, `mime`, `size_bytes` |

### Admin and system

| Table | Purpose |
| --- | --- |
| `admin_perms` | The permission matrix: (`role`,`perm`) -> `allowed`. Keys are `view add block reset del export support bc cat cur grp log backup set` |
| `support_tickets`, `support_messages` | Help requests and the conversation (`is_staff` marks staff replies) |
| `announcements`, `announcement_dismissed` | Banners with optional start/end; who closed which |
| `app_categories` | Expense category list (name, icon key, sort order, active). Seeded with Food, Transport, Shopping, Entertainment, Bills, Healthcare, Education, Other |
| `app_currency_hidden` | Currency codes staff hid from pick lists (e.g. if KRW is missing in a dropdown, check Admin > Currencies) |
| `security_events` | Failed logins and lock-outs (e-mails are masked when shown) |
| `ip_blocks` | Blocked IPs with optional expiry |
| `app_settings` | Key/value: `maintenance` (0/1), `maintenance_msg`, `signups` (1 = open) |

**Audit log:** there is no separate table. Staff actions are rows in `user_logs` whose `action` starts with `admin_` (written by `admin_audit()`), shown in Admin > Audit log; normal user rows are shown in Admin > User logs.

## 3. Relationships (simplified)

```
users 1─* income, expenses, recurring_items, user_logs, user_monthly_currency      (ON DELETE CASCADE)
users 1─1 user_settings
users *─* shared_groups  via shared_members
shared_groups 1─* shared_expenses 1─* shared_splits
shared_groups 1─* shared_settlements, activity_events, comments_items, receipts_files
shared_expenses 0..1─1 expenses   (personal_expense_id: the member's own copy of their share)
users 1─* user_identities (Google sign-in)
users *─* users via friend_links
support_tickets 1─* support_messages
```

Deleting an account (`auth/delete-account.php`, admin delete) removes Personal rows by cascade and calls `shared_on_user_deleted()`, which in turn calls the `friends_`, `activity_` and `support_on_user_deleted` hooks when those modules exist (debts written off, friend links, notifications and tickets removed).

## 4. Sign-up is not in the database until verified

`modules/signup` keeps the name, e-mail, password **hash** and 6-digit code in the **PHP session** (`signup_pending_*`). A `users` row is created only after the correct code is entered, so typos and throw-away addresses never create accounts. If an e-mail already exists the form behaves the same (it sends an "account exists" mail instead) so the site never reveals which addresses are registered.

## 5. Backups and restore

- Admin > Backups (permission `backup`) writes `storage/backups/backup-YYYYMMDD-HHMMSS-<hex>.sql(.gz)`, keeps the newest 10, and only streams them through `admin/backup-action.php` after a permission check. They contain every account and password hash: treat as secret.
- Restore: import the `.sql` in phpMyAdmin. Receipt pictures (`storage/receipts/`) are **not** included; copy that folder separately.
- InfinityFree does not take automatic backups for you: download one regularly.

## 6. Common queries

```sql
-- make someone Super Admin
UPDATE users SET role='super', is_admin=1 WHERE email='you@example.com';

-- unlock a blocked user
UPDATE users SET blocked=0 WHERE email='x@example.com';

-- who owes whom in a group (minor units): use the app, the SQL is in shared_group_pairs()
```
