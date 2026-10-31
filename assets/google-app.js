/* "Continue with Google" inside the Android / Windows app. Google refuses sign-in in embedded web views, so the Google step
   runs in the normal browser. This script starts it, shows the 4-letter code the person must compare, and waits for the
   approval. The state is kept in sessionStorage so a page reload (or Android recreating the page) resumes the wait. */
(function () {
  var btn = document.getElementById('gapp');
  if (!btn) return;
  var panel = document.getElementById('gapp-panel'), note = document.getElementById('gapp-note');
  var codeEl = document.getElementById('gapp-code'), statusEl = document.getElementById('gapp-status');
  var KEY = 'ef_gapp', job = null, timer = null, busy = false;

  function save() { try { if (job) sessionStorage.setItem(KEY, JSON.stringify(job)); else sessionStorage.removeItem(KEY); } catch (e) {} }
  function load() { try { var j = JSON.parse(sessionStorage.getItem(KEY) || 'null'); return j && j.until > Date.now() ? j : null; } catch (e) { return null; } }
  function status(t, bad) { statusEl.textContent = t; statusEl.style.color = bad ? '#b42318' : ''; }
  function post(data) {
    var b = new URLSearchParams(data); b.set('csrf_token', btn.getAttribute('data-csrf'));
    return fetch(btn.getAttribute('data-api'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() })
      .then(function (r) { return r.json().catch(function () { return { state: 'error', message: 'Something went wrong. Please try again.' }; }); });
  }
  function stop(msg) {
    if (timer) { clearInterval(timer); timer = null; }
    job = null; save(); busy = false; btn.disabled = false;
    if (msg) { panel.hidden = false; codeEl.hidden = true; status(msg, true); } else { panel.hidden = true; }
    if (note) note.hidden = !panel.hidden ? true : false;
  }
  function openBrowser() { if (job) (window.efOpenExternal || function (u) { location.href = u; })(job.url); } // the app catches this address and opens the browser; this page stays (tests replace efOpenExternal)
  function poll() {
    if (!job) return;
    if (Date.now() > job.until) { stop('The sign-in timed out. Please try again.'); return; }
    post({ do: 'poll', secret: job.secret }).then(function (p) {
      if (!job) return;
      if (p.state === 'ok') { status('Signed in. Opening your account…'); var u = p.url; job = null; save(); if (timer) clearInterval(timer); location.href = u; }
      else if (p.state === 'gone' || p.state === 'error') { stop(p.message || 'Sign-in did not finish. Please try again.'); }
    }).catch(function () { /* no connection for a moment: keep waiting */ });
  }
  function wait() {
    panel.hidden = false; codeEl.hidden = false; codeEl.textContent = job.code; if (note) note.hidden = true;
    btn.disabled = true; status('Waiting for you to finish in the browser…');
    if (timer) clearInterval(timer);
    timer = setInterval(poll, 2500);
  }
  btn.addEventListener('click', function () {
    if (busy) return; busy = true; btn.disabled = true;
    panel.hidden = false; codeEl.hidden = true; status('Starting…');
    post({ do: 'start' }).then(function (d) {
      if (d.state !== 'started') { stop(d.message || 'Could not start Google sign-in. Please try again.'); return; }
      job = { secret: d.secret, url: d.url, code: d.code, until: Date.now() + (d.ttl || 300) * 1000 }; save();
      wait(); openBrowser();
    }).catch(function () { stop('No connection. Please check your internet and try again.'); });
  });
  document.getElementById('gapp-reopen').addEventListener('click', openBrowser);
  document.getElementById('gapp-cancel').addEventListener('click', function () { stop(''); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && job) poll(); }); // coming back from the browser
  var again = load();
  if (again) { job = again; wait(); poll(); }
})();
