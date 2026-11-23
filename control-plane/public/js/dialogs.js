/*
 * The console's own alerts, confirmations and messages, in place of the browser's.
 *
 *   cm.confirm({title, message, ok, cancel, tone}) -> Promise<boolean>
 *   cm.alert({title, message, ok, tone})           -> Promise<void>
 *   cm.toast(message, {tone, title, ms})           (tone: info | ok | warn | danger)
 *
 * A form, or its submit button, asks first when it has data-confirm="Message":
 *   data-confirm-title, data-confirm-ok (the button's words), data-confirm-tone="danger".
 * Written without a framework: components re-render and replace parts of the page, so the
 * listeners are on the document.
 */
(function () {
  'use strict';
  if (window.cm) return;

  var root = document.documentElement;
  var dialog = null, resolver = null, opener = null;
  var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function build() {
    dialog = el('dialog', 'dlg');
    dialog.setAttribute('aria-labelledby', 'dlg-title');
    dialog.setAttribute('aria-describedby', 'dlg-msg');
    dialog.innerHTML =
      '<form method="dialog" class="dlg-card">' +
        '<div class="dlg-mark" aria-hidden="true"></div>' +
        '<h2 class="dlg-title" id="dlg-title"></h2>' +
        '<p class="dlg-msg" id="dlg-msg"></p>' +
        '<div class="dlg-actions"><button type="button" class="btn dlg-cancel"></button><button type="submit" value="ok" class="btn dlg-ok"></button></div>' +
      '</form>';
    document.body.appendChild(dialog);
    // Esc and a click on the backdrop both mean no.
    dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });
    dialog.addEventListener('mousedown', function (e) { if (e.target === dialog) close(false); });
    dialog.querySelector('.dlg-cancel').addEventListener('click', function () { close(false); });
    dialog.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); close(true); });
  }

  function close(answer) {
    if (!dialog || !dialog.open) return;
    dialog.classList.add('leaving');
    var done = function () {
      dialog.classList.remove('leaving');
      dialog.close();
      var r = resolver; resolver = null;
      if (opener && opener.focus && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
      if (r) r(answer);
    };
    reduced ? done() : setTimeout(done, 130);
  }

  function open(o, ask) {
    if (!dialog) build();
    // One at a time: a second request answers the first with no.
    if (resolver) { var r = resolver; resolver = null; r(false); }
    var tone = o.tone || (ask ? 'info' : 'info');
    dialog.dataset.tone = tone;
    dialog.querySelector('.dlg-title').textContent = o.title || (ask ? 'Are you sure?' : 'Heads up');
    dialog.querySelector('.dlg-msg').textContent = o.message || '';
    var ok = dialog.querySelector('.dlg-ok'), cancel = dialog.querySelector('.dlg-cancel');
    ok.textContent = o.ok || (ask ? 'Continue' : 'OK');
    ok.className = 'btn dlg-ok ' + (tone === 'danger' ? 'btn-danger' : 'btn-primary');
    cancel.textContent = o.cancel || 'Cancel';
    cancel.hidden = !ask;
    opener = document.activeElement;
    return new Promise(function (resolve) {
      resolver = resolve;
      dialog.showModal();
      // The careful answer has the focus: no, for something that cannot be undone.
      (ask && tone === 'danger' ? cancel : ok).focus();
    });
  }

  var stack = null;
  function toast(message, o) {
    o = o || {};
    var tone = o.tone || 'info';
    if (!stack) {
      stack = el('div', 'toasts');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    var t = el('div', 'toast');
    t.dataset.tone = tone;
    t.setAttribute('role', tone === 'danger' || tone === 'warn' ? 'alert' : 'status');
    if (o.title) t.appendChild(el('b', 'toast-title', o.title));
    t.appendChild(el('span', 'toast-msg', message));
    var x = el('button', 'toast-x', '×');
    x.type = 'button'; x.setAttribute('aria-label', 'Dismiss');
    var gone = function () { if (!t.parentNode) return; t.classList.add('leaving'); reduced ? t.remove() : setTimeout(function () { t.remove(); }, 160); };
    x.addEventListener('click', gone);
    t.appendChild(x);
    stack.appendChild(t);
    // Trouble stays until it is dismissed; the rest goes by itself.
    var ms = o.ms != null ? o.ms : (tone === 'danger' ? 0 : 6000);
    if (ms > 0) setTimeout(gone, ms);
    return gone;
  }

  window.cm = {
    confirm: function (o) { return open(o || {}, true); },
    alert: function (o) { return open(o || {}, false).then(function () {}); },
    toast: toast
  };

  // data-confirm on a form or on the button that submits it.
  var passed = new WeakSet();
  document.addEventListener('submit', function (e) {
    var form = e.target, by = e.submitter;
    var src = (by && by.dataset && by.dataset.confirm) ? by : (form.dataset && form.dataset.confirm ? form : null);
    if (!src || passed.has(form)) { passed.delete(form); return; }
    e.preventDefault();
    cm.confirm({ title: src.dataset.confirmTitle, message: src.dataset.confirm, ok: src.dataset.confirmOk, tone: src.dataset.confirmTone }).then(function (yes) {
      if (!yes) return;
      passed.add(form);
      by ? form.requestSubmit(by) : form.requestSubmit();
    });
  }, true);

  // u-confirm on a component button: the runtime would ask with the browser's own confirm(), which looks
  // different and can be blocked. Ask here instead, then let the click through with that answer given.
  var approved = new WeakSet();
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[u-confirm]') : null;
    if (!t) return;
    if (approved.has(t)) { approved.delete(t); return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    var message = t.getAttribute('u-confirm') || '';
    var label = (t.textContent || '').replace(/^[^A-Za-z0-9]+/, '').trim();
    var danger = /delet|remov|destroy|erase|cannot be undone|can't be undone|permanently|disconnect|roll(ed)? back|replace/i.test(message + ' ' + label);
    cm.confirm({ title: label ? label + '?' : 'Are you sure?', message: message, ok: label || 'Continue', tone: danger ? 'danger' : 'info' }).then(function (yes) {
      if (!yes) return;
      approved.add(t);
      var native = window.confirm;
      window.confirm = function () { return true; };
      t.click();
      setTimeout(function () { window.confirm = native; }, 1500);
    });
  }, true);

  // data-copy on a button: puts its text on the clipboard.
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var text = b.getAttribute('data-copy') || '';
    var ok = function () { toast('Copied.', { tone: 'ok', ms: 2500 }); };
    var fallback = function () {
      var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); ok(); } catch (x) {} t.remove();
    };
    navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(text).then(ok, fallback) : fallback();
  });

  // Messages the server left for this page: <script type="application/json" id="cm-flash">[{"message":..,"tone":..}]</script>
  function flash() {
    var n = document.getElementById('cm-flash');
    if (!n) return;
    try { JSON.parse(n.textContent).forEach(function (m) { toast(m.message, m); }); } catch (e) {}
    n.remove();
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', flash) : flash();
})();
