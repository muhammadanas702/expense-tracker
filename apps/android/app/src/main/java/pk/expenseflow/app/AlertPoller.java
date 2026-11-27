package pk.expenseflow.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.job.JobInfo;
import android.app.job.JobScheduler;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Alerts for the app (Part 130b). The app is a WebView, which cannot receive Web Push, so about every 15 minutes Android runs
 * AlertJob, which asks the server for new alerts (POST /app-alerts.php with this phone's own secret) and shows them as
 * notifications. Works while the app is closed and after a restart. Not instant: Android decides the exact time.
 */
final class AlertPoller {
    private static final String PREFS = "ef_alerts";
    private static final int JOB_ID = 4802;

    private AlertPoller() { }

    private static SharedPreferences prefs(Context c) { return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE); }

    static boolean hasToken(Context c) { return !prefs(c).getString("token", "").isEmpty(); }

    static void saveToken(Context c, String token, String base) {
        prefs(c).edit().putString("token", token).putString("base", base).apply();
        schedule(c);
    }

    static void clearToken(Context c) {
        prefs(c).edit().clear().apply();
        try {
            JobScheduler js = (JobScheduler) c.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js != null) js.cancel(JOB_ID);
        } catch (Exception ignored) { }
    }

    /** (Re)plans the repeating job when this phone has a secret. Safe to call often (keeps the existing plan). */
    static void schedule(Context c) {
        if (!hasToken(c) || Build.VERSION.SDK_INT < 21) return;
        try {
            JobScheduler js = (JobScheduler) c.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) return;
            for (JobInfo j : js.getAllPendingJobs()) if (j.getId() == JOB_ID) return;
            JobInfo.Builder b = new JobInfo.Builder(JOB_ID, new ComponentName(c, AlertJob.class))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setPersisted(true)
                .setPeriodic(15 * 60 * 1000L);
            js.schedule(b.build());
        } catch (Exception ignored) { }
    }

    /** Asks the server for new alerts and shows them. Returns quietly when offline. Call from a background thread. */
    static void poll(Context c) {
        SharedPreferences sp = prefs(c);
        String token = sp.getString("token", "");
        String base = sp.getString("base", "");
        if (token.isEmpty() || !base.startsWith("https://")) return;
        try {
            JSONObject body = new JSONObject();
            body.put("token", token);
            body.put("action", "poll");
            String url = base + "/app-alerts.php";
            String text = null;
            for (int hop = 0; hop < 4 && text == null; hop++) { // the old address forwards to the new one: keep POST across redirects
                HttpURLConnection h = (HttpURLConnection) new URL(url).openConnection();
                h.setInstanceFollowRedirects(false);
                h.setRequestMethod("POST");
                h.setDoOutput(true);
                h.setConnectTimeout(8000);
                h.setReadTimeout(8000);
                h.setRequestProperty("Content-Type", "application/json");
                try (OutputStream os = h.getOutputStream()) { os.write(body.toString().getBytes(StandardCharsets.UTF_8)); }
                int code = h.getResponseCode();
                if (code == 301 || code == 302 || code == 303 || code == 307 || code == 308) {
                    String loc = h.getHeaderField("Location");
                    if (loc == null || !loc.startsWith("https://")) return;
                    url = loc;
                    continue;
                }
                if (code == 401) { clearToken(c); return; } // this phone was removed on the server: stop asking
                if (code != 200) return;
                try (InputStream in = h.getInputStream(); ByteArrayOutputStream bo = new ByteArrayOutputStream()) {
                    byte[] buf = new byte[4096];
                    int n;
                    while ((n = in.read(buf)) > 0) bo.write(buf, 0, n);
                    text = bo.toString("UTF-8");
                }
            }
            if (text == null) return;
            JSONArray items = new JSONObject(text).optJSONArray("items");
            if (items == null) return;
            for (int i = 0; i < items.length(); i++) {
                JSONObject it = items.optJSONObject(i);
                if (it != null) show(c, base, it);
            }
        } catch (Exception e) {
            // offline or the server is busy: the next run tries again (unread alerts stay on the server for 3 days)
        }
    }

    private static void show(Context c, String base, JSONObject it) {
        NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || !nm.areNotificationsEnabled()) return;
        if (Build.VERSION.SDK_INT >= 26) nm.createNotificationChannel(new NotificationChannel("alerts", "Alerts", NotificationManager.IMPORTANCE_DEFAULT));
        String path = it.optString("url", "");
        if (path.startsWith("/") || path.contains(":") || path.contains("..")) path = ""; // only a page of this site, never another address
        Intent open = new Intent(c, MainActivity.class);
        open.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        if (!path.isEmpty()) open.putExtra("open_path", path);
        int id = 5000 + (it.optInt("id") % 100000);
        PendingIntent pi = PendingIntent.getActivity(c, id, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(c, "alerts") : new Notification.Builder(c);
        String title = it.optString("title", "ExpenseFlow");
        String text = it.optString("body", "");
        b.setSmallIcon(android.R.drawable.stat_notify_chat).setContentTitle(title).setContentText(text)
            .setStyle(new Notification.BigTextStyle().bigText(text)).setContentIntent(pi).setAutoCancel(true);
        nm.notify(id, b.build());
    }
}
