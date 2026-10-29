# Architecture

How ExpenseFlow is put together, and why. Read this first if you are new to the project or coming back after a long break.

> Related: [MODULES](MODULES.md) · [DATABASE](DATABASE.md) · [THEME](THEME.md) · [SECURITY](SECURITY.md) · [DEVELOPMENT](DEVELOPMENT.md) · [DEPLOYMENT](DEPLOYMENT.md)

## 1. The idea in one minute

ExpenseFlow is a **server-rendered PHP 8 + MySQL web app with no framework**. Every page is a plain `.php` file that loads a few shared includes, checks the session, runs some PDO queries and prints HTML. There is no build step for the website: edit a file, reload the browser.

The same website is shipped in four ways:

| Channel | What it is | Source |
| --- | --- | --- |
| Website / PWA | The PHP site itself, installable from the browser | repo root |
| Android app | A thin WebView shell that opens the live site | `apps/android/` |
| Windows app | An Electron shell that opens the live site (NSIS `.exe` and Microsoft Store `.msix`) | `apps/desktop/` |
| iOS | Not built yet (use Safari, Add to Home Screen) | n/a |

The Android and Windows apps contain **no business logic**. They load `https://expenseflow.rf.gd/auth/login.php`. So a website deploy updates every platform at once; a new app release is only needed for shell changes (icon, download handling, updater, store metadata).

## 2. Two "modes"

The product has two sides, switched with a segmented control in the top bar:

| Mode | `data-mode` | Colour | What it covers |
| --- | --- | --- | --- |
| **Personal** (`p`) | none (default) | teal | Own income, expenses, dashboard, reports, categories, recurring items |
| **Shared** (`s`) | `s` | blue | Groups, split bills, balances, settlements, friends, activity feed, comments, receipts |

"Neutral" pages (Profile, Notifications, Help) belong to neither; they keep whichever side the user was last on (`$_SESSION['ef_mode']`). The mode only changes the CSS variables (see [THEME](THEME.md)) and which sidebar items show.

## 3. Folder map

```
.
├── index.php, about.php, privacy.php, terms.php, contact.php   Public pages (landing + information)
├── guides/                    Public SEO guides
├── auth/                      login, register (+ e-mail OTP), forgot/reset password, logout, delete account
│   └── src/                   Bundled PHPMailer (blocked from the web)
├── dashboard.php, profile.php, edit-*.php, delete-*.php,
│   change-base-currency.php, reset-data.php, stop-recurring.php   Personal-mode pages
├── expense/, income/          Add-entry forms
├── reports/                   Report view + Excel/PDF/CSV export
├── shared/, friends/, activity/, comments/, receipts/,
│   announce/, support/        Shared-mode and cross-cutting pages (thin wrappers over modules/)
├── admin/                     Admin panel (role based)
├── modules/                   One folder per feature: lib.php (logic) + module.php (manifest). NOT web-served.
├── includes/                  Shared PHP: security, layouts, icons, schema helpers, export, currency converter
├── config/                    app.php (base URL), db.php, mail.php (+ git-ignored *.local.php)
├── assets/                    app.css (the real stylesheet), JS, icons, local Chart.js (assets/lib/)
├── database/part-*.sql        Optional manual SQL per development part
├── schema.sql                 Fresh-install schema
├── storage/                   Runtime data: sessions, schema markers, backups, receipts, rate cache (blocked)
├── tests/                     PHP + Python HTTP tests (never deployed)
├── docs/                      This documentation (never deployed)
├── apps/android, apps/desktop Native shells
├── sw.js, manifest.json, offline.html   PWA files
├── router.php                 Dev server router (acts like .htaccess for `php -S`)
└── .github/workflows/         deploy.yml (website), release.yml (APK / EXE / MSIX)
```

`tcpdf/` and `vendor/` are third-party libraries (TCPDF, PhpSpreadsheet and dependencies). Do not edit them.

## 4. Anatomy of a request

A signed-in page such as `dashboard.php` always follows this order:

1. `require includes/security.php` and call **`secure_session_start()`**: hardened cookie, idle timeout, security headers.
2. `require config/db.php`: creates the PDO connection `$conn`, runs `ensure_schema()` (self-healing, see section 6) and loads `config/app.php` (`$base_url`).
3. Redirect to `auth/login.php` if `$_SESSION['user_id']` is missing; load the user row; if the row is gone (admin deleted it) destroy the session.
4. Page logic: prepared statements only; POST handlers call `require_post_csrf()` first.
5. Output through **`ef_page_start([...])` ... `ef_page_end()`** from `includes/layout.php`. It prints `<head>`, sidebar, top bar, notification bell, bottom bar on phones, and loads `assets/app.css`.

Other layouts:

| Layout file | Used by | Entry points |
| --- | --- | --- |
| `includes/layout.php` | signed-in app | `ef_page_start()` / `ef_page_end()` |
| `includes/auth-layout.php` | login, register, reset | `ef_auth_start()` / `ef_auth_end()` (split brand panel + form card) |
| `includes/public-layout.php` | landing, about, privacy, terms, contact, guides | `ef_public_start()` / `ef_public_end()` (adds SEO meta, JSON-LD, footer) |
| `modules/admin/layout.php` | admin panel | wraps `ef_page_start` with the admin menu |

