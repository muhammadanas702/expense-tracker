# ExpenseFlow architecture

How the code is organised and why. Read this first, then [`THEME.md`](THEME.md) (looks), [`DATABASE.md`](DATABASE.md) (data), [`MODULES.md`](MODULES.md) (features), [`SECURITY.md`](SECURITY.md) (rules you must not break).

## 1. The big picture

ExpenseFlow is a **server-rendered PHP 8 + MySQL web app**. There is no framework, no build step for the website and no JavaScript framework. Each URL is a PHP file that checks the session, talks to the database through PDO, and prints HTML. The same website is wrapped as an Android app and a Windows app (thin shells that just open the live site).

```
                       ┌──────────────────────────────────────────┐
  Browser / PWA ──────►│  expenseflow.rf.gd  (InfinityFree, Apache)│
  Android WebView ────►│  PHP 8 pages  ──► MySQL (PDO)             │
  Windows Electron ───►│               ──► storage/ (private files)│
                       │               ──► SMTP (PHPMailer, Gmail) │
                       │               ──► exchangerate-api (rates)│
                       └──────────────────────────────────────────┘
        GitHub (main) ──push──► GitHub Actions ──FTPS──► hosting
        GitHub tag/dispatch ──► Actions ──► APK + EXE + MSIX in Releases
```

| Concern | Choice | Why |
| --- | --- | --- |
| Language | PHP 8.1+ (no framework) | Runs on free shared hosting; simple to deploy by FTP |
| Database | MySQL/MariaDB via PDO, prepared statements only | Free hosting provides it |
| Front end | Hand-written CSS (`assets/app.css`), small vanilla JS files, Chart.js (self-hosted in `assets/lib/`) | No build tooling, fast, offline-friendly |
| Mail | PHPMailer over SMTP |
| Sign-in | e-mail + password, optional Google (OpenID Connect, PKCE) | Sign-up codes, password reset, friend requests, support replies |
| Spreadsheet/PDF export | PhpSpreadsheet (Excel), TCPDF (PDF) | Branded exports, see section 8 |
| Hosting | InfinityFree free plan | Free; has quirks, see [`OPERATIONS.md`](OPERATIONS.md) |
| Installable | PWA (`manifest.json`, `sw.js`), Android WebView shell, Electron shell, Microsoft Store MSIX | One codebase, many surfaces |

## 2. Folder map

```
.
├── index.php                  Landing page (public)
├── about.php privacy.php terms.php contact.php   Public information pages
├── guides/                    SEO guide articles (public)
├── auth/                      Register, verify e-mail, login, Google sign-in (google.php, google-callback.php), logout, forgot/reset password, delete account
├── dashboard.php              Personal dashboard
├── expense/ income/           "Add" pages (thin wrappers, real code in modules/personal)
├── edit-*.php delete-*.php change-base-currency.php reset-data.php stop-recurring.php   Personal actions
├── reports/view-report.php    Full report + CSV/Excel/PDF export
├── profile.php                Profile and settings (neutral page)
├── shared/                    Shared mode: groups, add expense, settle, join by invite
├── friends/ activity/ comments/ receipts/ support/ announce/   Public entry points of those modules
├── admin/                     Admin panel pages (staff only)
├── modules/                   ★ The feature code (see section 4). modules/ is deny-all from the web
├── includes/                  Shared building blocks (layouts, security, schema helper, icons, export, currency)
├── config/                    app.php (base URL), db.php, mail.php, google.php (+ private *.local.php, never committed)
├── assets/                    app.css, admin.css, JS, icons, lib/chart.umd.min.js
├── storage/                   Runtime data: sessions, schema markers, backups, receipts, rate cache (never public)
├── database/                  part-N.sql scripts (historic manual upgrades; the app self-heals, see DATABASE.md)
├── schema.sql                 Full schema for a fresh install
├── tests/                     PHP and Python HTTP tests (test database only)
├── apps/android, apps/desktop Native shells
├── .github/workflows/         deploy.yml (site) and release.yml (apps)
├── sw.js manifest.json offline.html   PWA files
├── router.php Procfile Dockerfile     Local dev server / container hosting helpers
├── vendor/ tcpdf/             Third-party libraries (Composer + bundled TCPDF)
└── docs/                      You are here
```

Deploy-time rules: `.md`, `.sql`, `.yml`, `.log`, tests and `docs/` are never served; `.htaccess` (Apache) and `router.php` (PHP built-in server) both block them.

## 3. Request life-cycle (every signed-in page)

