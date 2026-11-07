/* Landing page behaviour (Part 82). Everything is optional: with JavaScript off the page is fully visible and usable. */
(function () {
  'use strict';
  var d = document, root = d.documentElement;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var KEY = 'ef_landing_nomo';

  // Pause motion (remembered): the strip, shine, floating chips and reveals all stop.
  var btn = d.getElementById('mo');
  function setMo(off) {
    root.classList.toggle('nomo', off);
    if (btn) { btn.setAttribute('aria-pressed', off ? 'true' : 'false'); btn.querySelector('span').textContent = off ? btn.dataset.play : btn.dataset.pause; }
    try { localStorage.setItem(KEY, off ? '1' : '0'); } catch (e) {}
  }
  var saved = false; try { saved = localStorage.getItem(KEY) === '1'; } catch (e) {}
  if (saved || reduce) setMo(true);
  if (btn) btn.addEventListener('click', function () { setMo(!root.classList.contains('nomo')); });

  // Navigation: solid background after scrolling, full-screen menu on small screens.
  var nav = d.getElementById('nav'), burger = d.getElementById('burger'), menu = d.getElementById('menu');
  function onScroll() { if (nav) nav.classList.toggle('sc', (window.scrollY || 0) > 30); }
  onScroll(); window.addEventListener('scroll', onScroll, { passive: true });
  function closeMenu() { if (!menu) return; menu.classList.remove('on'); burger.setAttribute('aria-expanded', 'false'); root.style.overflow = ''; }
  if (burger && menu) {
    burger.addEventListener('click', function () {
      var open = !menu.classList.contains('on');
      menu.classList.toggle('on', open); burger.setAttribute('aria-expanded', open ? 'true' : 'false'); root.style.overflow = open ? 'hidden' : '';
      menu.querySelectorAll('a.mi').forEach(function (a, i) { a.style.transitionDelay = open ? (0.06 * i + 0.1) + 's' : '0s'; });
    });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    window.addEventListener('resize', function () { if (window.innerWidth > 1020) closeMenu(); });
  }

  // Reveal on scroll.
  var rv = d.querySelectorAll('.rv');
  if ('IntersectionObserver' in window && !root.classList.contains('nomo')) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    rv.forEach(function (el) { io.observe(el); });
  } else { rv.forEach(function (el) { el.classList.add('in'); }); }

  // Spotlight that follows the pointer on cards.
  d.querySelectorAll('.cd').forEach(function (c) {
    c.addEventListener('pointermove', function (e) {
      var r = c.getBoundingClientRect();
      c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
  });

  // Gentle tilt of the hero mock (mouse only, never on touch).
  var mock = d.getElementById('mock');
  if (mock && window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches) {
    var mk = mock.querySelector('.mk');
    window.addEventListener('pointermove', function (e) {
      if (root.classList.contains('nomo') || !mk) return;
      var x = (e.clientX / window.innerWidth - 0.5), y = (e.clientY / window.innerHeight - 0.5);
      mk.style.transform = 'rotateY(' + (x * 8).toFixed(2) + 'deg) rotateX(' + (-y * 6).toFixed(2) + 'deg)';
    }, { passive: true });
  }

  // Count-up for the balance in the mock.
  d.querySelectorAll('[data-countup]').forEach(function (el) {
    var txt = el.textContent, m = txt.match(/([\d,]+)/); if (!m || reduce) return;
    var target = parseInt(m[1].replace(/,/g, ''), 10), pre = txt.slice(0, m.index), suf = txt.slice(m.index + m[1].length), t0 = null;
    function step(t) {
      if (t0 === null) t0 = t; var p = Math.min(1, (t - t0) / 1400), v = Math.round(target * (1 - Math.pow(1 - p, 3)));
      el.textContent = pre + v.toLocaleString('en-US') + suf; if (p < 1) requestAnimationFrame(step);
    }
    el.textContent = pre + '0' + suf; setTimeout(function () { requestAnimationFrame(step); }, 1100);
  });
})();
