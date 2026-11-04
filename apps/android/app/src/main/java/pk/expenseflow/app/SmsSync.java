package pk.expenseflow.app;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.regex.Pattern;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Reading payment SMS on the phone and sending them to the server in the background (Part 48d).
 * The phone holds a per-device secret (given by the server only while "Add payments automatically" is on) in private
 * preferences. When a message arrives, SmsReceiver calls sync(): it reads the inbox exactly like the Quick add page does
 * (same ids and dates, so the server never adds a message twice) and posts what was not sent yet.
 */
final class SmsSync {
    private SmsSync() { }

    private static final String PREFS = "sms_sync";
    private static final Pattern AMOUNT = Pattern.compile("(?i)(rs\\.?|pkr|usd|aed|sar|gbp|eur|\\$)\\s*[0-9][0-9,]*(\\.[0-9]{1,2})?|[0-9][0-9,]*(\\.[0-9]{1,2})?\\s*(pkr|rs)\\b");
    private static final Pattern MONEY = Pattern.compile("(?i)\\b(debited|credited|received|paid|payment|spent|purchase|purchased|sent|withdrawn|withdrawal|charged|deposited|transferred|salary|refund|deducted)\\b");
    private static final Pattern OTP = Pattern.compile("(?i)\\b(otp|one[- ]time|verification code|login code|passcode|pin)\\b");

    /** The newest money messages of the last {days} days (max 40): amount + a money word, never a code. Empty without permission. */
    static JSONArray readMoneySms(Context c, int days) {
        JSONArray out = new JSONArray();
        if (c.checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) return out;
        long since = System.currentTimeMillis() - days * 24L * 3600 * 1000;
        try (Cursor cur = c.getContentResolver().query(Uri.parse("content://sms/inbox"), new String[]{"_id", "address", "body", "date"}, "date >= ?", new String[]{String.valueOf(since)}, "date DESC LIMIT 300")) {
            if (cur == null) return out;
            while (cur.moveToNext() && out.length() < 40) {
                String body = cur.getString(2);
                if (body == null || body.length() > 600) continue;
                if (OTP.matcher(body).find() || !AMOUNT.matcher(body).find() || !MONEY.matcher(body).find()) continue;
                JSONObject o = new JSONObject();
                o.put("id", cur.getLong(0));
                o.put("from", cur.getString(1) == null ? "" : cur.getString(1));
                o.put("date", cur.getLong(3));
                o.put("text", body);
                out.put(o);
            }
        } catch (Exception e) {
            return new JSONArray();
        }
        return out;
    }

    /**
     * Counts for the "Check my SMS setup" panel (nothing leaves the phone except what the page asks for):
     * {total: inbox messages of the last {days} days, money: how many look like a payment, moneyNew: of those, newer than {sinceMs},
     *  newest: date of the newest inbox message, senders: up to 5 sender names of payment-looking messages, error: text if the inbox could not be read}.
     */
    static JSONObject probe(Context c, int days, long sinceMs) {
        JSONObject o = new JSONObject();
        try {
            o.put("total", 0); o.put("money", 0); o.put("moneyNew", 0); o.put("newest", 0);
            JSONArray senders = new JSONArray();
            o.put("senders", senders);
            if (c.checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) { o.put("error", "no_permission"); return o; }
            long since = System.currentTimeMillis() - days * 24L * 3600 * 1000;
            try (Cursor cur = c.getContentResolver().query(Uri.parse("content://sms/inbox"), new String[]{"address", "body", "date"}, "date >= ?", new String[]{String.valueOf(since)}, "date DESC LIMIT 1000")) {
                if (cur == null) { o.put("error", "no_cursor"); return o; }
                int total = 0, money = 0, moneyNew = 0; long newest = 0;
                Set<String> seen = new HashSet<>();
                while (cur.moveToNext()) {
                    total++;
                    String body = cur.getString(1); long date = cur.getLong(2);
                    if (date > newest) newest = date;
                    if (body == null || body.length() > 600) continue;
                    if (OTP.matcher(body).find() || !AMOUNT.matcher(body).find() || !MONEY.matcher(body).find()) continue;
                    money++;
                    if (date >= sinceMs - 60000) moneyNew++;
                    String a = cur.getString(0);
                    if (a != null && seen.size() < 5 && seen.add(a)) senders.put(a);
                }
                o.put("total", total); o.put("money", money); o.put("moneyNew", moneyNew); o.put("newest", newest);
            }
        } catch (Exception e) {
            try { o.put("error", "exception"); } catch (Exception ignored) { }
        }
        return o;
    }

