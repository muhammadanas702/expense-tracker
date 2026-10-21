(function () {
  var bell = document.querySelector('.ic.bl[data-pop]');
  if (!bell) return;
  var panel = null, html = '', base = bell.getAttribute('data-pop');
  function load(peek) {
    return fetch(base + (peek ? '&peek=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw 0; return r.text(); });
  }
  // Fetch the list quietly right after the page is ready (without marking it read), so the click shows it at once.
  function prefetch() { load(true).then(function (h) { html = h; }).catch(function () {}); }
  if ('requestIdleCallback' in window) requestIdleCallback(prefetch, { timeout: 2500 }); else setTimeout(prefetch, 1200);

  function build() {
    panel = document.createElement('div');
    panel.className = 'npop g';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Notifications');
    panel.hidden = true;
    document.body.appendChild(panel);
  }
  function close() {
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
  }
  function open() {
    if (!panel) build();
    panel.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    panel.innerHTML = html || '<p class="k np-e">Loading…</p>';
    var d = bell.querySelector('.dt');
    // Opening counts as reading: tell the server in the background and refresh the list if it was not ready yet.
    load(false).then(function (h) {
      if (d) d.remove();
      if (!html || html !== h) { html = h; if (panel && !panel.hidden) panel.innerHTML = h; }
      html = h.replace(/ new"/g, '"');
    }).catch(function () { if (!html) window.location.href = bell.href; });
  }
  bell.addEventListener('click', function (e) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    if (panel && !panel.hidden) close(); else open();
  });
  document.addEventListener('click', function (e) {
    if (panel && !panel.hidden && !panel.contains(e.target) && !bell.contains(e.target)) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
