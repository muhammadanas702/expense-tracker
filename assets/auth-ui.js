/* Auth pages: show/hide password, password strength meter, OTP digits only. */
(function () {
  document.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input');
      if (i) { i.type = i.type === 'password' ? 'text' : 'password'; }
    });
  });
  var pw = document.querySelector('input[data-strength]');
  var mt = document.querySelector('.mt');
  if (pw && mt) {
    pw.addEventListener('input', function () {
      var v = pw.value, n = 0;
      if (v.length >= 8) n++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) n++;
      if (/\d/.test(v)) n++;
      if (/[^A-Za-z0-9]/.test(v)) n++;
      mt.querySelectorAll('i').forEach(function (el, k) {
        el.className = k < n ? (n > 2 ? 'g' : 'f') : '';
      });
    });
  }
  var otp = document.querySelector('input.otp');
  if (otp) {
    otp.addEventListener('input', function () { otp.value = otp.value.replace(/\D/g, '').slice(0, 6); });
  }
})();
/* Part 96: show a spinner on the submit button while the page is sending (still a normal form post). */
(function () {
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button.btn[type=submit], button.btn:not([type])');
      if (b && (!f.checkValidity || f.checkValidity())) b.classList.add('busy');
    });
  });
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) document.querySelectorAll('.btn.busy').forEach(function (b) { b.classList.remove('busy'); });
  });
})();
/* Part 97: code check animation. Typing the 6th digit (or pressing the button) sends the form in the background, shows
   "checking", then a green tick (and goes on) or a red cross with a shake. Without JavaScript it is a normal form post. */
(function () {
  var otp = document.querySelector('input.otp'), T = document.querySelector('.otps-t');
  if (!otp || !T || !otp.form || !window.fetch || !window.FormData || !window.URL) return;
  var form = otp.form, box = document.createElement('div'), busy = false;
  var btn = form.querySelector('button[type=submit]');
  box.className = 'otps'; box.hidden = true; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite');
  otp.insertAdjacentElement('afterend', box);
  function tx(k) { var n = T.querySelector('[data-k="' + k + '"]'); return n ? n.textContent : ''; }
  var ICON = {
    ok: '<svg class="otpi" viewBox="0 0 52 52" aria-hidden="true"><circle class="c" cx="26" cy="26" r="23" fill="none" stroke="currentColor" stroke-width="3"/><path class="k" d="M15 27l8 8 14-16" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    bad: '<svg class="otpi" viewBox="0 0 52 52" aria-hidden="true"><circle class="c" cx="26" cy="26" r="23" fill="none" stroke="currentColor" stroke-width="3"/><path class="k" d="M18 18l16 16M34 18L18 34" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>',
    chk: '<span class="otpd"><i></i><i></i><i></i></span>'
  };
  function show(state, msg) {
    otp.classList.remove('chk', 'ok', 'bad');
    if (state) otp.classList.add(state);
    box.className = 'otps' + (state ? ' ' + state : '');
    box.hidden = !state;
    if (!state) return;
    box.innerHTML = ICON[state] + '<span></span>';
    box.lastChild.textContent = msg || tx(state);
    var big = document.querySelector('.fc .big');
    if (big) big.classList.toggle('ok', state === 'ok');
  }
  function submitCode() {
    if (busy || otp.value.length !== 6) return;
    busy = true; otp.readOnly = true;
    if (btn) { btn.classList.remove('busy'); btn.disabled = true; }
    document.querySelectorAll('.fc .msg').forEach(function (m) { m.hidden = true; });
    show('chk');
    var t0 = Date.now();
    fetch(form.getAttribute('action') || location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) { return r.text().then(function (h) { return { r: r, h: h }; }); })
      .then(function (x) {
        setTimeout(function () {
          var moved = x.r.redirected && new URL(x.r.url).pathname !== location.pathname;
          if (moved) { show('ok'); setTimeout(function () { location.href = x.r.url; }, 1100); return; }
          var doc = new DOMParser().parseFromString(x.h, 'text/html');
          var m = doc.querySelector('.fc .msg.err, .fc .msg'), say = m ? m.textContent.trim() : '';
          var tok = doc.querySelector('input[name=csrf_token]');
          if (tok) document.querySelectorAll('input[name=csrf_token]').forEach(function (i) { i.value = tok.value; });
          show('bad', say || tx('bad'));
          otp.value = ''; otp.readOnly = false; busy = false;
          if (btn) btn.disabled = false;
          setTimeout(function () { otp.focus(); }, 450);
        }, Math.max(0, 750 - (Date.now() - t0)));
      })
      .catch(function () { busy = false; otp.readOnly = false; if (btn) btn.disabled = false; show(null); form.submit(); });
  }
  form.addEventListener('submit', function (e) { if (otp.value.length === 6) { e.preventDefault(); submitCode(); } });
  otp.addEventListener('input', function () {
    if (!busy) show(null);
    if (otp.value.length === 6) setTimeout(submitCode, 150);
  });
})();
