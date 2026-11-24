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
