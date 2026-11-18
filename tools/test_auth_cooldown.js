// Run with node; exercises the shipped script without a browser or external packages.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/auth-cooldown.js'), 'utf8');
let checks = 0;
function check(value) { assert.ok(value); checks++; }
function fixture(seconds) {
  let now = 0;
  let interval;
  let submit;
  let pageshow;
  let cleared = false;
  const button = { disabled: seconds > 0 };
  const message = { textContent: '', hidden: seconds <= 0 };
  const form = {
    dataset: { authCooldown: String(seconds) },
    querySelector: selector => selector === '[data-auth-submit]' ? button : message,
    addEventListener: (event, callback) => { if (event === 'submit') submit = callback; }
  };
  vm.runInNewContext(source, {
    document: { querySelectorAll: () => [form] },
    Date: { now: () => now },
    window: {
      setInterval: callback => { interval = callback; return 1; },
      clearInterval: () => { cleared = true; },
      addEventListener: (event, callback) => { if (event === 'pageshow') pageshow = callback; }
    }
  });
  return {
    button, message,
    elapsed: milliseconds => { now = milliseconds; interval(); },
    restore: milliseconds => { now = milliseconds; pageshow(); },
    submit: () => { let blocked = false; if (submit) submit({ preventDefault: () => { blocked = true; } }); return blocked; },
    cleared: () => cleared
  };
}
const active = fixture(125);
check(active.button.disabled && !active.message.hidden);
check(active.message.textContent === 'Too many attempts. Try again in 3 minutes.');
check(active.submit());
active.elapsed(65000);
check(active.button.disabled && active.message.textContent === 'Too many attempts. Try again in 1 minute.');
active.elapsed(124001);
check(active.button.disabled && active.submit());
active.elapsed(125000);
check(!active.button.disabled && !active.submit());
check(active.message.textContent === 'You can try signing in again.' && active.cleared());
const restored = fixture(30);
restored.restore(40000);
check(!restored.button.disabled && restored.cleared());
const ready = fixture(0);
check(!ready.button.disabled && ready.message.hidden && !ready.submit());
console.log('PASS: ' + checks + ' cooldown checks including expiry, keyboard submission and back/forward restoration.');
