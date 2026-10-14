/* Auth pages: show/hide password, password strength meter, OTP digits only. */
(function () {
  document.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input');
      if (i) { i.type = i.type === 'password' ? 'text' : 'password'; }
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
