# Move ExpenseFlow to a standby host (Part 107)

Use this only when InfinityFree is down or suspended for a long time. Expected time: 30-45 minutes. Everything below uses the newest encrypted copy in the private repository `muhammadanas702/expenseflow-backups` (Admin > Backups > Off-site copy).

## What you need before it happens (do once, 10 minutes)
- The off-site copy is switched on and has at least one file (check the repository).
- You know the **backup passphrase** (without it the copy cannot be opened) and keep it somewhere outside the website.
- A second free PHP + MySQL host account is optional to open in advance; any host with PHP 8.1+, MySQL/MariaDB and FTP works.

## Steps
1. **Get the newest copy.** In the backup repository download the newest `backup-....sql.gz.enc`.
2. **Code.** Download the repository as a zip (GitHub > Code > Download ZIP) and upload the files to the new host's web folder (`htdocs/` or `public_html/`).
3. **Database.** In the new host's panel create an empty MySQL database + user.
4. **Restore.** On your computer (PHP 8 installed), inside the project folder:
   `php tools/restore-standby.php backup-....sql.gz.enc --host=DBHOST --db=DBNAME --user=DBUSER`
   It asks for the backup passphrase and the database password, then prints `Done: 45 tables ... N users`. (If the new host only allows local connections, run the same command in the host's terminal, or unzip the backup with `php tools/decrypt-backup.php` and use the host's phpMyAdmin Import tab.)
5. **Connect the code to the database.** Add `--write-config` in step 4, or create `config/db.local.php` on the host by hand:
   `<?php return ['host'=>'...','dbname'=>'...','username'=>'...','password'=>'...'];`
6. **Point the address.** Cloudflare > DNS: change the two A records (`@` and `www`) for `expenseflow.page` to the new host's IP. Keep them Proxied. SSL/TLS mode **Full** needs a certificate on the new host: use its free Let's Encrypt, or set the mode to **Flexible** until it has one.
7. **Check.** Open `https://expenseflow.page/health.php`, sign in, add one expense. Everyone has to sign in once more (sessions are not in the backup).
8. **When InfinityFree is back:** make a backup on the standby (Admin > Backups), restore it on InfinityFree the same way, point the A records back to `185.27.134.103`.

## Good to know
- The backup does not contain receipt photos, the logo upload or session files. Receipts attached to expenses will be missing after a restore.
- E-mail needs the SMTP secrets again on the new host (`config/mail.php`).
- The restore script only replaces tables with the same names in the database you give it. It never touches other databases.
- Tested by `tests/restore_standby_test.php` (backup -> encrypt -> restore into an empty database -> same row counts).
