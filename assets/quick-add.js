/* Quick add > "Read my messages" (Android app only). The messages are read on the phone; nothing is sent to the server
   until the person taps "Use this" on ONE message, which then goes through the normal paste form. */
(function () {
  'use strict';
  var box = document.getElementById('sms-device');
  var app = window.EFApp;
  var none = document.getElementById('sms-nodevice'), noneText = document.getElementById('sms-nodevice-text');
  if (!box || !app || typeof app.smsSupported !== 'function') { // website, Windows app or an older Android app: paste only, but say why
    if (app && noneText) { noneText.textContent = 'This version of the app cannot read messages yet. Install the latest ExpenseFlow.apk from the Releases page (Settings > Apps) and open Quick add again. You can still paste a message below.'; }
    return;
  }
  if (none) { none.hidden = true; }
  box.hidden = false;
  var btn = document.getElementById('sms-read'), info = document.getElementById('sms-info'), list = document.getElementById('sms-list');
  var KEY = 'ef_sms_used', used = [];
  try { used = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { used = []; }
  function remember(id) { used.push(id); used = used.slice(-300); try { localStorage.setItem(KEY, JSON.stringify(used)); } catch (e) { } }
  function say(t, link) {
    info.textContent = t;
    if (link) {
      var a = document.createElement('a'); a.href = '#'; a.textContent = ' Open settings';
      a.addEventListener('click', function (ev) { ev.preventDefault(); try { app.smsSettings(); } catch (e) { } });
      info.appendChild(a);
    }
  }
  function render(items) {
    list.textContent = '';
    var fresh = items.filter(function (m) { return used.indexOf(m.id) === -1; });
    if (!fresh.length) { say(items.length ? 'All recent payment messages are already added.' : 'No payment messages found in the last 30 days. You can still paste one below.'); return; }
    say(fresh.length + ' payment message' + (fresh.length === 1 ? '' : 's') + ' found. Tap one to add it.');
    fresh.forEach(function (m) {
      var row = document.createElement('button'); row.type = 'button'; row.className = 'row sms-row'; row.style.cssText = 'width:100%;text-align:left;background:none;border:0;border-top:1px solid rgba(91,107,128,.15);padding:10px 0;cursor:pointer;color:inherit;font:inherit';
      var s = document.createElement('span'); s.textContent = m.text.length > 140 ? m.text.slice(0, 140) + '…' : m.text;
      var small = document.createElement('small'); small.style.cssText = 'display:block;color:var(--mu)';
      small.textContent = (m.from || 'SMS') + ' · ' + new Date(m.date).toLocaleDateString();
      s.appendChild(small); row.appendChild(s);
      row.addEventListener('click', function () {
        remember(m.id);
        document.getElementById('sms').value = m.text;
        document.getElementById('sms').form.submit();
      });
      list.appendChild(row);
    });
  }
  function load() {
    var items = [];
    try { items = JSON.parse(app.smsRead(30)); } catch (e) { items = []; }
    render(items);
  }
  window.efSmsPermission = function (ok) {
    if (ok) { load(); if (window.efSmsAutoRun) { window.efSmsAutoRun(); } } else { say('No problem, the messages stay private. Paste a message below instead.', true); }
  };
  btn.addEventListener('click', function () {
    var granted = false;
    try { granted = app.smsGranted(); } catch (e) { }
    if (granted) { load(); } else { say('Android will ask for permission to read messages…'); try { app.smsAsk(); } catch (e) { say('Could not ask for permission. Paste a message below instead.'); } }
  });
})();
