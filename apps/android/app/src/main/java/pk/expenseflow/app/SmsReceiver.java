package pk.expenseflow.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Wakes up when an SMS arrives (even when the app is closed) and, if auto-add is on for this phone, sends new payment messages to the server. */
public class SmsReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(final Context ctx, Intent intent) {
        if (intent == null || !"android.provider.Telephony.SMS_RECEIVED".equals(intent.getAction())) return;
        if (!SmsSync.hasToken(ctx)) return;
        final PendingResult pr = goAsync();
        final Context app = ctx.getApplicationContext();
        new Thread(() -> {
            try {
                // The messaging app writes the message into the inbox a moment after the broadcast: wait, and look once more if it was not there yet.
                Thread.sleep(2500);
                if (!SmsSync.sync(app)) {
                    Thread.sleep(2500);
                    SmsSync.sync(app);
                }
            } catch (Throwable ignored) {
            } finally {
                pr.finish();
            }
        }).start();
    }
}
