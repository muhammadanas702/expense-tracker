/* Quick add > "Check my SMS setup" (Android app only). Looks at the phone (permissions, saved secret, inbox counts) and at the server
   (auto-add, phones, last background send, results of the last 7 days) and lists each step as OK / needs attention with what to do.
   Reading the inbox only COUNTS messages; nothing is sent anywhere. */
(function () {
  'use strict';
  var btn = document.getElementById('sms-check-btn'), list = document.getElementById('sms-check-list'), since = document.getElementById('sms-since');
  var app = window.EFApp;
  if (!btn || !list) { return; }
  var base = (document.querySelector('script[data-base]') || {}).getAttribute ? (document.querySelector('script[data-base]').getAttribute('data-base') || '') : '';
  function row(kind, title, hint) {
    var li = document.createElement('li'); li.className = kind;
    var st = document.createElement('span'); st.className = 'st'; st.textContent = kind === 'ok' ? '✓' : (kind === 'no' ? '✕' : '!');
    var d = document.createElement('div'); d.textContent = title;
    if (hint) { var s = document.createElement('small'); s.textContent = hint; d.appendChild(s); }
    li.appendChild(st); li.appendChild(d); list.appendChild(li);
  }
  function ago(ms, now) {
    var m = Math.max(0, Math.round((now - ms) / 60000));
    return m < 2 ? 'just now' : m < 120 ? m + ' minutes ago' : m < 2880 ? Math.round(m / 60) + ' hours ago' : Math.round(m / 1440) + ' days ago';
  }
  function can(name) { return app && typeof app[name] === 'function'; }
  function safe(fn, d) { try { return fn(); } catch (e) { return d; } }
  function run() {
    list.textContent = ''; if (since) { since.hidden = true; }
    if (!app || !can('smsSupported')) { row('no', 'This is not the ExpenseFlow Android app', 'Automatic reading only works in the Android app. Install the newest ExpenseFlow.apk from the Releases page.'); return; }
    var ver = safe(function () { return app.version(); }, '');
    var newApp = can('smsHasToken');
    row(newApp ? 'ok' : 'no', 'App version ' + (ver || '?') + (newApp ? '' : ' is too old'), newApp ? '' : 'Background reading needs app 1.0.18 or newer. Install the newest ExpenseFlow.apk (Releases page); your account and data stay.');
    fetch(base + '/quick-add-import.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (st) {
      if (!st) { row('no', 'Could not reach the server', 'Check the internet connection and try again.'); return; }
      row(st.auto ? 'ok' : 'no', st.auto ? 'Auto-add is ON' : 'Auto-add is OFF', st.auto ? '' : 'Press "Turn auto-add on" above. Nothing is added by itself while it is off.');
      var read = safe(function () { return app.smsGranted(); }, false);
      row(read ? 'ok' : 'no', read ? 'Permission to read messages: allowed' : 'Permission to read messages: NOT allowed', read ? '' : 'Phone Settings > Apps > ExpenseFlow > three dots > "Allow restricted settings", then Permissions > SMS > Allow. (Android 13+ blocks this for apps installed outside Google Play until you do.)');
      var recv = can('smsBackgroundReady') ? safe(function () { return app.smsBackgroundReady(); }, false) : false;
      row(recv ? 'ok' : 'wn', recv ? 'Permission to receive messages: allowed' : 'Permission to receive messages: not allowed', recv ? '' : 'Without it new messages are only added when you open the app. Allow SMS under the app permissions.');
      var notif = can('smsNotifGranted') ? safe(function () { return app.smsNotifGranted(); }, true) : true;
      if (!notif) { row('wn', 'Notifications are not allowed', 'You will not see "Added Rs. …" notices. Allow Notifications for ExpenseFlow in the app settings.'); }
      var has = newApp ? safe(function () { return app.smsHasToken(); }, false) : false;
      row(has && st.devices > 0 ? 'ok' : 'no', has ? (st.devices > 0 ? 'This phone is registered for background sending' : 'The phone has a secret but the server does not know it') : 'This phone is not registered for background sending',
        has && st.devices > 0 ? '' : 'Open this page with auto-add ON and permissions allowed, wait a few seconds, and press the check again. It registers itself.');
      if (st.devices > 0) {
        row(st.last_sync ? 'ok' : 'wn', st.last_sync ? 'Last background send: ' + ago(st.last_sync, st.now) : 'The phone has never sent a message in the background yet', st.last_sync ? '' : 'It sends when a new payment message arrives. Some phones stop apps in the background: set ExpenseFlow battery to "Unrestricted"/"No restrictions".');
      }
      var c = st.counts || {};
      row('ok', 'Last 7 days: ' + (c.added || 0) + ' added, ' + (c.review || 0) + ' need your check, ' + (c.ignored || 0) + ' ignored', (c.ignored || 0) > 0 ? 'Ignored ones are listed under "Skipped by the filter" below, where you can still add them.' : '');
      if (!read || !can('smsProbe')) { if (read && !can('smsProbe')) { row('wn', 'The inbox counter needs a newer app', 'Install the newest ExpenseFlow.apk to see how many payment messages the phone can see.'); } return; }
      var p = {};
      try { p = JSON.parse(app.smsProbe(30, st.since || 0)); } catch (e) { p = { error: 'exception' }; }
      if (p.error) { row('no', 'The phone would not let the app read the inbox (' + p.error + ')', 'Allow SMS permission (see above). If it is allowed, your messages app may keep them somewhere Android does not share (RCS/chat features of Google Messages): try turning off "Chat features" there, or use Share > ExpenseFlow on a message.'); return; }
      if (!p.total) { row('no', 'The app sees 0 messages from the last 30 days', 'Either there are none, or your messages app hides them from other apps (RCS / chat features). Turn off "Chat features" in Google Messages, or use Share > ExpenseFlow on a message.'); return; }
      row('ok', 'The app can see ' + p.total + ' messages from the last 30 days');
      row(p.money ? 'ok' : 'wn', p.money + ' of them look like payments' + (p.senders && p.senders.length ? ' (from ' + p.senders.join(', ') + ')' : ''), p.money ? '' : 'A payment message needs an amount (like Rs. 500) and a word like debited / received / paid. Codes (OTP) are skipped on purpose. If your bank texts differently, tell me one example.');
      if (st.auto && p.money && !p.moneyNew) {
        row('wn', 'All of those payment messages are older than the moment auto-add was turned on', 'Auto-add only adds NEW messages. You can include the last few days below.');
        if (since) { since.hidden = false; }
      } else if (st.auto && p.moneyNew) {
        row('ok', p.moneyNew + ' payment message(s) are new enough to be added', 'Press "Check for new messages now" above if they are not in your list yet.');
      }
    }).catch(function () { row('no', 'Could not reach the server', 'Check the internet connection and try again.'); });
  }
  btn.addEventListener('click', run);
})();
