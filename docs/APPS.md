# ExpenseFlow native apps (Android, Windows, Store)

All apps are **thin shells around the live website**. They open `https://expenseflow.rf.gd/auth/login.php`, keep the login cookie and add native niceties (file upload, downloads, window menu). Because the logic is on the server, a website deploy updates every app immediately.

| Platform | Folder | Technology | Output |
| --- | --- | --- | --- |
| Android | `apps/android` | Java `WebView`, Gradle 8.7, JDK 17 | `ExpenseFlow.apk` (package `pk.expenseflow.app`) |
| Windows | `apps/desktop` | Electron 33.2.1 + electron-builder 25.1.8 | `ExpenseFlow-Setup.exe` (NSIS, appId `pk.expenseflow.desktop`) |
| Microsoft Store | `apps/desktop` (`build.appx`) | same Electron app packaged as MSIX | `ExpenseFlow-Store.msix` |
| iPhone/iPad | not built | install the website with Safari > Share > Add to Home Screen. A real `.ipa` needs a Mac/Apple developer account (postponed) | |
| Any browser | root | PWA (`manifest.json`, `sw.js`) | "Install app" |

## Android (`apps/android`)

- `app/src/main/java/pk/expenseflow/app/MainActivity.java`: full-screen `WebView` with `HOST = expenseflow.rf.gd`, `HOME = https://expenseflow.rf.gd/auth/login.php`.
  - Links on the same host stay inside; others open in the browser (`shouldOverrideUrlLoading`).
  - Photo/receipt upload via `onShowFileChooser`.
  - Downloads (`setDownloadListener` + `DownloadManager`): flushes cookies, copies `Cookie`, `User-Agent`, `Referer`, `Accept` onto the request so PDF/Excel/CSV come as files, not as the login page. If a future host again returns HTML instead of the file, the fallback plan is a JavaScript blob download bridge.
  - Back button goes back in history; an offline snapshot page is shown when the network is down.
- Signing: `apps/android/sideload.jks` (kept in the repo so every APK is signed the same and installs over the old one; not a Play Store key). Version: `-PvName` (version) and `-PvCode` (GitHub run number) from the workflow.
- Install on a phone: open the APK from GitHub Releases, allow "Install unknown apps", install. Play Protect may warn because it is sideloaded.
- Local build (optional): `cd apps/android && gradle assembleRelease -PvName=1.0.0 -PvCode=1`.

## Windows (`apps/desktop`)

- `main.js`: one window loading `HOME`; external links open in the default browser (`setWindowOpenHandler`, `will-navigate`); menu with Home (Ctrl+H); offline page (`offline.html`) when the site cannot be reached; **auto-update check against GitHub Releases** that downloads the new installer after asking. When running as the Store version (`process.windowsStore`) the updater is skipped because the Store updates it.
- `preload.js`: exposes `window.EFApp` (installed `version()` and "Check for updates") so the Profile page can show the app version. `build/icon.ico`: exe + installer icon (multi-size; do not let `.gitignore` hide `apps/**/build/icon.ico`).
- NSIS installer: one-click, per-user (no admin), desktop and Start-menu shortcut, data kept on uninstall.
- Local build (optional, Windows): `cd apps/desktop && npm install && npx electron-builder --win nsis --publish never`.

## Microsoft Store package

- `build.appx` in `apps/desktop/package.json` holds the Store identity: `identityName` `55408MuhammadAnas.ExpenseFlow-MoneyManager`, `publisher` `CN=BD6A12CC-89FA-40A3-8A17-F92091A0CCD3`, `publisherDisplayName` `Muhammad_Anas`, `displayName` `ExpenseFlow - Money Manager` (must equal the reserved name), `minVersion 10.0.17763.0` (Store rejects older), `maxVersionTested 10.0.22621.0`, language `en-US`.
- Tile images: `apps/desktop/build/appx/*.png`.
- The Store signs the package itself; no certificate purchase.
- Capability `runFullTrust` is mandatory for desktop apps; explain it in "Notes for certification".
- Walk-through, certification-failure causes and fixes: [`MICROSOFT_STORE.md`](MICROSOFT_STORE.md).

## What needs a new release and what does not

| Change | New app release? |
| --- | --- |
| Any PHP/CSS/JS change on the site | No, deploy the site only |
| App icon, window title, menu, updater, download handling, permissions | Yes |
| Store listing text/screenshots | No (Partner Center only) |
| Domain change | Yes (`HOST`/`HOME` in both shells) |

## Testing a shell change

- Android: install the APK on a phone; test login, add expense, receipt upload, and Excel/PDF/CSV download from Full Report.
- Windows: run the installer; check the icon in Start menu and taskbar, login, downloads, external link opening, and that the update prompt appears when a higher version exists.

## Android: reading SMS for Quick add (permission asked on tap)
- `AndroidManifest.xml` declares `READ_SMS` (and telephony as NOT required, so tablets still install). Android asks only when the person taps "Allow and read" on Quick add; nothing is read at install or start.
- `MainActivity.Bridge` (`window.EFApp`): `smsSupported()`, `smsGranted()`, `smsAsk()`, `smsRead(days)`, `smsSettings()`. `smsRead` runs on the phone and returns at most 40 messages of the last 1-90 days that contain an amount AND a money word (debited, credited, paid, ...) and are not codes (OTP, PIN, verification). The result goes to the page as JSON and is shown as plain text.
- Only the message the person taps is posted to the server (through the normal paste form); used message ids are remembered in the browser (`localStorage ef_sms_used`).
- Deny: the page says so, offers "Open settings", and paste keeps working. Website, Windows and older Android apps never see the card (`typeof EFApp.smsSupported`).
- This APK is installed from GitHub Releases. Google Play restricts `READ_SMS` heavily: if the app is ever published on Play, replace this with "Share to ExpenseFlow" or the notification listener.
- Needs a new release: run the release workflow with a new version (after v1.0.17) and test on a phone.
