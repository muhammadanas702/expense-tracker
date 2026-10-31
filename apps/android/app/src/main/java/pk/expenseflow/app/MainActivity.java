package pk.expenseflow.app;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.IntentFilter;
import android.os.Build;
import android.provider.Settings;
import androidx.core.content.FileProvider;
import android.content.SharedPreferences;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.os.Environment;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import org.json.JSONObject;
import org.json.JSONTokener;
import java.net.HttpURLConnection;
import java.net.URL;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;

/** Thin shell: shows https://expenseflow.rf.gd/ full screen, keeps the login, handles uploads, downloads and Back. */
public class MainActivity extends Activity {
    private static final String HOST = "expenseflow.rf.gd";
    private static final String HOME = "https://" + HOST + "/auth/login.php";
    private static final int PICK = 1;
    private WebView web;
    private ValueCallback<Uri[]> pending;
    private boolean showingSnapshot = false;
    private static final String RELEASE_API = "https://api.github.com/repos/muhammadanas702/expense-tracker/releases/latest";

    // ---- Update check: asks GitHub for the newest release and offers the download when it is newer ----
    private String myVersion() {
        try { return getPackageManager().getPackageInfo(getPackageName(), 0).versionName; } catch (Exception e) { return "1.0.0"; }
    }
    private static int[] parts(String v) {
        String[] p = v.replaceAll("[^0-9.]", "").split("\\.");
        int[] r = new int[3];
        for (int i = 0; i < 3 && i < p.length; i++) { try { r[i] = Integer.parseInt(p[i]); } catch (Exception ignored) { } }
        return r;
    }
    private static boolean newer(String latest, String mine) {
        int[] a = parts(latest), b = parts(mine);
        for (int i = 0; i < 3; i++) { if (a[i] != b[i]) return a[i] > b[i]; }
        return false;
    }
    // ---- Update inside the app: download the APK, then hand it to Android's installer (one tap to confirm) ----
    private File updateFile;
    private boolean waitingForPermission = false;
    private BroadcastReceiver doneReceiver;