1. `require includes/security.php` then `secure_session_start()`: hardened cookie, 3-day idle timeout, security headers.
2. `require config/db.php` creates `$conn` (PDO). It reads `config/db.local.php` (private) or `EXPENSEFLOW_DB_*` env vars. `config/app.php` decides `$base_url`.
3. The page checks `$_SESSION['user_id']`; if missing it redirects to `auth/login.php`. It loads the user row; if the row is gone (deleted account) the session is destroyed.
4. Self-healing schema: the module's `*_ensure_schema($conn)` creates missing tables/columns (section 6).
5. POST actions call `require_post_csrf()` first, validate and clean every input (`clean_text`, `parse_amount`, `valid_date`, `normalize_currency`), run prepared statements, call `logAction()`, then redirect (Post/Redirect/Get).
6. The page prints its shell with `ef_page_start([...])`, then its content, then `ef_page_end()`.

Shared pages use one boot file (`modules/shared/boot.php`) that does steps 1-4 for them; `friends`, `activity` and `support` have their own `boot.php` with the same idea.

## 4. Modules (how features are plugged in)

Every feature lives in `modules/<name>/` and describes itself in `module.php`:

```php
return [
  'id' => 'personal', 'mode' => 'p',   // p = Personal (teal), s = Shared (blue)
  'enabled' => true,                    // false = vanishes everywhere
  'nav' => [['dashboard.php','home','Dashboard','dash'], ...],   // url, icon, label, active-key
  'order' => 50,
];
```

`includes/modules.php` globs all manifests; the layout builds the sidebar and phone bottom bar **only** from them. Disabling or adding a module never edits another module. Optional modules (activity, comments, receipts) are called through guarded helpers (`isset(ef_modules()['activity'])`), so Shared works without them. Full catalogue: [`MODULES.md`](MODULES.md).

**Important:** `modules/` is denied from the web. A page that must be reachable by URL needs a thin **root wrapper** (for example `expense/add-expense.php` is two lines calling `personal_tx_page('expense')`). If you add an endpoint inside `modules/` it will return 403.

## 5. Two modes: Personal and Shared

| | Personal | Shared |
| --- | --- | --- |
| Colour | teal | blue |
| What | your own income, expenses, reports, recurring items | groups, split bills, balances, friends, activity |
| Money storage | `DECIMAL(15,2)` amount + currency + `amount_pkr` | **integer minor units** (`amount_minor`, paisa/cents) to avoid rounding drift |
| Session flag | `$_SESSION['ef_mode'] = 'p'` | `'s'` |

A shared expense can optionally write **your share only** into your Personal expenses (`shared_personal_copy`). Deleting the shared expense removes that copy. Balances are computed person-by-person per currency; two people only cancel each other out.

## 6. Self-healing database schema

There is no migration runner. Each module has `<name>_ensure_schema()` which runs `CREATE TABLE IF NOT EXISTS` and adds missing columns. To avoid doing this on every request, `ef_schema_ok(__FUNCTION__, __FILE__)` (in `includes/schema.php`) writes a marker file in `storage/schema/` keyed by function and file modification time. When the file changes (new deploy) the check runs once again.

Consequences you must remember:
- Add a new table/column by editing the module's `ensure_schema` **and** `schema.sql` (for fresh installs). No SQL has to be run by hand on production.
- After wiping/resetting a database locally, **delete `storage/schema/`**, otherwise the markers say "done" and tables are not recreated.
- `database/part-*.sql` are historic manual scripts (up to Part 11f); do not rely on them or add new ones.

## 7. Cross-cutting building blocks (`includes/`)

| File | Gives you |
| --- | --- |
| `security.php` | `e()` escape, `client_ip()`, `send_security_headers()`, `secure_session_start()`, CSRF (`csrf_field`, `require_post_csrf`), `password_policy_error`, input cleaners, rate limiter (`rl_key`, `rl_blocked`, `rl_hit`, `rl_clear`) |
| `layout.php` | `ef_page_start/ef_page_end` (signed-in shell, notification bell, mode switch) |
| `auth-layout.php` | `ef_auth_start/ef_auth_end`, `ef_field`, `ef_msg`, `ef_check`, brand panel + trust footer |
| `public-layout.php` | `ef_public_start/end` for About/Privacy/Terms/Guides |
| `assets.php` | `ef_url()`, `ef_asset()` (adds `?v=<mtime>` so deploys refresh caches) |
| `icons.php` | `ef_icon('name')` SVG icon set |
| `favicons.php` | the single place that prints favicon links |
| `currencies.php`, `CurrencyConverter.php` | 40 supported currencies, select options, live/fallback rates (cached in `storage/currency_cache.json` for 1 hour) |
| `schema.php` | `schema_has()`, `ef_schema_ok()`, `ensure_schema()` for core tables |
| `logging.php` | `logAction($conn, $userId, $action, $details)` writes to `user_logs` with IP |
| `export.php` | branded Excel/PDF engine (section 8) |
| `pwa-head.php` | manifest/theme-colour tags |

