/**
 * Hypex UI — نوافذ تنبيه/تأكيد وسط الصفحة + توست
 * window.HypexUI.alert / .confirm / .toast
 * يُحوّل confirm() في onsubmit/onclick إلى نافذة النظام.
 */
(function () {
  'use strict';

  var root = null;
  var queue = Promise.resolve();
  var toastHost = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function ensureRoot() {
    if (root && document.body.contains(root)) return root;
    root = document.createElement('div');
    root.id = 'hx-ui-root';
    root.className = 'hx-ui';
    root.hidden = true;
    root.setAttribute('role', 'presentation');
    root.innerHTML =
      '<div class="hx-ui__backdrop" data-hx-ui-close="1"></div>' +
      '<div class="hx-ui__panel" role="dialog" aria-modal="true" aria-labelledby="hx-ui-title">' +
      '<p class="hx-ui__kicker" id="hx-ui-kicker">تنبيه النظام</p>' +
      '<h2 class="hx-ui__title" id="hx-ui-title"></h2>' +
      '<p class="hx-ui__msg" id="hx-ui-msg"></p>' +
      '<div class="hx-ui__actions" id="hx-ui-actions"></div>' +
      '</div>';
    document.body.appendChild(root);
    return root;
  }

  function ensureToastHost() {
    if (toastHost && document.body.contains(toastHost)) return toastHost;
    toastHost = document.createElement('div');
    toastHost.className = 'hx-ui-toast-host';
    toastHost.setAttribute('aria-live', 'polite');
    document.body.appendChild(toastHost);
    return toastHost;
  }

  function closeDialog() {
    if (!root) return;
    root.hidden = true;
    root.classList.remove('hx-ui--warn', 'hx-ui--error', 'hx-ui--ok', 'hx-ui--info');
  }

  /**
   * @param {object} opts
   * @param {string} opts.message
   * @param {string} [opts.title]
   * @param {string} [opts.kind] info|warn|error|ok
   * @param {Array<{label:string,value:*,primary?:boolean,danger?:boolean}>} opts.buttons
   * @returns {Promise<*>}
   */
  function showDialog(opts) {
    opts = opts || {};
    var message = String(opts.message == null ? '' : opts.message);
    var title = String(opts.title || '');
    var kind = String(opts.kind || 'info');
    var buttons = Array.isArray(opts.buttons) && opts.buttons.length
      ? opts.buttons
      : [{ label: 'حسناً', value: true, primary: true }];

    return new Promise(function (resolve) {
      queue = queue.then(function () {
        return new Promise(function (done) {
          var el = ensureRoot();
          el.hidden = false;
          el.classList.remove('hx-ui--warn', 'hx-ui--error', 'hx-ui--ok', 'hx-ui--info');
          el.classList.add('hx-ui--' + (kind === 'danger' ? 'error' : kind));

          var kickers = {
            info: 'تنبيه النظام',
            warn: 'تأكيد العملية',
            error: 'خطأ',
            ok: 'نجاح',
          };
          el.querySelector('#hx-ui-kicker').textContent = kickers[kind] || kickers.info;
          el.querySelector('#hx-ui-title').textContent = title || (kind === 'warn' ? 'هل أنت متأكد؟' : 'رسالة');
          el.querySelector('#hx-ui-msg').textContent = message;

          var actions = el.querySelector('#hx-ui-actions');
          actions.innerHTML = '';

          function finish(val) {
            closeDialog();
            resolve(val);
            done();
          }

          buttons.forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className =
              'hx-ui__btn' +
              (b.primary ? ' hx-ui__btn--primary' : '') +
              (b.danger ? ' hx-ui__btn--danger' : '');
            btn.textContent = b.label || 'حسناً';
            btn.addEventListener('click', function () {
              finish(b.value);
            });
            actions.appendChild(btn);
          });

          function onKey(e) {
            if (e.key === 'Escape') {
              document.removeEventListener('keydown', onKey, true);
              finish(buttons[0] ? buttons[0].value : false);
            }
            if (e.key === 'Enter') {
              var primary = buttons.find(function (x) {
                return x.primary;
              });
              if (primary) {
                e.preventDefault();
                document.removeEventListener('keydown', onKey, true);
                finish(primary.value);
              }
            }
          }
          document.addEventListener('keydown', onKey, true);

          var backdrop = el.querySelector('.hx-ui__backdrop');
          backdrop.onclick = function () {
            document.removeEventListener('keydown', onKey, true);
            // إلغاء عند الإغلاق بالنقر على الخلفية إذا تأكيد
            var cancel = buttons.find(function (x) {
              return x.value === false;
            });
            finish(cancel ? false : buttons[0] && buttons[0].value);
          };

          setTimeout(function () {
            var focusBtn =
              actions.querySelector('.hx-ui__btn--primary') || actions.querySelector('.hx-ui__btn');
            if (focusBtn) focusBtn.focus();
          }, 30);
        });
      });
    });
  }

  function alert(message, kind) {
    return showDialog({
      message: message,
      kind: kind || 'info',
      title: kind === 'error' ? 'تعذّر الإكمال' : kind === 'ok' ? 'تم' : 'تنبيه',
      buttons: [{ label: 'حسناً', value: true, primary: true }],
    });
  }

  function confirm(message, opts) {
    opts = opts || {};
    return showDialog({
      message: message,
      kind: opts.kind || 'warn',
      title: opts.title || 'تأكيد',
      buttons: [
        { label: opts.cancelLabel || 'إلغاء', value: false },
        {
          label: opts.okLabel || 'موافق',
          value: true,
          primary: true,
          danger: !!opts.danger,
        },
      ],
    });
  }

  function toast(message, kind, ms) {
    var host = ensureToastHost();
    var el = document.createElement('div');
    el.className = 'hx-ui-toast' + (kind ? ' is-' + kind : '');
    el.textContent = String(message || '');
    host.appendChild(el);
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transition = 'opacity .25s ease';
      setTimeout(function () {
        el.remove();
      }, 280);
    }, ms || 2800);
  }

  /** استخراج نص confirm من خاصية onsubmit/onclick */
  function extractConfirmMsg(attr) {
    if (!attr) return null;
    var m = String(attr).match(/confirm\s*\(\s*(['"])([\s\S]*?)\1\s*\)/);
    if (!m) return null;
    return m[2].replace(/\\n/g, '\n').replace(/\\'/g, "'").replace(/\\"/g, '"');
  }

  function stripConfirmFromAttr(attr) {
    return String(attr || '')
      .replace(/return\s+confirm\s*\([^)]*\)\s*;?/g, 'return true;')
      .replace(/confirm\s*\([^)]*\)/g, 'true');
  }

  function installLegacyHooks() {
    document.addEventListener(
      'submit',
      function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.getAttribute('data-hx-ui-pass') === '1') {
          form.removeAttribute('data-hx-ui-pass');
          return;
        }
        var onsubmit = form.getAttribute('onsubmit') || '';
        if (onsubmit.indexOf('confirm') === -1) return;
        var msg = extractConfirmMsg(onsubmit);
        if (msg == null) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        confirm(msg).then(function (ok) {
          if (!ok) return;
          form.setAttribute('data-hx-ui-pass', '1');
          var prev = form.getAttribute('onsubmit');
          form.setAttribute('onsubmit', stripConfirmFromAttr(prev));
          try {
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
          } finally {
            if (prev != null) form.setAttribute('onsubmit', prev);
          }
        });
      },
      true
    );

    document.addEventListener(
      'click',
      function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[onclick]') : null;
        if (!el) return;
        if (el.getAttribute('data-hx-ui-pass') === '1') {
          el.removeAttribute('data-hx-ui-pass');
          return;
        }
        var oc = el.getAttribute('onclick') || '';
        if (oc.indexOf('confirm') === -1) return;
        var msg = extractConfirmMsg(oc);
        if (msg == null) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        confirm(msg).then(function (ok) {
          if (!ok) return;
          el.setAttribute('data-hx-ui-pass', '1');
          var prev = el.getAttribute('onclick');
          el.setAttribute('onclick', stripConfirmFromAttr(prev));
          try {
            el.click();
          } finally {
            if (prev != null) el.setAttribute('onclick', prev);
          }
        });
      },
      true
    );

    window.alert = function (message) {
      alert(message, 'info');
    };
  }

  window.HypexUI = {
    alert: alert,
    confirm: confirm,
    toast: toast,
    dialog: showDialog,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installLegacyHooks);
  } else {
    installLegacyHooks();
  }
})();
