(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('.maximopay-pix-copy');
    if (!button) return;
    var code = document.querySelector('.maximopay-pix-code');
    if (!code) return;
    var done = function () { button.textContent = button.getAttribute('data-copied'); };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(code.value).then(done, function () { code.select(); });
    } else {
      code.select();
      document.execCommand('copy');
      done();
    }
  });
  var config = window.maximopayPix;
  if (!config || !config.statusUrl) return;
  var tries = 0;
  var timer = setInterval(function () {
    // Para de consultar depois de ~1 h (o Pix já expirou).
    if (++tries > 720) return clearInterval(timer);
    fetch(config.statusUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (body) {
        if (body && (body.paid || body.status === 'cancelled' || body.status === 'failed')) {
          clearInterval(timer);
          window.location.reload();
        }
      })
      .catch(function () {});
  }, 5000);
})();
