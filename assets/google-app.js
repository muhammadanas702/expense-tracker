/* "Continue with Google" inside the Android / Windows app: the Google step runs in the normal browser (Google blocks
   embedded web views); this script starts it, shows the 4-letter code and waits for the approval. */
(function () {
  var btn = document.getElementById('gapp');
  if (!btn) return;
  var note = document.getElementById('gapp-note');
  var timer = null;
  function say(t, bad) { if (note) { note.textContent = t; note.style.color = bad ? '#b42318' : ''; } }
  function post(data) {
    var b = new URLSearchParams(data); b.set('csrf_token', btn.getAttribute('data-csrf'));
    return fetch(btn.getAttribute('data-api'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() })
      .then(function (r) { return r.json(); });
  }
  function stop() { if (timer) { clearInterval(timer); timer = null; } btn.disabled = false; }
  btn.addEventListener('click', function () {
    btn.disabled = true; say('Starting…');
    post({ do: 'start' }).then(function (d) {
      if (d.state !== 'started') { stop(); say(d.message || 'Could not start Google sign-in.', true); return; }
      say('Finish in your browser. When it asks, check the code is ' + d.code + '. Waiting…');
      location.href = d.url; // the app catches this address and opens it in the normal browser (this page stays)
      var until = Date.now() + (d.ttl || 300) * 1000;
      timer = setInterval(function () {
        if (Date.now() > until) { stop(); say('The sign-in timed out. Please try again.', true); return; }
        post({ do: 'poll', secret: d.secret }).then(function (p) {
          if (p.state === 'ok') { stop(); say('Signed in. Opening your account…'); location.href = p.url; }
          else if (p.state === 'gone' || p.state === 'error') { stop(); say(p.message || 'Sign-in did not finish.', true); }
        }).catch(function () { /* no connection for a moment: keep waiting */ });
      }, 2500);
    }).catch(function () { stop(); say('No connection. Please try again.', true); });
  });
})();
