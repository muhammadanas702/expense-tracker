# Publishing ExpenseFlow on the Microsoft Store (free)

Registration is free for individuals. The release workflow already builds the Store package
(`ExpenseFlow-store-msix` artifact, file `ExpenseFlow-Store.msix`) every time you run **Release**.

## Identity (already filled in)
Store name `ExpenseFlow - Money Manager`; the identity values from Partner Center are stored in `apps/desktop/package.json` (`build.appx`). The GitHub secrets below are optional overrides.

## One-time setup
1. Go to https://partner.microsoft.com/dashboard , sign in with a Microsoft account, choose **Windows & Xbox**, register as an **Individual** developer (free) and verify your identity.
2. **Apps and games → New product → MSIX or PWA app**, reserve the name **ExpenseFlow** (pick another if taken).
3. Open the product → **Product management → Product identity**. Copy three values:
   - `Package/Identity/Name`  (e.g. `12345YourName.ExpenseFlow`)
   - `Package/Identity/Publisher` (e.g. `CN=AAAA-BBBB-...`)
   - `Package/Properties/PublisherDisplayName`
4. In GitHub → repo **Settings → Secrets and variables → Actions → New repository secret**, add:
   `STORE_IDENTITY_NAME`, `STORE_PUBLISHER`, `STORE_PUBLISHER_DISPLAY_NAME`.

## Every release
1. Run the **Release** workflow with a new version (e.g. 1.0.12).
2. Open the finished run → **Artifacts → ExpenseFlow-store-msix** → download and unzip → `ExpenseFlow-Store.msix`.
3. Partner Center → your product → **Start submission → Packages** → upload the `.msix`.
4. Fill the listing once (Store listing): description, 4+ screenshots (use the web app on desktop), icon (assets/icons/icon-512.png), category *Finance*, age rating questionnaire.
5. Privacy policy URL: `https://expenseflow.rf.gd/privacy.php` — support contact: `https://expenseflow.rf.gd/contact.php`.
6. Submit. Review usually takes a few days. The Store signs the package itself; no certificate to buy.

Notes: the Store build does not use the in-app updater (the Store updates it). The `.exe` from GitHub Releases keeps working for people who prefer it. Version numbers in the Store must always increase; the last number is kept `.0` automatically.

## Notes
- `build.appx.displayName` must equal the reserved Store name exactly ("ExpenseFlow - Money Manager").
- The Store rejects packages whose MinVersion is 10.0.17134.0 or lower; `build.appx.minVersion` is 10.0.17763.0 (Windows 10 1809).
- `runFullTrust` is required by every Electron/desktop app. In Partner Center → Submission options → "Notes for certification" explain: "ExpenseFlow is an Electron desktop app that displays the ExpenseFlow web app; it needs runFullTrust to run as a desktop process."
- **Certification failures seen (Oct 2026) and fixes**
  - *10.3.1 App Is Testable - Test Account*: the sign-up needs an e-mail code, so the tester could not get past the login screen. Fix: create a dedicated review account on the live site, add a few sample entries, and put its e-mail and password in "Notes for certification". Keep that account alive.
  - *10.1.3 Search Terms*: a keyword was another company's product name ("split wise"). Fix: use only generic terms such as `bill splitter, split expenses, shared expenses, group expenses, budget tracker, expense tracker, money manager`. Never use other brands in keywords.
  - Earlier rejections: display name not reserved (use `ExpenseFlow - Money Manager`), MinVersion too low (use `10.0.17763.0`).
- Each resubmission after a failure needs a **Submit for certification** click again; a failed submission stays editable ("In draft").
