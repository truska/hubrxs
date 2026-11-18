(function () {
  'use strict';

  document.querySelectorAll('[data-auth-cooldown]').forEach(function (form) {
    var seconds = Number(form.dataset.authCooldown);
    var button = form.querySelector('[data-auth-submit]');
    var message = form.querySelector('[data-auth-cooldown-message]');
    if (!button || !message || !Number.isFinite(seconds) || seconds <= 0) return;

    var deadline = Date.now() + seconds * 1000;
    var timer;

    function remainingSeconds() {
      return Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    }

    function update() {
      var remaining = remainingSeconds();
      var minutes = Math.ceil(remaining / 60);
      var text = remaining > 0
        ? 'Too many attempts. Try again in ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '.'
        : 'You can try signing in again.';

      button.disabled = remaining > 0;
      message.hidden = false;
      // Announce minute changes, rather than reading a countdown every second.
      if (message.textContent !== text) message.textContent = text;
      if (remaining === 0) window.clearInterval(timer);
    }

    form.addEventListener('submit', function (event) {
      if (remainingSeconds() > 0) event.preventDefault();
    });
    timer = window.setInterval(update, 1000);
    window.addEventListener('pageshow', update);
    update();
  });
}());
