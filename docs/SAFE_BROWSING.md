# Google "Dangerous site" / "Social engineering content" warnings

**What happened:** Google Search Console (message WNC-806900, 1 Nov 2026) said Safe Browsing found social engineering content on
`http://expenseflow.rf.gd/` (the **plain-http** address of the landing page). Chrome then shows a red warning, and the Android app's
web view shows the same "Dangerous site" screen. Nothing was hacked: Safe Browsing guesses from patterns, and a sign-in page on a free,
shared sub-domain (`*.rf.gd`) next to an app download looks like what phishing sites do. This has now happened more than once.

**What code can and cannot do.** Code can remove the patterns that trigger the guess and make the warning harmless inside the app. Only Google
can lift the warning (a review), and only a domain of your own makes it stop coming back.

## What is in the code (Part 61)

| Change | Why |
|---|---|
| Plain http is redirected (301) to https; any other host name goes to the one address (`app_enforce_site()` in `config/app.php`). Loop-proof: `?ef_https=1` and a Secure cookie `ef_s`. Switch off: `EXPENSEFLOW_NO_REDIRECT=1`. | Google flagged the **http** address. Nothing is served unencrypted now. |
| The public landing page no longer tells people how to get around Android or Windows warnings ("allow installing from this source", "Run anyway", Play Protect / Lite link). Those hints live in Settings > Apps (signed in) and in the docs. | Pages that coach visitors past security warnings are a classic social-engineering signal. |
| Android app: `onSafeBrowsingHit` lets the app go on **for our own address only**, and stops anything else that is flagged. | No "Dangerous site" screen inside the app while the flag exists. |
| `php tools/change-domain.php your.domain` moves the whole project (site address, sitemap, robots, security.txt, README, Android and Windows app address) to a new domain in one step. | Makes the permanent fix a 10-minute job. |

## What you must do (cannot be done from code)

1. Deploy this change (merge the PR; GitHub Actions deploys).
2. Open https://search.google.com/search-console > your site > **Security & Manual Actions > Security issues**. Check that it lists
   `http://expenseflow.rf.gd/`, and add the **https** property too if it is missing.
3. Press **Request a review** and write something like:
   > ExpenseFlow is a personal expense tracker made by a student (Muhammad Anas, UMT Lahore). It is not a copy of any other site and does not
   > ask for bank or card details. The flagged address was the unencrypted http copy of our landing page. We now redirect all http traffic to
   > https, removed download/installation tips from the public page, and added About, Privacy, Terms and Contact pages and security.txt.
   > Source code: https://github.com/muhammadanas702/expense-tracker
4. Also use https://safebrowsing.google.com/safebrowsing/report_error/ ("Report an incorrect phishing warning") for `https://expenseflow.rf.gd/`.
5. A review takes from a few hours to a few days. If the warning comes back, go to step 6.

## The permanent fix: your own domain

The shared `rf.gd` sub-domain is the root cause: Safe Browsing and phishing reports are made per address, thousands of strangers share the
same parent domain, and you cannot set up Google's trust signals (domain verification, e-mail records) for it properly.

1. Buy a cheap domain (about 1-10 USD a year; for example `expenseflow.app`, `expenseflow.pk`, `.xyz`, `.online`).
2. InfinityFree client area > **Addon Domains / Parked Domains**: add it, and at the registrar change the name servers to the ones InfinityFree shows. Turn on the free SSL certificate for it (the host's SSL page).
3. Locally: `php tools/change-domain.php your.domain` (use `--dry` first), then `php tests/change_domain_test.php` and the usual tests, commit and deploy.
   - The old `expenseflow.rf.gd` keeps working only as a redirect to the new address (when both names point to the same hosting account).
4. Google Cloud console > your OAuth client: add `https://your.domain/auth/google-callback.php` as an **authorised redirect URI** (and the new JavaScript origin).
5. Publish a new release (v1.0.xx): the Android and Windows apps carry the address, so they must be rebuilt.
6. Search Console: add the new domain as a property, submit `https://your.domain/sitemap.xml`.
7. Optional but helpful: SPF / DKIM records for the mail sender, so e-mail stops landing in spam (Operations guide).

Until step 5 is done and installed, the old app keeps using the old address (and, with this change, no longer shows the red screen).