## 8. Exports (Excel, PDF, CSV)

All downloads share one neutral **report model** and two renderers so every file looks the same (logo, name, who/what/when, summary tiles, banded tables):

```php
$report = ['title'=>..., 'subtitle'=>..., 'meta'=>[[label,value]],
           'summary'=>[[label,value,tone,unit,numfmt]],
           'sections'=>[['name'=>..., 'columns'=>[[label,type,weight]], 'rows'=>[...], 'footer'=>[...], 'tone'=>fn]],
           'note'=>...];
ef_export_xlsx($report);  // PhpSpreadsheet, returns binary string
ef_export_pdf($report);   // TCPDF with DejaVu Sans (supports Rs, symbols, Urdu names)
```

Wrap the build in `ef_export_try(fn() => ...)` (catches errors and raises limits), send with `ef_export_send()`. Cells are written as **text when they start with `= + - @`** so a malicious title cannot become an Excel formula. Callers: `reports/view-report.php` (Full Report: Excel/PDF) and `admin/user-export.php` (user list, linked from `admin/users.php`). Sends proper `Content-Disposition` so the Android/Windows shells download instead of showing HTML.

## 9. Admin panel

`admin/*.php` pages wrap `modules/admin/layout.php` (same glass shell + staff menu). Roles: **super**, **admin**, **support**, **user**. A Super Admin edits a matrix of 14 permissions for Admin and Support (`ADMIN_PERMS` in `modules/admin/lib.php`); every page calls `admin_can($conn, $role, 'perm')` on the server, and the menu hides what the role cannot use. All staff actions go to the audit log; normal user activity goes to `user_logs`. Details in [`MODULES.md`](MODULES.md).

## 10. Frontend behaviour

- **No framework.** JS files: `assets/fx.js` (landing animations, scroll reveal), `auth-ui.js` (password toggle, strength meter), `client-time.js` (gives the browser's local date/time to forms and the activity log), `notif-pop.js` (bell pop-up), `pwa-register.js` (service worker). Chart.js is self-hosted in `assets/lib/` (the router blocks `vendor/` paths, so never place browser assets under `vendor/`).
- **Service worker (`sw.js`)**: caches the offline page and icons (`expenseflow-static-v6`), keeps signed-in pages for offline viewing (`expenseflow-pages-v1`, wiped on logout), shows `offline.html` when the network is gone. Bump `CACHE_NAME` when you change the pre-cached list.
- **No-JS safe:** the landing page's reveal animation only hides content when JS has run.

## 11. Native apps

Both shells just open `https://expenseflow.rf.gd/auth/login.php` full screen and keep the login cookie; all logic stays on the server, so a website deploy updates the apps instantly. Only changes to the shell itself (icon, download handling, window menu) need a new release. See [`APPS.md`](APPS.md).

## 12. Conventions

- File names: lower-case with dashes for pages, `lib.php` for module logic, `module.php` manifest.
- Functions are prefixed by area: `shared_*`, `friends_*`, `admin_*`, `personal_*`, `activity_*`, `ef_*` (framework-level helpers).
- Output: always `e($x)`; URLs: always `ef_url('path')`; assets: `ef_asset('assets/x.css')`.
- SQL: only prepared statements with `?` placeholders. Money in Shared: integers.
- Dates: stored in server time zone `Asia/Karachi` (set in `config/app.php`, override with `EXPENSEFLOW_TIMEZONE`).
- Anything that changes data: POST + CSRF + `logAction()`; deletes are never GET links.
- Comments explain *why*, not *what*.

## 13. Where to look for things (cheat sheet)

| I want to... | Open |
| --- | --- |
| change colours or spacing | `assets/app.css` (tokens at the top) |
| add a sidebar item | the module's `module.php` |
| add a page under a module | a root wrapper + `modules/<m>/lib.php` function |
| add a column/table | module `ensure_schema` + `schema.sql` |
| change an e-mail | `config/mail.php` (`ef_mail_html` and the `send*Mail` functions) |
| change a PDF/Excel look | `includes/export.php` |
| change who can do what in admin | `modules/admin/lib.php` (`ADMIN_PERMS`, `ADMIN_DEFAULTS`) |
| change the list of currencies | `includes/currencies.php` |
| change app icon / logo | see `THEME.md` section 8 |
| release the Android/Windows app | `docs/DEPLOYMENT.md` |
