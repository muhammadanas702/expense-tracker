# ExpenseFlow modules and features

Every feature is a folder in `modules/` with a `module.php` manifest (see [`ARCHITECTURE.md`](ARCHITECTURE.md) section 4). Set `'enabled' => false` in a manifest to switch a module off everywhere. `modules/` is not reachable from the web; the URL a user visits is a small **root wrapper** (listed per module below).

## Summary

| Module | Mode | In menu | Purpose | Public pages (wrappers) |
| --- | --- | --- | --- | --- |
| `personal` | Personal | Dashboard, Add Income, Add Expense, Full Report | Income, expenses, recurring items, dashboard, reports | `dashboard.php`, `income/add-income.php`, `expense/add-expense.php`, `edit-*.php`, `delete-*.php`, `reports/view-report.php`, `change-base-currency.php`, `reset-data.php`, `stop-recurring.php` |
| `profile` | neutral | via layout link | Profile, savings goal, notifications, first-run setup, danger zone | `profile.php` |
| `shared` | Shared | Overview, Groups, Add Shared | Groups, split bills, balances, settle up | `shared/*.php` |
| `friends` | Shared | Friends | Friends by e-mail + people you share groups with, per-person balances | `friends/*.php` |
| `activity` | Shared | Activity | Feed + notification bell | `activity/*.php` |
| `comments` | Shared | none | Comments under shared expenses | `comments/action.php` |
| `receipts` | Shared | none | One receipt photo per shared expense | `receipts/view.php` |
| `support` | neutral | Help in Profile | Help requests and replies | `support/*.php` |
| `announce` | any | none | Banner notices from staff | `announce/dismiss.php` |
| `signup` | auth | none | E-mail code confirmation at sign-up | used by `auth/register.php`, `auth/verify-email.php` |
| `oauth` | auth | none | **Continue with Google** (login and sign-up) | `auth/google.php`, `auth/google-callback.php` |
| `admin` | staff | Admin button | Roles, permissions, audit trail, shell | `admin/*.php` |
| `categories` | admin | none | Expense category list | `admin/categories.php` |
| `currencies` | admin | none | Hide currencies, see rates | `admin/currencies.php` |
| `groupsadmin` | admin | none | Staff view/cleanup of shared groups | `admin/groups.php` |
| `security` | admin | none | Failed-login log, IP blocks | `admin/security.php` |
| `backup` | admin | none | SQL backups | `admin/backups.php` |
| `settings` | admin | none | Maintenance mode, sign-ups on/off | `admin/settings.php` |

## Personal (`modules/personal`)

