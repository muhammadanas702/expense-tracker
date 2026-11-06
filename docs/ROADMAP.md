# ExpenseFlow – new UI roadmap

Design references: `docs/design/v6-personal-shared.html`, `docs/design/v7-landing-auth.html`.
Work is split into small parts. Each part ships on its own branch + PR, with its own SQL (idempotent) and README note.
Never push to `main` directly (a push to `main` auto-deploys).

## Architecture rules
- One feature = one module folder `modules/<name>/` with a `module.php` manifest (see `includes/modules.php`).
- Pages use `includes/layout.php` (`ef_page_start` / `ef_page_end`) and `assets/app.css`.
- A module owns its own tables/files and never edits another module's tables. Cross-module links go through small helper functions only.
- Every new table goes into the module's `ensure_schema()` (self-healing) AND `schema.sql`. `database/part-N.sql` files stopped at Part 11f and are no longer needed.
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
| 10 | Native apps: APK (Android), EXE (Windows), GitHub Releases workflow | done (iOS: PWA only, native .ipa needs a Mac + Apple developer account, postponed) |
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

### Notes for Part 15 (app options in Profile)
- Profile → More: "Android app" (link to the latest release, hidden inside the app) and "App updates" (only inside the Android app: shows the installed version and a Check button that asks GitHub for the newest release; the app talks to the page through the `EFApp` bridge).

### Notes for Part 10b (Windows app)
- `apps/desktop`: Electron shell for https://expenseflow.rf.gd/ (opens on login; other links go to the browser; offline screen; Check for updates in the App menu + a prompt 4 s after start; Profile shows the installed version through the same `EFApp` bridge).
- `release.yml` has a second job (`windows`, after `android`): builds `ExpenseFlow-Setup.exe` (NSIS one-click installer) and adds it to the same release. Not tested on a real Windows PC yet; the installer is unsigned, so Windows SmartScreen shows "Windows protected your PC" -> More info -> Run anyway.
- Still to do: 10c iOS (needs Mac + Apple developer account; otherwise PWA "Add to Home Screen").

### Notes for Part 16 (in-app updates, 3-day login)
- Android: "Update now" downloads the APK inside the app (DownloadManager, app-private folder) and opens Android's installer (FileProvider); the very first time Android asks to allow ExpenseFlow to install apps. Windows: "Update now" downloads the installer, closes the app, runs it silently (`/S --force-run`) and the new version starts. A completely silent install is not possible on Android without special device rights.
- Login: stays valid while used; after 3 days without any visit the user is signed out and the login page says why. Every visit restarts the 3 days. The cookie lasts 30 days (sliding) so an expired login can be explained; the server enforces the 3 days. Sessions are stored in `storage/sessions` (shared hosts clean the default folder after minutes). Test: `tests/session_http_test.py`.


### Notes for Part 17 (final review)
- Super Admin activity is hidden from other staff everywhere: audit log, user logs, overview, user profile, and now also "blocked by" in Security and the author of support replies (shown as "Super Admin").
- Landing page: "Get the app" section (Android APK, Windows installer, iPhone/browser instructions) using the latest-release download links.
- Review: all PHP files lint clean, every test suite passes, all user and admin pages crawled on desktop and phone without warnings or overflow.
- Open items (optional): iOS .ipa; Google Play / Microsoft Store listings (need paid developer accounts + own signing key); a domain with SPF/DKIM so emails stop landing in spam; saving expenses while offline (needs a sync queue).

### Notes for Part 18 (Safe Browsing warning + SEO)
- Google flagged login/dashboard as "Deceptive pages" (typical for a login form on a free subdomain). Added trust signals: About, Privacy, Terms, Contact pages linked from the landing page and footer, a "we never ask for bank or card details" line, honest ownership info. Then request a review in Search Console -> Security issues. A custom domain is the lasting fix.
- SEO: richer title/description, Open Graph + Twitter large image (`assets/og-image.png`), JSON-LD (WebSite, Organization, SoftwareApplication, FAQPage, Article, BreadcrumbList), longer FAQ, three money guides under `/guides/`, full sitemap, robots blocks private areas. Shared layout: `includes/public-layout.php`. Test: `tests/seo_http_test.py`.

### Notes for Part 19 (more search-targeted guides)
- Five new guides aimed at searches people in Pakistan really type (English and Roman Urdu): expense tracker app for Pakistan, kharcha ka hisab, monthly budget, trip expense sharing, saving from salary. All listed in `guides/index.php` and `sitemap.xml`. Keep adding about one guide a week.

## Part 20 — Windows icon, sign-up consent, landing refine
- Windows installer/exe/shortcut icon: classic multi-size `build/icon.ico` (BMP + 256 PNG), nsis installer/uninstaller icons, shortcutName.
- Sign-up requires accepting Terms + Privacy (server-checked; `terms_accepted` logged at account creation).
- Landing: new "Guides, privacy & help" cards section and a 5-column professional footer.

## Part 21 — Friends by email, search bar, one transparent favicon
- Friends: add by email (request → accept/decline/cancel/remove), table `friend_links`, request e-mail; direct friends show even without a shared group. Search bar redesigned (instant filter).
- Favicon: transparent logo in favicon.ico/png, one shared `includes/favicons.php` for app, admin, public, auth, offline and maintenance pages (sw cache v5).
- Next: Part 22 = professional exports (Excel/PDF/CSV, all places), Part 23 = full security/logic/UI review.

