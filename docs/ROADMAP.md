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
| 8 | Shared 4: friends, activity feed, notifications, receipts, comments | todo (SQL) |
| 9 | Full review, security pass, tests, final README | todo |
| 10 | Native apps: APK (Android), EXE (Windows), iOS (see below), GitHub Releases workflow | todo |

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
