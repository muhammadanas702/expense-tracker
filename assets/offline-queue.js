/* Offline expense queue (Part 54).
   - On the Add Expense / Add Income form: every save carries a one-time id (cid) and the time it was made (ts), so a retry can never create a double entry.
   - When the device is offline the entry is kept on this phone (localStorage) instead of failing, and is sent when the connection is back.
   - The queue is flushed on every page of the app, on load and on the "online" event. Entries the server refuses are dropped with a message. */
(function () {
  'use strict';
  var KEY = 'ef_offq', MAX = 50;
  function load() { try { var q = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(q) ? q : []; } catch (e) { return []; } }
  function save(q) { try { localStorage.setItem(KEY, JSON.stringify(q)); return true; } catch (e) { return false; } }
  function rid() { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function toast(text, ms) {
    var t = document.getElementById('ef-offq-toast');
    if (!t) {
      t = document.createElement('div'); t.id = 'ef-offq-toast'; t.setAttribute('role', 'status');
      t.style.cssText = 'pointer-events:none;position:fixed;left:50%;transform:translateX(-50%);bottom:130px;z-index:99999;background:#0b2545;color:#fff;font:600 13px system-ui,sans-serif;padding:9px 16px;border-radius:99px;box-shadow:0 6px 20px rgba(0,0,0,.25);max-width:92vw;text-align:center';
      document.body.appendChild(t);
    }
    t.textContent = text; clearTimeout(t._h); t._h = setTimeout(function () { t.remove(); }, ms || 4000);
  }
  function addHidden(form, name, value) {
    var i = form.querySelector('input[name=' + name + ']');
    if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = name; form.appendChild(i); }
    i.value = value;
  }

  // ---- the form
  var form = document.querySelector('form[data-offline-queue]');
  if (form) {
    form.addEventListener('submit', function (ev) {
      addHidden(form, 'cid', rid());
      addHidden(form, 'ts', String(Math.floor(Date.now() / 1000)));
      if (navigator.onLine !== false) { return; }
      ev.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      var fields = {};
      new FormData(form).forEach(function (v, k) { if (k !== 'csrf_token' && typeof v === 'string') { fields[k] = v; } });
      var q = load();
      if (q.length >= MAX) { toast('Too many entries are waiting. Connect to the internet first.'); return; }
      q.push({ path: location.pathname, fields: fields });
      if (!save(q)) { toast('Could not save on this device. Connect to the internet and try again.'); return; }
      form.reset();
      toast('Saved on this phone. It will be added when you are online (' + q.length + ' waiting).', 6000);
    });
  }

  // ---- sending
  var busy = false;
  function token(html) { var m = /name="csrf_token" value="([^"]+)"/.exec(html); return m ? m[1] : null; }
  function flush() {
    if (busy || navigator.onLine === false) { return; }
    var q = load(); if (!q.length) { return; }
    busy = true;
    var added = 0, dropped = 0;
    (function next() {
      var item = q[0];
      if (!item) { done(); return; }
      fetch(item.path, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        if (!r.ok || /\/auth\/login\.php/.test(r.url)) { throw new Error('stop'); }
        return r.text();
      }).then(function (html) {
        var t = token(html); if (!t) { throw new Error('stop'); }
        var fd = new FormData(); fd.append('csrf_token', t);
        Object.keys(item.fields).forEach(function (k) { fd.append(k, item.fields[k]); });
        return fetch(item.path, { method: 'POST', body: fd, credentials: 'same-origin' });
      }).then(function (r) {
        if (/\/auth\/login\.php/.test(r.url) || r.status >= 500) { throw new Error('stop'); }
        if (/dashboard\.php/.test(r.url)) { added++; } else { dropped++; } // still on the form = the server refused it
        q.shift(); save(q); next();
      }).catch(function () { done(); });
    })();
    function done() {
      busy = false;
      if (added) { toast(added + ' offline ' + (added === 1 ? 'entry was' : 'entries were') + ' added.', 5000); }
      if (dropped) { toast(dropped + ' offline ' + (dropped === 1 ? 'entry' : 'entries') + ' could not be added (invalid).', 7000); }
    }
  }
  window.addEventListener('online', function () { setTimeout(flush, 800); });
  document.addEventListener('DOMContentLoaded', function () { setTimeout(flush, 1500); });
  // Keep the add pages (and the dashboard) saved for offline use: asked at most every 6 hours, only while online and signed in.
  function prewarm() {
    try {
      if (navigator.onLine === false || !navigator.serviceWorker || !navigator.serviceWorker.controller) { return; }
      var last = parseInt(localStorage.getItem('ef_prewarm') || '0', 10);
      if (Date.now() - last < 2 * 3600 * 1000) { return; }
      var urls = [];
      document.querySelectorAll('a[href*="add-expense.php"], a[href*="add-income.php"], a[href*="dashboard.php"], a[href*="view-report.php"], a[href*="budgets.php"], a[href*="goals.php"], a[href*="reminders.php"], a[href*="search.php"]').forEach(function (a) {
        var h = a.href; if (h && urls.indexOf(h) === -1 && h.indexOf('?') === -1 && h.indexOf('#') === -1) { urls.push(h); }
      });
      if (!urls.length) { return; }
      navigator.serviceWorker.controller.postMessage({ type: 'prewarm', urls: urls });
      localStorage.setItem('ef_prewarm', String(Date.now()));
    } catch (e) { }
  }
  document.addEventListener('DOMContentLoaded', function () { setTimeout(prewarm, 3000); });
  window.addEventListener('online', function () { setTimeout(prewarm, 2000); });
  window.efOfflineQueue = { flush: flush, count: function () { return load().length; } };
})();