## Part 22 — Professional exports
- New `includes/export.php`: one report model → branded Excel (.xlsx via PhpSpreadsheet: logo, summary sheet, styled tables, filters, frozen headers, print setup) and PDF (TCPDF: logo header, KPI cards, tables, page footer). Used by the personal Full Report (CSV/Excel/PDF) and the admin user list (CSV/Excel/PDF). Formula-injection safe. File names `ExpenseFlow-<name>-<date>.<ext>`.
- Backups stay raw .sql (restore file) and receipts stay original images.

## Part 23 — Full review (security, logic, UI)
- Checked: exposed paths, deploy excludes, CSRF on every POST handler, SQL/XSS/redirect scans, IDOR (edit/delete across users, receipts), amount validation, login/forgot enumeration, 3-page-size UI crawl (desktop+mobile, user+admin), console errors.
- Fixed: sign-up no longer reveals whether an email has an account (same screen; owner gets an "already have an account" mail); Chart.js is now self-hosted (works offline, no third-party script); dashboard chart size on phones; PWA manifest has proper separate "any" and padded "maskable" icons.
- Known/accepted: Android signing key (sideload.jks) is in the repo by design; HTTPS redirect still off in .htaccess (site works on https; HSTS header is sent).

## Part 24 — Microsoft Store package
- Release workflow also builds `ExpenseFlow-Store.msix` (artifact `ExpenseFlow-store-msix`); identity comes from secrets STORE_IDENTITY_NAME / STORE_PUBLISHER / STORE_PUBLISHER_DISPLAY_NAME. Tile assets in `apps/desktop/build/appx`. Store build skips the in-app updater. Steps: docs/MICROSOFT_STORE.md.

## Part 29 — Export hardening
- Exports are built fully in memory and sent only when complete (`ef_export_try/send/begin`): PHP warnings or errors can no longer end up inside a downloaded file; on failure the user sees a message and CSV still works. Android download manager now sends the page's User-Agent/Referer/cookies.

- Part 30: Safe Browsing "Deceptive pages" response — login/register pages now carry a plain "what is ExpenseFlow / who made it / never asks for bank or card details" block with links to Home/About/Privacy/Terms/Contact; added `/.well-known/security.txt`. Permanent fix = own domain.


## Part 31 — Project documentation
- New docs: `docs/README.md` (index), `ONBOARDING`, `ARCHITECTURE`, `THEME`, `MODULES`, `DATABASE`, `SECURITY`, `TESTING`, `DEPLOYMENT`, `OPERATIONS`, `APPS`, `CONTRIBUTING`; `MICROSOFT_STORE.md` gained the certification-failure fixes; root `README.md` rewritten and `DEPLOYMENT.md` now points to the docs.
- `schema.sql` now includes `friend_links` (28 tables, checked on an empty database).
- Found: `assets/theme.css`, `style.css`, `dashboard.css`, `responsive.css`, `landing.css`, `auth.css` are legacy and not loaded by any page; only `app.css` + `admin.css` are live. Inter is not downloaded (system font fallback).

## Part 32 — Continue with Google
- New module `modules/oauth` + `auth/google.php`, `auth/google-callback.php`, `config/google.php`: OpenID Connect code flow with PKCE, single-use state, verified e-mail only; finds, links (same e-mail) or creates the account; refuses blocked users/IPs and respects closed sign-ups. Table `user_identities` (29 tables now).
- Button on login and sign-up, hidden until `EXPENSEFLOW_GOOGLE_CLIENT_ID/SECRET` are set and hidden inside the Android/Windows shells (Google blocks embedded web views). Deploy workflow writes `config/google.local.php` from the secrets. Hints for Google-created accounts to set a password via Forgot password.
- Tests: `google_signin_test.php`, `google_http_test.py`. Setup guide: `docs/OPERATIONS.md` section 4b.

## Part 33 — Contact page
- Contact page no longer links to the GitHub issue tracker; it shows `expenseflowteam@gmail.com` instead (also added to `security.txt`).

## Part 34 — Google sign-in fixes + sign-in page polish
- Google callback now shows a reason code on failure and keeps the saved state when a return URL has none; Gmail dots / `+tag` / googlemail.com no longer create a duplicate account; button uses `assets/icons/google-g.svg` if present.
- Sign-in/register footers renamed `.aft` (they were broken by the landing footer class `.ft2`); button/divider/spacing refined.

## Part 35 — Fix HTTP 500 on the Google callback
- `user_identities` no longer has a foreign key to `users` (some hosts reject it, so the table could not be created and the callback crashed); `shared_on_user_deleted()` now calls `oauth_on_user_deleted()` to remove the rows.
- `auth/google-callback.php` is wrapped in try/catch plus a shutdown handler: any error becomes a normal "try again" message with a short `(reason: ...)` code instead of a blank 500.
- Tests: stale-identity and no-foreign-key cases in `tests/google_signin_test.php`.

## Part 36 — Official Google "G" on the sign-in button
- `assets/icons/google-g.png` (supplied by the owner) is used by `google_mark()`; an `.svg` with the same name takes priority if added later.

## Part 37 — Windows app icon background + package size
- Cause of the changing tile colour (blue / red / white): the Store tile images were transparent and `appx.backgroundColor` was `transparent`, so Windows painted the user's accent colour behind the logo. All tile images in `apps/desktop/build/appx/` are now regenerated from `assets/icons/icon-512.png` on a solid white plate and `backgroundColor` is `#FFFFFF` (same look as the Android and PWA icons).
- Size: `electronLanguages: ["en-US"]` (drops about 40 MB of unused language packs), maximum compression, asar on. Electron itself ships a whole Chromium, so the app cannot go much below ~150 MB installed; the only big saving is a WebView2-based shell (a few MB) — see ROADMAP ideas.
- To apply to the Store version: run the release workflow with a new version, upload the new `.msix` as a new submission in Partner Center. The tile in the Start menu changes only after the update installs.

