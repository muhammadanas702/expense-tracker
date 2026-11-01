/* Phone bottom bar: the "More" button opens a sheet with the other menu items. */
(function () {
  'use strict';
  var btn = document.getElementById('more-btn'), sheet = document.getElementById('more-sheet');
  if (!btn || !sheet) { return; }
  function close() { sheet.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = sheet.hidden;
    sheet.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function (e) { if (!sheet.hidden && !sheet.contains(e.target)) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
})();
