# Deploying and releasing ExpenseFlow

There are **two separate pipelines**:

| Pipeline | Workflow | Trigger | Result |
| --- | --- | --- | --- |
| Website | `.github/workflows/deploy.yml` | every push to `main`, or "Run workflow" | files uploaded to InfinityFree `htdocs/` over FTPS |
| Apps | `.github/workflows/release.yml` | push a tag `v*`, or "Run workflow" with a version | Android APK, Windows installer, Microsoft Store MSIX |

A website deploy updates the apps instantly (they just show the website). Build a new app release only when the **shell** changed (icon, download handling, window behaviour, version for the Store).

## 1. Website deploy (automatic)

1. Work on a branch: `git checkout -b feature/part-N-short-name` from the latest `origin/main`.
2. Test locally (see [`TESTING.md`](TESTING.md)).
3. Push, open a Pull Request, merge it into `main`.
4. GitHub Actions starts **Deploy ExpenseFlow to InfinityFree**:
   - writes `config/db.local.php` and `config/mail.local.php` on the runner from secrets (stops with a clear message if a required secret is missing),
   - uploads the repo with `SamKirkland/FTP-Deploy-Action` (explicit FTPS, port 21, strict TLS, **no clean-slate delete**, `.git*` excluded),
   - only changed files are sent (state file `.ftp-deploy-sync-state.json` on the server; denied by `.htaccess`).
5. Open `https://expenseflow.rf.gd` and check the page you changed. Browsers get fresh CSS/JS automatically (`?v=<file time>`).

Deploy takes 1-3 minutes. If it fails with an FTP error, see "InfinityFree outage" in [`OPERATIONS.md`](OPERATIONS.md): wait for the host, then re-run the failed workflow (Actions > the run > Re-run, or "Run workflow" on `deploy.yml`).

### Required GitHub Actions secrets

Settings > Secrets and variables > Actions.

| Secret | Value |
| --- | --- |
| `FTP_SERVER` | FTP host of the InfinityFree account (`ftpupload.net`) |
| `FTP_USERNAME`, `FTP_PASSWORD` | FTP login |
| `EXPENSEFLOW_DB_HOST`, `EXPENSEFLOW_DB_NAME`, `EXPENSEFLOW_DB_USERNAME`, `EXPENSEFLOW_DB_PASSWORD` | live MySQL |
| `EXPENSEFLOW_SMTP_USERNAME`, `EXPENSEFLOW_SMTP_PASSWORD` | Gmail address and **app password** |
| `EXPENSEFLOW_MAIL_FROM`, `EXPENSEFLOW_MAIL_FROM_NAME`, `EXPENSEFLOW_MAIL_REPLY_TO` | optional sender details |
| `EXPENSEFLOW_SMTP_HOST/PORT/ENCRYPTION` (env) | optional; default `smtp.gmail.com`, `587`, `tls` |
| `STORE_IDENTITY_NAME`, `STORE_PUBLISHER`, `STORE_PUBLISHER_DISPLAY_NAME` | optional overrides for the Microsoft Store identity (defaults are in `apps/desktop/package.json`) |

### What is not deployed

`.git*`, tests, docs, SQL files and private files are blocked from the web by `.htaccess`/`router.php` even if uploaded. `vendor/` and `tcpdf/` are uploaded because exports need them.

### First-time setup of a new host / domain

1. Create the MySQL database in the host panel; import `schema.sql` (or let the app create tables on first visit).
2. Put the secrets above into GitHub.
3. Set the base URL: either edit the production URL in `config/app.php` or define the `EXPENSEFLOW_BASE_URL` environment variable (preferred when you move to your own domain).
4. Run the **Deploy** workflow.
5. Register your account, then promote it: `UPDATE users SET role='super', is_admin=1 WHERE email='you@example.com';`
6. Turn on HTTPS redirect: uncomment the 4 `RewriteRule` lines at the top of `.htaccess` after confirming HTTPS works (a wrong redirect can loop on some free hosts).
7. Update the places that contain the domain: `sitemap.xml`, `robots.txt`, `manifest.json` (relative, fine), `.well-known/security.txt`, `apps/android/.../MainActivity.java` (`HOST`/`HOME`), `apps/desktop/main.js` (`HOST`/`HOME`), the Store listing links, and mail templates (`ef_mail_login_url()` uses the base URL). Then build a new app release.

## 2. App release (manual, when needed)

**Before you run it, check the latest tag** so you never rebuild a version the owner already built: `gh release list --limit 3`. Versions must always increase (the Microsoft Store rejects equal or lower versions).

1. GitHub > Actions > **Build apps & publish release** > Run workflow > enter version, e.g. `1.0.16`. (Or `git tag v1.0.16 && git push origin v1.0.16`.)
2. Jobs:
   - **android** (Ubuntu, JDK 17, Gradle 8.7): builds `ExpenseFlow.apk`, versionName = your version, versionCode = run number, signed with `apps/android/sideload.jks`; creates/updates GitHub Release `vX.Y.Z` with the APK.
   - **windows** (Windows runner, Node 20): `electron-builder` makes `ExpenseFlow-Setup.exe` (NSIS one-click, per-user) and uploads it to the same Release; a second step builds the Store package `ExpenseFlow-Store.msix` (step is `continue-on-error`, artifact `ExpenseFlow-store-msix`).
3. Download links for users: GitHub Releases page; the landing page links to `releases/latest/download/ExpenseFlow.apk` and `.../ExpenseFlow-Setup.exe`, so every new release is picked up automatically (keep those two file names).

### Microsoft Store update

1. Download the `ExpenseFlow-store-msix` artifact of the release run, unzip.
2. Partner Center > ExpenseFlow - Money Manager > **Update** > Packages: upload the `.msix`.
3. Before submitting: Store listing search terms must contain **no other brand names** (for example "Splitwise"); "Notes for certification" must contain a **working test account** and the `runFullTrust` explanation (Electron). Details in [`MICROSOFT_STORE.md`](MICROSOFT_STORE.md).
4. Submit for certification (1-3 days).

## 3. Running the same code elsewhere

| Option | How |
| --- | --- |
| Local PHP dev server | `php -S 127.0.0.1:8080 -t . router.php` (router blocks private paths because the built-in server ignores `.htaccess`) |
| XAMPP/WAMP | copy to `htdocs/expense-tracker`, create `config/db.local.php` with a `local` section |
| Heroku-style | `Procfile`: `web: php -S 0.0.0.0:$PORT -t . router.php`; set `EXPENSEFLOW_DB_*` and `EXPENSEFLOW_BASE_URL` |
| Docker | `Dockerfile` (php:8.2-apache + pdo_mysql, rewrite, headers; `storage/` writable) |

## 4. Rollback

Revert the merge commit on `main` (`git revert -m 1 <merge sha>`), push; the deploy workflow publishes the old files. Database changes made by `ensure_schema` are additive (new tables/columns), so old code keeps working. For a bad app build, publish a new higher version; do not delete a Store version.
