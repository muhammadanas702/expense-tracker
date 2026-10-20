/* Part 12: small visual touches. Everything here is optional: the pages work the same without it. */
(function () {
  var d = document, root = d.documentElement;
  root.classList.add('js');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Scroll reveal: .rv elements fade up the first time they are seen.
  var items = d.querySelectorAll('.rv');
  if (items.length) {
    if (reduce || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('seen'); });
    } else {
      var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          if (e.isIntersecting) { e.target.classList.add('seen'); io.unobserve(e.target); }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      items.forEach(function (el) { io.observe(el); });
      // Safety net: never leave something hidden if the observer does not fire.
      setTimeout(function () { items.forEach(function (el) { el.classList.add('seen'); }); }, 6000);
    }
  }

  // Sticky navigation gets a shadow once the page is scrolled.
  var nb = d.querySelector('.nb');
  if (nb) {
    var onScroll = function () { nb.classList.toggle('sc', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // Count-up for headline numbers: [data-countup] keeps its text and prefix/suffix (for example "Rs 42,500" or "− 1,200.50 PKR").
  d.querySelectorAll('[data-countup]').forEach(function (el) {
    var text = el.textContent, m = text.match(/\d[\d,]*(\.\d+)?/);
    if (reduce || !m) { return; }
    var target = parseFloat(m[0].replace(/,/g, '')), dec = m[1] ? m[1].length - 1 : 0, comma = m[0].indexOf(',') !== -1;
    if (!isFinite(target) || target === 0) { return; }
    var fmt = function (n) {
      var parts = n.toFixed(dec).split('.');
      if (comma) { parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
      return parts.join('.');
    };
    var start = null, dur = 900;
    function step(t) {
      if (start === null) { start = t; }
      var p = Math.min(1, (t - start) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = text.replace(m[0], p === 1 ? m[0] : fmt(target * e));
      if (p < 1) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
  });

  // Flash messages ("saved", "deleted") fade away by themselves; errors stay.
  d.querySelectorAll('[data-flash].ok, .msg.ok[data-flash]').forEach(function (el) {
    setTimeout(function () { el.classList.add('gone'); setTimeout(function () { el.remove(); }, 450); }, 5000);
  });
})();
