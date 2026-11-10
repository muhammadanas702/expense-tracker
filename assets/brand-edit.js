/* Edit mode (Admin > Branding): staff click any text on any page, type the new words, Save. Loaded only for staff in edit mode.
   No page markup is changed: the text under the pointer is found with the browser's caret lookup, outlined, and edited in a small box. */
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me || !document.body) return;
  var POST = me.getAttribute('data-post'), CSRF = '', LANG = me.getAttribute('data-lang') === 'ur' ? 'ur' : 'en', BACK = me.getAttribute('data-back');
  var nav = false, saved = 0, hlEl, pop, bar, cur = null;
  try { nav = sessionStorage.getItem('bt-nav') === '1'; } catch (e) {}

  var css = document.createElement('style');
  css.textContent = '[data-bt-ui]{font:14px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:#0b2545;box-sizing:border-box}[data-bt-ui] *{box-sizing:border-box}' +
    '.bt-hl{position:fixed;z-index:2147483640;pointer-events:none;border:2px solid #f59e0b;background:rgba(245,158,11,.14);border-radius:6px;display:none}' +
    '.bt-bar{position:fixed;z-index:2147483646;left:10px;right:10px;bottom:14px;margin-inline:auto;width:max-content;max-width:calc(100vw - 20px);box-sizing:border-box;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:center;background:#0b2545;color:#fff;padding:8px 10px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.35)}' +
    '.bt-bar b,.bt-bar span{display:inline;color:#fff;font-size:13.5px;line-height:1.3}.bt-bar b{font-weight:700}.bt-bar span{opacity:.8;font-size:12.5px}' +
    '.bt-bar button,.bt-pop button{font:inherit;font-weight:700;border:0;border-radius:10px;padding:8px 12px;min-height:36px;cursor:pointer}' +
    '.bt-bar button{display:inline-flex;align-items:center;width:auto;margin:0;background:rgba(255,255,255,.14);color:#fff}.bt-bar button.on{background:#f59e0b;color:#0b2545}.bt-bar button.go{background:#10b981;color:#06281d}' +
    '.bt-pop{position:fixed;z-index:2147483647;width:min(380px,calc(100vw - 20px));background:#fff;border-radius:16px;padding:14px;box-shadow:0 18px 50px rgba(0,0,0,.35);border:1px solid #dbe3ee}' +
    '.bt-pop small{display:block;color:#5b6b80;margin-bottom:6px}.bt-pop textarea{width:100%;min-height:84px;max-height:40vh;resize:vertical;font-size:16px;line-height:1.45;font-family:inherit;padding:10px;border:1.5px solid #cbd5e1;border-radius:10px;color:#0b2545;background:#fff}' +
    '.bt-pop textarea:focus{outline:0;border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.18)}.bt-pop .row{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}' +
    '.bt-pop .sv{background:#0f766e;color:#fff}.bt-pop .ca{background:#eef2f7;color:#0b2545}.bt-pop .rs{background:none;color:#b45309;padding-inline:4px;margin-inline-start:auto}.bt-pop .er{color:#b91c1c;font-weight:600;margin-top:8px}';
  document.head.appendChild(css);

  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; n.setAttribute('data-bt-ui', ''); if (html) n.innerHTML = html; return n; }
  hlEl = el('div', 'bt-hl'); document.body.appendChild(hlEl);
  bar = el('div', 'bt-bar'); document.body.appendChild(bar);
  function drawBar() {
    bar.innerHTML = '';
    bar.appendChild(el('b', '', '✎ Editing texts'));
    bar.appendChild(el('span', '', nav ? 'Links work now' : 'Click any text' + (saved ? ' · ' + saved + ' saved' : '')));
    var t = el('button', nav ? 'on' : '', nav ? 'Back to editing' : 'Use links'); t.type = 'button';
    t.onclick = function () { nav = !nav; try { sessionStorage.setItem('bt-nav', nav ? '1' : '0'); } catch (e) {} hide(); drawBar(); };
    var d = el('button', 'go', 'Done'); d.type = 'button';
    d.onclick = function () { post({ do: 'edit_off', json: '1' }).then(function () { location.href = BACK || location.pathname; }); };
    bar.appendChild(t); bar.appendChild(d);
  }
  drawBar();

  var tokenP = null;
  function token() {
    if (!tokenP) tokenP = fetch(POST + '?do=token', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); }).then(function (j) { CSRF = j.csrf || ''; return CSRF; });
    return tokenP;
  }
  function post(data) {
    return token().then(function () { return post2(data); });
  }
  function post2(data) {
    data.csrf_token = CSRF;
    var b = new URLSearchParams(); for (var k in data) b.append(k, data[k]);
    return fetch(POST, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server did not accept this (code ' + r.status + ').' }; }); });
  }
  function norm(s) { return String(s).replace(/\s+/g, ' ').trim(); }
  function editable(s) { return /[A-Za-z؀-ۿ]{2}/.test(s); }
  function inUi(n) { return !!(n && n.closest && n.closest('[data-bt-ui]')); }

  /* The text node (or placeholder) under a point. */
  function targetAt(x, y) {
    var e = document.elementFromPoint(x, y);
    if (!e || inUi(e)) return null;
    if (/^(INPUT|TEXTAREA)$/.test(e.tagName) && e.getAttribute('placeholder') && !e.value && editable(e.getAttribute('placeholder'))) {
      return { kind: 'attr', node: e, text: e.getAttribute('placeholder'), rect: e.getBoundingClientRect() };
    }
    if (e.closest('script,style,noscript,textarea,select')) return null;
    var n = null, r;
    if (document.caretPositionFromPoint) { var p = document.caretPositionFromPoint(x, y); if (p && p.offsetNode && p.offsetNode.nodeType === 3) n = p.offsetNode; }
    else if (document.caretRangeFromPoint) { r = document.caretRangeFromPoint(x, y); if (r && r.startContainer.nodeType === 3) n = r.startContainer; }
    if (!n || inUi(n.parentNode) || !editable(n.nodeValue)) return null;
    r = document.createRange(); r.selectNodeContents(n);
    var hit = null, rects = r.getClientRects();
    for (var i = 0; i < rects.length; i++) { var q = rects[i]; if (x >= q.left - 3 && x <= q.right + 3 && y >= q.top - 3 && y <= q.bottom + 3) { hit = q; break; } }
    if (!hit) return null;
    return { kind: 'text', node: n, text: n.nodeValue, rect: r.getBoundingClientRect() };
  }
  function hide() { hlEl.style.display = 'none'; }
  var raf = 0, lastEv = null;
  document.addEventListener('mousemove', function (ev) {
    if (nav || pop) return;
    lastEv = ev;
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = 0;
      var t = lastEv && targetAt(lastEv.clientX, lastEv.clientY);
      if (!t) { hide(); return; }
      var q = t.rect;
      hlEl.style.cssText = 'display:block;left:' + (q.left - 3) + 'px;top:' + (q.top - 2) + 'px;width:' + (q.width + 6) + 'px;height:' + (q.height + 4) + 'px';
    });
  }, true);

  function closePop() { if (pop) { pop.remove(); pop = null; } cur = null; }
  function openPop(t) {
    closePop(); hide(); cur = t;
    pop = el('div', 'bt-pop');
    pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Edit text');
    pop.innerHTML = '<small></small><textarea dir="auto" maxlength="300"></textarea><div class="er" hidden></div><div class="row"><button type="button" class="sv">Save</button><button type="button" class="ca">Cancel</button><button type="button" class="rs">Restore original</button></div>';
    pop.querySelector('small').textContent = (LANG === 'ur' ? 'Urdu' : 'English') + ' version of this text. It changes everywhere this exact text appears.';
    var ta = pop.querySelector('textarea'); ta.value = norm(t.text);
    pop.setAttribute('data-bt-ui', ''); document.body.appendChild(pop);
    var q = t.rect, w = pop.offsetWidth, h = pop.offsetHeight, vw = innerWidth, vh = innerHeight;
    var left = Math.min(Math.max(10, q.left), vw - w - 10), top = q.bottom + 10;
    if (top + h > vh - 70) top = Math.max(10, q.top - h - 10);
    pop.style.left = left + 'px'; pop.style.top = top + 'px';
    ta.focus(); ta.select();
    var er = pop.querySelector('.er');
    function save(val) {
      er.hidden = true;
      post({ do: 'set', lang: LANG, cur: norm(t.text), new: val }).then(function (res) {
        if (!res.ok) { er.textContent = res.error || 'Could not save.'; er.hidden = false; return; }
        applyEverywhere(norm(t.text), res.value, t);
        saved++; closePop(); drawBar();
      }).catch(function () { er.textContent = 'No connection. Try again.'; er.hidden = false; });
    }
    pop.querySelector('.sv').onclick = function () { save(ta.value); };
    pop.querySelector('.ca').onclick = closePop;
    pop.querySelector('.rs').onclick = function () { save(''); };
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closePop();
      else if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(ta.value); }
    });
  }
  /* Show the change at once on this page: every identical text and placeholder. */
  function applyEverywhere(oldText, newText, t) {
    var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null), n, list = [];
    while ((n = w.nextNode())) { if (!inUi(n.parentNode) && norm(n.nodeValue) === oldText) list.push(n); }
    list.forEach(function (x) {
      var s = x.nodeValue, lead = (s.match(/^\s*/) || [''])[0], trail = (s.match(/\s*$/) || [''])[0];
      x.nodeValue = lead + newText + trail;
    });
    document.querySelectorAll('[placeholder]').forEach(function (i) { if (norm(i.getAttribute('placeholder')) === oldText) i.setAttribute('placeholder', newText); });
    if (t.kind === 'attr') t.node.setAttribute('placeholder', newText);
  }

  document.addEventListener('click', function (ev) {
    if (inUi(ev.target) || nav) return;
    var t = targetAt(ev.clientX, ev.clientY);
    ev.preventDefault(); ev.stopPropagation();
    if (t) openPop(t); else closePop();
  }, true);
  document.addEventListener('submit', function (ev) { if (!nav) ev.preventDefault(); }, true);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closePop(); });
})();
