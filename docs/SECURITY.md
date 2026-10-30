# ExpenseFlow security rules

What protects the app, where it lives in code, and the checklist you must follow when adding anything. Report a vulnerability through Help & support in the app or `https://expenseflow.rf.gd/contact.php` (also listed in `/.well-known/security.txt`).

## 1. Layers

| Layer | What | Where |
| --- | --- | --- |
| Passwords | `password_hash()` (bcrypt). Policy: 8-72 chars with upper, lower, number, symbol | `includes/security.php` `password_policy_error()` |
| Sessions | HttpOnly + SameSite=Lax cookie (Secure on HTTPS), ID regenerated at login, 3-day idle timeout, 30-day cookie life, session files in `storage/sessions` | `secure_session_start()`, `session_after_login()` |
| CSRF | Token in every form (`csrf_field()`), checked by `require_post_csrf()` before any state change | `includes/security.php` |
| SQL injection | PDO prepared statements only (exception error mode) | every `lib.php`, `config/db.php` |
| XSS | Every printed value goes through `e()` (`htmlspecialchars`) | all pages |
| Headers | `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (camera/mic/geo off), CSP `frame-ancestors 'self'; base-uri 'self'; object-src 'none'`, HSTS on HTTPS | `send_security_headers()` and `.htaccess` |
| Rate limits | DB-backed buckets (`rl_key/rl_blocked/rl_hit/rl_clear`) | login 5/15 min per e-mail+IP, 20 per e-mail, 100 per IP; register 10/hour/IP; reset code 5/hour per e-mail, 20 per IP; wrong sign-up code 5 tries |
| Enumeration | Register and friend-request replies never reveal whether an e-mail has an account (an "account exists" mail is sent instead) | `auth/register.php`, `modules/friends` |
| Roles | server-side permission check on every admin page; menu hiding is cosmetic | `modules/admin/lib.php` `admin_can()` |
| Audit | every staff action is logged (`admin_audit`); failed logins in `security_events`; staff can block IPs | `modules/security`, `admin/audit.php` |
| File access | `modules/`, `includes/`, `config/`, `storage/`, `vendor/`, `*.log/.sql/.md/.yml/.ini` are denied by `.htaccess` and `router.php`; `*.local.php` private config denied | `.htaccess`, `router.php` |
| Uploads | Receipts: type + size check (JPG/PNG/WebP, 3 MB), re-encoded to JPEG (strips EXIF and hidden code), random file name, served only to group members | `modules/receipts` |
| Exports | Cells that start with `= + - @` are written as text (no Excel formula injection) | `includes/export.php` |
| Account deletion | needs the password; cascades data and runs cleanup hooks | `auth/delete-account.php` |
| Privacy | Chart.js self-hosted, no third-party trackers, no ad scripts; fonts are system fonts | `assets/lib/` |
| Crawlers | `robots.txt` blocks all signed-in areas; auth pages send `<meta name="robots" content="noindex">` | `robots.txt` |

## 2. Secrets

- Never commit `config/db.local.php`, `config/mail.local.php`, SQL dumps, backups or exported files. They are in `.gitignore`.
- Production secrets live only in **GitHub Actions secrets** (list in [`DEPLOYMENT.md`](DEPLOYMENT.md)); the deploy workflow writes the private config files on the runner.
- Use a Gmail **app password** for SMTP, never the account password. Rotate it and the DB password if either was ever pasted somewhere public.
- `apps/android/sideload.jks` is a *sideload* signing key kept in the repo on purpose so every APK installs as an update of the previous one. It is not a Play Store key. If you ever publish on Google Play, create a new upload key and keep it out of Git.

## 3. Checklist for every new page or feature

1. Starts with `require includes/security.php; secure_session_start();`
2. Signed-in pages: redirect to login when `$_SESSION['user_id']` is missing; load the user row and drop the session if the row is gone.
3. Admin pages: `admin/auth.php` first, then `admin_can()` for the exact permission.
4. Anything that changes data is **POST**, calls `require_post_csrf()`, never a GET link.
5. Validate and clean every input: `clean_text`, `clean_multiline`, `parse_amount`, `valid_date`, `normalize_currency`, `(int)` casts for ids.
6. Check **ownership** on every id you receive: the row must belong to `$_SESSION['user_id']` (Personal) or the user must be a member of the group (Shared). An unguessable id is not authorisation.
7. SQL: prepared statements; never concatenate user input.
8. Output: `e()` for text, `ef_url()` for links; never echo raw `$_GET/$_POST`.
9. Anything that can be hammered (login, codes, mail sending, search by e-mail): add `rl_*` limits.
10. Anything that reveals existence of an account: make the answer identical for "exists" and "does not exist".
11. Log with `logAction()` (users) or `admin_audit()` (staff).
12. Downloads: send `Content-Type`, `Content-Disposition: attachment`, `nosniff`, and no cache; check permission before streaming.
13. Add a test in `tests/` for the new rule (a non-member must get 403/redirect).

## 4. Known limits (be honest with yourself)

- Runs on free shared hosting: no WAF, no automatic backups, no real mail domain. Mail from a Gmail SMTP account may land in spam until the site has its own domain with SPF/DKIM.
- The free subdomain `expenseflow.rf.gd` is on a shared reputation. Google Safe Browsing can flag sign-in pages on such subdomains as "Deceptive pages" (it happened in October 2026). Mitigation done: clear "who we are / we never ask for bank or card details" text and policy links on every sign-in page. **Permanent fix: use your own domain** (see [`OPERATIONS.md`](OPERATIONS.md)).
- CSP currently only restricts framing/base/object; a full script CSP would require removing inline scripts first.
- Receipt files are not part of the SQL backup.
- No two-factor login yet.

## 5. If something goes wrong

| Event | Action |
| --- | --- |
| Suspected account abuse | Admin > Users: block the user (signs them out), check Audit/User logs, block the IP in Admin > Security |
| Leaked DB/SMTP password | change it at the host / Google, update GitHub secrets, run **Deploy** workflow |
| Attack traffic | Admin > Security: block IPs; Admin > Settings: turn on maintenance mode (staff still get in) |
| Site flagged by Google | see `OPERATIONS.md` "Safe Browsing" |
| Need to restore | Admin > Backups, import `.sql` in phpMyAdmin |