    private static SharedPreferences prefs(Context c) { return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE); }

    static boolean hasToken(Context c) { return !prefs(c).getString("token", "").isEmpty(); }

    static void saveToken(Context c, String token, String base) {
        prefs(c).edit().putString("token", token).putString("base", base).putString("sent", "").apply();
    }

    static void clearToken(Context c) { prefs(c).edit().clear().apply(); }

    /** Sends messages not sent before. Returns true when there was something new to send (so the caller may try again later if not). */
    static boolean sync(Context c) {
        SharedPreferences sp = prefs(c);
        String token = sp.getString("token", "");
        String base = sp.getString("base", "");
        if (token.isEmpty() || !base.startsWith("https://")) return true;
        try {
            JSONArray all = readMoneySms(c, 1);
            Set<String> sent = new HashSet<>(Arrays.asList(sp.getString("sent", "").split(",")));
            JSONArray fresh = new JSONArray();
            List<String> ids = new ArrayList<>();
            for (int i = 0; i < all.length(); i++) {
                JSONObject o = all.getJSONObject(i);
                String id = String.valueOf(o.getLong("id"));
                if (!sent.contains(id)) { fresh.put(o); ids.add(id); }
            }
            if (fresh.length() == 0) return false;
            JSONObject body = new JSONObject();
            body.put("token", token);
            body.put("action", "import");
            body.put("messages", fresh);
            HttpURLConnection h = (HttpURLConnection) new URL(base + "/quick-add-import.php").openConnection();
            h.setRequestMethod("POST");
            h.setDoOutput(true);
            h.setConnectTimeout(5000);
            h.setReadTimeout(5000);
            h.setRequestProperty("Content-Type", "application/json");
            try (OutputStream os = h.getOutputStream()) { os.write(body.toString().getBytes(StandardCharsets.UTF_8)); }
            int code = h.getResponseCode();
            if (code == 401) { clearToken(c); return true; } // the account turned auto-add off or removed this phone
            if (code != 200) return true;
            String text;
            try (InputStream in = h.getInputStream(); ByteArrayOutputStream bo = new ByteArrayOutputStream()) {
                byte[] buf = new byte[4096];
                int n;
                while ((n = in.read(buf)) > 0) bo.write(buf, 0, n);
                text = bo.toString("UTF-8");
            }
            JSONObject r = new JSONObject(text);
            if (r.optBoolean("off")) { clearToken(c); return true; }
            StringBuilder keep = new StringBuilder(sp.getString("sent", ""));
            for (String id : ids) keep.append(',').append(id);
            String[] parts = keep.toString().split(",");
            StringBuilder trimmed = new StringBuilder();
            for (int i = Math.max(0, parts.length - 300); i < parts.length; i++) if (!parts[i].isEmpty()) trimmed.append(parts[i]).append(',');
            sp.edit().putString("sent", trimmed.toString()).apply();
            notifyResult(c, r);
        } catch (Exception e) {
            // offline or server busy: the same messages are tried again with the next SMS, or when the app is opened
        }
        return true;
    }

    private static void notifyResult(Context c, JSONObject r) {
        int added = r.optInt("added"), review = r.optInt("review");
        if (added == 0 && review == 0) return;
        String text;
        JSONArray items = r.optJSONArray("items");
        if (added == 1 && items != null && items.length() == 1) {
            JSONObject it = items.optJSONObject(0);
            text = ("income".equals(it.optString("kind")) ? "Income added: " : "Expense added: ") + it.optString("title") + " · " + it.optString("currency") + " " + it.optString("amount");
        } else if (added > 0) {
            text = added + " payments added from SMS";
        } else {
            text = "";
        }
        if (review > 0) text += (text.isEmpty() ? "" : " · ") + review + (review == 1 ? " message needs" : " messages need") + " your check";
        NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || !nm.areNotificationsEnabled()) return;
        if (Build.VERSION.SDK_INT >= 26) nm.createNotificationChannel(new NotificationChannel("sms_sync", "Payments from SMS", NotificationManager.IMPORTANCE_DEFAULT));
        Intent open = new Intent(c, MainActivity.class);
        open.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pi = PendingIntent.getActivity(c, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(c, "sms_sync") : new Notification.Builder(c);
        b.setSmallIcon(android.R.drawable.stat_notify_chat).setContentTitle("ExpenseFlow").setContentText(text).setContentIntent(pi).setAutoCancel(true);
        nm.notify(4801, b.build());
    }
}
