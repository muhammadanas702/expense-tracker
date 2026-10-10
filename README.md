<div align="center">

# ExpenseFlow

**A multi-currency income and expense tracker, built as an installable web app.**

Record what you earn and spend in the currency you actually used, see everything converted into the one you choose, and understand your money through a live dashboard, charts and a printable report.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-PDO-4479A1?logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.4-FF6384?logo=chartdotjs&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8?logo=pwa&logoColor=white)
![CI/CD](https://img.shields.io/badge/CI%2FCD-GitHub%20Actions-2088FF?logo=githubactions&logoColor=white)

**[Live site: expenseflow.rf.gd](https://expenseflow.rf.gd)**

</div>

---

## Table of contents

- [Overview](#overview)
- [Features](#features)
- [Tech stack](#tech-stack)
- [How it works](#how-it-works)
- [Project structure](#project-structure)
- [Database](#database)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [Deployment](#deployment)
- [Installing as an app](#installing-as-an-app)
- [Project status and roadmap](#project-status-and-roadmap)
- [Contributing](#contributing)
- [Author](#author)

## Overview

ExpenseFlow is a server-rendered PHP and MySQL application for personal finance. You add income and expenses in any of 40 currencies, and ExpenseFlow converts them with live exchange rates so your totals always make sense in a single currency.

It is built for people who earn or spend across several currencies, for example a freelancer paid in USD who lives on PKR. A dashboard summarises your money, charts show where it goes, and a report page lets you export or print your history. An admin panel lets the site owner manage users and review activity. The site can be installed on a phone like a native app.

## Features

### Money tracking
- Add, edit and delete **income** and **expenses**, each with a title, amount and currency.
- Expense categories: Food, Transport, Shopping, Entertainment, Bills, Healthcare, Education, or a custom category of your own.
- Every entry is time-stamped automatically.
- Reset all income, all expenses, or all data from the dashboard.

### Multi-currency
- 40 currencies for transactions, including PKR, USD, EUR, GBP, AED, SAR, INR, JPY and more.
- Live exchange rates, cached for one hour, with built-in fallback rates if the rate service cannot be reached.
- A **base currency per month**, which you can change from the dashboard.

### Dashboard and analytics
- Current-month overview with total income, total expense and net balance.
- Custom date-range filter (results are shown in USD).
- Smart insights: financial health from your savings rate, spending trend against last month, top spending category, monthly surplus or deficit, and average daily spend.
- Recent income and expense lists with edit and delete actions.
- Interactive Chart.js charts: income vs expense doughnut, bar chart and a category pie chart.

### Reports
- Full report for any date range with summary, monthly breakdown and detailed transactions.
- **Export to Excel** (styled, Excel-compatible file) or **Save as PDF** through the browser's print dialog.

### Accounts
- Register, log in and log out.
- Password reset with a one-time code (OTP) sent by email.
- Update your profile name, change your password, or delete your account.

### Admin panel
- Available only to users flagged as administrators.
- List all users, view any user's income and expenses, edit a user's name, email, role or password, and delete users.
- **Activity log** with time, user, action, details and IP address, filterable by user, action and date and paginated. Logged actions include login, logout, adding, editing and deleting expenses and income, data resets and base currency changes.

### Progressive web app
- Web app manifest, service worker and a custom offline page.
- Installable on Android, iPhone and iPad, and responsive from phone to desktop.

## Tech stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.1+ |
| Database | MySQL through PDO with prepared statements |
| Frontend | HTML, CSS and vanilla JavaScript, Chart.js 4.4, Font Awesome, Inter font |
| Email | PHPMailer over SMTP |
| Exchange rates | exchangerate-api.com (free endpoint) |
| Hosting and CI/CD | InfinityFree, deployed from GitHub Actions over FTPS |

## How it works

### Currency conversion

| Part | Behaviour |
| --- | --- |
| Rates | Fetched from exchangerate-api.com and cached in `storage/currency_cache.json` for one hour |
| Conversion | An amount is converted to USD first, then to the target currency |
| Fallback | If the rate service is unreachable, built-in rates for USD, PKR, EUR, GBP, AED, SAR and INR are used |
| Expenses | Stored in the currency they were entered in and converted when displayed |
| Income | Stored with its PKR equivalent, calculated when the income is added |
| Base currency | Stored per user and per month. The first transaction of a month sets it, and it can be changed from the dashboard |

### Dashboard insights

| Insight | How it is calculated |
| --- | --- |
| Financial health | From your savings rate: 30% or more is Excellent, 15% or more is Good, anything lower is Needs Control |
| Spending trend | Percentage change in spending compared with the previous month (not shown for custom ranges) |
| Top category | The category with the highest total expense |
| Monthly status | Surplus, deficit or neutral, from income minus expenses |

### Password reset

1. On the forgot-password page you enter your account email.
2. ExpenseFlow emails you a 6-digit code that is valid for 10 minutes.
3. You enter the code and choose a new password, which is stored as a hash.

## Project structure

```
.
├── admin/                  Admin panel: users, view/edit/delete user, activity logs
├── assets/                 CSS, JavaScript and icons (including PWA icons)
├── auth/                   Register, login, logout, password reset, delete account
├── config/                 App base URL, database, mail and currency settings
├── expense/                Add expense
├── income/                 Add income
├── reports/                Full report with Excel export and print-to-PDF
├── includes/               CurrencyConverter, activity logging, PWA head tags
├── exports/                Server-side Excel/PDF export scripts (not linked from the UI)
├── storage/                Cached exchange rates
├── tcpdf/, vendor/         Bundled third-party libraries
├── .github/workflows/      GitHub Actions deployment workflow
├── dashboard.php           Main dashboard
├── profile.php             Profile and password settings
├── edit-*.php, delete-*.php   Edit and delete income and expenses
├── change-base-currency.php   Monthly base currency switch
├── reset-data.php          Reset income, expenses or all data
├── index.php               Landing page
├── sw.js, manifest.json, offline.html   PWA files
└── DEPLOYMENT.md           Deployment guide
```

## Database

ExpenseFlow uses MySQL. These are the main tables and the columns the application relies on.

| Table | Purpose | Main columns |
| --- | --- | --- |
| `users` | Accounts and roles | `id`, `name`, `email`, `password` (hashed), `is_admin`, `created_at` |
| `income` | Income entries | `id`, `user_id`, `title`, `amount`, `amount_pkr`, `currency`, `transaction_date` |
| `expenses` | Expense entries | `id`, `user_id`, `title`, `amount`, `currency`, `category`, `transaction_date` |
| `user_monthly_currency` | Base currency per user per month | `user_id`, `year_month`, `base_currency` |
| `user_logs` | Activity log shown in the admin panel | `user_id`, `action`, `details`, `ip_address`, `created_at` |

## Getting started

### Prerequisites

- PHP 8.1 or newer with the **PDO MySQL**, **calendar** and **OpenSSL** extensions, and `allow_url_fopen` enabled (used to fetch exchange rates)
- MySQL or MariaDB
- Apache, for example through XAMPP, WAMP or similar

### Installation

1. Clone the repository into your web root. With XAMPP that is `htdocs/expense-tracker`:

   ```bash
   git clone https://github.com/muhammadanas702/expense-tracker.git
   ```

2. Create an empty MySQL database and the tables described in [Database](#database).

3. Create `config/db.local.php` with your local credentials:

   ```php
   <?php
   return [
       'local' => [
           'host'     => 'localhost',
           'dbname'   => 'your_database_name',
           'username' => 'your_database_user',
           'password' => 'your_database_password',
       ],
   ];
   ```

4. Set up an SMTP account for password-reset emails. For Gmail, use an **app password**, never your normal password.

5. Open `http://localhost/expense-tracker/`, register your first account and log in.

### Becoming an administrator

Register normally, then promote your account in the database:

```sql
UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
```

The **Admin Panel** button then appears on your dashboard.

## Configuration

| Setting | Where | Notes |
| --- | --- | --- |
| Database | `config/db.local.php`, or the `EXPENSEFLOW_DB_HOST`, `EXPENSEFLOW_DB_NAME`, `EXPENSEFLOW_DB_USERNAME` and `EXPENSEFLOW_DB_PASSWORD` environment variables | The file supports separate `local` and `production` blocks |
| Base URL | `config/app.php` | Separate values for local development and production. Set the production value to your own domain if you host it elsewhere |

`config/db.local.php` and `config/mail.local.php` hold credentials and are ignored by Git. Never commit credentials of any kind.

## Deployment

Every push to `main` runs `.github/workflows/deploy.yml`, which creates the private database configuration inside the GitHub Actions runner from repository secrets and uploads the site over explicit FTPS. The list of secrets to create and the full walkthrough are in [DEPLOYMENT.md](DEPLOYMENT.md).

ExpenseFlow runs on any host that provides PHP 8.1+, MySQL and Apache.

## Installing as an app

Once the site is served over HTTPS:

- **Android (Chrome):** open the site, then Menu > **Install app**.
- **iPhone and iPad (Safari):** open the site, then **Share > Add to Home Screen**.

When the connection drops, the service worker shows a custom offline page instead of a browser error.

## Project status and roadmap

ExpenseFlow is an actively developed personal project. Planned next:

- Split-bill groups (shared expenses between friends or hostel mates)
- Email verification at sign-up
- A ready-to-import database schema script

## Contributing

Suggestions and contributions are welcome.

1. Fork the repository and create a branch: `git checkout -b feature/your-feature`
2. Make your changes and test them locally.
3. Open a pull request that explains what changed and why.

For bugs and feature ideas, please open an issue.

## Author

Developed by **Muhammad Anas** ([@muhammadanas702](https://github.com/muhammadanas702)).

If ExpenseFlow is useful to you, a star on the repository is appreciated.