## Part 38 — One place for app links and contact (Admin > Settings)
- New "Apps & contact" block in Admin > Settings: Android link, Windows link, Microsoft Store link (empty = button hidden) and the contact e-mail. Landing page, profile page and contact page read them via `settings_public()`, which uses `storage/public-settings.json` (written on save) so they never need the database. Changes are audit-logged.
- Idea left: logo/colours are still files (`assets/`, `THEME.md`); moving them to the panel is possible later but riskier.

## Part 39 — Continue with Google inside the Android and Windows apps
- Google blocks sign-in in embedded web views, so the apps use a hand-off: the app (`assets/google-app.js` > `auth/google-app.php`) gets an id, a secret and a 4-letter code; the Google step opens in the normal browser (`auth/google.php?app=<id>`, caught by `MainActivity` / `main.js`); the browser shows the same code on `auth/google-app-confirm.php` and the person confirms; the app polls and is signed in once (`app_handoffs` table, 5 minute lifetime, secret stored hashed).
- The browser itself is never signed in during this flow. Rate limits: 20 starts/hour, 600 polls/hour per IP.
- Tests: `tests/google_app_handoff_test.py` (starts its own servers and a fake Google token endpoint, `tests/mock_google_token.php`).
- To ship: merge, then run the release workflow with a new version (Android + Windows shells changed) and submit the new .msix to the Store.

## Part 40 — Google sign-in made solid (app code panel, password offer, friendly errors)
- App: the code is now a big panel under the Google button ("compare with the browser"), survives a page reload (sessionStorage), has Open browser again / Cancel and a timeout message. `google_in_app_shell()` also recognises plain Electron / Android web view user agents (the service worker fetches pages without our marker, which made the app show the plain link instead of the code panel).
- Password: a person who signed up with Google is offered "Set a password / Skip" on first sign-in (`auth/google-password.php`, `users`-level state `pw_state`: 1 offered, 2 skipped, 0 has a password). Profile keeps offering it; Forgot password and admin password reset mark it as set. Delete account no longer needs the Forgot-password detour.
- Errors: `error.php` + `includes/errors.php` give friendly 400/403/404/405/429/500/503 pages without needing the database (`.htaccess` ErrorDocument, router parity, uncaught exception and fatal handlers; JSON endpoints answer JSON). `router.php` now blocks `modules/` like Apache does.
- Tests added: `google_app_ui_test.py` (real headless browser), `google_password_http_test.py`, `errors_http_test.py`; `google_signin_test.php` extended.

## Part 41 — Google in the apps: no code to compare, the browser returns to the app by itself
- The 4-letter code is gone (it was the same text in two places, so it protected nothing). Security now comes from WHERE the proof goes: after Google, `auth/google-app-return.php` sends the browser back to the app on the same device with a one-time proof: Android `expenseflow://auth?h=&p=` (intent filter in `AndroidManifest.xml`, handled in `MainActivity`), Windows `http://127.0.0.1:<port>/done?h=&p=` (`apps/desktop/google-return.js`, started by `main.js` through `EFApp.googleListen()`). The app signs in with id + secret + proof (`do=redeem`); `poll` only reports progress. Someone else's app never receives your proof; a crafted return link is ignored unless it matches the hand-off the app itself started.
- `app_handoffs` gained `ret` and `proof_hash` (added by the ensure function on the live DB).
- To ship: merge, then run the release workflow with a new version (Android + Windows shell changed) and upload the new `.msix`.

## Part 42 — Profile & Settings hub with sections
- `profile.php` is now a hub (who you are + one card per section). Each section is its own page under `settings/` with a side menu and breadcrumb on desktop: Account, Password & security, Preferences, Notifications, Apps & devices, Data & privacy. Add a section by listing it in `settings_sections()`.
- Plan for the next Parts (see the list in the chat/ROADMAP ideas): dark mode, category budgets, savings goals, reminders, tags + search, SMS quick-add, 2FA, onboarding + crowded pages, Urdu/RTL, backups/push.

## Part 43 — Dark mode
- `assets/theme-dark.css` + `includes/theme.php`: Auto / Light / Dark in Settings > Preferences > Appearance (cookie `ef_theme`, applied before paint, also on login, public pages, landing and admin). Charts follow the theme colours.

## Part 44 — Category budgets with alerts
- New optional module `modules/budgets/` (page `budgets.php`, menu entry Budgets): a monthly limit per category in any currency, progress bars (green under 80%, orange 80-99%, red from 100%), and a small card on the dashboard (month view, three fullest budgets).
- After an expense is added or edited, `budgets_check_alerts()` sends ONE bell notification at 80% and ONE at 100% per category per month (`budget_alerts` remembers; jumping straight past 100% sends one message). Respects the person's notifications switch. Events use group id 0 and are labelled "Budgets".
- Spending uses the PKR value saved with each expense, so rate changes never move a budget. Switch off by setting `enabled` to false in `module.php`; the expense form and dashboard check with `function_exists`/`ef_modules()`.
- Tables: `budgets`, `budget_alerts` (self-healing, no foreign keys). Tests: `budgets_test.php`, `budgets_http_test.py`.

