/* Landing page (Part 85): menu toggle only. The page works fully without JavaScript. */
(function () {
  'use strict';
  var b = document.getElementById('burger'), m = document.getElementById('menu'), n = document.getElementById('nav');
  if (!b || !m) return;
  function set(o) { m.hidden = !o; b.setAttribute('aria-expanded', o ? 'true' : 'false'); n.classList.toggle('open', o); }
  b.addEventListener('click', function () { set(m.hidden); });
  m.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  window.addEventListener('resize', function () { if (window.innerWidth > 900) set(false); });
})();
