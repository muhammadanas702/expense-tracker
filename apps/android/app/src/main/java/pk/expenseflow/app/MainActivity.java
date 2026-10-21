package pk.expenseflow.app;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.SharedPreferences;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.os.Environment;
import android.view.View;
import android.webkit.CookieManager;
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
    private void checkForUpdate() {
        new Thread(() -> {
            try {
                HttpURLConnection c = (HttpURLConnection) new URL(RELEASE_API).openConnection();
                c.setConnectTimeout(6000); c.setReadTimeout(6000);
                c.setRequestProperty("Accept", "application/vnd.github+json");
                if (c.getResponseCode() != 200) return;
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
                if (apk == null || !newer(tag, myVersion())) return;
                SharedPreferences sp = getSharedPreferences("update", MODE_PRIVATE);
                if (tag.equals(sp.getString("later_tag", "")) && System.currentTimeMillis() - sp.getLong("later_at", 0) < 24L * 3600 * 1000) return;
                final String url = apk, ver = tag;
                runOnUiThread(() -> {
                    if (isFinishing()) return;
                    new AlertDialog.Builder(this)
                        .setTitle("Update available")
                        .setMessage("A new version of ExpenseFlow (" + ver + ") is ready. You have " + myVersion() + ".\n\nDownload it, then open the file to install it over the old app. Your data stays.")
                        .setPositiveButton("Download", (d, w) -> startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))))
                        .setNegativeButton("Later", (d, w) -> sp.edit().putString("later_tag", ver).putLong("later_at", System.currentTimeMillis()).apply())
                        .show();
                });
            } catch (Exception ignored) { }
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
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                Uri u = r.getUrl();
                if (u.getPath() != null && u.getPath().endsWith("/auth/logout.php")) clearSnapshots();
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
                q.addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url));
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