## Part 45 — Savings goals
- New optional module `modules/goals/` (page `goals.php`, menu entry Goals): name, target, currency and optional deadline; add or take out money by hand (history kept in `goal_entries`, never below zero); progress bar, "to go", and "save about X a month" until the deadline; reached goals are marked.
- Goals do not touch income/expense totals (they are a separate piggy-bank), so nothing in reports changes.
- Fix: Budgets had menu order 45 and so appeared before Dashboard (its link became the home link); now 55. `goals_test.php` checks Dashboard is first.
- Tables: `savings_goals`, `goal_entries`. Tests: `goals_test.php`, `goals_http_test.py`.

## Fix after Parts 44/45 — Budgets/Goals errors on the live site
- Live dashboard and Budgets page showed "Error 500" inside the page. Hardening: `ef_schema_add_missing()` (in `includes/schema.php`) adds any missing column to a table that already exists in another shape, `budgets`/`budget_alerts` also get their unique keys repaired; the dashboard budget card is wrapped so it can never break the dashboard; Budgets and Goals pages catch load errors and show "could not load right now" (admins also see the technical reason). Test: `module_failsafe_http_test.py`.

## Part 46 — Reminders
- New optional module `modules/reminders/` (page `reminders.php`, menu entry Reminders): bill/payment with due date, optional amount, optional "every month". States: late, today, soon (within 3 days), later. "Paid" moves a monthly reminder to the next month (clamped for short months, skips dates already past) or finishes a one-time one; the box "Also save as expense" records it in Bills, once (concurrent clicks cannot double-record).
- Bell: one notification when it gets within 3 days and one when it is late, per due date (`soon_for`/`late_for`). Checked whenever the dashboard or Reminders page opens (no cron needed on free hosting). Dashboard card "Due soon" shows late/soon items.
- Not done yet: "remind me to settle up" for shared groups (needs balances per friend; planned as a follow-up inside the Shared section).
- Tests: `reminders_test.php`, `reminders_http_test.py`; `module_failsafe_http_test.py` now cleans its schema markers.

## Part 47 — Tags + search
- Add/Edit income and expense have a Tags field (up to 5 per entry, up to 20 letters/numbers each, `#` optional). Stored in the new `tags` column as `,trip,work,` so one tag matches exactly (`expenses`/`income` get the column from `personal_ensure_schema()`).
- New optional module `modules/search/` (page `search.php`, menu entry Search): free text over title, note, category and tags, plus tag chips (your own tags), type, category, date range and amount range (PKR). Shows count and income/expense totals of the matches, newest 100, each row opens the edit page. Only the signed-in person's rows are ever read; `%` and `_` typed by the person are escaped.
- Tests: `search_test.php`, `search_http_test.py`.

## Part 48 — Quick add from a pasted bank SMS
- New optional module `modules/quickadd/` (page `quick-add.php`, menu entry Quick add): paste a payment SMS, the app finds amount and currency (Rs/PKR/USD…), debit or credit (the first money word wins when both appear), who (after at / to / from, never an account or card word) and a category guess from merchant keywords, then offers "Review and save" which opens the normal Add Expense / Add Income form already filled in. If it cannot tell debit from credit it offers both buttons.
- Privacy and safety: the message is not stored (no table), only the first 600 characters are read, everything shown is escaped, and the Add form treats the pre-filled values as suggestions (unknown category or currency is ignored, the amount is validated on save).
- Idea left for later: Android "share to ExpenseFlow" from the SMS app (needs the Android shell change and a release).
- Tests: `quickadd_test.php`, `quickadd_http_test.py`.

## Part 48b — Quick add can read SMS on Android (with permission)
- Quick add shows "Read my messages" only inside the new Android app. Tapping it makes Android ask for the SMS permission; if allowed, the phone lists the recent payment messages (amount + money word, no codes) and one tap sends that single message through the normal Quick add flow. If denied, the person is told it is fine and pastes as before. Website and Windows keep paste only (browsers cannot read SMS).
- Files: `AndroidManifest.xml`, `MainActivity.java` (bridge), `assets/quick-add.js`, `quick-add.php`. Test: `quickadd_device_ui_test.py` (the Java itself cannot run here: check on a phone after the release).

## Speed check + sturdier schema markers
- Live site felt slow after Parts 44-48. Local profile: a warm dashboard needs about 24 queries (same as before Part 44) and opens in milliseconds, so the cause is on the host. New super-admin page `admin/speed.php` (Admin > System > Speed check) measures it on the real server: database round trip, whether the schema markers work (if not, every page repeats its CREATE/ALTER checks), cold cost per module, session files, exchange-rate cache.
- Markers now fall back to a folder in the system temp directory when `storage/schema` cannot be written (`ef_schema_dirs()`).
- Dashboard saves one query (`reminders_check_due()` can reuse the list it already loaded).
- Tests: `speed_check_test.py`; reminders/goals HTTP tests now use Karachi time like the app (they failed after midnight UTC).

## Fix: slow pages when the exchange-rate service cannot be reached
- The live Speed check showed a fast database and working schema markers, but the exchange-rate cache was 13 hours old with no failure note: the cache could not remember its failed attempts, so any page that converts money (a base currency other than PKR) waited up to 4 seconds for the rate service on every view.
- `CurrencyConverter` now: remembers an attempt BEFORE asking (one wait per 15 minutes at most), waits 2 seconds instead of 4, keeps its files in the first writable folder (`storage/`, `storage/schema/`, or a temp folder) and does not call the service at all when no folder is writable (it uses the old cache or built-in rates). Speed check shows the folders and whether the service is reachable in under 2 seconds.
- Tests: `currency_cache_test.php`.

