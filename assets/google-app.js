/* "Continue with Google" inside the Android / Windows app. Google refuses sign-in in embedded web views, so the Google
   step runs in the normal browser; afterwards the browser sends the person back to the app (Android: expenseflow:// link,
   Windows: a port on this computer) with a one-time proof, and this script signs the app in with it.
   The wait is kept in localStorage so a page reload (or Android recreating the page) does not lose it. */
(function () {
  var btn = document.getElementById('gapp');
  if (!btn) return;
  var panel = document.getElementById('gapp-panel'), note = document.getElementById('gapp-note');
  var titleEl = document.getElementById('gapp-title'), statusEl = document.getElementById('gapp-status');
  var reopenBtn = document.getElementById('gapp-reopen');
  var KEY = 'ef_gapp', job = null, timer = null, busy = false, approvedAt = 0;

  function save() { try { if (job) localStorage.setItem(KEY, JSON.stringify(job)); else localStorage.removeItem(KEY); } catch (e) {} }
  function load() { try { var j = JSON.parse(localStorage.getItem(KEY) || 'null'); return j && j.until > Date.now() ? j : null; } catch (e) { return null; } }
  function status(t, bad) { statusEl.textContent = t; statusEl.style.color = bad ? '#b42318' : ''; }
  function post(data) {
    var b = new URLSearchParams(data); b.set('csrf_token', btn.getAttribute('data-csrf'));
    return fetch(btn.getAttribute('data-api'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() })
      .then(function (r) { return r.json().catch(function () { return { state: 'error', message: 'Something went wrong. Please try again.' }; }); });
  }
  function stop(msg) {
    if (timer) { clearInterval(timer); timer = null; }
    job = null; save(); busy = false; btn.disabled = false; approvedAt = 0;
    if (msg) { panel.hidden = false; titleEl.textContent = 'Google sign-in did not finish'; reopenBtn.hidden = true; status(msg, true); if (note) note.hidden = true; }
    else { panel.hidden = true; if (note) note.hidden = false; }
  }
  function openBrowser() { if (job) (window.efOpenExternal || function (u) { location.href = u; })(job.url); } // the app catches this address and opens the browser; this page stays (tests replace efOpenExternal)
  function poll() {
    if (!job) return;
    if (Date.now() > job.until) { stop('The sign-in timed out. Please try again.'); return; }
    post({ do: 'poll', secret: job.secret }).then(function (p) {
      if (!job) return;
      if (p.state === 'approved') {
        approvedAt = approvedAt || Date.now();
        titleEl.textContent = 'Signed in with Google';
        status(Date.now() - approvedAt > 15000 ? 'Almost done. If this app did not open by itself, tap "Open ExpenseFlow app" in your browser.' : 'Taking you back to the app…');
      } else if (p.state === 'gone' || p.state === 'error') { stop(p.message || 'Sign-in did not finish. Please try again.'); }
    }).catch(function () { /* no connection for a moment: keep waiting */ });
  }
  function wait() {
    panel.hidden = false; reopenBtn.hidden = false; if (note) note.hidden = true;
    titleEl.textContent = 'Finish signing in with your browser';
    btn.disabled = true; status('Your browser has opened. Choose your Google account there. You will be brought back here automatically.');
    if (timer) clearInterval(timer);
    timer = setInterval(poll, 2500);
  }
  function start(ret) {
    post({ do: 'start', ret: ret }).then(function (d) {
      if (d.state !== 'started') { stop(d.message || 'Could not start Google sign-in. Please try again.'); return; }
      job = { id: d.id, secret: d.secret, url: d.url, until: Date.now() + (d.ttl || 300) * 1000 }; save();
      wait(); openBrowser();
    }).catch(function () { stop('No connection. Please check your internet and try again.'); });
  }
  btn.addEventListener('click', function () {
    if (busy) return; busy = true; btn.disabled = true;
    panel.hidden = false; reopenBtn.hidden = true; status('Starting…');
    // Windows app: ask it to listen on a local port for the way back; Android: the expenseflow:// link
    if (window.EFApp && typeof window.EFApp.googleListen === 'function') {
      window.EFApp.googleListen().then(function (port) { start(String(port)); }, function () { stop('Could not start Google sign-in on this computer. Please use your email and password.'); });
    } else { start('android'); }
  });
  reopenBtn.addEventListener('click', openBrowser);
  document.getElementById('gapp-cancel').addEventListener('click', function () { stop(''); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && job) poll(); }); // coming back from the browser

  // Back from the browser: ?gh=<id>&gp=<proof> (added by the app shell). Redeem it with the secret kept here.
  var q = new URLSearchParams(location.search), gh = q.get('gh'), gp = q.get('gp');
  if (gh && gp) {
    try { history.replaceState(null, '', location.pathname); } catch (e) {} // the proof does not stay in the address bar
    var mine = load();
    panel.hidden = false; reopenBtn.hidden = true; btn.disabled = true; if (note) note.hidden = true;
    if (!mine || mine.id !== gh) { stop('This sign-in link is not for this app, or it expired. Please try again.'); }
    else {
      job = null; save(); titleEl.textContent = 'Signed in with Google'; status('Opening your account…');
      post({ do: 'redeem', id: gh, secret: mine.secret, proof: gp }).then(function (r) {
        if (r.state === 'ok') { location.href = r.url; } else { stop(r.message || 'The sign-in could not be completed. Please try again.'); }
      }).catch(function () { stop('No connection. Please try again.'); });
    }
    return;
  }
  var again = load();
  if (again) { job = again; wait(); poll(); }
})();
