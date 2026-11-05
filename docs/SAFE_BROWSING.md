# "Dangerous site" warnings (Google Safe Browsing)

## What happened
Google Search Console mailed "Social engineering content detected on https://expenseflow.rf.gd/" (message type WNC-806900; sample URL `http://expenseflow.rf.gd/`). Chrome then shows a red "Dangerous site" page, and the Android app's web view shows the same page because it uses the same list.

This is an automatic flag, not a hack: nothing on the site was changed by anyone. Typical reasons for a site like this:
- It is on a **free shared sub-domain** (`rf.gd`). Google judges reputation per domain, and many phishing pages live on free sub-domains, so one flagged neighbour or one login form can mark the whole thing.
- The page the crawler saw was **plain `http://`** with a sign-in form.
- The landing page told people to bypass security prompts ("allow installing from this source", "Windows protected your PC > Run anyway"). Those sentences look like "trick users into installing software" to an automatic reader.

No code can promise the flag never returns. What the code can do is remove the things above; the lasting fix is your own domain.

## What the project now does (Part 61)
1. **Force HTTPS** (Admin > Settings). Turn it on while you are on the `https://` address. It creates `storage/force-https.on`, and `.htaccess` then sends every `http://` address to `https://`. If the site ever shows "too many redirects", delete that file with the host's file manager and the redirect is gone. A browser also remembers HTTPS for 6 months (HSTS header).
2. The public landing page no longer contains install-bypass instructions. They moved to **Settings > Apps > Install help** (signed-in only). Downloads point to the GitHub releases page.
3. **Android app**: `onSafeBrowsingHit` skips Google's warning page only for the app's own host; anything else is stopped. So the app keeps working even when the site is wrongly flagged. (Chrome itself cannot be changed from code.)
4. `tools/switch-domain.php` moves every address in the project to a new domain in one command (see below).

## What you do
1. Deploy, open `https://expenseflow.rf.gd/admin/settings.php`, press **Turn on** under Force HTTPS, then open `http://expenseflow.rf.gd/` and check it lands on https.
2. Search Console > **Security issues** > **Request a review**. Write what changed: HTTPS enforced, install instructions removed from the public page, the site is a personal expense tracker that never asks for bank or card details. Reviews usually take a few days.
3. **Lasting fix: your own domain.** A `.com`/`.net` costs a few dollars a year; the free plan of the host accepts a custom domain, so nothing else needs to be paid for.
   - Buy the domain, add it in the host's control panel, set the name servers / DNS records it shows, wait for the free SSL certificate.
   - On your computer: `php tools/switch-domain.php expenseflow.rf.gd your-new-domain.com` (dry run), then add `--apply`, commit, merge.
   - Update the Google sign-in redirect URI (`https://your-new-domain.com/auth/google-callback.php`) in Google Cloud Console and add the new domain as a Search Console property.
   - Build a new Android / Windows release (the apps have the address inside).
   - Keep `expenseflow.rf.gd` redirecting to the new domain for a while.
4. Google Play Protect's "App blocked" (SMS permission) is a different thing: use the Lite APK (Part 58).

## Limits (honest)
- A review can be refused if Google still sees something it dislikes; the message then says what. Send that text and it can be fixed.
- Free hosts may show visitors/robots a "checking your browser" page; if Google's crawler only sees that, it can look like hidden content. A custom domain on a normal host avoids this.
