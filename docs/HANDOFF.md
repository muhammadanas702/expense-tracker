# Handoff — where the project stands (2026-11-22)

Owner: Muhammad Anas. Stack: PHP 8 + MySQL, no framework. Repo `muhammadanas702/expense-tracker`. Free host InfinityFree (`if0_41994702`, IP 185.27.134.103). Deploy: merge to `main` -> GitHub Actions "Deploy ExpenseFlow to InfinityFree" (FTPS, 15-30 s). Rule for every Part: small, tested, then a numbered "what you do next" list (which PR to merge, APK/exe build needed or not). Owner writes Roman Urdu and is on the free Claude plan: keep each job small.

## Done (everything merged to main)
- The whole app: personal income/expenses, multi-currency, budgets, goals, reminders, reports/exports, shared groups + friends + settle up + activity, shared search (108a), admin panel, auth with 2FA, backups, health page, uptime monitor, status page. Roadmap with every Part: `docs/ROADMAP.md`.
- UI audit finished (Parts 108a-d, 109a-c): `tests/ui_measure.py` finds 0 spacing/alignment problems on app, admin and public pages, light and dark, at 360/390/768/1366 px.
- Own domain (Parts 106, 106b, 106c, 106d), all live:
  - `expenseflow.page` (name.com, GitHub Student Pack, first year free, expires 2027-11-11, auto-renew OFF) is the **main address**; `expenseflow.rf.gd` stays alive: it redirects to `.page`, and serves the site itself if `.page` is down. `/admin/` and `/health.php` on `.rf.gd` are never redirected.
  - DNS at Cloudflare (Free plan, nameservers arya/cash.ns.cloudflare.com). SSL/TLS mode **Full**. A records `@` and `www` -> 185.27.134.103 **Proxied**; `_acme-challenge` CNAME -> `5079c13e-…acmedns.infinityfree.net` **DNS only** (needed for renewal, keep it).
  - Why the proxy: InfinityFree sends no intermediate certificate, so the uptime monitor and apps fail on the bare origin certificate. Cloudflare serves a complete chain. `client_ip()` reads `CF-Connecting-IP` only from Cloudflare ranges.
  - InfinityFree has a Let's Encrypt certificate for `.page` (expires 2027-02-11; InfinityFree renews through the CNAME).
  - GitHub repo variable `SITE_URL=https://expenseflow.page`.

- Shared part redone (Parts 110a-110f, 111a-111e, see `docs/ROADMAP.md`): calm single-accent style with panels and a few drop-downs, Add in the middle of the phone bar, friends can be added to a group from the app (invitation), private two-person account with a friend, optional @username.

## Check once (owner, a few minutes)
1. Actions > "Uptime monitor" > Run workflow: green, and issue #168 "ExpenseFlow is down" closes itself. If it stays red, open the run and read the message.
2. Sign in on `https://expenseflow.page` (everyone signs in once more after the address change) and use the app normally: dashboard, add expense, shared group.
3. If Google sign-in is used: add `https://expenseflow.page/...` to the authorised redirect URIs in Google Cloud.
4. Undo for anything odd: Admin > Domain > "Go back to expenseflow.rf.gd"; Cloudflare A records back to DNS only.

## Still to do
- **Apps (needs a Part, then a build):** `php tools/change-domain.php expenseflow.page`, then run the Actions workflow "Build apps & publish release" (APK, exe), then update the Microsoft Store listing (`docs/MICROSOFT_STORE.md`). Do this only after check 1-2 above are green. Not built yet, so the installed apps still use the old address (which redirects).
- **Part 107 — standby host: DONE** (`tools/restore-standby.php`, `docs/STANDBY.md`, `tests/restore_standby_test.php`). If InfinityFree itself is down, `.rf.gd` cannot answer; then the `.page` A records in Cloudflare must point to the standby host. Owner can still open a spare free host in advance (optional).
- **Owner-side setup not confirmed done:** Admin > Backups > "Off-site copy (GitHub)" (private repo `muhammadanas702/expenseflow-backups`, fine-grained token, passphrase); optionally GitHub Pages from branch `status-page` for the public status page.
- Domain renewal: name.com auto-renew is OFF; renew before 2027-11-11 (about 18.99 USD) or turn auto-renew on.

## How things are run (for the next session)
- Local test setup: `PHP_CLI_SERVER_WORKERS=4 EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php` and mysqld (test DB `expenseflow_test`, `mysql --socket=/run/mysqld/mysqld.sock -uroot expenseflow_test`). Both die between turns (symptom: 503 "database is busy"): restart them.
- Tests: `tests/*.py` and `tests/*.php`, see `docs/TESTING.md`. Domain tests: `tests/domain_test.py`, `tests/old_address_test.py`, `tests/redirect_test.php`, `tests/change_domain_test.php`, `tests/old_ca_test.php`, `tests/client_ip_test.php`.
- The sandbox cannot reach the live site or the Cloudflare / InfinityFree / name.com dashboards: the owner does those clicks and sends screenshots.
- The stop-hook "unpushed commit(s) and no remote branch" message is a false alarm: check `git ls-remote --heads origin <branch>` equals `git rev-parse HEAD`.
- GitHub GraphQL is blocked in the sandbox: create PRs with `gh api repos/muhammadanas702/expense-tracker/pulls -f ...`.

