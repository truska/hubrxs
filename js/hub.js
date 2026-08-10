(function () {
  function setPasswordVisibility(button, visible) {
    var field = button.closest('.password-field');
    var input = field ? field.querySelector('input[type="password"], input[type="text"]') : null;

    if (!input) {
      return;
    }

    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password while hovered');
    button.classList.toggle('is-visible', visible);
  }

  function bindPasswordReveal(button) {
    if (button.hasAttribute('onmouseenter')) {
      return;
    }

    button.addEventListener('mouseenter', function () {
      setPasswordVisibility(button, true);
    });

    button.addEventListener('mouseleave', function () {
      if (button.getAttribute('aria-pressed') !== 'true') {
        setPasswordVisibility(button, false);
      }
    });

    button.addEventListener('focus', function () {
      setPasswordVisibility(button, true);
    });

    button.addEventListener('blur', function () {
      if (button.getAttribute('aria-pressed') !== 'true') {
        setPasswordVisibility(button, false);
      }
    });

    button.addEventListener('click', function () {
      var pressed = button.getAttribute('aria-pressed') === 'true';
      button.setAttribute('aria-pressed', pressed ? 'false' : 'true');
      setPasswordVisibility(button, !pressed);
    });
  }

  document.querySelectorAll('[data-password-reveal]').forEach(bindPasswordReveal);
}());

(function () {
  function rememberFilterFocus(form, field) {
    if (!form || !field.name) return;

    var focusInput = form.querySelector('input[name="_focus"]');
    var cursorInput = form.querySelector('input[name="_cursor"]');

    if (!focusInput) {
      focusInput = document.createElement('input');
      focusInput.type = 'hidden';
      focusInput.name = '_focus';
      form.appendChild(focusInput);
    }

    if (!cursorInput) {
      cursorInput = document.createElement('input');
      cursorInput.type = 'hidden';
      cursorInput.name = '_cursor';
      form.appendChild(cursorInput);
    }

    focusInput.value = field.name;
    cursorInput.value = String(field.selectionStart || field.value.length || 0);
  }

  function restoreFilterFocus() {
    var params = new URLSearchParams(window.location.search);
    var focusName = params.get('_focus');

    if (!focusName) return;

    document.querySelectorAll('[data-auto-filter]').forEach(function (field) {
      if (field.name !== focusName) return;

      var cursor = parseInt(params.get('_cursor') || field.value.length, 10);
      field.focus();

      if (field.setSelectionRange) {
        field.setSelectionRange(cursor, cursor);
      }
    });
  }

  function bindAutoFilterTables() {
    var timers = new WeakMap();

    document.querySelectorAll('[data-auto-filter]').forEach(function (field) {
      if (field.dataset.autoFilterBound === '1') return;
      field.dataset.autoFilterBound = '1';

      var eventName = field.tagName === 'SELECT' ? 'change' : 'input';

      field.addEventListener('keydown', function (event) {
        var form = field.form;
        if (event.key !== 'Enter' || !form) return;

        event.preventDefault();
        window.clearTimeout(timers.get(form));
        rememberFilterFocus(form, field);
        form.submit();
      });

      field.addEventListener(eventName, function () {
        var form = field.form;
        if (!form) return;

        window.clearTimeout(timers.get(form));

        if (field.tagName !== 'SELECT') {
          var valueLength = field.value.trim().length;
          if (valueLength > 0 && valueLength < 3) return;
        }

        timers.set(form, window.setTimeout(function () {
          rememberFilterFocus(form, field);
          form.submit();
        }, field.tagName === 'SELECT' ? 0 : 650));
      });
    });

    restoreFilterFocus();
  }

  window.hubBindAutoFilterTables = bindAutoFilterTables;
  bindAutoFilterTables();
}());