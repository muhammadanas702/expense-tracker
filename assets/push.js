/* Phone alerts card (Settings > Notifications): subscribe this browser to Web Push. Not available inside the Android/Windows app shells. */
(function () {
  var box = document.getElementById('push-box');
  if (!box) { return; }
  var msg = box.querySelector('[data-push-msg]'), btn = box.querySelector('[data-push-btn]'), test = box.querySelector('[data-push-test]');
  var URL_ = box.getAttribute('data-url') || '/push-subscribe.php', T = JSON.parse(box.getAttribute('data-t') || '{}'), csrf = '', reg = null, sub = null;
  function say(k) { msg.textContent = T[k] || k; }
  function b64u(s) { s = (s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'); var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) { a[i] = r.charCodeAt(i); } return a; }
  function post(body) { body.csrf = csrf; return fetch(URL_, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json().then(function (j) { j.status = r.status; return j; }); }); }
  var shell = !!window.EFApp || /ExpenseFlowApp|; wv\)|Electron/i.test(navigator.userAgent);
  var app = window.EFApp;
  if (app && typeof app.alertsSupported === 'function') { // Android app: alerts are fetched by the phone itself (Part 130b)
    test.hidden = true;
    var appOn = function () { var t = false, g = false; try { t = app.alertsHasToken(); g = app.alertsNotifGranted(); } catch (e) { } return t && g; };
    var paintApp = function () { var on = appOn(); btn.hidden = false; btn.textContent = on ? T.turnOff : T.turnOn; say(on ? 'appOn' : 'appOff'); };
    paintApp();
    btn.addEventListener('click', function () {
      if (appOn()) { try { app.alertsClearToken(); localStorage.setItem('ef_alerts_off', '1'); } catch (e) { } paintApp(); return; }
      try { localStorage.removeItem('ef_alerts_off'); if (!app.alertsNotifGranted()) { app.alertsAsk(); } } catch (e) { }
      fetch(URL_.replace('push-subscribe.php', 'app-alerts.php'), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        return fetch(URL_.replace('push-subscribe.php', 'app-alerts.php'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: j.csrf, action: 'register' }) });
      }).then(function (r) { return r.json(); }).then(function (t) { if (t && t.token) { app.alertsSetToken(t.token, location.origin); } paintApp(); }).catch(function () { say('error'); });
    });
    setInterval(paintApp, 2000);
    return;
  }
  if (shell) { say('shell'); btn.hidden = true; return; }
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { say('unsupported'); btn.hidden = true; return; }
  function paint() {
    var on = !!sub && Notification.permission === 'granted';
    btn.hidden = false; btn.textContent = on ? T.turnOff : T.turnOn; btn.setAttribute('data-on', on ? '1' : '0'); test.hidden = !on;
    say(Notification.permission === 'denied' ? 'denied' : (on ? 'on' : 'off'));
  }
  fetch(URL_, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
    if (!j.key) { say('unsupported'); btn.hidden = true; return; }
    csrf = j.csrf; box.setAttribute('data-key', j.key);
    return navigator.serviceWorker.ready.then(function (r) { reg = r; return r.pushManager.getSubscription(); }).then(function (s) { sub = s; paint(); });
  }).catch(function () { say('error'); });
  btn.addEventListener('click', function () {
    btn.disabled = true;
    var done = function (k) { btn.disabled = false; if (k) { say(k); } else { paint(); } };
    if (sub) {
      var ep = sub.endpoint; try { localStorage.setItem('ef_alerts_off', '1'); } catch (e) { }
      sub.unsubscribe().then(function () { sub = null; return post({ action: 'unsubscribe', subscription: { endpoint: ep } }); }).then(function () { done(); }, function () { done('error'); });
      return;
    }
    try { localStorage.removeItem('ef_alerts_off'); } catch (e) { }
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { return done('denied'); }
      return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(box.getAttribute('data-key')) }).then(function (s) {
        sub = s; var j = s.toJSON();
        return post({ action: 'subscribe', subscription: { endpoint: j.endpoint, keys: j.keys } });
      }).then(function (r) { if (r.ok) { done(); } else { done('error'); } });
    }).catch(function () { done('error'); });
  });
  test.addEventListener('click', function () {
    test.disabled = true;
    post({ action: 'test' }).then(function (r) { test.disabled = false; say(r.sent > 0 ? 'sent' : 'notsent'); }, function () { test.disabled = false; say('error'); });
  });
})();
