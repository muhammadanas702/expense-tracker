# Testing ExpenseFlow

Tests run against a **local test database only**, never the live site. They are plain scripts: no PHPUnit, nothing to install except `requests` and Playwright for Python.

## 1. One-time local setup

```bash
# 1. MariaDB/MySQL running, with a test database
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS expenseflow_test CHARACTER SET utf8mb4"
mysql -uroot expenseflow_test < schema.sql

# 2. private config for localhost (not committed)
cat > config/db.local.php <<'PHP'
<?php
return ['local' => ['host'=>'127.0.0.1','dbname'=>'expenseflow_test','username'=>'root','password'=>'']];
PHP

# 3. PHP dev server (the router blocks private paths like the live .htaccess does)
php -S 127.0.0.1:8080 -t . router.php

# 4. Python helpers
pip install requests playwright && playwright install chromium
```

If you ever reset the database, also run `rm -rf storage/schema` (self-healing markers, see ARCHITECTURE section 6).

Mail is not sent locally. `signup_verify_http_test.py` and `staff_mail_http_test.py` start a small **local SMTP sink** to capture the e-mail (and read the 6-digit code from it); read the top of those files for the exact config they write.

## 2. The test files (`tests/`)

| File | Type | Covers |
| --- | --- | --- |
| `account_delete_test.php` | PHP | deleting an account cleans personal and shared data |
| `admin_roles_test.php` | PHP | role and permission matrix rules |
| `friend_links_test.php`, `friends_test.php` | PHP | friend requests by e-mail, derived friends, per-person balances |
| `shared_split_test.php` (`modules/shared/split.php`), `shared_balance_test.php` (`modules/shared/balance.php`), `shared_ledger_test.php` | PHP | split maths (shares add up to the cent), balance maths, randomised end-to-end ledger check |
| `security_http_test.py` | HTTP | login required, CSRF required, a non-member cannot reach another group's data |
| `session_http_test.py` | HTTP | login lasts while used: 3 days idle signs out with a message, any visit restarts the 3 days |
| `signup_verify_http_test.py` | HTTP | code confirmation, wrong codes, non-enumerating existing-e-mail flow, Terms tick box |
| `seo_http_test.py` | HTTP | public pages reachable, one `h1`, canonical, valid JSON-LD, listed in the sitemap, private areas blocked in robots.txt |
| `admin_http_test.py`, `admin_users_http_test.py`, `admin_content_http_test.py`, `admin_audit_http_test.py` (roles page + audit log), `admin_system_http_test.py` (security, backups, settings), `admin_super_privacy_http_test.py` (only Super Admins see Super Admin activity), `staff_mail_http_test.py` (temporary-password and support-reply e-mails) | HTTP | admin access by role, user management, content pages, audit trail, system pages, privacy of super admin activity, staff mail |
| `support_http_test.py` | HTTP | help requests and replies |
| `export_http_test.py` | HTTP | Excel/PDF/CSV downloads: correct content type, headers, not HTML, branded content |

## 3. Running

```bash
# PHP tests (each prints PASS/FAIL lines)
for t in tests/*_test.php; do echo "== $t"; php "$t" | tail -3; done

# HTTP tests (server must be running on 127.0.0.1:8080)
for t in tests/*_http_test.py; do echo "== $t"; python3 "$t" | tail -2; done
```

A passing file ends with `ALL PASSED` / `all passed`; a failure prints `FAIL <message>`. Run the whole set before every Pull Request that touches PHP. For a CSS-only change, a screenshot check (below) is enough.

## 4. Visual check (UI work)

Use Playwright at **390x800** (phone) and **1280x800** (desktop):

```python
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch()
    for name, w in (('phone', 390), ('desktop', 1280)):
        pg = b.new_page(viewport={'width': w, 'height': 800})
        pg.goto('http://127.0.0.1:8080/auth/login.php'); pg.wait_for_timeout(1200)
        pg.screenshot(path=f'/tmp/login-{name}.png', full_page=True)
```

Check: no sideways scroll on phone, text readable, teal for Personal and blue for Shared, income green and expense orange, icons the same style, nothing cut off. Log in with a seeded user to see signed-in pages.

## 5. Demo data

Create two or three users, a shared group and a few entries by registering in the browser, or insert with a small PHP script that calls `shared_create_group()`, `shared_expense_save()` and plain `INSERT`s for `income`/`expenses`. Good manual demo: user A (Personal entries in PKR and USD), user B, a group "Lahore trip" with a dinner split equally, then a settle.

## 6. Manual test list before a release

1. Register (code arrives), log in, log out, forgot password.
2. Add income + expense in two currencies; dashboard totals and base currency change.
3. Report: filters, CSV, Excel, PDF open correctly and show the ExpenseFlow header.
4. Shared: create group, invite link, join as second user, add expense with each split type, settle, leave blocked while owing.
5. Friends: add by e-mail, accept, balance per person.
6. Receipt upload (JPG), comment, notification bell.
7. Admin (super): users list, block/unblock, roles matrix change, audit shows it, backup creates a file, maintenance mode on/off.
8. Phone width and Android app: downloads give a file, not HTML.
9. Offline: stop network, the offline page or saved page appears.
10. Delete account removes the data.

## 7. Rules for new tests

- A test creates its own users (`...@something.local`), cleans them first, and never touches other rows.
- Test the **rule** (a stranger cannot read a group, a wrong code is refused), not just the happy path.
- Name files `<area>_test.php` or `<area>_http_test.py`; keep them runnable alone.
- Never point tests at production credentials.
