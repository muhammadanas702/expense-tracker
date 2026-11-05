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
EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php

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
| `google_app_handoff_test.py` | Python | Google sign-in hand-off for the apps: Android link / Windows port return, proof checks, single use (fake Google token endpoint, own servers) |
| `google_app_ui_test.py` | Python | The app's Google screen in a real headless browser: waiting panel, return link signs in |
| `desktop_google_return_test.js` | Node | The Windows app's local listener (`apps/desktop/google-return.js`): only well-formed `/done` requests, delivered once |
| `settings_hub_http_test.py` | Python | Profile & Settings hub: every section page, saving, sign-in required |
| `theme_http_test.py` | Python | Light/dark theme in a headless browser: cookie + device decide before paint, Preferences buttons, readable colours |
| `budgets_test.php` | PHP | Budgets: validation, this-month status, 80%/100% alerts sent once, notifications off, clean-up |
| `budgets_http_test.py` | Python | Budgets page CRUD, alert after adding an expense, bell text, dashboard card, other users cannot delete |
| `goals_test.php` | PHP | Goals: validation, money in/out, never below zero, per-month hint, ownership, clean-up; Dashboard stays first in the menu |
| `goals_http_test.py` | Python | Goals page: create, add/take out, progress, reached, escaping, other users cannot touch it |
| `reminders_test.php` | PHP | Reminders: validation, month maths (31 Jan, leap year), states, notifications once per due date, Paid (+expense), ownership |
| `reminders_http_test.py` | Python | Reminders page: create, bell text, dashboard card, Paid saves the expense once, other users cannot touch it |
| `search_test.php` | PHP | Tags (store/list rules, limits) and search filters: text, tag, type, category, dates, amounts, only own rows, LIKE wildcards escaped |
| `search_http_test.py` | Python | Search page and tag field on the add/edit forms, chips, results, other users' data never shown |
| `quickadd_test.php` | PHP | Reading 9 kinds of bank/wallet SMS (debit, credit, USD, who, category guess), no amount, length limit, markup, form link |
| `quickadd_http_test.py` | Python | Quick add page: sign-in, CSRF, result card, pre-filled Add form, nothing stored until saved, crafted links cannot inject |
| `quickadd_device_ui_test.py` | Python (browser) | Quick add "Read my messages" with a fake Android bridge: hidden on the website and older apps, allow lists money messages as plain text, deny falls back to paste, used messages hidden |
| `speed_check_test.py` | Python | Admin Speed check page: only super admin sees it, shows markers working, cold checks listed, marker folder fallback |
| `mobile_nav_ui_test.py` | Python (browser) | Phone bottom bar: 4 main items + More sheet (light and dark), fits the screen, More opens/closes, desktop sidebar unchanged, Dashboard search box |
| `currency_cache_test.php` | PHP | Exchange-rate cache with a dead service: one short wait, the attempt is remembered, stale cache still used, a good fetch refreshes it (`mock_rates.php` is the fake service) |
| `schema_once_test.php` | PHP | A second schema check in the same request does not commit an open transaction |
| `offline_prewarm_ui_test.py` | Python (Playwright, service worker on) | Add pages saved while online, open offline, entry queued and sent after reconnect, one offline bar, net balance never stuck at 0 |
| `quickadd_check_ui_test.py` | Python (Playwright) | "Check my SMS setup" with a fake Android bridge: auto off / no permission, inbox counts and senders, old-messages explanation and the include-last-days action (limited to 30), empty inbox, old app; status fields |
| `lite_apk_test.py` | Python (Playwright) | Gradle flavors, Lite manifest removes SMS, release workflow builds both APKs, Lite links on the landing page and Settings > Apps, Quick add in a Lite app (no SMS card, paste works) |
| `share_http_test.py` | Python | `?sms=` fills and escapes the Quick add box, saves nothing, is cut at 600 characters; Android manifest and MainActivity declare the share target |
| `offline_queue_test.php` | PHP | Client id and phone-time validation (bad ids, old/future times refused) |
| `offline_queue_ui_test.py` | Python (Playwright) | Same id twice = one row, phone time kept, offline save is queued, sent after reconnect, invalid entry dropped, kept when signed out |
| `backup_auto_http_test.py` | Python | Opening the admin overview creates a backup when none is under 24 h old, not repeated, audit-logged, never for ordinary users |
| `i18n_test.php` | PHP | English default, Urdu placeholder consistency, every `t()` text in the checked pages has an Urdu entry |
| `urdu_auth_ui_test.py` | Python (Playwright) | Register, forgot password and the wrong-sign-in message in Urdu at phone width (right-to-left, placeholders, no sideways scroll, e-mail box left-to-right), English unchanged |
| `urdu_pages_ui_test.py` | Python (Playwright) | Budgets, Goals, Reminders in Urdu at phone width: right-to-left, Urdu words, no sideways scroll, number boxes stay left-to-right, English unchanged |
| `urdu_ui_test.py` | Python (Playwright) | `?lang=ur` gives `dir=rtl`, Urdu login/dashboard/add form, menu on the right, language card in Settings, back to English, no sideways scroll on a phone |
| `resilience_ui_test.py` | Python (Playwright) | health.php, saved pages incl. admin, server down / slow (4 s) / device offline messages, auto-recovery, logout wipes saved pages, dead DB host gives a friendly 503 (`slow_router.php` simulates a slow server) |
| `twofactor_http_test.py` | Python | Two-step sign-in: turn on/off with a mailed code, login needs the code, wrong/expired/used codes, resend cooldown, staff always asked and cannot opt out, emergency switch skips it, no code on a wrong password (starts its own servers, mail goes to a sink file) |
| `quickadd_classify_test.php` | PHP | Which SMS is added, reviewed or ignored (codes, offers, failed, reminders, requests, friend numbers, mixed) |
| `quickadd_import_http_test.py` | Python | Auto-import endpoint: off by default, counts, learned category, no duplicates, review list, ownership, always-ignore, undo, turn off |
| `quickadd_auto_ui_test.py` | Python (Playwright) | Fake Android bridge: opening a page imports new SMS, tap-to-see bar, nothing when off |
| `quickadd_device_token_http_test.py` | Python | Background key: only a hash stored, import without cookies, duplicates with the page, bad keys refused, max 5 phones, turning auto-add off cancels keys |
| `part50_http_test.py` | Python | Getting started card (new vs old account, steps tick) and the settle-up reminder (button only for the creditor, notification, 3-day limit, csrf) |
| `admin_mobile_ui_test.py` | Python (Playwright) | Admin overview on 320-1200 px: 2 columns x 3 rows and small cards on phones, more columns on wide screens, no sideways scroll |
| `module_failsafe_http_test.py` | Python | Break the Budgets/Goals tables: dashboard stays 200 without the card, their pages show a small notice (no 500) |
| `errors_http_test.py` | Python | Friendly error pages and JSON errors |
| `settings_links_test.php` | PHP | Apps & contact settings: https-only links, e-mail check, save and the public JSON copy |
| `google_signin_test.php` | PHP | Google claim checks (issuer, audience, expiry, verified e-mail), find / link / create / refuse (blocked, sign-ups closed), PKCE start URL, single-use state |
| `google_http_test.py` | HTTP | button hidden until configured and inside the app shells, redirect to Google with state + PKCE, bad/replayed state refused. Temporarily writes `config/google.local.php` and restores it |
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