Helpers every page can rely on: `e()` (HTML escape), `ef_url()`, `ef_asset()` (adds `?v=<mtime>` so a deploy busts the browser cache), `ef_icon()` (inline SVG sprite from `includes/icons.php`), `csrf_field()`, `logAction()`.

## 5. The module system

Each feature lives in `modules/<name>/` and describes itself in `module.php`:

```php
return [
  'id' => 'friends', 'mode' => 's', 'enabled' => true, 'order' => 15,
  'nav' => [['friends/index.php', 'usr', 'Friends', 'fr']],   // url, icon, label, active key
];
```

`includes/modules.php` globs `modules/*/module.php`, keeps the enabled ones, sorts by `order`, and the layout builds the sidebar and bottom bar only from those manifests. Setting `'enabled' => false` hides a feature everywhere without touching another module. Details and the dependency rules are in [MODULES](MODULES.md).

Because `modules/` is **deny-all** on the web server, public pages live in the repo root (for example `friends/index.php`) and `require` the logic from `modules/friends/lib.php`.

## 6. Self-healing database schema

There are no migration tools. Instead:

- `schema.sql` creates a fresh database.
- Every module has an `*_ensure_schema()` function that runs `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE ... ADD COLUMN` when it is needed. This is how the live site upgrades itself after a deploy.
- Because those checks cost round trips, `ef_schema_ok($name, $file)` writes a marker file `storage/schema/<fn>-<filemtime>.ok` after a clean run. A deploy changes the file time, so each check runs exactly once per deploy.
- **After wiping the database (tests, local reset) also delete `storage/schema/`**, otherwise tables are assumed to exist.

Never write a destructive change in an ensure function. Only add.

## 7. Money and currencies

- 40 currencies, defined in `includes/currencies.php`. Admin can hide some (`app_currency_hidden`).
- Rates come from exchangerate-api.com, cached one hour in `storage/currency_cache.json`; built-in fallback rates cover the main currencies if the service is down (`includes/CurrencyConverter.php`).
- Amounts are stored **in the currency they were entered in**, together with a converted value at the time of entry (`amount_pkr`), so history does not change when rates move.
- Each user has a **base currency per month** (`user_monthly_currency`); a default currency is a profile setting.
- Shared groups keep their own currency per group; balances are computed in `modules/shared/balance.php`, splitting in `split.php`.

## 8. Export pipeline

All downloads (Excel, PDF, CSV) go through one report model in `includes/export.php`:

```
report = { title, subtitle, meta, summary[[label,value,tone,unit,numfmt]],
           sections[{name, columns[[label,type,weight]], rows, footer, tone}], note }
```

`ef_export_xlsx($report)` (PhpSpreadsheet) and `ef_export_pdf($report)` (TCPDF, DejaVu Sans) render it with the ExpenseFlow logo and brand colours. Cells that start with `= + - @` are neutralised to stop spreadsheet formula injection. Callers today: `reports/view-report.php` and `admin/user-export.php`. If you add an export, build a report array and reuse these functions, never hand-write a new file format.

## 9. Security in the architecture

Short version (full detail in [SECURITY](SECURITY.md)): prepared statements everywhere, CSRF token on every POST, hashed passwords with a policy, e-mail OTP at signup, rate limits stored in `rate_limit_hits`, role/permission checks on the server for every admin page, blocked accounts, IP blocks, audit log, security headers, deny-all folders and no secrets in Git.

## 10. Front end

- Plain HTML, one stylesheet (`assets/app.css`), small vanilla JS files, Chart.js self-hosted in `assets/lib/`.
- Icons are an inline SVG sprite (`ef_icon('name')`), not an icon font.
- PWA: `manifest.json`, `sw.js` (cache version constant `CACHE_NAME`; bump it when changing cached static files), `offline.html`.
- Design language, tokens and components: [THEME](THEME.md).

## 11. Native shells

- **Android** (`apps/android`): Java `MainActivity` hosting a WebView on the live site, handles file downloads through `DownloadManager` (forwards cookies, user agent and referer so exports are authorised). Package id `pk.expenseflow.app`, signed with `sideload.jks`.
- **Windows** (`apps/desktop`): Electron 33, `main.js` opens the login URL, sends links to other hosts to the default browser, shows an offline page, skips the auto-updater inside the Microsoft Store build (`process.windowsStore`). Built by electron-builder as NSIS installer and as an MSIX with the Store identity.
- Both are built by `.github/workflows/release.yml`. See [DEPLOYMENT](DEPLOYMENT.md).

## 12. Why these choices

| Decision | Reason |
| --- | --- |
| No framework | Runs on free shared hosting (InfinityFree), easy for a student to read and debug |
| Server-rendered pages | Works on weak phones, no build tooling, SEO-friendly public pages |
| Modules with manifests | A feature can be added, hidden or removed without editing the rest |
| Self-healing schema | Deploys are a plain FTP upload; no SSH to run migrations |
| WebView/Electron shells | One codebase for web, Android and Windows |
| Inline SVG icons, self-hosted Chart.js | No third-party requests, faster, works offline |
