# If the website goes down

ExpenseFlow runs on a free host (InfinityFree). Free hosts can be slow, hit traffic limits or suspend an account. This page lists what protects the data and what to do.

## What protects you (and which Part built it)
| Layer | What it does | Part |
|---|---|---|
| Daily backup on the server | A database copy is made when staff open the admin overview | 52b |
| **Off-site backup** | The newest backup, encrypted, goes to a private GitHub repository. Admin > Backups > "Off-site copy (GitHub)" | 104 |
| **Uptime monitor** | GitHub Actions asks the site every ~10 minutes. When it is down, a GitHub issue "ExpenseFlow is down" opens (GitHub e-mails the owner) and closes when the site is back | 105 |
| **Status page** | A static page (works even when the site is down) showing "working / has a problem" | 105 |
| Own domain on Cloudflare DNS | Lets the address be moved to another host in minutes | 106 (planned) |
| Standby host + restore guide | A second host that can take over from the off-site backup | 107 (planned) |

## When the site is down: 5 steps
1. Open the address in a browser and in a phone with mobile data (rules out your own network).
2. Open the InfinityFree client area: is the account active? Is MySQL running? (A "suspended" message usually means a traffic or policy limit.)
3. Check the GitHub issue "ExpenseFlow is down": it says what the monitor saw (HTTP code, database down, suspended page, no answer).
4. Wait 15 to 30 minutes for a host problem. If it is a suspension, follow the host's instructions.
5. If it will take long, restore on the standby host (Part 107) from the newest copy in the backup repository: `php tools/decrypt-backup.php backup-....sql.enc` gives back the SQL file.

## Setting up the monitor
- The workflow `.github/workflows/uptime.yml` works as soon as it is on `main`. The address checked is the repository variable **SITE_URL** (GitHub > Settings > Secrets and variables > Actions > Variables); without it `https://expenseflow.rf.gd` is used. Change it when the own domain goes live.
- Alerts: GitHub e-mails you for the issue it opens, if notifications for this repository are on (Watch > All activity, or at least Issues).
- **Status page**: the monitor writes `index.html` and `status.json` to the branch `status-page` only when the state changes. Turn it on once: Settings > Pages > Source "Deploy from a branch" > branch `status-page`, folder `/ (root)`. The address is `https://<user>.github.io/<repository>/`.

## Good to know
- A scheduled GitHub workflow can run a few minutes late, and GitHub pauses scheduled workflows of a repository with no activity for 60 days. Pushing to the repository (or running the workflow once by hand) turns it on again.
- The monitor counts the host's own "bot check" page as UP, because that page proves the host is answering. It counts HTTP errors, no answer, a database that does not respond and a "suspended" page as DOWN. One failed try is retried twice (about a minute) before an alert, so a short blip does not wake you up.
- The monitor also calls `health.php?db=1`, which feeds the uptime history and the "Outside monitor" row in Admin > Website health.