## Fix: phone menu crowded, Search moved to the Dashboard
- With Budgets, Goals, Reminders, Search and Quick add the phone bottom bar had 9 tiny items. Now: Dashboard, Income, Expense, Report + a "More" button with a small sheet (Budgets, Goals, Reminders, Quick add). Works in light and dark, closes with a tap elsewhere or Escape, and highlights More when the current page is inside it. Desktop keeps the full sidebar.
- Search is no longer a menu entry: the Dashboard has a search box at the top that opens the Search page (`search.php`, still the full search with tags and filters).
- Tests: `mobile_nav_ui_test.py`; `search_http_test.py` updated.

## Part 67 — Phone alerts (Web Push)

- The `modules/push` server code was swept into the Part 63 commit by mistake (it was unfinished work, inert); this Part finishes and tests it. Roadmap line "push notifications still open" is now closed for browsers.
- Flow: Settings > Notifications > **Phone alerts** > Turn on (browser asks permission) > `push-subscribe.php` stores the subscription (`push_subscriptions`, max 5 devices per person, https endpoints only, rate limited, CSRF) > every bell notification (`activity_record`: shared bills, settle-ups, reminders, budget alerts, nudges) is also pushed to people who have notifications on. Encrypted with RFC 8291 and signed with VAPID (key created on first use, stored in `push_vapid`), pure PHP openssl + curl. Sending runs after the page has been sent (`push_defer`), dead devices (404/410) are removed, account deletion (self or admin) removes devices.
- `sw.js`: `push` shows the notification, `notificationclick` opens only pages of this app. Cache `v13`.
- **Limits, honestly:** works in Chrome/Edge (Android and computer) and an installed web app; the Android/Windows app shells (WebView/Electron) cannot receive Web Push, so the card says so there and the bell list stays. The host must allow outgoing HTTPS from PHP (curl) to the browser push services; if it does not (some free hosts block it), "Send a test" says it could not be delivered.
- Tests: `tests/push_test.php` (RFC 8291 test vector, subscriptions, delivery to a sink, opt-out, deletion), `tests/push_http_test.py` (endpoint, CSRF, other users, settings card, service worker, Urdu).

## Part 66 — Privacy and Terms in Urdu

- `privacy.php` and `terms.php` now show Urdu in Urdu mode (about 40 entries). It is a plain translation for readability; the English text is the version to rely on if the two ever differ.
- Now the only English left for a signed-in or public visitor in Urdu mode is the admin panel (staff), the Roman-Urdu guide articles and literal names (`adb install`, button names the phone shows).
- Tests: `tests/urdu_public_test.py` also checks both pages.

## Part 65 — One Urdu font (no flash) and better English fonts

- **Urdu flash fixed:** before, the browser first drew Urdu in another font and swapped to Nastaleeq when it finished downloading. Now the two Nastaleeq files are preloaded (`ef_font_head()` in `includes/i18n.php`, URL without `?v=` so the preload is really used), `font-display:block`, the files are kept by the service worker, and one stack (`--font-ur`) applies to every element (also beats rules that set a font on all elements). Latin letters and digits inside Urdu use Inter. The landing page was not loading `rtl.css` at all on a phone without a system Nastaleeq font; fixed.
- **English fonts:** Inter (text) and Plus Jakarta Sans (headings, brand, big amounts), both self-hosted in `assets/fonts` (SIL OFL licences included) instead of the Google Fonts import. New `assets/fonts.css`: balanced headings, tidier letter spacing, tabular numbers for amounts, anti-aliasing, visible keyboard focus ring.
- `offline.html` uses the same fonts. Service-worker cache `v12`.
- Tests: `tests/fonts_test.py`.

## Part 64 — Urdu polish: size, public pages, offline page

- Urdu text is scaled to 110% (`html[dir=rtl]{font-size:110%}` in `assets/rtl.css`) because Nastaleeq has a small x-height; bottom-menu labels are larger too.
- Public pages are fully Urdu: landing page (now `<html lang=ur dir=rtl>` so the layout flips), About, Contact, Guides index, sign-in side text (about 130 new entries). Privacy and Terms (legal text) and the guide articles themselves stay as written.
- `offline.html` (static) switches to Urdu from the `ef_lang` cookie and loads `rtl.css`.
- Removed the Lite APK link from the landing page (it stays in Settings > Apps) so the public page has no install-workaround wording, as Part 61 intended; `tests/lite_apk_test.py` passes again.
- Service worker cache `v11` (restart the app once).
- Tests: `tests/urdu_public_test.py` (no English left on public pages, RTL, font and size, offline page).

## Part 63 — SMS accuracy (opt-out footers, amount titles)

- `quickadd_strip_footer()` cuts "To stop receiving messages dial 930" style endings (stop receiving / unsubscribe / opt out / to stop / to deactivate) before the SMS is parsed or classified, so a footer can no longer turn into a title ("Stop Receiving Messages 930") or a money word.
- A title can no longer be just an amount ("Pkr 55.00") or only digits; the next candidate or "Money received / Bank payment" is used.
- Tests: new cases in `tests/quickadd_classify_test.php`.
- Still helpful: send the original text of the SMS that was wrongly added as income, to add it as an exact case.

## Part 62 — Urdu everywhere, in Nastaleeq

