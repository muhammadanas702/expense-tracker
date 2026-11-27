/* Alerts on by default (Part 130b).
   - Android app: hands this phone its own secret (app-alerts.php) so alerts arrive even when the app is closed, and asks Android for
     the notification permission. Stops if the person turned alerts off on this phone (Settings > Notifications).
   - Browser: asks the browser's permission once, on the first tap (browsers do not allow it without a tap), and turns Web Push on.
   Nothing here runs on the public pages (this file is only added for signed-in pages). */
(function () {
  'use strict';
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) { } }
  var me = document.currentScript, base = (me && me.getAttribute('data-base')) || '';
  var app = window.EFApp;
  if (app && typeof app.alertsSupported === 'function') {
    if (get('ef_alerts_off') === '1') { return; }
    try {
      if (!app.alertsNotifGranted() && get('ef_alerts_asked') !== '1') { put('ef_alerts_asked', '1'); app.alertsAsk(); }
      if (app.alertsHasToken()) { return; }
    } catch (e) { return; }
    fetch(base + '/app-alerts.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (!j || !j.csrf) { return null; }
      return fetch(base + '/app-alerts.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: j.csrf, action: 'register' }) });
    }).then(function (r) { return r && r.ok ? r.json() : null; }).then(function (t) {
      if (t && t.token) { try { app.alertsSetToken(t.token, location.origin); } catch (e) { } }
    }).catch(function () { });
    return;
  }
  if (window.EFApp || /ExpenseFlowApp|; wv\)|Electron/i.test(navigator.userAgent)) { return; }
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return; }
  if (Notification.permission === 'denied') { return; }
  var URL_ = base + '/push-subscribe.php';
  function b64u(s) { s = (s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'); var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) { a[i] = r.charCodeAt(i); } return a; }
  function turnOn() {
    var reg, info;
    return navigator.serviceWorker.getRegistration().then(function (r) {
      if (!r) { return null; }
      reg = r;
      return fetch(URL_, { credentials: 'same-origin' }).then(function (x) { return x.json(); });
    }).then(function (j) {
      if (!j || !j.key) { return null; }
      info = j;
      return reg.pushManager.getSubscription();
    }).then(function (s) {
      if (s === null && !info) { return null; }
      return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(info.key) });
    }).then(function (s) {
      if (!s) { return; }
      var j = s.toJSON();
      return fetch(URL_, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: info.csrf, action: 'subscribe', subscription: { endpoint: j.endpoint, keys: j.keys } }) });
    }).catch(function () { });
  }
  if (get('ef_alerts_off') === '1') { return; }
  if (Notification.permission === 'granted') {
    if (get('ef_alerts_sync') !== String(new Date().getUTCDate())) { put('ef_alerts_sync', String(new Date().getUTCDate())); turnOn(); }
    return;
  }
  var asked = parseInt(get('ef_alerts_asked_at') || '0', 10);
  if (Date.now() - asked < 30 * 86400000) { return; }
  var once = function () {
    document.removeEventListener('pointerdown', once, true);
    put('ef_alerts_asked_at', String(Date.now()));
    Notification.requestPermission().then(function (p) { if (p === 'granted') { turnOn(); } });
  };
  document.addEventListener('pointerdown', once, true);
})();
