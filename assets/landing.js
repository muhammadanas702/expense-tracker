/* Landing scroll helpers (Part 94): progress bar, section dots, active nav link, back-to-top. The page works fully without JavaScript. */
(function () {
  'use strict';
  var prog = document.getElementById('prog'), top = document.getElementById('totop');
  var rail = document.getElementById('rail'), nav = document.getElementById('nav');
  var ids = ['home', 'features', 'modes', 'how', 'apps', 'faq'];
  var secs = ids.map(function (i) { return document.getElementById(i); });
  var railLinks = rail ? rail.querySelectorAll('a') : [];
  var navLinks = nav ? nav.querySelectorAll('a[href^="#"], a[href*="/#"]') : [];
  var raf = 0;

  function mark(k) {
    for (var i = 0; i < railLinks.length; i++) {
      var on = i === k;
      railLinks[i].classList.toggle('on', on);
      if (on) railLinks[i].setAttribute('aria-current', 'true'); else railLinks[i].removeAttribute('aria-current');
    }
    var cur = k > 0 ? ids[k] : '';
    for (var j = 0; j < navLinks.length; j++) {
      var h = navLinks[j].getAttribute('href') || '', hit = cur && h.slice(h.lastIndexOf('#') + 1) === cur;
      navLinks[j].classList.toggle('cur', !!hit);
    }
  }

  function update() {
    raf = 0;
    var y = window.scrollY || 0, max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    if (prog) prog.style.transform = 'scaleX(' + Math.min(1, y / max).toFixed(4) + ')';
    if (top) top.classList.toggle('show', y > 700);
    var line = window.innerHeight * 0.4, k = 0;
    for (var i = 0; i < secs.length; i++) { if (secs[i] && secs[i].getBoundingClientRect().top <= line) k = i; }
    mark(k);
  }
  function req() { if (!raf) raf = requestAnimationFrame(update); }
  update();
  window.addEventListener('scroll', req, { passive: true });
  window.addEventListener('resize', req);
})();
