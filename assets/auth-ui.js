/* Auth pages: show/hide password, password strength meter, OTP digits only. */
(function () {
  document.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input');
      if (i) { i.type = i.type === 'password' ? 'text' : 'password'; b.setAttribute('aria-pressed', i.type === 'text' ? 'true' : 'false'); }
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
/* Part 98d: Caps Lock hint under the password field, and focus the first field on devices with a real keyboard. */
(function () {
  var pw = document.querySelector('input[name=password]'), hint = document.querySelector('.caps');
  if (pw && hint) {
    var upd = function (e) { if (e.getModifierState) hint.hidden = !e.getModifierState('CapsLock'); };
    pw.addEventListener('keydown', upd); pw.addEventListener('keyup', upd);
    pw.addEventListener('blur', function () { hint.hidden = true; });
  }
  if (window.matchMedia && matchMedia('(hover:hover) and (pointer:fine)').matches && !document.querySelector('input.otp')) {
    var f = document.querySelector('.fc input.inp:not([type=hidden])');
    if (f && !f.value && document.activeElement === document.body) f.focus({ preventScroll: true });
  }
})();
/* Part 98d: six code boxes. The real input.otp stays in the form (hidden) so paste, autofill, tests and the POST keep working.
   The 6th digit (or Enter) sends the form in the background: the boxes flip one after another while it checks, then turn
   green with a tick and go on, or turn red, shake and clear. Without JavaScript the single input is used as before. */
(function () {
  var otp = document.querySelector('input.otp'), T = document.querySelector('.otps-t');
  if (!otp || !T || !otp.form || !window.fetch || !window.FormData || !window.URL) return;
  var form = otp.form, busy = false, N = 6, cells = [], box = document.createElement('div');
  var btn = form.querySelector('button[type=submit]');
  var grp = document.createElement('div');
  grp.className = 'otpg'; grp.setAttribute('role', 'group'); grp.setAttribute('aria-label', otp.getAttribute('aria-label') || '');
  for (var i = 0; i < N; i++) {
    var c = document.createElement('input');
    c.type = 'text'; c.className = 'inp otpc'; c.inputMode = 'numeric'; c.autocomplete = i === 0 ? 'one-time-code' : 'off';
    c.setAttribute('aria-label', (i + 1) + '/' + N); c.setAttribute('autocapitalize', 'off'); c.spellcheck = false; c.dir = 'ltr';
    c.style.setProperty('--i', i); cells.push(c); grp.appendChild(c);
  }
  otp.type = 'hidden'; otp.required = false; otp.removeAttribute('autofocus'); otp.removeAttribute('placeholder');
  otp.insertAdjacentElement('beforebegin', grp);
  box.className = 'otps'; box.hidden = true; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite');
  grp.insertAdjacentElement('afterend', box);
  function tx(k) { var n = T.querySelector('[data-k="' + k + '"]'); return n ? n.textContent : ''; }
  var ICON = {
    ok: '<svg class="otpi" viewBox="0 0 52 52" aria-hidden="true"><circle class="c" cx="26" cy="26" r="23" fill="none" stroke="currentColor" stroke-width="3"/><path class="k" d="M15 27l8 8 14-16" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    bad: '<svg class="otpi" viewBox="0 0 52 52" aria-hidden="true"><circle class="c" cx="26" cy="26" r="23" fill="none" stroke="currentColor" stroke-width="3"/><path class="k" d="M18 18l16 16M34 18L18 34" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>',
    chk: '<span class="otpd"><i></i><i></i><i></i></span>'
  };
  function code() { return cells.map(function (c) { return c.value; }).join(''); }
  function sync() {
    var v = code(); otp.value = v;
    cells.forEach(function (c) { c.classList.toggle('f', c.value !== ''); });
    return v;
  }
  function fill(str, from) {
    var d = String(str).replace(/\D/g, '');
    if (!d) return;
    for (var k = 0; k < d.length && from + k < N; k++) cells[from + k].value = d[k];
    var last = Math.min(from + d.length, N - 1);
    cells[last].focus(); cells[last].select();
    if (sync().length === N) setTimeout(submitCode, 220);
  }
  function show(state, msg) {
    grp.classList.remove('chk', 'ok', 'bad');
    if (state) grp.classList.add(state);
    box.className = 'otps' + (state ? ' ' + state : '');
    box.hidden = !state;
    if (!state) return;
    box.innerHTML = ICON[state] + '<span></span>';
    box.lastChild.textContent = msg || tx(state);
    var big = document.querySelector('.fc .big');
    if (big) big.classList.toggle('ok', state === 'ok');
  }
  function reset() {
    cells.forEach(function (c) { c.value = ''; c.readOnly = false; });
    sync(); busy = false;
    if (btn) btn.disabled = false;
  }
  function submitCode() {
    if (busy || sync().length !== N) return;
    busy = true;
    cells.forEach(function (c) { c.readOnly = true; });
    if (btn) { btn.classList.remove('busy'); btn.disabled = true; }
    document.querySelectorAll('.fc .msg').forEach(function (m) { m.hidden = true; });
    document.activeElement && document.activeElement.blur && document.activeElement.blur();
    show('chk');
    var t0 = Date.now();
    fetch(form.getAttribute('action') || location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) { return r.text().then(function (h) { return { r: r, h: h }; }); })
      .then(function (x) {
        setTimeout(function () {
          var moved = x.r.redirected && new URL(x.r.url).pathname !== location.pathname;
          if (moved) { show('ok'); setTimeout(function () { location.href = x.r.url; }, 1300); return; }
          var doc = new DOMParser().parseFromString(x.h, 'text/html');
          var m = doc.querySelector('.fc .msg.err, .fc .msg'), say = m ? m.textContent.trim() : '';
          var tok = doc.querySelector('input[name=csrf_token]');
          if (tok) document.querySelectorAll('input[name=csrf_token]').forEach(function (i) { i.value = tok.value; });
          show('bad', say || tx('bad'));
          setTimeout(function () { reset(); cells[0].focus(); }, 1100);
        }, Math.max(0, 1000 - (Date.now() - t0)));
      })
      .catch(function () { reset(); show(null); form.submit(); });
  }
  cells.forEach(function (c, idx) {
    c.addEventListener('input', function () {
      if (busy) return;
      if (grp.classList.contains('bad')) show(null);
      var d = c.value.replace(/\D/g, '');
      if (d.length > 1) { c.value = ''; fill(d, idx); return; }   // autofill or paste into one box
      c.value = d;
      if (d && idx < N - 1) cells[idx + 1].focus();
      if (sync().length === N) setTimeout(submitCode, 220);
    });
    c.addEventListener('keydown', function (e) {
      if (busy) { e.preventDefault(); return; }
      if (e.key === 'Backspace' && !c.value && idx > 0) { e.preventDefault(); cells[idx - 1].value = ''; cells[idx - 1].focus(); sync(); }
      else if (e.key === 'ArrowLeft' && idx > 0) { e.preventDefault(); cells[idx - 1].focus(); }
      else if (e.key === 'ArrowRight' && idx < N - 1) { e.preventDefault(); cells[idx + 1].focus(); }
      else if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : submitCode(); }
    });
    c.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text');
      fill(t, t.replace(/\D/g, '').length >= N ? 0 : idx);
    });
    c.addEventListener('focus', function () { try { c.select(); } catch (er) {} });
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    if (sync().length === N) { submitCode(); return; }
    var first = cells.filter(function (c) { return !c.value; })[0] || cells[0];
    show('bad', tx('short')); first.focus();
    setTimeout(function () { if (!busy && grp.classList.contains('bad')) show(null); }, 1800);
  });
  cells[0].focus({ preventScroll: true });
})();
