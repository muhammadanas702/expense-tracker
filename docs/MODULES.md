# Modules and pages

Every feature is a **module**: a folder in `modules/<name>/` with a `lib.php` (logic) and a `module.php` (manifest). The web-facing pages that use a module sit in the repo root, because `modules/` is closed to browsers (`modules/.htaccess` denies everything; `router.php` mirrors the rules for local development).

> Back to [ARCHITECTURE](ARCHITECTURE.md). Tables are described in [DATABASE](DATABASE.md).

## 1. Rules every module follows

1. **Own your tables.** A module creates and queries only its own tables (listed below). It may *read* `users`.
2. **Self-healing schema.** `<module>_ensure_schema($conn)` runs `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN`, guarded by `ef_schema_ok()`.
3. **Best-effort extras.** Optional modules (activity, comments, receipts, announce, support) must never break the page that calls them: catch `Throwable`, `error_log`, carry on.
4. **Optional means optional.** Other code includes an optional module only with `if (is_file(...lib.php))` and checks the function exists. Setting `'enabled' => false` must not cause errors.
5. **Cleanup hooks.** When a user or a group is deleted, each module removes its own rows through hooks named `<module>_on_user_deleted()`, `<module>_on_group_deleted()` and `<module>_on_expense_deleted()` (for example `comments_on_group_deleted()`); the Shared module calls them when it deletes a group or user.
6. **Pages stay thin.** A page checks login/permission/CSRF, calls module functions, prints HTML with the shared layout. No SQL in templates beyond small read queries.

## 2. Manifest reference

```php
return [
  'id'      => 'friends',     // unique
  'mode'    => 's',           // 'p' Personal (teal), 's' Shared (blue), 'a' Admin
  'enabled' => true,          // false hides the module everywhere
  'order'   => 15,            // lower comes first; the first Shared item is the Shared home
  'nav'     => [              // [url, icon key from includes/icons.php, label, active key]
    ['friends/index.php', 'usr', 'Friends', 'fr'],
  ],
];
```

The `active` key is what a page passes as `'active' => 'fr'` to `ef_page_start()` to highlight its menu item. The key `sadd` is the "Add Shared" item and is hidden from the phone bottom bar (it becomes the floating + button).

## 3. Module catalogue

| Module | Mode / order | Owns tables | Purpose | Root pages |
| --- | --- | --- | --- | --- |
| `personal` | p | `income`, `expenses`, `recurring_items` | Payment methods, monthly repeating items, add/edit form (`tx-form.php`, default currency) | `dashboard.php`, `income/add-income.php`, `expense/add-expense.php`, `edit-*.php`, `delete-*.php`, `reports/view-report.php`, `stop-recurring.php`, `change-base-currency.php`, `reset-data.php` |
| `profile` | p | `user_settings` | Name, password, savings goal, notification switch, first-run setup | `profile.php`, `modules/profile/setup.php` |
| `shared` | s / 10 | `shared_groups`, `shared_members`, `shared_expenses`, `shared_splits`, `shared_settlements` | Groups, invite links, split maths (`split.php`), balances (`balance.php`), settle up | `shared/index.php`, `groups.php`, `group.php`, `add.php`, `join.php`, `settle.php`, `action.php` |
| `friends` | s / 15 | `friend_links` | People you share groups with, balance per person, add friend by e-mail, accept/decline | `friends/index.php`, `view.php`, `action.php` |
| `activity` | s / 20 | `activity_events`, `activity_notifications` | Feed ("Ali added Dinner"), notification bell, unread count | `activity/index.php`, `notifications.php` |
| `comments` | s / 60 | `comments_items` | Comments (max 500 chars) on shared expenses | `comments/action.php` |
| `receipts` | s / 70 | `receipts_files` (+ `storage/receipts/`) | One re-encoded JPEG per shared expense, served only to group members | `receipts/view.php` |
| `signup` | p / 96 | none (session only) | 6-digit e-mail code before an account exists; the pending signup lives in the visitor's session | `auth/register.php`, `auth/verify-email.php` |
| `support` | p / 90 | `support_tickets`, `support_messages` | Help requests from the user; staff inbox and replies. Status: open, pending, closed | `support/index.php`, `ticket.php`, `action.php`, `admin/support.php`, `admin/ticket.php` |
| `announce` | p / 90 | `announcements`, `announcement_dismissed` | Banner messages with start/end time, closable per user | `announce/dismiss.php`, `admin/announcements.php` |
| `categories` | p / 91 | `app_categories` | Admin-managed expense categories. Expenses store the category *name*, so edits never rewrite history; "Other" always exists | `admin/categories.php` |
| `currencies` | p / 92 | `app_currency_hidden` | Hide currencies from pick lists for new entries (PKR and USD cannot be hidden) | `admin/currencies.php` |
| `groupsadmin` | p / 93 | none | Staff view of shared groups: counts only, plus delete through `shared_delete_group()` | `admin/groups.php` |
| `security` | p / 94 | `security_events`, `ip_blocks` | Failed sign-in log, lock-outs, IP blocks. Fails open so bookkeeping can never lock real users out | `admin/security.php` |
| `backup` | p / 94 | none (writes `storage/backups/*.sql`) | Pure-PHP SQL dump, no `mysqldump` needed; files leave only through `admin/backup-action.php` | `admin/backups.php` |
| `settings` | p / 95 | `app_settings` | Key/value system settings: close sign-ups, maintenance mode, message | `admin/settings.php` |
| `admin` | a / 90 | `admin_perms` | Roles, permission matrix, audit trail helpers, admin layout | `admin/*.php` |