    private void downloadUpdate(String url) {
        try {
            updateFile = new File(getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), "ExpenseFlow-update.apk");
            if (updateFile.exists()) updateFile.delete();
            DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            DownloadManager.Request q = new DownloadManager.Request(Uri.parse(url));
            q.setTitle("ExpenseFlow update");
            q.setMimeType("application/vnd.android.package-archive");
            q.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            q.setDestinationUri(Uri.fromFile(updateFile));
            final long id = dm.enqueue(q);
            doneReceiver = new BroadcastReceiver() {
                @Override public void onReceive(Context c, Intent i) {
                    if (i.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1) != id) return;
                    try { unregisterReceiver(this); } catch (Exception ignored) { }
                    doneReceiver = null;
                    installUpdate();
                }
            };
            IntentFilter f = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
            if (Build.VERSION.SDK_INT >= 33) registerReceiver(doneReceiver, f, Context.RECEIVER_EXPORTED); else registerReceiver(doneReceiver, f);
            Toast.makeText(this, "Downloading the update… you will be asked to install it when it finishes.", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(this, "Could not start the download.", Toast.LENGTH_LONG).show();
        }
    }

    private void installUpdate() {
        if (updateFile == null || !updateFile.exists()) { Toast.makeText(this, "The update file is missing. Try again.", Toast.LENGTH_LONG).show(); return; }
        if (Build.VERSION.SDK_INT >= 26 && !getPackageManager().canRequestPackageInstalls()) {
            // First time only: Android asks the user to allow ExpenseFlow to install updates.
            waitingForPermission = true;
            Toast.makeText(this, "Allow ExpenseFlow to install updates, then press Back.", Toast.LENGTH_LONG).show();
            startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getPackageName())));
            return;
        }
        try {
            Uri apk = FileProvider.getUriForFile(this, "pk.expenseflow.app.files", updateFile);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(apk, "application/vnd.android.package-archive");
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (Exception e) {
            Toast.makeText(this, "Could not open the installer.", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (waitingForPermission && Build.VERSION.SDK_INT >= 26 && getPackageManager().canRequestPackageInstalls()) {
            waitingForPermission = false;
            installUpdate();
        }
    }

    /** Small bridge so the Profile page can show the version and run "Check for updates". */
    private class Bridge {
        @JavascriptInterface public String version() { return myVersion(); }
        @JavascriptInterface public void checkUpdate() { runOnUiThread(() -> checkForUpdate(true)); }
    }
    private void note(String m) { runOnUiThread(() -> Toast.makeText(this, m, Toast.LENGTH_LONG).show()); }
    private void checkForUpdate() { checkForUpdate(false); }
    private void checkForUpdate(boolean manual) {
        if (manual) Toast.makeText(this, "Checking for updates…", Toast.LENGTH_SHORT).show();
        new Thread(() -> {
            try {
                HttpURLConnection c = (HttpURLConnection) new URL(RELEASE_API).openConnection();
                c.setConnectTimeout(6000); c.setReadTimeout(6000);
                c.setRequestProperty("Accept", "application/vnd.github+json");
                if (c.getResponseCode() != 200) { if (manual) note("Could not check right now. Try again later."); return; }
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                try (InputStream in = c.getInputStream()) { byte[] buf = new byte[4096]; int n; while ((n = in.read(buf)) > 0) out.write(buf, 0, n); }
                JSONObject j = new JSONObject(out.toString("UTF-8"));
                String tag = j.getString("tag_name");
                String apk = null;
                org.json.JSONArray assets = j.getJSONArray("assets");
                for (int i = 0; i < assets.length(); i++) {
                    JSONObject a = assets.getJSONObject(i);
                    if (a.getString("name").endsWith(".apk")) { apk = a.getString("browser_download_url"); break; }
                }
                if (apk == null || !newer(tag, myVersion())) { if (manual) note("You have the latest version (" + myVersion() + ")."); return; }
                SharedPreferences sp = getSharedPreferences("update", MODE_PRIVATE);
                if (!manual && tag.equals(sp.getString("later_tag", "")) && System.currentTimeMillis() - sp.getLong("later_at", 0) < 24L * 3600 * 1000) return;
                final String url = apk, ver = tag;
                runOnUiThread(() -> {
                    if (isFinishing()) return;
                    new AlertDialog.Builder(this)
                        .setTitle("Update available")
                        .setMessage("A new version of ExpenseFlow (" + ver + ") is ready. You have " + myVersion() + ".\n\nThe update downloads here and Android then asks you to confirm the install. Your data stays.")
                        .setPositiveButton("Update now", (d, w) -> downloadUpdate(url))
                        .setNegativeButton("Later", (d, w) -> sp.edit().putString("later_tag", ver).putLong("later_at", System.currentTimeMillis()).apply())
                        .show();
                });
            } catch (Exception ignored) { if (manual) note("No internet connection."); }
        }).start();
    }

    // ---- Saved pages: a copy of each page you open is kept on the phone and shown when there is no internet ----
    private File snapFile(String url) {
        try {
            String key = url.replaceAll("#.*$", "");
            byte[] d = MessageDigest.getInstance("SHA-1").digest(key.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            for (byte x : d) sb.append(String.format("%02x", x));
            File dir = new File(getFilesDir(), "snap");
            if (!dir.exists()) dir.mkdirs();
            return new File(dir, sb + ".html");
        } catch (Exception e) { return null; }
    }
    private void clearSnapshots() {
        File[] f = new File(getFilesDir(), "snap").listFiles();
        if (f != null) for (File x : f) x.delete();
    }
    private void saveSnapshot(String url) {
        web.evaluateJavascript("(function(){return document.documentElement.outerHTML})()", v -> {
            try {
                if (v == null || v.length() < 200) return;
                String html = (String) new JSONTokener(v).nextValue();
                File f = snapFile(url);
                if (f == null) return;
                try (FileOutputStream o = new FileOutputStream(f)) { o.write(("<!DOCTYPE html>" + html).getBytes(StandardCharsets.UTF_8)); }
            } catch (Exception ignored) { }
        });
    }
    private String readSnapshot(String url) {
        try {
            File f = snapFile(url);
            if (f == null || !f.exists()) return null;
            byte[] b = new byte[(int) f.length()];
            try (FileInputStream i = new FileInputStream(f)) { i.read(b); }
            return new String(b, StandardCharsets.UTF_8);
        } catch (Exception e) { return null; }
    }
    private boolean online() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE);
            NetworkCapabilities c = cm.getNetworkCapabilities(cm.getActiveNetwork());
            return c != null && c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        } catch (Exception e) { return true; }
    }
    /** Show the saved copy of this page (or of the dashboard when the login page is asked for). */
    private boolean showSaved(String url) {
        String html = readSnapshot(url);
        String base = url;
        if (html == null && url.contains("/auth/login.php")) {
            base = HOME.replace("auth/login.php", "dashboard.php");
            html = readSnapshot(base);
        }
        if (html == null) return false;
        showingSnapshot = true;
        web.loadDataWithBaseURL(base, html, "text/html", "UTF-8", base);
        return true;
    }

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        web = new WebView(this);
        setContentView(web);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setAllowFileAccess(false);
        s.setMediaPlaybackRequiresUserGesture(true);
        s.setUserAgentString(s.getUserAgentString() + " ExpenseFlowApp/" + myVersion());
        web.addJavascriptInterface(new Bridge(), "EFApp");
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                Uri u = r.getUrl();
                if (u.getPath() != null && u.getPath().endsWith("/auth/logout.php")) clearSnapshots();
                // Google refuses sign-in inside a web view: its first step goes to the normal browser (the page keeps waiting).
                if (HOST.equals(u.getHost()) && u.getPath() != null && u.getPath().endsWith("/auth/google.php")) {
                    try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) { }
                    return true;
                }
                if (HOST.equals(u.getHost()) || "file".equals(u.getScheme())) return false;
                try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) { }
                return true;
            }
            @Override
            public void onPageFinished(WebView v, String url) {
                if (showingSnapshot || url == null || !url.contains(HOST)) { showingSnapshot = false; return; }
                if (url.contains("/auth/login.php") || url.contains("/auth/register.php")) { clearSnapshots(); return; }
                if (!url.contains("/auth/") && !url.contains("/admin/") && online()) saveSnapshot(url);
            }
            @Override
            public void onReceivedError(WebView v, WebResourceRequest r, WebResourceError e) {
                if (!r.isForMainFrame()) return;
                if (!showSaved(r.getUrl().toString())) v.loadUrl("file:///android_asset/offline.html");
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> cb, FileChooserParams p) {
                if (pending != null) pending.onReceiveValue(null);
                pending = cb;
                try {
                    startActivityForResult(p.createIntent(), PICK);
                } catch (Exception e) {
                    pending = null;
                    return false;
                }
                return true;
            }
        });
        web.setDownloadListener((url, ua, cd, mime, len) -> {
            try {
                DownloadManager.Request q = new DownloadManager.Request(Uri.parse(url));
                CookieManager.getInstance().flush();
                String ck = CookieManager.getInstance().getCookie(url);
                if (ck != null) q.addRequestHeader("Cookie", ck);
                // Same browser identity as the page, so the host serves the file and not a "checking your browser" page.
                if (ua != null) q.addRequestHeader("User-Agent", ua);
                q.addRequestHeader("Referer", HOME);
                q.addRequestHeader("Accept", "*/*");
                q.setMimeType(mime);
                q.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                q.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, URLUtil.guessFileName(url, cd, mime));
                ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(q);
                Toast.makeText(this, "Downloading…", Toast.LENGTH_SHORT).show();
            } catch (Exception e) {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            }
        });
        if (b != null) web.restoreState(b); else web.loadUrl(HOME);
        checkForUpdate();
        web.setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        if (req == PICK && pending != null) {
            pending.onReceiveValue(res == RESULT_OK && data != null ? WebChromeClient.FileChooserParams.parseResult(res, data) : null);
            pending = null;
        }
        super.onActivityResult(req, res, data);
    }

    @Override protected void onSaveInstanceState(Bundle b) { super.onSaveInstanceState(b); web.saveState(b); }
    @Override protected void onPause() { super.onPause(); CookieManager.getInstance().flush(); }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) web.goBack(); else super.onBackPressed();
    }
}