- **Add/edit income and expense** (`tx-form.php`): title, amount, currency (defaults to the user's main currency), category (expenses), payment method, note, date, and **Repeat every month**. Edit pages show the same fields.
- **Recurring items** (`recurring_items`): `personal_run_recurring()` runs when the dashboard opens and creates entries that came due; "Stop" button (`stop-recurring.php`).
- **Dashboard** (`dashboard.php`): period chips (this month, last month, last 3 months, this year, custom), hero balance, income/expense totals, savings rate vs the user's goal, insights (financial health, trend vs last month, top category, average daily spend), category chart (Chart.js, self-hosted), recent items, recurring list. Monthly **base currency** can be changed (`change-base-currency.php`).
- **Full Report** (`reports/view-report.php`): date and type filters, monthly comparison, category breakdown, all transactions; **CSV, Excel and PDF** downloads (branded, see exports).
- **Reset data** (`reset-data.php`): clears all income, all expenses or everything (POST + CSRF).
- Helper functions are `personal_*` in `modules/personal/lib.php`.

## Profile and settings (`modules/profile`)

Name, password change, **monthly savings goal** (default 20 %), notifications on/off, first-run card (main currency + goal; can be skipped), "Danger zone" with reset data and **delete account** (`auth/delete-account.php`, asks for the password).

## Shared (`modules/shared`)

- **Groups**: create (name + icon), invite by link or WhatsApp (`shared/join.php?code=...`; joining needs sign-in). Owner can rename, remove members, make a new invite link, delete the group; members can leave. A member **cannot leave or be removed while they still owe or are owed money**.
- **Shared expenses** (`shared/add.php`): who paid, who shares, split **equal / exact amounts / percent / shares**; the cents always add up to the total. A form token (`shared_token_*`) ignores a double submit. Optional **Add my share to Personal expenses** writes only your share into your Personal expenses.
- **Edit/delete**: the person who added it or the group owner (`shared_can_edit`).
- **Balances** (`shared/group.php`, `shared/index.php`): person-by-person "who pays whom" per currency, never netted across different people. Per member: paid, share, paid back, so a balance can be checked by hand.
- **Settle** (`shared/settle.php`): record a payment; deleting a settlement is possible for its author/owner.
- **Pure maths files** (easy to test, no database): `modules/shared/split.php` (equal/exact/percent/shares to the cent) and `modules/shared/balance.php` (who owes whom).
- **Account deletion**: `shared_on_user_deleted()` leaves groups, hands over or deletes owned groups, writes off debts and calls the friends/activity/support hooks.

## Friends (`modules/friends`)

- Everyone who shares a group with you appears automatically with what they owe you / you owe them.
- **Add a friend by e-mail**: sends `sendFriendRequestMail`; the page's answer never reveals whether the address has an account. The other person accepts or declines. Table `friend_links`.
- Search bar filters the list. Open a friend to see shared groups, settle in one tap and the combined balance.

## Activity (`modules/activity`)

Feed of everything in your groups (Today / Yesterday / Earlier, filter by group) and a **notification bell** with an unread count in the top bar (`activity_unread_count`). Support replies add to the same count. Other modules only call the guarded helper `shared_emit()`; switch the module off in its manifest and Shared still works.

## Comments and Receipts

- **Comments**: 500 characters, duplicates ignored, members notified through Activity; delete your own, owner deletes any.
- **Receipts**: JPG/PNG/WebP up to 3 MB, **re-drawn as a JPEG (max 1600 px)** to remove metadata and hidden payloads, stored under `storage/receipts/` with a random name, served by `receipts/view.php` only to group members.
- Both clean up when an expense, group or user is deleted (`*_on_expense_deleted`, `*_on_group_deleted`, `*_on_user_deleted`).

## Support (`modules/support`)

Users open a ticket from Profile (subject, category, message) and read replies there; staff with the `support` permission get an inbox to reply, close, reopen. Staff replies show as "ExpenseFlow support" and trigger `sendSupportReplyMail`. The public `contact.php` page has no form: it explains how to reach support (sign in, then Profile & Settings > Help & support).

## Admin and staff modules

**Roles:** `super` (everything), `admin`, `support`, `user`. A Super Admin edits what Admin and Support may do in **Admin > Roles & permissions** (stored in `admin_perms`, defaults in `ADMIN_DEFAULTS`). Admin and Support can only manage plain users, never other staff.

| Menu item | File | Permission key |
| --- | --- | --- |
| Dashboard | `admin/index.php` | none (any staff) |
| Users (search, filter, block, temp password, role, delete, export CSV/Excel/PDF) | `admin/users.php`, `view-user.php`, `edit-user.php`, `user-add.php`, `user-action.php`, `user-export.php` | `view`, `add`, `block`, `reset`, `del`, `export` |
| Roles & permissions | `admin/roles.php` | Super Admin only |
| Support inbox | `admin/support.php`, `ticket.php` | `support` |
| Announcements | `admin/announcements.php` | `bc` |
| Categories | `admin/categories.php` | `cat` |
| Currencies | `admin/currencies.php` | `cur` |
| Groups | `admin/groups.php` | `grp` (counts only; staff never see group expenses) |
| Audit log | `admin/audit.php` | `log` |
| User logs | `admin/logs.php` | `log` |
| Security (failed logins, IP blocks) | `admin/security.php` | `log` |
| Backups | `admin/backups.php` | `backup` |
| Settings (maintenance, sign-ups) | `admin/settings.php` | `set` |

Rules every admin page follows: sign-in + role check at the top (`admin/auth.php`), permission check with `admin_can()`, POST + CSRF for actions, `admin_audit()` for every change, and the menu hides what the role cannot use (but the server check is what really protects). Maintenance mode lets only signed-in staff through.

## Sign-up and sign-in (not a module menu, but central)

- `auth/register.php`: name, e-mail, password policy, Terms/Privacy tick box, rate-limited (10/hour/IP). Sends a 6-digit code (`sendVerifyMail`); `auth/verify-email.php` creates the account. Throw-away e-mail domains are refused.
- `auth/login.php` (also offers Continue with Google, below): rate limits per e-mail+IP (5 per 15 min), per e-mail (20), per IP (100); failures go to `security_events`; blocked users and blocked IPs are refused.
- `auth/forgot-password.php` -> `send-otp.php` (5/hour/e-mail) -> `verify-otp.php` -> `set-new-password.php`; 6-digit code valid 10 minutes.
- Sessions: 3-day idle timeout, 30-day cookie lifetime, regenerated ID after login.

## Continue with Google (`modules/oauth`)

- Button on `auth/login.php` and `auth/register.php` (`google_button()`), shown **only when** a Google client id + secret are configured (`config/google.php`) and the visitor is not inside the Android/Windows shell (Google refuses sign-in from embedded web views, so those users keep e-mail + password).
- Flow: `auth/google.php` stores a random `state` and a PKCE verifier in the session and redirects to Google (scope `openid email profile`, `prompt=select_account`); `auth/google-callback.php` checks the single-use state (10 min), exchanges the code on the server, checks the claims (`iss`, `aud`, `exp`, `sub`, and **`email_verified` must be true**), then `google_user_for_claims()`:
  1. known Google identity -> that account;
  2. else an account with the same e-mail -> linked to it (the person proved the address);
  3. else a new account (only if sign-ups are open) with a random password hash nobody knows; Terms acceptance is logged;
  4. blocked accounts and blocked IPs are refused; rate limits per IP.
- Table `user_identities` (`provider`, `subject` unique, cascades with the user). Google-created users can set a real password with **Forgot password** (hints on Profile and Delete account pages).
- Setup steps: [`OPERATIONS.md`](OPERATIONS.md) "Google sign-in setup". Tests: `tests/google_signin_test.php`, `tests/google_http_test.py`.

## Adding a new module (recipe)

1. `modules/<name>/module.php` with `id`, `mode`, `enabled`, `nav`, `order`.
2. `modules/<name>/lib.php` with `<name>_ensure_schema()` (use `ef_schema_ok`) and your functions; tables go in `schema.sql` too.
3. Root wrapper pages (`<name>/index.php` ...) that `require` `boot.php` or the standard header (security, session, db, layout), check sign-in, then `ef_page_start([...])` / `ef_page_end()`.
4. If other modules need to react (delete cleanup, notifications), expose `<name>_on_user_deleted()` etc. and call them only behind `function_exists()`.
5. Add tests in `tests/` (see [`TESTING.md`](TESTING.md)) and a line in `docs/ROADMAP.md`.