- Urdu pages now use **Noto Nastaliq Urdu** (SIL OFL, self-hosted in `assets/fonts`, loaded by `assets/rtl.css`) with taller line-heights so the tall letters are not clipped.
- Output filter `ef_i18n_page()` (`includes/i18n.php`): in Urdu mode it exact-matches page text, placeholders, titles and button values against `includes/lang/ur.php`, so pages need no per-string edits. Scripts, JSON and unknown text are left alone.
- About 200 new Urdu entries: Reports, Quick add (SMS help), Profile & Settings (all sub-pages), Shared overview, Groups, Friends, Activity, Support, currency names, month names (`ef_month_year()`), error pages (404 etc.).
- Left in English on purpose: the admin panel (staff only), and literal names such as `adb install`, "Allow restricted settings", "Play Protect" labels the phone shows in English.
- Tool: `python3 tests/urdu_coverage.py [--admin]` crawls the pages in Urdu and lists English text still showing.
- Tests: `tests/i18n_test.php` (filter, month names, more files checked).
- After deploy: fully close and reopen the app once (new service-worker cache `v10`) to get the font.

## Part 61 — "Dangerous site" warnings (Safe Browsing)

- Cause and plan: `docs/SAFE_BROWSING.md`. Google flagged `http://expenseflow.rf.gd/` as social engineering (free shared sub-domain, plain-http sign-in page, install-bypass wording on the landing page).
- Admin > Settings > **Force HTTPS** (marker file `storage/force-https.on` read by `.htaccess`; refuses to turn on from plain http; easy undo). HSTS header added.
- Landing page: install-bypass instructions moved to Settings > Apps > Install help; Lite link text shortened.
- Android: `onSafeBrowsingHit` proceeds only for the app's own host (needs a new APK).
- `tools/switch-domain.php old new [--apply]` for the move to a custom domain.
- You still have to: turn on Force HTTPS, request a review in Search Console, and (lasting fix) get your own domain.
- Tests: `tests/trust_test.py`.

## Part 60 — Urdu on sign-up and password pages

- Translated: Create account, Confirm your email, Forgot password, Check your email (reset code), Create new password, the sign-in/sign-up error and notice messages, field labels and placeholders, the password checklist and the footer of the sign-in pages (about 80 new entries in `includes/lang/ur.php`).
- `ef_msg()`, `ef_field()` (label and placeholder) and `ef_check()` in `includes/auth-layout.php` run their text through `t()`, so any message set on those pages is translated when an Urdu text exists; texts without one stay English.
- E-mail addresses and codes stay left-to-right inside Urdu sentences.
- Not translated yet: the two-step login-code page headings, reports, history lists, other settings pages, admin panel, public pages.
- Tests: `tests/i18n_test.php` also reads the messages, labels and placeholders of these pages and fails when one has no Urdu text; `tests/urdu_auth_ui_test.py` (real browser).

## Part 59 — Urdu on Budgets, Goals and Reminders

- `budgets.php`, `goals.php`, `reminders.php` use `t()`: titles, forms, buttons, progress text, confirm boxes and success/error messages now show in Urdu when the language is Urdu (about 26 new entries in `includes/lang/ur.php`). Amounts, dates and category names that the person typed stay as they are; built-in category names are translated.
- `tests/i18n_test.php` now also checks these three pages; `tests/urdu_pages_ui_test.py` opens them in Urdu at phone width (right-to-left, Urdu words, no sideways scroll, number boxes left-to-right) and checks English is unchanged.
- Not translated yet: reports, history lists, the other settings pages, register / forgot password, admin panel, public pages.

## Part 58 — Lite Android app (installs even when Play Protect blocks the full one)

- Problem: Google Play Protect blocks installing an APK from a browser / WhatsApp / file manager when it declares SMS permissions ("App blocked to protect your device"). Code cannot override that.
- Two builds from the same project (Gradle flavors, same package and signing key, so either updates the other): `ExpenseFlow.apk` (full, reads SMS) and `ExpenseFlow-Lite.apk` (no `READ_SMS`/`RECEIVE_SMS`, no SMS receiver: `src/lite/AndroidManifest.xml`). The release workflow builds and uploads both.
- In the Lite app Quick add says so and points to Share > ExpenseFlow or paste; the SMS card, auto-add script and check panel stay off (`smsSupported()` is false when the permission is not declared). The in-app updater downloads the matching file.
- The landing page and Settings > Apps show a "Blocked by Play Protect? Get the Lite APK" link (derived from the Android link when it ends in `/ExpenseFlow.apk`).
- Full APK on a blocked phone: install from a PC with `adb install -r ExpenseFlow.apk` (installer is not a browser; reported to work, not documented by Google).
- Could not be compiled here (no Android SDK): the first release build on GitHub is the real test. If it fails, the error is in the run's annotations.
- Tests: `tests/lite_apk_test.py`.

## Part 57 — Fixes from phone testing (offline add, offline bars, net balance, SMS dates)

- Offline add did not work because the Add Expense / Add Income pages had never been opened online, so no saved copy existed. Now the page asks the service worker (message `prewarm`, at most every 6 hours, only while online) to save the Add pages and the dashboard, so they open offline and entries are queued (Part 54). Service worker cache v9.
- Two offline bars overlapped and one said "Changes cannot be saved" (no longer true). The old bar in `fx.js` was removed; `net-status.js` shows one bar that now says new income and expenses are kept on the phone. The bars no longer block taps (`pointer-events:none`), so the Save button below them can be pressed.
- Net balance showed 0.00 while income and expense had values: the count-up animation could stay on its first frame when the page was resumed or opened in the background. The final number is now always restored (timer, `visibilitychange`, `pageshow`).
- SMS entries are saved with the date of the message, so messages from an earlier month are not in "This month". Quick add now says so and shows each entry's date.
- Test: `tests/offline_prewarm_ui_test.py` (service worker ON).

