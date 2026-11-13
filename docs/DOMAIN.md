# Connecting your own domain (expenseflow.page)

The site runs on the free host (InfinityFree) at `expenseflow.rf.gd`. A domain you own (here `expenseflow.page`, bought at name.com) gives you
an address that **you** control: if the free host ever stops, you point the domain somewhere else (Part 107, standby host) and the address
people use does not change. The free address keeps working in the meantime.

> InfinityFree changes its client area from time to time. The steps below are the usual way; if a button has a different name, the idea is the same.
> The Admin page **Admin > Domain** shows the same list and checks every step for you.

## What you need
- name.com account with `expenseflow.page` (done).
- InfinityFree account with the ExpenseFlow hosting account.
- A free Cloudflare account (cloudflare.com).

## Steps
1. **Add the domain to InfinityFree.** Client area > your hosting account > *Addon Domains* (or *Domain Names*) > add `expenseflow.page`, and also `www.expenseflow.page`. Write down the **IP address** shown for the account (client area > the account > *IP Address*).
2. **Add the site to Cloudflare.** Cloudflare > *Add a domain* > `expenseflow.page` > Free plan. Cloudflare gives you two name servers (for example `xxx.ns.cloudflare.com`).
3. **Point name.com to Cloudflare.** name.com > My Domains > `expenseflow.page` > *Nameservers* > *Custom* > remove the name.com ones, paste the two from Cloudflare > Save. Cloudflare shows **Active** after a few minutes to a few hours.
4. **DNS records in Cloudflare** (DNS > Records):
   | Type | Name | Content | Proxy |
   |------|------|---------|-------|
   | A | `@` | the InfinityFree IP | **DNS only** (grey cloud) |
   | A | `www` | the InfinityFree IP | **DNS only** (grey cloud) |
5. **Free SSL certificate.** InfinityFree client area > *Free SSL Certificates* > pick `expenseflow.page` > request > when it shows *Ready*, install it. This needs step 4 to be live, so it can take from minutes to a few hours. Open `https://expenseflow.page/` in a private window: no warning = done.
6. **Check.** Admin > Domain > type `expenseflow.page` > *Check*. Three lines: *points to a server*, *https answers*, *ExpenseFlow answers*. All three **OK**?
7. **Make it the main address.** Press *Make it the main address*. From now on the free address and `www` send everybody to `https://expenseflow.page`. The check runs again first, so a half-ready domain is never switched on. People have to sign in once more (a sign-in belongs to one address).
8. **GitHub (monitor).** Repository > Settings > Secrets and variables > Actions > *Variables* > new variable `SITE_URL` = `https://expenseflow.page`, so the uptime monitor watches the new address.
9. **Google sign-in** (only if you use it): Google Cloud console > your OAuth client > add `https://expenseflow.page/auth/google-callback.php` (copy the exact redirect address from the existing entry and change only the host) as an authorised redirect URI, and `https://expenseflow.page` as an authorised origin.
10. **Android app / Windows app** carry the address inside them. When you next build them run `php tools/change-domain.php expenseflow.page` first (it changes every file that contains the address; use `--dry` to look first), see `docs/APPS.md`. The old apps keep working because the free address redirects.

## Optional: Cloudflare in front (orange cloud)
After the site works over https with the grey cloud, you can turn the cloud **orange** (proxied) for speed and protection. Then set Cloudflare > SSL/TLS > **Full** (not Flexible: Flexible causes endless redirects because the site already forces https). If the site shows "too many redirects" or a certificate error, turn the cloud back to grey. InfinityFree may show a visitor-check page to scripts; that is normal and the monitor knows it.

## Going back
Admin > Domain > *Go back to expenseflow.rf.gd*. Or delete `storage/site.json` with the file manager / FTP. The free address always keeps its `/admin/` pages and `/health.php` (they are never redirected), so you can reach the admin panel even if the domain is broken.

## If the free host stops (preview of Part 107)
Because DNS is in Cloudflare, moving is one change: DNS > the two A records > the IP of the new host. Nothing else about the address changes. Part 107 adds the restore guide for the new host (database + files from the off-site backup).

## Common problems
| What you see | What to do |
|---|---|
| Check says *does not point anywhere yet* | Cloudflare is not Active yet, or the A record is missing. Wait 10 minutes, press Check again. |
| *https did not answer* / certificate warning | The SSL certificate is not installed yet (step 5), or the cloud is orange with SSL mode Flexible/Off. Set DNS only and finish step 5. |
| *Opens, but it is not ExpenseFlow* | The domain is not added to the InfinityFree account (step 1), or a parking page is still cached. |
| Redirect loop after switching | Cloudflare SSL mode must be **Full**, or turn the cloud grey. Use *Go back* on the free address meanwhile. |
| Everyone was signed out | Expected once: sign-ins do not move between addresses. |
