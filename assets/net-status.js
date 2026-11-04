/* Keeps people informed when the connection or the server has a problem, and recovers by itself.
   - A small bar appears when the device goes offline and disappears when it is back.
   - When the service worker showed a saved copy (#ef-stale), it asks health.php every 8 seconds and reloads when the server answers. */
(function () {
  'use strict';
  var bar = null;
  function show(text) {
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'ef-net';
      bar.setAttribute('role', 'status');
      bar.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:84px;z-index:99998;background:#0b2545;color:#fff;font:600 13px system-ui,sans-serif;padding:9px 16px;border-radius:99px;box-shadow:0 6px 20px rgba(0,0,0,.25);max-width:92vw;text-align:center;pointer-events:none';
      document.body.appendChild(bar);
    }
    bar.textContent = text;
  }
  function hide() { if (bar) { bar.remove(); bar = null; } }
  window.addEventListener('offline', function () { show('You are offline. Saved pages still open. New income and expenses are kept on this phone and sent later.'); });
  window.addEventListener('online', function () { show('Back online'); setTimeout(hide, 2000); });
  if (navigator.onLine === false) { show('You are offline. Saved pages still open. New income and expenses are kept on this phone and sent later.'); }

  if (document.getElementById('ef-stale')) {
    var base = (document.currentScript && document.currentScript.src) ? new URL('../health.php', document.currentScript.src).href : 'health.php';
    var busy = false;
    setInterval(function () {
      if (busy || navigator.onLine === false) { return; }
      busy = true;
      var c = new AbortController(), t = setTimeout(function () { c.abort(); }, 5000);
      fetch(base + '?t=' + Date.now(), { cache: 'no-store', signal: c.signal }).then(function (r) {
        clearTimeout(t); busy = false;
        if (r.ok) { location.reload(); }
      }).catch(function () { clearTimeout(t); busy = false; });
    }, 8000);
  }
})();
