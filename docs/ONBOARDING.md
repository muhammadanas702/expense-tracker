# Start here: ExpenseFlow in 30 minutes

For a new developer, or for you after a year away. Follow this page top to bottom, then use the other docs as reference.

## 1. What this project is

**ExpenseFlow** ("Track. Budget. Split.") is a free personal expense tracker and bill splitter by **Muhammad Anas** (UMT Lahore, Pakistan). Live at `https://expenseflow.rf.gd`. Two sides:

- **Personal** (teal): your income and expenses in 40 currencies, dashboard, reports, recurring items.
- **Shared** (blue): groups, split bills, friends, balances and settling up, activity, comments, receipts.
- Plus an **admin panel** (roles and permissions, support inbox, announcements, security, backups) and installable **apps** (PWA, Android, Windows, Microsoft Store).

Repository: `github.com/muhammadanas702/expense-tracker`. Hosting: InfinityFree free plan. Database: MySQL. Stack: plain PHP 8, no framework.

## 2. Reading order

1. This page.
2. [`ARCHITECTURE.md`](ARCHITECTURE.md): folders, request flow, modules, conventions (15 min).
3. [`THEME.md`](THEME.md): colours, fonts, components (before touching any UI).
4. [`MODULES.md`](MODULES.md) and [`DATABASE.md`](DATABASE.md): what each feature does and where its data lives.
5. [`SECURITY.md`](SECURITY.md): the checklist you must follow for every page.
6. [`TESTING.md`](TESTING.md), [`DEPLOYMENT.md`](DEPLOYMENT.md), [`OPERATIONS.md`](OPERATIONS.md), [`APPS.md`](APPS.md).
7. [`ROADMAP.md`](ROADMAP.md): history of every "Part" (what changed, when) and the ideas list.

## 3. Run it locally (10 minutes)

Requirements: PHP 8.1+ (pdo_mysql, openssl, mbstring, gd), MariaDB/MySQL, Python 3 (for tests), Git.

```bash
git clone https://github.com/muhammadanas702/expense-tracker.git && cd expense-tracker
mysql -uroot -e "CREATE DATABASE expenseflow_test CHARACTER SET utf8mb4" && mysql -uroot expenseflow_test < schema.sql
cat > config/db.local.php <<'PHP'
<?php
return ['local' => ['host'=>'127.0.0.1','dbname'=>'expenseflow_test','username'=>'root','password'=>'']];
PHP
composer install            # only if vendor/ is missing
php -S 127.0.0.1:8080 -t . router.php
```

Open `http://127.0.0.1:8080/`. Register an account (the 6-digit e-mail code needs SMTP; locally either set `config/mail.local.php` to a real SMTP account, or insert the user by SQL with a hash from `php -r 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'`). Make yourself Super Admin: `UPDATE users SET role='super', is_admin=1 WHERE email='you@example.com';` and open `/admin/`.

## 4. The five ideas that explain the whole codebase

1. **One PHP file per URL**, a small `require` header, then `ef_page_start()` ... `ef_page_end()` for the shell.
2. **Features are modules** (`modules/<name>/module.php` + `lib.php`); the menu is built from manifests; `modules/` is not web-accessible, so each public page is a thin wrapper in the root.
3. **The database fixes itself**: `*_ensure_schema()` creates missing tables/columns (markers in `storage/schema/`).
4. **Security helpers are mandatory**: `e()`, `csrf_field()`/`require_post_csrf()`, prepared statements, `rl_*` rate limits, ownership checks.
5. **One design system**: `assets/app.css` with a few colour tokens. Teal = Personal, blue = Shared, green = income, orange = expense.

## 5. Recipes

### Add a field to an expense
1. `schema.sql`: add the column; `modules/personal/lib.php` or `includes/schema.php`: add it to the ensure function with `schema_has()` + `ALTER TABLE`.
2. `modules/personal/tx-form.php`: add the input and validation; save it in the INSERT/UPDATE.
3. Show it in `dashboard.php` / `reports/view-report.php` / `includes/export.php` columns if needed.
4. Add a test line and a ROADMAP note.

### Add a new page to Shared
1. Create `shared/<page>.php` (copy `shared/groups.php` as a starting point: it requires `modules/shared/boot.php`).
2. Put logic in `modules/shared/lib.php` as `shared_<thing>()`.
3. Use `ef_page_start(['title'=>..., 'mode'=>'s', 'active'=>'key', 'user'=>$user])`.
4. POST actions go through `shared/action.php` style handlers: CSRF, membership check, `shared_emit()` for the activity feed.

### Add a sidebar item
Edit the module's `module.php` `nav` array: `[url, icon, label, active-key]`. The icon must exist in `includes/icons.php`.

### Add an admin page
1. `admin/<page>.php` with `admin/auth.php` first and `admin_can($conn,$role,'perm')`.
2. Add it to `admin_menu()` in `modules/admin/layout.php` with its permission key (add a key to `ADMIN_PERMS`/`ADMIN_DEFAULTS` if new).
3. Write `admin_audit()` for every change. Add an admin test.

### Add an export column or a new export
Build a `$report` array (see `includes/export.php` header comment) and call `ef_export_try(fn()=>ef_export_xlsx($report))` / `ef_export_pdf`. Never write a one-off Excel/PDF; the shared engine keeps the branding identical.

### Change the colours or look
Edit the tokens at the top of `assets/app.css` (see `THEME.md` section 2). Test phone and desktop screenshots.

### Add an e-mail
Add a `sendXxxMail()` in `config/mail.php` using `ef_mail_html()` and `ef_send_mail()`. Mail failures must never break the page: check the return value and show a friendly message.

### Add a currency
`includes/currencies.php` list + `app_fallback_rates()`.

### Add a public/SEO page
Use `ef_public_start/end` (see `guides/*.php`), add the URL to `sitemap.xml`, keep one `<h1>`, a canonical, and JSON-LD like the others (`tests/seo_http_test.py` checks this).

## 6. Working with Git (the way this project is run)

- `main` is production: every push deploys to the live site. Never commit to `main` directly.
- Work in small "Parts": branch `feature/part-N-short-name` from `origin/main` > build > test > push > Pull Request > merge > automatic deploy > (only if the shell changed) run the **Build apps & publish release** workflow with a new version.
- Describe each Part in `docs/ROADMAP.md`.
- Commit messages: a short imperative title, then a paragraph on why.

## 7. People and accounts to know

| Thing | Owner / where |
| --- | --- |
| Code, GitHub secrets, releases | Muhammad Anas (GitHub `muhammadanas702`) |
| Hosting panel, FTP, MySQL | InfinityFree account (same owner) |
| Microsoft Store listing | Partner Center, individual account "Muhammad_Anas" |
| Search Console | Google account of the owner (site + `google18b56792a4ec2f26.html`) |
| SMTP | Gmail account with app password stored in GitHub secrets |

## 8. Glossary

| Word | Meaning |
| --- | --- |
| Part N | one deliverable iteration (small enough to finish in one session) |
| Personal / Shared mode | teal / blue halves of the app (`$_SESSION['ef_mode']`, `data-mode`) |
| base currency | the currency a user chose to see their month in (`user_monthly_currency`) |
| minor units | integer cents/paisa used in Shared money columns (`amount_minor`) |
| ensure_schema | self-healing table/column creator per module |
| staff | users with role `super`, `admin` or `support` |
| wrapper | tiny public PHP file that calls code inside `modules/` |
| shell | the Android/Windows wrapper around the website |
| Store | Microsoft Store listing of the Windows app |
