(function () {
  var bell = document.querySelector('.ic.bl[data-pop]');
  if (!bell) return;
  var panel = null, loaded = false;
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
    panel.innerHTML = '<p class="k np-e">Loading…</p>';
    fetch(bell.getAttribute('data-pop'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw 0; return r.text(); })
      .then(function (h) {
        panel.innerHTML = h;
        var d = bell.querySelector('.dt'); if (d) d.remove();
      })
      .catch(function () { window.location.href = bell.href; });
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
