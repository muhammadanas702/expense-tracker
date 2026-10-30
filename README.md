<div align="center">

<img src="assets/icons/favicon-192.png" width="84" alt="ExpenseFlow logo">

# ExpenseFlow

**Track. Budget. Split.** A free personal expense tracker and bill splitter: multi-currency, installable, with groups, friends and an admin panel.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-PDO-4479A1?logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.4-FF6384?logo=chartdotjs&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8?logo=pwa&logoColor=white)
![Android](https://img.shields.io/badge/Android-APK-3DDC84?logo=android&logoColor=white)
![Windows](https://img.shields.io/badge/Windows-exe%20%2B%20Store-0078D4?logo=windows&logoColor=white)
![CI/CD](https://img.shields.io/badge/CI%2FCD-GitHub%20Actions-2088FF?logo=githubactions&logoColor=white)

**[Live site: expenseflow.rf.gd](https://expenseflow.rf.gd)** · **[Documentation](docs/README.md)** · **[Latest release (APK / Windows)](https://github.com/muhammadanas702/expense-tracker/releases/latest)**

</div>

---

## Table of contents

- [What it is](#what-it-is)
- [Features](#features)
- [Tech stack](#tech-stack)
- [Documentation map](#documentation-map)
- [Quick start](#quick-start)
- [Project structure](#project-structure)
- [Look and feel](#look-and-feel)
- [Security in brief](#security-in-brief)
- [Apps and installing](#apps-and-installing)
- [Deployment](#deployment)
- [Status and roadmap](#status-and-roadmap)
- [Contributing](#contributing)
- [Author](#author)

## What it is

ExpenseFlow is a server-rendered **PHP 8 + MySQL** web app with no framework and no build step. It has two sides that share one account:

- **Personal** (teal): income and expenses in **40 currencies** with live exchange rates, a dashboard with insights and charts, reports, and monthly recurring items.
- **Shared** (blue): groups for trips, rent or family, bills split four ways, friends, balances person by person, settle up, activity feed, comments and receipt photos.

Around that: a role-based **admin panel**, branded **Excel / PDF / CSV exports**, e-mail confirmation at sign-up, and apps for **Android**, **Windows** and the **Microsoft Store**. It runs on free shared hosting.

## Features

### Personal
- Add, edit, delete **income** and **expenses** with title, amount, currency, category, payment method, note and date; **repeat monthly** switch.
- **Base currency per month**; every entry keeps the exchange rate it had when saved, so old totals never change.
- Dashboard: period chips (this month, last month, last 3 months, this year, custom), savings rate against your goal, insights (health, trend, top category, daily average), category and income/expense charts.
- **Full Report** with filters, monthly comparison, category breakdown and **CSV, Excel and PDF** downloads (branded with the ExpenseFlow logo).
- Profile: name, password, savings goal, notifications, reset data, delete account.

### Shared
- Groups with invite link or WhatsApp; owner tools; members can leave when settled.
- Shared expenses split **equally, by exact amounts, by percent or by shares**, always adding up to the last paisa; optional "add my share to Personal".
- **Friends** (everyone you share a group with, plus friends added by e-mail) with per-person balances; settle in one tap.
- Activity feed and notification bell, comments (500 chars), one receipt photo per expense (re-encoded for safety).

### Admin and support
- Roles **Super Admin / Admin / Support / User** with a permission matrix edited by the Super Admin.
- Users (search, filter, block, temporary password, roles, delete, export), support inbox, announcements, categories, currencies, shared groups (counts only), audit log, user logs, security (failed logins, IP blocks), backups, maintenance mode and sign-up switch.

### Platform
- PWA with offline page; Android app; Windows installer with auto-update; Microsoft Store package.
- Public pages for SEO: landing, About, Privacy, Terms, Contact and 8 guides (English and Roman Urdu), sitemap, robots, structured data.

## Tech stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.1+ (no framework) |
| Database | MySQL / MariaDB through PDO prepared statements |
| Frontend | Hand-written CSS design system (`assets/app.css`), vanilla JS, Chart.js 4 (self-hosted) |
| Mail | PHPMailer over SMTP |
| Exports | PhpSpreadsheet (Excel), TCPDF (PDF) |
| Rates | exchangerate-api.com with cache and fallback rates |
| Hosting / CI | InfinityFree, GitHub Actions (FTPS deploy, app builds) |
| Apps | Android WebView (Gradle), Electron + electron-builder (NSIS, MSIX) |

## Documentation map

New here? Read **[`docs/ONBOARDING.md`](docs/ONBOARDING.md)** first.

| Doc | Topic |
| --- | --- |
| [`docs/ONBOARDING.md`](docs/ONBOARDING.md) | what it is, run locally, key ideas, recipes, glossary |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | folders, request flow, modules, exports, conventions |
| [`docs/THEME.md`](docs/THEME.md) | colours, fonts, components, icons, logos, UI rules |
| [`docs/MODULES.md`](docs/MODULES.md) | every feature and admin page |
| [`docs/DATABASE.md`](docs/DATABASE.md) | tables, money rules, backups |
| [`docs/SECURITY.md`](docs/SECURITY.md) | protections and the checklist for new code |
| [`docs/TESTING.md`](docs/TESTING.md) | test setup, files, checklists |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | deploy, secrets, releases, rollback |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | production runbook, Safe Browsing, own domain |
| [`docs/APPS.md`](docs/APPS.md), [`docs/MICROSOFT_STORE.md`](docs/MICROSOFT_STORE.md) | Android, Windows, Store |
| [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) | workflow and definition of done |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | history of every Part and ideas |

## Quick start

Requirements: PHP 8.1+ (`pdo_mysql`, `openssl`, `mbstring`, `gd`), MySQL or MariaDB, Python 3 only for the tests.

```bash
git clone https://github.com/muhammadanas702/expense-tracker.git && cd expense-tracker
mysql -uroot -e "CREATE DATABASE expenseflow_test CHARACTER SET utf8mb4"
mysql -uroot expenseflow_test < schema.sql

cat > config/db.local.php <<'PHP'
<?php
return ['local' => ['host'=>'127.0.0.1','dbname'=>'expenseflow_test','username'=>'root','password'=>'']];
PHP

php -S 127.0.0.1:8080 -t . router.php      # then open http://127.0.0.1:8080/
```

Sign-up e-mails need SMTP (`config/mail.local.php`, Gmail app password). To become Super Admin: `UPDATE users SET role='super', is_admin=1 WHERE email='you@example.com';` and open `/admin/`. Details, including how to run the tests, are in the docs above.

## Project structure

```
.
├── index.php, about/privacy/terms/contact.php, guides/   Public pages
├── auth/                  Register, e-mail code, login, reset, delete account
├── dashboard.php, expense/, income/, reports/, profile.php   Personal pages (thin wrappers)
├── shared/, friends/, activity/, comments/, receipts/, support/, announce/   Shared + helper pages
├── admin/                 Admin panel pages
├── modules/               ★ Feature code: one folder per module with module.php manifest (not web-accessible)
├── includes/              Layouts, security, schema helper, icons, export engine, currencies
├── config/                app.php, db.php, mail.php (+ private *.local.php, never committed)
├── assets/                app.css, admin.css, JS, icons, lib/chart.umd.min.js
├── storage/               sessions, schema markers, backups, receipts, rate cache (private)
├── apps/android, apps/desktop   Native shells
├── tests/                 PHP + HTTP tests (test DB only)
├── docs/                  Documentation
├── schema.sql             Fresh-install schema (29 tables)
├── sw.js, manifest.json, offline.html   PWA
└── .github/workflows/     deploy.yml, release.yml
```

More in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Look and feel

Frosted-glass cards over a soft gradient. **Teal = Personal, blue = Shared, green = income, orange = expense**, deep navy text (`#0B2545`), rounded corners (20 px cards, 12 px controls), one set of 24 px outline icons, mobile bottom bar and desktop sidebar. Tokens and components are documented in [`docs/THEME.md`](docs/THEME.md); the whole design is `assets/app.css` + `assets/admin.css`.

## Security in brief

Prepared statements, CSRF on every form, escaped output, hardened sessions, rate limits on login/codes, non-enumerating sign-up and friend requests, role checks on the server, private folders blocked at web-server level, re-encoded uploads, formula-safe exports and an audit trail. Full list and the checklist for new code: [`docs/SECURITY.md`](docs/SECURITY.md).

## Apps and installing

| Platform | How |
| --- | --- |
| Android | download `ExpenseFlow.apk` from [Releases](https://github.com/muhammadanas702/expense-tracker/releases/latest), allow "Install unknown apps" |
| Windows | `ExpenseFlow-Setup.exe` from Releases, (Microsoft Store listing "ExpenseFlow - Money Manager" is in certification) |
| Any browser / iPhone / iPad | open the site, then Install app (Chrome) or Share > Add to Home Screen (Safari) |

The apps display the live website, so a site deploy updates them instantly. See [`docs/APPS.md`](docs/APPS.md).

## Deployment

Merging into `main` deploys the website to InfinityFree through GitHub Actions (private DB and mail config are generated from repository secrets). Tagging `vX.Y.Z` (or running the release workflow) builds the APK, the Windows installer and the Store package. Full guide: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Status and roadmap

Actively developed in small Parts; history and ideas are in [`docs/ROADMAP.md`](docs/ROADMAP.md). Planned or open: Microsoft Store certification, own domain (recommended), report/block user, offline entry sync, scheduled backups, ARM64 Windows build, iOS app.

## Contributing

Branch from `main`, keep changes small, run the tests, update the docs, open a Pull Request. Rules and definition of done: [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md).

## Author

Developed by **Muhammad Anas** ([@muhammadanas702](https://github.com/muhammadanas702)), software engineering student at UMT Lahore, Pakistan.

If ExpenseFlow is useful to you, a star on the repository is appreciated.
