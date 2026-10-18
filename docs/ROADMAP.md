# ExpenseFlow – new UI roadmap

Design references: `docs/design/v6-personal-shared.html`, `docs/design/v7-landing-auth.html`.
Work is split into small parts. Each part ships on its own branch + PR, with its own SQL (idempotent) and README note.
Never push to `main` directly (a push to `main` auto-deploys).

## Architecture rules
- One feature = one module folder `modules/<name>/` with a `module.php` manifest (see `includes/modules.php`).
- Pages use `includes/layout.php` (`ef_page_start` / `ef_page_end`) and `assets/app.css`.
- A module owns its own tables/files and never edits another module's tables. Cross-module links go through small helper functions only.
- Every new table goes into `schema.sql` AND `database/part-N.sql` (copy-paste for InfinityFree phpMyAdmin).
- All writes are POST + CSRF. Prepared statements only.

## Status
| Part | Scope | Status |
|---|---|---|
| 1 | Foundation: app.css, layout, icons, module registry, design files, roadmap | done |
| 2 | Landing page + login/register/forgot/OTP/reset/delete-account UI, offline page | done (setup wizard moved to Part 4, invite page to Part 5; both need SQL) |
| 3a | Personal: Add Income/Expense in new UI (payment method, note, monthly repeat) + `database/part-3.sql` | done |
| 3b | Personal: new dashboard (period chips, charts, insights) + edit pages (payment method, note) + list/stop repeating items | done (no new SQL) |
| 3c | Personal: Full Report in new UI + CSV/Excel/PDF export | done (no new SQL) |
| 4 | Profile/Settings: savings goal, notifications, danger zone, delete account, first-run setup card | done (SQL: database/part-4.sql) |
| 5 | Shared 1: groups, members, invite link (join, leave, remove, rename, delete) | done (SQL: database/part-5.sql) |
| 6 | Shared 2: shared expense, 4 split types, add my share to Personal | done (SQL: database/part-6.sql) |
| 7 | Shared 3: balances, simplified debts, settle up | done (SQL: database/part-7.sql) |
| 8a | Activity feed + notifications bell (module `activity`) | done (SQL: database/part-8a.sql) |
| 8b | Comments on expenses + receipt upload | done (SQL: database/part-8b.sql) |
| 8c | Friends list (people you split with) | done (no SQL needed) |
| 9 | Full review, security pass, tests, final README | done (admin pages are restyled in Part 11) |
| 10 | Native apps: APK (Android), EXE (Windows), iOS (see below), GitHub Releases workflow | todo |
| 11 | Role-based admin panel (design: docs/design/v8-admin-panel-v3.html), split into 11a-11f | in progress |
| 11a | Roles (Super Admin, Admin, Support), permission table, admin shell and Overview | done (SQL: database/part-11a.sql) |
| 11b | Users: search, filters, profile, add, block/unblock, role change, reset link, delete, CSV export | todo (SQL) |
| 11c | Roles and permissions page, Audit log | todo |
| 11d | Support: help form for users and the staff inbox | todo (SQL) |
| 11e | Announcements, Categories, Currencies, Groups | todo (SQL) |
| 11f | Security (failed logins, blocked IPs), Backups, System settings | todo (SQL) |
| 12 | Website polish: animations and small details (design: docs/design/v8-complete-website.html) | todo |

## Native apps (plan)
- PWA already works (Install app / Add to Home Screen).
- Android `.apk`: Trusted Web Activity (Bubblewrap/PWABuilder) built by GitHub Actions, attached to a Release.
- Windows `.exe`: Electron (or PWABuilder) wrapper that loads the live site, built by GitHub Actions on a Windows runner.
- iOS: an `.ipa` needs a Mac + Apple Developer account (paid). Without that, iOS users use Safari > Share > Add to Home Screen (the PWA). An Xcode/Capacitor project can be added to the repo for later signing.

## Handoff notes for the next chat
Say: "ROADMAP.md padho aur agla part karo." Read this file, `README.md`, and the design file for that part only.

## Review notes (after Part 4)
- Fixed: sidebar and top bar now stay in place on PC while the page scrolls (`.fr` used `overflow:hidden`, which breaks `position:sticky`).
- Fixed: dashboard "Custom" range (date inputs were inside `.chips`, which hides inputs for the radio chips).
- Fixed: CSS/JS files get `?v=<file time>` so browsers load the new copy after each deploy.
- Fixed: dashboard info pills wrap on phones (the tip was cut off).
- Still old style (restyle in Part 9): admin pages and the delete-account page.

## Notes for Part 7

- Done in Part 7: leaving or removal is blocked while a balance is open. A deleted account still shows as "Former member" in old expenses.
- Expenses are stored per currency (minor units). Balances are calculated per currency.
- Run `php tests/shared_split_test.php` and `php tests/shared_balance_test.php` to check the split and balance maths. `php tests/shared_ledger_test.php` checks balances against a test database (never run it on the live database).

## Notes for Part 8c

- Friends is derived data: a friend is anyone who shares at least one group with you. It has **no table and no SQL**. It only reads Shared helpers (`shared_groups_of`, `shared_user_summary`) so it can be switched off in `modules/friends/module.php` without touching anything else.
- Pages: `friends/index.php` (list with search and balance per person), `friends/view.php?id=` (balance per currency, Settle buttons, shared groups, payments between you). Only people who share a group with you can be opened.
- Run `php tests/friends_test.php` against a test database.

## Notes for Part 9

- Security pass over all PHP files: login check, CSRF on every POST, escaped output, prepared statements (the few `query()` calls only use integer ids). No new vulnerability in the application code.
- Fixed: `tests/` was being deployed and its scripts create users, so it is now excluded from deployment and blocked by `tests/.htaccess`. Added `modules/.htaccess` and `storage/.htaccess` (deny all).
- Fixed: account deletion created module tables inside a transaction (CREATE TABLE commits it). `shared_ensure_all_schemas()` now runs before the transaction in `auth/delete-account.php` and `admin/delete-user.php`.
- New checks: `tests/security_http_test.py` (anonymous access, non-member access, CSRF; needs the local test server) and `tests/account_delete_test.php`.
- The admin panel keeps its current look until Part 11 (design: docs/design/v8-admin-panel-v3.html), so it is not restyled twice.
- On the live server: delete the old `tests` folder (the deploy does not remove files that were already uploaded).

## Notes for Part 11a

- Roles live in `users.role`: `super` (Super Admin, can do everything), `admin`, `support`, `user`. `users.is_admin` stays 1 for all staff, so older code keeps working. Everybody who was an admin becomes Super Admin when the column is added.
- `admin_perms(role, perm, allowed)` holds what Admin and Support may do; defaults are in `modules/admin/lib.php` (`ADMIN_DEFAULTS`). `SA` means Super Admin only.
- Every admin page starts with `admin/auth.php` (role looked up in the database on every request) and calls `admin_need('perm')`.
- The audit trail is `user_logs` rows whose action starts with `admin_` (use `admin_audit()`). No extra table.
- The side menu only shows pages that exist and that the role may use, so each later part just adds a page file.
- Old pages `edit-user.php` (Super Admin only), `delete-user.php` (`del` permission), `view-user.php` (`view`), `logs.php` (`log`) now check permissions; they get the new look in 11b/11c.
- Tests: `php tests/admin_roles_test.php`, `python3 tests/admin_http_test.py` (test database and local server only).