## Part 56 — "Check my SMS setup" (why SMS are not read / added)

- Quick add (Android app) has a new "Check my SMS setup" button. It lists each step as OK / needs attention with what to do: app version, auto-add on/off, read and receive permissions (with the "Allow restricted settings" steps), notifications, whether this phone is registered for background sending, the last background send, the results of the last 7 days (added / need check / ignored), and what the phone's inbox shows (how many messages, how many look like payments, from which senders).
- Typical cause found by the panel: payment messages older than the moment auto-add was turned on. A new box then offers "also include the last 1-30 days" (`since_days` action, `quickadd_set_since_days()`); messages already handled are never added twice.
- Server: `quick-add-import.php` status now also returns `devices`, `last_sync`, `counts`, `now`. Android: `smsProbe()` and `smsNotifGranted()` in the bridge (`SmsSync.probe()`); needs the new APK for the inbox counts, the other rows work with 1.0.18.
- Files: `assets/sms-check.js`, `quick-add.php`, `modules/quickadd/lib.php`, `quick-add-import.php`.

## Part 55 — Share to ExpenseFlow (Android)

- In any app (Messages, WhatsApp, a bank app) choose Share > ExpenseFlow on a payment message: the app opens Quick add with the text already in the box. Press the button to parse it; nothing is saved until you confirm.
- `quick-add.php?sms=<text>` only fills the box (cut at 600 characters, escaped). Android side: a `SEND text/plain` intent filter in `AndroidManifest.xml` and `shareUrl()` in `MainActivity.java`.
- Needs a new APK (release v1.0.19 or later). If you are signed out, sign in first and share again.

## Part 54 — Offline expense queue

- Add Expense / Add Income keep working without internet: if the device is offline when you press Save, the entry is stored on the phone (localStorage `ef_offq`, up to 50) and a note says so. It is sent automatically when the connection returns (on the `online` event and when any page loads).
- Every save carries a one-time id (`cid`) and the phone time (`ts`). The server stores the id in `offline_imports` (30 tables now), so a retry or double send never creates a second entry; `ts` is used as the entry date if it is within the last 14 days and not in the future.
- Entries the server refuses (invalid) are dropped with a message; when signed out the entries are kept until you sign in again.
- Limits: the Add page must have been opened once while online (saved page). Only new entries are queued, not edits; the "repeat monthly" box is kept. A connection that dies in the middle of a normal save still shows the browser's own error.
- Also fixed here: a schema check (`ef_schema_ok`) used to run its CREATE TABLE twice in one request, and the second run committed an open transaction, so deleting an account right after a deploy could fail with "no active transaction". It now runs once per request, and `shared_ensure_all_schemas()` covers every module.
- Files: `assets/offline-queue.js`, `modules/personal/lib.php` (`personal_client_id`, `personal_client_time`), `modules/personal/tx-form.php`.

## Part 52b — Automatic daily backup

- Free hosting has no cron, so when a staff member with the "backup" permission opens the admin overview and the newest backup is older than 24 hours (or none exists), one is created automatically (`backup_auto_maybe()` in `modules/backup/lib.php`). A lock file stops two requests doing it at once; failures are logged and never break the page. Ordinary users never trigger it.
- It is written to the audit log as "Automatic daily database backup". The newest 10 backups are kept, so daily automatic ones push out old ones after 10 days: download an encrypted copy now and then (Admin > Backups).
- Off switch: environment variable `EXPENSEFLOW_AUTO_BACKUP_OFF=1`.
- Still open in Part 52: Google re-link prompt (push notifications: see Part 67).

## Part 51 — Urdu language + right-to-left (first step)

- Language switch: Settings > Preferences > Language, and a link on the login page (`?lang=ur|en`, remembered in the `ef_lang` cookie).
- `t('English text')` in `includes/i18n.php`; Urdu texts live in `includes/lang/ur.php`. A missing entry simply stays English, so nothing breaks.
- `assets/rtl.css` mirrors the layout (menu on the right, chevrons, switches); numbers, money, e-mail and charts stay left-to-right.
- Translated so far: menu and frame, dashboard, getting started, add/edit transaction form, categories, payment methods, login.
- To add more: wrap the text in `t()` and add a line to `ur.php`; `tests/i18n_test.php` fails if a wrapped text has no Urdu entry in the checked files.
- Not translated yet: admin panel, public pages, most settings pages, reports.

## Part 53 — Resilience (site keeps working when internet or server fails)
- Cause of the "no internet / nothing loads for half an hour" outage: the database connection had no timeout (workers hung when the DB host was slow), the old service worker waited forever for the network and never saved admin pages, and the signed-in layout did not register it.
- Service worker: waits at most 4 s for the server, then shows the last saved copy of the page with a yellow "saved copy" bar (admin pages included); never-seen pages get a smart offline page that says whether the DEVICE has no internet or the SERVER is not answering, retries every 10 s and offers the saved dashboard. Saved private pages are wiped on logout.
- When the server comes back the page reloads by itself (`assets/net-status.js`, polls `health.php` only while the saved-copy bar is shown) and an online/offline toast appears.
- `health.php` (cheap JSON, `?db=1` also checks the database) — point a free uptime monitor (UptimeRobot, 5 min) at it.
- `config/db.php`: 4 s connect timeout, 20 s read timeout; a dead DB shows a friendly 503 page that reloads itself after 15 s instead of hanging.
- Honest limit: on a free host a real outage cannot be removed by code; saved pages + auto-recovery hide it. A custom domain behind Cloudflare (free) or a paid host is the real fix.

