/* Top bar behaviour for the landing and public pages (Part 89). The pages work fully without JavaScript. */
(function () {
  'use strict';
  var n = document.getElementById('nav'), b = document.getElementById('burger'), m = document.getElementById('menu');
  if (!n) return;
  function sc() { n.classList.toggle('on', (window.scrollY || 0) > 8); }
  sc(); window.addEventListener('scroll', sc, { passive: true });
  if (!b || !m) return;
  function set(o) { m.hidden = !o; b.setAttribute('aria-expanded', o ? 'true' : 'false'); n.classList.toggle('open', o); }
  b.addEventListener('click', function () { set(m.hidden); });
  m.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  window.addEventListener('resize', function () { if (window.innerWidth > 960) set(false); });
})();
