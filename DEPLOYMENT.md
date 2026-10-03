# ExpenseFlow PWA deployment

The `.github/workflows/deploy.yml` workflow deploys the site to InfinityFree when a commit is pushed to the `main` branch. It builds the private database and mail configuration in the temporary GitHub Actions runner, then uploads the website to `htdocs/` over explicit FTPS. The production credentials are never stored in tracked project files.

## GitHub Actions secrets

In the repository, open **Settings → Secrets and variables → Actions** and create these repository secrets:

| Secret | Value to enter |
| --- | --- |
| `FTP_SERVER` | The FTP hostname shown for this InfinityFree account; `ftpupload.net` supports the host's TLS certificate. |
| `FTP_USERNAME` | The account's FTP username. |
| `FTP_PASSWORD` | The account's FTP password. |
| `EXPENSEFLOW_DB_HOST` | The live MySQL hostname shown in the hosting control panel. |
| `EXPENSEFLOW_DB_NAME` | The live ExpenseFlow database name. |
| `EXPENSEFLOW_DB_USERNAME` | The live database username. |
| `EXPENSEFLOW_DB_PASSWORD` | The live database password. |
| `EXPENSEFLOW_SMTP_USERNAME` | The Gmail address used by password-reset email. |
| `EXPENSEFLOW_SMTP_PASSWORD` | The Gmail app password used by SMTP. Do not use or share a normal Google account password. |
| `EXPENSEFLOW_MAIL_FROM` | Optional sender address. If omitted, the SMTP username is used. |

The SMTP host and port default to Gmail's `smtp.gmail.com:587` with TLS. The workflow stops before FTP upload if required database or SMTP secrets are missing. FTP deployment uses FTPS on port 21 and does not enable clean-slate deletion.

## Local secrets and private files

`config/db.local.php` and `config/mail.local.php` are private local configuration files and are ignored by Git. Do not add them to a commit. The deployment workflow creates production versions from GitHub Actions secrets. SQL dumps, ZIP backups, local exports, storage data, and one-time administrator/diagnostic scripts are also excluded from Git or deployment.

## Installing the PWA

After the workflow succeeds and HTTPS is active for `https://expenseflow.rf.gd`, open the site in Chrome on Android and choose **Install app** from Chrome's menu. On iPhone/iPad, open the site in Safari and use **Share → Add to Home Screen**. Local development works at `http://localhost/expense-tracker/`.
