/* Android app only: when "Add payments automatically" is on, read new payment SMS on the phone and send them to
   quick-add-import.php, where the classifier adds the real payments and ignores codes, offers and failed payments.
   Runs at most every 5 minutes (any time on the Quick add page). Nothing happens on the website or without permission. */
(function () {
  'use strict';
  var app = window.EFApp;
  if (!app || typeof app.smsSupported !== 'function' || app.smsSupported() === false) { return; }
  var me = document.currentScript, base = (me && me.getAttribute('data-base')) || '';
  var onQuick = !!document.getElementById('sms-auto');
  var T = 'ef_sms_auto_t', SEEN = 'ef_sms_seen';
  function get(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) { } }
  function toast(t) {
    var b = document.getElementById('ef-sms-toast');
    if (!b) {
      b = document.createElement('a'); b.id = 'ef-sms-toast'; b.href = base + '/quick-add.php';
      b.style.cssText = 'position:fixed;left:12px;right:12px;bottom:84px;z-index:60;background:#0B2545;color:#fff;padding:12px 14px;border-radius:14px;text-align:center;text-decoration:none;font-size:.9rem;box-shadow:0 8px 24px rgba(0,0,0,.25)';
      document.body.appendChild(b); setTimeout(function () { if (b.parentNode) { b.parentNode.removeChild(b); } }, 7000);
    }
    b.textContent = t;
  }
  function run(force) {
    var now = Date.now();
    if (!force && !onQuick && now - parseInt(get(T, '0'), 10) < 300000) { return; }
    put(T, String(now));
    fetch(base + '/quick-add-import.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (st) {
      if (!st) { return; }
      var bgApp = typeof app.smsHasToken === 'function'; // app 1.0.18+: can also work while the app is closed
      if (!st.auto) { if (bgApp) { try { if (app.smsHasToken()) { app.smsClearToken(); } } catch (e) { } } return; }
      var granted = false;
      try { granted = app.smsGranted(); } catch (e) { }
      if (!granted) { if (onQuick) { try { app.smsAsk(); } catch (e) { } } return; }
      var note = document.getElementById('sms-bg');
      if (bgApp) {
        var ready = false, has = false;
        try { ready = app.smsBackgroundReady(); has = app.smsHasToken(); } catch (e) { }
        if (!ready) {
          if (note) { note.textContent = 'While the app is closed: needs the "Receive SMS" permission.'; }
          if (onQuick) { try { app.smsAsk(); } catch (e) { } }
        } else if (!has) {
          // Hand this phone its own secret (only the import endpoint accepts it) so new messages are sent even when the app is closed.
          fetch(base + '/quick-add-import.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: st.csrf, action: 'device_token' }) })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (t) { if (t && t.token) { try { app.smsSetToken(t.token, location.origin); } catch (e) { } if (note) { note.textContent = 'While the app is closed: on.'; } } })
            .catch(function () { });
        } else if (note) { note.textContent = 'While the app is closed: on.'; }
      }
      var days = Math.max(1, Math.min(90, Math.ceil((Date.now() - (st.since || Date.now())) / 86400000) + 1));
      var items = [];
      try { items = JSON.parse(app.smsRead(days)); } catch (e) { items = []; }
      var seen = []; try { seen = JSON.parse(get(SEEN, '[]')); } catch (e) { seen = []; }
      var fresh = items.filter(function (m) { return m && seen.indexOf(m.id) === -1 && m.date >= (st.since || 0) - 60000; });
      if (!fresh.length) { return; }
      return fetch(base + '/quick-add-import.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: st.csrf, action: 'import', messages: fresh })
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (res) {
        if (!res || res.off) { return; }
        fresh.forEach(function (m) { seen.push(m.id); });
        put(SEEN, JSON.stringify(seen.slice(-400)));
        var parts = [];
        if (res.added) { parts.push(res.added + ' payment' + (res.added === 1 ? '' : 's') + ' added from SMS'); }
        if (res.review) { parts.push(res.review + ' need' + (res.review === 1 ? 's' : '') + ' your check'); }
        if (!parts.length) { return; }
        if (onQuick) { location.reload(); } else { toast(parts.join(', ') + ' · tap to see'); }
      });
    }).catch(function () { });
  }
  window.efSmsAutoRun = function () { run(true); };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { run(false); }); } else { run(false); }
})();