## Part 49 — Two-step sign-in (e-mailed code)
- New module `twofactor` (`modules/twofactor/`, table `user_2fa`). After the password the person enters a 6-digit code e-mailed to the account address (`auth/login-code.php`); the password step alone does not sign anybody in.
- Opt-in in Settings > Security: turning it on or off first needs a code sent to the e-mail (so a wrong address cannot lock anyone out). Staff roles (support, admin, super) always need the code and cannot turn it off.
- Codes: hashed in the session, 10 minutes, 5 tries, 30 s between re-sends, 10 mails/hour per account, 10 wrong codes per 15 min per IP+account.
- Never locks people out: skipped when the server cannot send e-mail at all, or when the host owner sets the emergency switch `EXPENSEFLOW_2FA_OFF=1`. If an SMTP error stops a code from being sent the login shows a retry message. Google sign-in is not asked again (Google already verified the person).
- Tests: `twofactor_http_test.py` (mail goes to a sink file via `EXPENSEFLOW_MAIL_SINK`). Local test servers must be started with `EXPENSEFLOW_2FA_OFF=1` when `config/mail.local.php` exists, otherwise staff test logins ask for a code.

## Part 48c — SMS auto-add with a classifier
- Quick add gets "Add payments automatically" (Android app, opt-in, starts from the moment it is turned on). Opening the app reads the new SMS and `quick-add-import.php` decides per message: ADD (clear bank/wallet payment or receipt), REVIEW (friend's phone number, direction unclear, both directions mentioned) or IGNORE (one-time codes, declined/failed, bill reminders, money requests, offers/adverts, balance alerts, anything without a money word, senders the person chose to ignore).
- Category: the same title's last category from the person's own entries, else the keyword guess, else Other. Payment method from the text (Easypaisa, JazzCash, card, bank). Entry date = the SMS date.
- Safety: hash claim (`sms_imports`, unique per user) means no duplicates; "Added from SMS" list with Undo (the message is then never added again); review list with Expense / Income / Ignore / "always ignore this sender" (`sms_rules`); ignored messages keep no text; review text is deleted once handled; 60 imports per hour per account.
- Tables: `sms_prefs`, `sms_imports`, `sms_rules` (self-healing). Tests: `quickadd_classify_test.php`, `quickadd_import_http_test.py`, `quickadd_auto_ui_test.py`.
- Honest limit: it reads when the app is opened (a background reader needs a native Android service, a future part).

## Part 48d — SMS sync while the app is closed (Android)
- New Android shell code (`SmsReceiver`, `SmsSync`, RECEIVE_SMS): new payment SMS are sent to the server the moment they arrive, even when ExpenseFlow is closed; a notification says what was added. Needs a NEW Android release (1.0.18+); the website and Windows app are unchanged.
- Server: `sms_devices` (hashed per-phone keys, max 5, removed when auto-add is turned off), `quick-add-import.php` accepts `{token, action:'import'}` with no session or cookie; the key can only import. Same classifier, same duplicate protection as the open-app path.
- `assets/sms-auto.js` hands the phone its key after the permission is granted and clears it when auto-add is off.
- Tests: `quickadd_device_token_http_test.py`. The Java code is compiled by the release workflow (not by the local tests).

## Part 50 — Getting started checklist + settle-up reminder
- New module `gettingstarted` (no tables): a "Getting started" card on the Dashboard for accounts younger than 45 days (add an expense, add income, and when the modules are on: set a budget, create a goal, add a bill reminder). Done steps come from the person's real data, "Hide this" is remembered in the browser, and the card disappears when every step is done.
- "Remind to settle up": on a friend's page, someone who is owed money sees "Remind <name> to settle up". The friend gets a bell notification ("<name> reminded you to settle up", with the amount). New activity kind `settle_nudge` (group 0), at most once every 3 days per friend, only when the friend really owes money.
- Not changed: the admin panel layout (no crowded page was found there); Urdu/RTL and backups stay as Parts 51-52.
- Tests: `part50_http_test.py`.

## Fix: admin overview numbers on phones
- The six overview cards (Total users, New this month, ...) were one huge card each on a phone. The compact layout is now the BASE style (2 columns = 3 rows, small numbers) instead of a `max-width:700px` override, and only screens wider than 700 px get the big cards in as many columns as fit. Changing `admin.css` also gives it a new `?v=` address, and the service worker cache name went from `static-v7` to `static-v8`, so a phone that kept an old copy of the style sheet loads the new one.
- Test: `admin_mobile_ui_test.py` (320 to 1200 px).

## Fix: payment SMS that were not detected
- Banks that write from a short numeric code (for example `8258`) were treated like a friend's phone number and sent to "Needs your check"; a short code (3-6 digits, no +) now counts as a bank.
- More money words count as a payment (successful/successfully, transaction, txn, trx, transfer, top-up). A message that has an amount but no clear direction waits in "Needs your check" instead of being lost.
- New "Skipped by the filter" list on Quick add: messages that looked like offers, failed payments, reminders, money requests or balance alerts keep their text for 7 days with the reason, and can be added with one tap (Expense / Income). One-time-code messages never keep their text.
- Tests: `quickadd_classify_test.php` (short code, "Transaction successful", direction unknown, phone number), `quickadd_import_http_test.py` (skipped list).
