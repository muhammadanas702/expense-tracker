# Running ExpenseFlow in production (runbook)

Practical knowledge about the live site `https://expenseflow.rf.gd`, its hosting (InfinityFree free plan) and the problems that already happened. Read this when something is "weird on the live site but fine locally".

## 1. The stack in production

| Part | Where |
| --- | --- |
| Website | InfinityFree free hosting, Apache + PHP, `htdocs/` |
| Database | InfinityFree MySQL (phpMyAdmin in the host panel) |
| Deploy | GitHub Actions over FTPS (see [`DEPLOYMENT.md`](DEPLOYMENT.md)) |
| Mail | Gmail SMTP with an app password (`config/mail.local.php`, created by the workflow) |
| Exchange rates | exchangerate-api.com free endpoint, cached 1 hour in `storage/currency_cache.json`; built-in fallback rates when unreachable |
| Apps | GitHub Releases (APK, exe) and Microsoft Store |
| Search | Google Search Console (site + sitemap `sitemap.xml`), verification file `google18b56792a4ec2f26.html` (keep it) |

## 2. InfinityFree quirks you will meet

| Symptom | Cause | What to do |
| --- | --- | --- |
| URL gets `?i=1` appended, or a page asking to enable JavaScript/cookies | Free-host anti-bot check, shown to scripts and some first visits | Normal for browsers after the first visit. Scripts (curl, tests, uptime checks) can not pass it; test through a real browser |
| `502 Bad Gateway` (openresty) on any page | Hosting outage or overloaded server | Check the status page of the host; wait; nothing in our code is wrong when even the landing page returns 502 |
| Deploy fails at FTP step | The same outage affects FTP | Wait for recovery, re-run the **Deploy** workflow |
| Downloads (PDF/Excel/CSV) open as HTML code | Host returned an error page instead of the file during an outage; exports themselves are light (0.2-0.7 s, ~26 MB) | Retry after recovery; exports now fail safely and send correct download headers |
| "Hit limit" / account suspended messages | Free plan daily hit limits | Reduce polling, avoid uptime monitors that hit every few seconds |
| Mail goes to spam | Gmail SMTP sending on behalf of an unauthenticated domain | Own domain with SPF/DKIM/DMARC (see section 6) |
| `.htaccess` HTTPS redirect loops | Host terminates TLS in front of Apache | That is why the redirect is commented out by default |
| Session or schema oddities after a manual DB restore | Stale `storage/schema/*` markers | Delete `storage/schema/` through FTP/File Manager so tables are checked again |

## 3. Routine tasks

| Task | How |
| --- | --- |
| Make someone staff | Admin > Users > edit role (Super Admin only), or SQL: `UPDATE users SET role='admin' WHERE email='...'` |
| First Super Admin | SQL: `UPDATE users SET role='super', is_admin=1 WHERE email='...'` |
| Unblock a user | Admin > Users > Unblock |
| Reset a user's password | Admin > Users > set temporary password (the user gets an e-mail) |
| Post a notice to everyone | Admin > Announcements (optional start/end) |
| Close sign-ups / maintenance | Admin > Settings |
| Backup | Admin > Backups > create > download (keeps newest 10). Do it before big changes; also copy `storage/receipts/` |
| Change expense categories / hide a currency | Admin > Categories / Currencies. If a currency (for example KRW) is missing from dropdowns, first check it is not hidden here |
| Add a currency | `includes/currencies.php` (`'CODE' => [flag emoji, 'Name', group]`), and a fallback rate in `app_fallback_rates()` |
| Look at errors | PHP `error_log` in the host panel; exports and mail failures log `ExpenseFlow ...` lines |
| Check what a user did | Admin > User logs; staff actions: Admin > Audit log |

## 4. Google Safe Browsing / Search Console

**What happened (Oct 2026):** Search Console reported "Deceptive pages" for `/auth/login.php` and `/dashboard.php`. Login pages on free shared subdomains get flagged often, because the sign-in form looks like phishing to the heuristic when the domain has no reputation. The site is genuine.

**What was done (Part 30):** the sign-in pages now say who runs the site and that bank/card details and passwords are never requested, and link Home/About/Privacy/Terms/Contact; `/.well-known/security.txt` was added; a review was requested.

**If it happens again:**
1. Search Console > Security & manual actions > Security issues: read the sample URLs.
2. Make sure those pages show the trust text, the footer links and have no look-alike branding.
3. Click **Request review** once, explaining it is a genuine personal finance tracker by an individual developer in Pakistan that never asks for banking details. Reviews take about 1-3 days; do not resubmit repeatedly.
4. Check `https://transparencyreport.google.com/safe-browsing/search?url=expenseflow.rf.gd`.
5. The durable fix is your **own domain** (section 6).

Indexing: guides under `/guides/` are listed in `sitemap.xml`; submit new URLs in Search Console > URL inspection > Request indexing. Update `sitemap.xml` whenever a public page is added.

## 5. Microsoft Store status checklist

Account type Individual (verification by ID can take days). A submission needs: package uploaded and valid, store listing (no foreign brand names in search terms), age rating, pricing Free, properties (category Finance), privacy policy URL, support contact, **Notes for certification with a working test account** (the tester cannot receive the 6-digit sign-up code) and the `runFullTrust` explanation. Common certification failures and the fixes already applied are in [`MICROSOFT_STORE.md`](MICROSOFT_STORE.md).

Keep a dedicated **review account** alive on the live site (name "Store Reviewer", some sample entries). Do not delete it while a submission is pending.

## 6. Moving to your own domain (recommended)

Why: fixes Safe Browsing flags on a shared subdomain, lets mail pass SPF/DKIM, improves search ranking, and gives a name you control forever.

1. Buy a domain (about 10 USD/year). InfinityFree supports custom domains: add it in the panel, point the registrar's nameservers/records as instructed, wait for SSL.
2. Set `EXPENSEFLOW_BASE_URL` (or edit `config/app.php`) to the new `https://` URL.
3. Update every place that contains the host (list in `DEPLOYMENT.md` "First-time setup", step 7): shells (`HOST`/`HOME`), `sitemap.xml`, `robots.txt`, `.well-known/security.txt`, (canonical/OG tags in `index.php` follow the base URL automatically), Store listing links.
4. Keep the old subdomain redirecting to the new domain (301) for a while.
5. For mail: use an address on the domain with SPF, DKIM and DMARC records, or a transactional mail service; then update the SMTP secrets.
6. Search Console: add the new property, submit the sitemap, use "Change of address" if both are verified.
7. Build and publish new app versions (Android, exe, Store) because the shells contain the host.

## 7. Monthly health check (10 minutes)

1. Open the site on phone and PC; log in; add and delete a test entry.
2. Download one PDF and one Excel from Full Report.
3. Admin > Security: look for unusual failed logins; Admin > Audit log: unexpected staff actions.
4. Admin > Backups: create and download one.
5. GitHub > Actions: last Deploy is green.
6. Search Console: no new security or indexing issues.
7. Microsoft Partner Center / GitHub Releases: nothing pending.
8. Gmail app password still works (send yourself a "forgot password" code).