Order numbers above 89 are staff-side modules with an empty `nav`; they exist for their libs and manifests.

## 4. Admin panel

Roles: `super` (everything, cannot be restricted), `admin`, `support`, `user`. Staff = first three. `users.is_admin = 1` for every staff member for backwards compatibility, but **always check the role and permission** with the helpers in `modules/admin/lib.php`.

Permissions a Super Admin can grant to Admin/Support (`ADMIN_PERMS`): `view`, `add`, `block`, `reset`, `del`, `export`, `support`, `bc` (announcements), `cat`, `cur`, `grp`, `log` (audit), `backup`, `set` (settings). Stored in `admin_perms` (role, perm, allowed).

Rules enforced on the server for every action file (`admin/*-action.php`): login → staff → permission → CSRF → "Admin and Support may only manage plain users" → write an `admin_*` row to `user_logs` (the **Audit log**) via `logAction()`. Staff never see shared expenses, only counts.

Pages: `index` (overview), `users`, `view-user`, `edit-user`, `user-add`, `user-export`, `delete-user`, `roles`, `audit`, `logs` (what users did), `support`, `ticket`, `announcements`, `categories`, `currencies`, `groups`, `security`, `backups`, `settings`. The menu is generated by `modules/admin/layout.php` and shows only what the role may use; on phones it adds a **More** page.

## 5. How to add a new module (checklist)

1. Create `modules/<id>/module.php` (manifest) and `lib.php`.
2. In `lib.php` write `<id>_ensure_schema()` using `ef_schema_ok(__FUNCTION__, __FILE__)`, and add the new table to `schema.sql` too (fresh installs).
3. Add public pages in a root folder `<id>/` that: `require` `includes/security.php`, call `secure_session_start()`, load `config/db.php`, check login, call `ef_page_start(['mode' => ..., 'active' => ..., 'title' => ..., 'user' => ...])`.
4. Every POST: `require_post_csrf()`, validate input (`clean_text`, `parse_amount`, `valid_date`), prepared statements, `logAction()` for important events.
5. Add `<?= csrf_field() ?>` to forms, `e()` to every printed value.
6. If a user, group or expense can be deleted, add the matching `<id>_on_*_deleted()` hook and call it from the delete path.
7. Add `robots.txt` Disallow for private folders; keep public SEO pages separate.
8. Write a test in `tests/` (see [DEVELOPMENT](DEVELOPMENT.md)) and add a line to `docs/ROADMAP.md`.
9. Update this file and [DATABASE](DATABASE.md).

## 6. Public and auth pages

| Page | Layout | Notes |
| --- | --- | --- |
| `index.php` | public layout + `assets/landing.css` | Landing: hero, feature grid, download section (APK / EXE / Store), rich footer |
| `about.php`, `privacy.php`, `terms.php`, `contact.php` | public layout | Needed for trust and SEO; linked from every auth page |
| `guides/*.php` | public layout | SEO articles in English and Roman Urdu; listed in `sitemap.xml` |
| `auth/login.php`, `register.php`, `forgot-password.php`, `verify-otp.php`, `set-new-password.php` | auth layout | Split screen. Login/register carry a short "what is ExpenseFlow / never asks for bank details" block and policy links (helps Google Safe Browsing trust) |
| `auth/logout.php`, `auth/delete-account.php` | none | POST + CSRF |
| `offline.html`, `sw.js`, `manifest.json` | n/a | PWA |
| `.well-known/security.txt` | n/a | Security contact; allowed through `router.php` |
