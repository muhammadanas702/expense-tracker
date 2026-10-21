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
| 11 | Role-based admin panel (design: docs/design/v8-admin-panel-v3.html), split into 11a-11f | done |
| 11a | Roles (Super Admin, Admin, Support), permission table, admin shell and Overview | done (SQL: database/part-11a.sql) |
| 11b | Users: search, filters, sort, pages, profile, add, block/unblock, role change, temporary password, delete, CSV export | done (SQL: database/part-11b.sql) |
| 11c | Roles and permissions page, Audit log, User logs in the new look | done (no SQL) |
| 11d | Support: help form for users and the staff inbox | done (SQL: database/part-11d.sql) |
| 11e | Announcements, Categories, Currencies, Groups | done (SQL: database/part-11e.sql) |
| 11f | Security (failed logins, blocked IPs), Backups, System settings, scrollable admin menu | done (SQL: database/part-11f.sql) |
| 12 | Email confirmation at sign-up + website polish and animations (design: docs/design/v8-complete-website.html) | done (no SQL) |

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

## Notes for Part 11b

- `users.blocked` (added by the site itself or by `database/part-11b.sql`): a blocked user cannot log in and a live session is signed out on its next request (check in `config/db.php`).
- Who may manage whom (`admin_can_manage`): nobody on themselves; Super Admin on anybody; Admin and Support only on plain users. Roles and name/email edits are Super Admin only. The last Super Admin cannot be demoted or deleted.
- Password "reset" sets a random temporary password shown once to the staff member (the e-mail code flow needs the user's own session). Shown once through the session; it is never stored in clear text.
- The profile page no longer lists a user's income and expense rows (privacy). It shows counts, last login and recent activity only.
- `admin/edit-user.php` now just redirects to the profile page. CSV export neutralises spreadsheet formulas and is written to the audit log.
- Tests: `python3 tests/admin_users_http_test.py` (block, sign-out, temporary password, roles, add user, CSV, filters).

## Notes for Part 12

- **Email confirmation** (`modules/signup/`, `auth/register.php`, `auth/verify-email.php`): sign-up no longer creates the account straight away. The form is checked (plausible address, not a throw-away service like mailinator, common typos such as gmial.com get a "Did you mean...?", the domain must be able to receive mail), then a 6-digit code is emailed. The account is created only after the code is typed (valid 10 minutes, 5 wrong tries, resend after 60 seconds, 4 emails per sign-up, 5 emails per address and 15 per connection per hour). The pending sign-up lives only in the visitor's session; nothing is stored in the database until the code is right. If the email cannot be sent, nothing is created and the person is told. `config/mail.php` now has `ef_send_mail()` used by both the reset code and the confirmation code. Needs working SMTP settings (the same ones as password reset).
- **Polish** (`assets/app.css` Part 12 block, `assets/fx.js`): drifting background, hero entrance, floating cards, drawn sparkline, count-up on headline numbers (`data-countup`), scroll reveal (`.rv`, becomes `.seen`; hidden only when JavaScript runs), sticky blurred navigation with shadow, nav underline, smooth anchors, button and card hover/press, smooth FAQ, auth card entrance and shake on errors, "saved" messages fade away, visible keyboard focus. Everything switches off with "reduce motion". The landing page now describes Shared (it used to say "coming soon").
- Tests: `python3 tests/signup_verify_http_test.py` (needs `pip install aiosmtpd`; it starts a local mail catcher and temporarily writes `config/mail.local.php`).

## Notes for Part 11f

- Admin sidebar: the logo and "Back to app / Logout" stay fixed, only the menu in between scrolls (the current page is scrolled into view). On phones the bottom bar shows three pages plus **More** (`admin/menu.php`, lists every page the role may use), so adding pages never overflows the bar.
- `modules/security/` + `admin/security.php` (view: permission `log`, block/unblock: permission `block`): failed sign-ins, lock-outs, refused addresses (kept 30 days, e-mails masked like s***@example.com), top addresses and IP blocks (1 hour to until removed, max 200, you cannot block your own address). A block only refuses sign-in, sign-up and reset e-mails from that address (`security_ip_blocked()` in `auth/login.php`, `register.php`, `send-otp.php`); everything fails open. Tables `security_events`, `ip_blocks`.
- `modules/backup/` + `admin/backups.php` (permission `backup`, default off for Admin and Support): plain SQL export written in PHP (no mysqldump needed), gzip when available, saved in `storage/backups` (closed to the web), newest 10 kept, one per 30 seconds, download only through a POST with CSRF and a strict file-name check, all audited. Contains every account and password hash, so give the permission only to people you trust. Receipt pictures are not included. Restore = import the file in phpMyAdmin (tested: the dump imports cleanly and the data matches).
- `modules/settings/` + `admin/settings.php` (permission `set`): **sign-ups open/closed** (checked in `auth/register.php`) and **maintenance mode** (checked in `config/db.php`: everyone except signed-in staff gets a 503 "be right back" page with your message; sign-in and password-reset pages stay open). Table `app_settings`.
- Audit log has a **System** group (blocks, backups, settings).
- Tests: `python3 tests/admin_system_http_test.py`.

## Notes for Part 11e

- Four small modules, each with its own tables and its own admin page + action file (all POST + CSRF, audited, group "Content" in the Audit log):
  - `modules/announce/` + `admin/announcements.php` (perm `bc`): banner for signed-in users with style, optional start/end time, Active switch; users close it for themselves (`announce/dismiss.php`). Tables `announcements`, `announcement_dismissed`.
  - `modules/categories/` + `admin/categories.php` (perm `cat`): add, order, turn on/off, delete. Expenses store the category name, so old expenses never change; "Other" always stays. Add-expense pages use `categories_active()` and fall back to the built-in list on any problem. Table `app_categories` (seeded with the 8 defaults).
  - `modules/currencies/` + `admin/currencies.php` (perm `cur`): hide currencies from the pick lists (PKR and USD always stay) and see the rates and how many entries use each. This only hides them for new entries; validation still accepts every supported currency so old entries stay editable. Table `app_currency_hidden`.
  - `modules/groupsadmin/` + `admin/groups.php` (perm `grp`): search, sort and delete shared groups. Staff see counts only (never expenses or balances). Delete uses `shared_delete_group()` so every module cleans up.
- Account delete also removes a person's closed-announcement marks (`announce_on_user_deleted`).
- Phones: tables with the `wide` class turn each row into a small card (`assets/admin.css`).
- Tests: `python3 tests/admin_content_http_test.py`.

## Notes for Part 11d

- Module `modules/support/` (own tables `support_tickets`, `support_messages`, self-created by `support_ensure_schema()`; no foreign keys). Users: `support/index.php` (new request + own list), `support/ticket.php`, `support/action.php` (create, reply, mark solved). Staff: `admin/support.php` (inbox, search, status chips), `admin/ticket.php`, `admin/ticket-action.php` (reply, close, reopen), permission `support`.
- Users only ever see their own requests; staff replies show as "ExpenseFlow support" (staff name hidden from the user). Limits: 5 open requests per user, 100 messages per request, rate limited. Staff actions are in the Audit log (group Support). Requests are deleted with the account.
- Tests: `python3 tests/support_http_test.py`.

## Notes for Part 11c

- `admin/roles.php` (Super Admin only): one form with a tick box per permission for Admin and Support. Only changed switches are saved, and each change is written to the audit log ("Admin: Delete users on"). Saving without a change writes nothing.
- `admin/audit.php` (permission `log`): the staff audit trail = `user_logs` rows whose action starts with `admin_`, with search, date range and groups (Users, Roles, Other). New admin features only need `admin_audit($conn, $actorId, 'kind', 'text')`; add a label in `admin_audit_kinds()` to get a nice name and group.
- `admin/logs.php` (permission `log`) is the old "User logs" page in the new look; it shows what normal users did (login, entries...), the audit log shows what staff did.
- Tests: `python3 tests/admin_audit_http_test.py`.

### Notes for Part 12c (fixes before the final step)
- OTP / confirmation emails redesigned (branded HTML, logo, 6 digit boxes, code in subject, auto-generated headers).
- Sender address: with Gmail SMTP the From is always the logged-in Gmail. Use a dedicated Gmail account for ExpenseFlow. Optional secrets: `EXPENSEFLOW_MAIL_FROM_NAME` (display name) and `EXPENSEFLOW_MAIL_REPLY_TO`. A real noreply@ address needs a custom domain + mail provider.
- Notifications, Profile and Help pages are "neutral": no Personal/Shared switch; the bell opens Notifications (highlighted) and each row links to its group.
- Mobile fix: grid children `min-width:0` (admin roles page overflow).

### Notes for Part 12d
- The bell now opens a pop-up panel (latest 8, "See all" link) instead of a page; fetches `activity/notifications.php?popup=1` (marks read, removes badge). Closes on outside click or Esc. Without JS the bell still links to the full page. Script: `assets/notif-pop.js`, CSS class `.npop`.

### Notes for Part 12e (speed)
- Schema self-checks run once per deploy (marker files in `storage/schema`, `ef_schema_ok`) instead of on every request.
- `.htaccess`: gzip + long browser cache for CSS/JS/images (URLs carry `?v=`); service worker serves versioned CSS/JS from cache (cache v3).
- Phones: no backdrop blur / endless animations; shorter page fade.
- Notification list is prefetched quietly (`peek=1`, does not mark read) so the bell pop-up opens instantly.
- Chart.js loads deferred; charts draw on window load.

### Notes for Part 10a (Android app + GitHub Releases)
- `apps/android`: small WebView shell for https://expenseflow.rf.gd/ (keeps login, file upload, PDF downloads, Back button, offline screen, external links open in the browser). Not deployed to the website (`apps/**` excluded in deploy.yml).
- `.github/workflows/release.yml`: push a tag like `v1.0.0` (or Actions → "Build apps & publish release" → Run workflow) → builds `ExpenseFlow.apk` and publishes it on the Releases page. Landing footer links to "Get the app".
- The APK is signed with `apps/android/sideload.jks` (committed on purpose, password in build.gradle) so every release installs as an update. Fine for sideloading; for Google Play use your own private key instead.
- Not built locally (no Android SDK offline); the first CI run is the real test.
- Still to do: 10b Windows EXE, 10c iOS (needs Mac + Apple account; otherwise PWA "Add to Home Screen").

### Notes for Part 10a-2 (offline + login start)
- The Android app opens on `auth/login.php`; a signed-in visitor is redirected straight to the dashboard (login form only shows when signed out; `?stay=1` forces it).
- Offline: the service worker keeps every signed-in page you open (cache `expenseflow-pages-v1`) and shows the saved copy when there is no connection; opening the login page offline shows the saved dashboard. A banner says changes can't be saved offline. Saved pages are wiped on logout and whenever the login form is served. Admin and auth pages are never saved.
- After restoring a database from a backup, delete `storage/schema` on the server (the schema self-checks are remembered per deploy).

### Notes for Part 10a-3 (app look + real offline start)
- Fixed: the phone speed CSS had overridden the dark Net-balance (hero) card background, making its text unreadable, and had switched off animations. Now only the live blur is dropped on phones; colours and animations are as designed.
- Android app keeps its own copy of every page you open (private app storage, wiped on logout / login page) and shows it when there is no internet, also when the app is started offline. Charts need internet (Chart.js CDN).

### Notes for Part 13 (staff emails, support reply button)
- "Set temporary password" (and "Add user") now also emails the user the temporary password with a Sign-in button; the admin still sees it once. If SMTP fails the admin is told to hand it over.
- Support: the list has a Reply/View button per request (opens the reply box); a staff reply is emailed to the user with a link, and still shows in the user's Help page.
- Test: `tests/staff_mail_http_test.py` (needs aiosmtpd).

### Notes for Part 14 (setup page fix, app update prompt, support replies in the bell)
- Bug: "Finish your quick setup" linked to `modules/profile/setup.php`, which the server blocks (modules/ is deny-all). Now `setup.php` and `stop-recurring.php` live in the root (thin wrappers). A new account is sent to Quick setup once, before the dashboard (Skip works).
- Support replies: `support_tickets.answered_unseen`; counted in the bell badge, listed first in the notification pop-up/page, cleared when the conversation is opened.
- Android app: on start it asks GitHub for the latest release; if the tag is newer than the installed versionName it shows "Update available" with Download / Later (Later = asks again after 24 h). Needs a release with a higher tag (e.g. v1.0.3).
- Tests that logged in by opening the dashboard now use profile.php (a fresh account is redirected to setup first).