## Parts 112-122 (UI polish after the Shared redesign)
- 112-116: Personal pages (dashboard, goals, reminders, budgets, tx forms, reports, account) moved to the calm panel/drop-down design; `.fgrid2` (amount + currency), `.more`, `.chips-scroll`, `--at` (accent for text).
- 117: dark mode surfaces (icon picker, More options, file input, outline buttons) in `assets/theme-dark.css`.
- 118-119: Add shared expense is one column; amount/currency same height; currency options read `PKR - Pakistani Rupee` so the code is never cut on phones.
- 120: Activity/Add-expense group chips are one scrolling row; duplicate Notifications button removed (header bell does it).
- 121: full audit: avatar colour by user id (`shared_avatar_for`), dashboard `.cu` currency suffix, search results in a panel, report/settings alignment, admin phone layouts (`.row[data-state]` stacks, backups buttons), dark selected chips, empty Goals/Reminders/Budgets stay one column (`.no-items` marker).
- 122: Features page used the global `.tx` class (orange tint): renamed `.ftx`.
- Audit method: seed rich data, crawl every page at 390 and 1366, light and dark, build contact sheets, then fix. Admin needs `role='super'`.

## Parts 123-126 (2026-11-24)
- Part 123: Shared "+" add flow can pick one friend ("just you two", `?friend=ID` opens the direct group); admin has a "Back to app" button in the top bar and on the All pages card.
- Part 124: Urdu amounts stay left-to-right; checkboxes/radios use the accent colour.
- Parts 125a/125b: all Shared, Friends and Activity pages (home, groups, add, search, group, settle, join, friend view, notifications), the activity sentences and all flash/error messages go through `t()`; Urdu text is in `includes/lang/ur.php`. English output is unchanged. New text must use `t()` and a `%s`/`%d` form for dynamic parts (never glue English pieces together).
- Part 126: Urdu language button font size. Audit of admin (desktop, dark), Settings (dark) and a very long group name found no other layout problems.
- Known test noise: 2 ViewTransition failures in `shared_cleanup_test` and `theme_http_test` "loaded on /" fail on main too.

## Part 129: phone nudges
- Split alerts already existed; the push now says "<name> added “<title>” · your share is <amount>" in the receiver's language (`push_subscriptions.lang`, saved from the language cookie when the device is turned on).
- New `modules/push/nudges.php`: debt reminder every 2 days (one message per person, names the biggest debt), "we miss you" at 3/7/14/30 days away (4 at most, restarts when the person opens the app). Table `push_nudges`.
- No cron: `health.php?db=1` (the uptime monitor, every 10 min) and normal page visits call `push_tick()`, which runs at most every 30 minutes and only 04:00-17:00 UTC (9:00-22:00 Pakistan).
- Phone alerts only work in the browser (Chrome/Edge, installed web app); the Android/Windows app shells do not receive Web Push. Native app push would need Firebase (FCM) and is a separate job.
- Test: `php tests/push_nudges_test.php`.

## Part 130a: alerts for the Android app (server side)
- The Android app is a WebView shell and cannot receive Web Push. Every alert is now ALSO queued in `app_inbox` for people who have a registered phone (`app_devices`, token hash only, max 3 phones, language saved).
- `app-alerts.php`: signed-in page `POST {csrf, action:'register'}` -> `{token}`; the phone `POST {token, action:'poll'}` -> `{items}` (each item is handed out once; items older than 3 days are dropped; 60 polls/hour per token).
- Nudges (2-day debt reminder, we-miss-you) also reach app-only people.
- Part 130b (next): the Android side (poll every ~15 min with JobScheduler, show a notification, tap opens the page) and automatic turn-on. Tests: `tests/app_alerts_test.php`, `tests/app_alerts_http_test.py`.

## Part 130b: alerts inside the Android app
- New `AlertPoller` + `AlertJob` (JobScheduler, every ~15 min, survives restarts via `BootReceiver`): POST `app-alerts.php {token, action:'poll'}` (follows the .rf.gd -> .page redirect keeping POST), shows each item as a notification; tapping opens that page (`open_path` extra, relative paths only).
- Bridge: `alertsSupported / alertsHasToken / alertsSetToken / alertsClearToken / alertsNotifGranted / alertsAsk`. Needs the new APK (manifest adds RECEIVE_BOOT_COMPLETED + the job service).
- `assets/alerts-auto.js` (signed-in pages): in the app it registers the phone and asks for the notification permission by itself (on by default); in a browser it asks once on the first tap and subscribes to Web Push. Turning alerts off in Settings > Notifications sets `localStorage.ef_alerts_off` so it never switches itself on again.
- Not instant: Android decides the exact run time (15-30 min, longer in battery saver). Instant delivery would need Firebase (FCM).
- Known: `tests/urdu_pages_ui_test.py` has 1 old failure ("کل واجب الادا" on /reminders.php) that also fails on main. SMS auto-add in the app also posts to the old address and relied on the redirect; `AlertPoller` handles it, `SmsSync` does not yet.
- Tests: `tests/app_alerts_ui_test.py` (pretend Android bridge).

## Part 131: Admin > Notifications
- `admin/notifications.php` (+ `-action.php`, permission "set"): status (browsers, Android phones, alerts waiting, last nudge round), send a test or announcement (me / one person by e-mail or @username / everyone with alerts on, 10-minute guard), and controls saved in `app_settings` (`modules/push/config.php`): browser channel, Android channel, shared-bill alerts, reminders and budgets, debt reminder (+ every N days), "we miss you" (+ day list, 1-4 numbers), nudge hours (Pakistan time). Real events ignore the hours.
- The switches are enforced in `push_notify`, `push_notify_i18n`, `push_for_event` and `push_tick`. Features on/off > "Phone notifications" still switches everything off.
- Tests: `tests/push_admin_test.php`, `tests/admin_notifications_ui_test.py`.
