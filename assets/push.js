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
      var ep = sub.endpoint;
      sub.unsubscribe().then(function () { sub = null; return post({ action: 'unsubscribe', subscription: { endpoint: ep } }); }).then(function () { done(); }, function () { done('error'); });
      return;
    }
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
