# Deployment

The full, current guide moved to **[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)** (website deploy, GitHub secrets, app releases, rollback, moving to your own domain: see also [`docs/OPERATIONS.md`](docs/OPERATIONS.md)).

Short version: every push to `main` runs `.github/workflows/deploy.yml`, which writes the private DB/SMTP config from GitHub secrets on the runner and uploads the site to InfinityFree over FTPS. App builds (APK, exe, Store MSIX) are made by `.github/workflows/release.yml` when you tag `vX.Y.Z` or run it by hand.
