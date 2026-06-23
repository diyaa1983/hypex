(function (global) {
  'use strict';

  if (global.AppDialog && global.AppDialog.__singleton === 2) {
    return;
  }

  function cleanupDuplicateDialogRoots() {
    var nodes = document.querySelectorAll('#ui-dialog-root');
    for (var i = 1; i < nodes.length; i++) {
      if (nodes[i].parentNode) {
        nodes[i].parentNode.removeChild(nodes[i]);
      }
    }
  }

  var root = null;

  var ICONS = {
    success: '✓',
    error: '✕',
    warning: '!',
    confirm: '?',
    info: 'i',
  };

  var TITLES = {
    success: 'تم بنجاح',
    error: 'تنبيه',
    warning: 'تحذير',
    confirm: 'تأكيد',
    info: 'معلومة',
  };

  var ORA_TITLES = {
    success: 'تم بنجاح',
    error: 'تنبيه',
    warning: 'تحذير',
    confirm: 'تأكيد',
    info: 'معلومة',
  };

  function isOracleScreen() {
    return (
      (document.body && document.body.classList.contains('hr-ora-ui')) ||
      !!document.querySelector('[class*="-ora-screen"]')
    );
  }

  function resolveTheme(options) {
    options = options || {};
    if (options.theme) {
      return options.theme;
    }
    return isOracleScreen() ? 'oracle' : '';
  }

  function resolveTitle(type, theme, customTitle) {
    if (customTitle) {
      return customTitle;
    }
    if (theme === 'oracle') {
      return ORA_TITLES[type] || ORA_TITLES.info;
    }
    return TITLES[type] || TITLES.info;
  }

  function appendUniqueLines(base, lines) {
    var msg = String(base || '').trim();
    (lines || []).forEach(function (line) {
      line = String(line || '').trim();
      if (!line || msg.indexOf(line) !== -1) {
        return;
      }
      msg += (msg ? '\n\n' : '') + line;
    });
    return msg;
  }

  /** رسالة API بدون تكرار التحذيرات (message + warning + warnings) */
  function formatActionMessage(data, options) {
    options = options || {};
    var msg = (data && data.message) || options.fallback || '';
    var cut = msg.indexOf(' — تنبيه:');
    if (cut !== -1) {
      msg = msg.substring(0, cut).trim();
    }
    var extras = (options.extras || []).slice();
    var warnings = (data && data.warnings) ? data.warnings.slice() : [];
    if (data && data.warning) {
      var w0 = String(data.warning);
      if (warnings.indexOf(w0) === -1) {
        warnings.unshift(w0);
      }
    }
    warnings.forEach(function (w) {
      w = String(w || '').trim();
      if (!w || extras.indexOf(w) !== -1 || msg.indexOf(w) !== -1) {
        return;
      }
      extras.push(w);
    });
    return appendUniqueLines(msg, extras);
  }

  var root = null;
  var backdrop = null;
  var card = null;
  var iconEl = null;
  var titleEl = null;
  var messageEl = null;
  var actionsEl = null;
  var resolver = null;
  var lastFocus = null;
  var dialogBusy = false;
  var dialogQueue = [];
  var dialogClosing = false;
  var lastOpenSignature = '';
  var lastOpenAt = 0;

  function lockPageBehindDialog() {
    if (document.body) {
      document.body.classList.add('ui-dialog-lock');
    }
  }

  function unlockPageBehindDialog() {
    if (document.body) {
      document.body.classList.remove('ui-dialog-lock');
    }
  }

  function dialogSignature(opts) {
    return [
      opts.mode || 'alert',
      opts.type || 'info',
      String(opts.title || ''),
      String(opts.message || ''),
    ].join('\u0001');
  }

  function enqueueDialog(opts, resolve) {
    var sig = dialogSignature(opts);
    var now = Date.now();
    if (sig === lastOpenSignature && now - lastOpenAt < 3000) {
      if (typeof resolve === 'function') {
        resolve(opts.mode === 'confirm' || opts.mode === 'save-discard' ? false : true);
      }
      return;
    }
    for (var i = 0; i < dialogQueue.length; i++) {
      if (dialogSignature(dialogQueue[i].opts) === sig) {
        if (typeof resolve === 'function') {
          resolve(opts.mode === 'confirm' || opts.mode === 'save-discard' ? false : true);
        }
        return;
      }
    }
    dialogQueue.push({ opts: opts, resolve: resolve });
  }

  function drainDialogQueue() {
    while (dialogQueue.length > 1) {
      var sigs = {};
      var compact = [];
      dialogQueue.forEach(function (item) {
        var sig = dialogSignature(item.opts);
        if (sigs[sig]) {
          if (item.resolve) {
            var mode = item.opts.mode || 'alert';
            item.resolve(mode === 'confirm' || mode === 'save-discard' ? false : true);
          }
          return;
        }
        sigs[sig] = true;
        compact.push(item);
      });
      dialogQueue = compact;
    }
    if (!dialogQueue.length || dialogBusy || dialogClosing || !root || root.classList.contains('is-open')) {
      return;
    }
    var next = dialogQueue.shift();
    if (!next) {
      return;
    }
    open(next.opts).then(next.resolve);
  }

  function ensureDom() {
    cleanupDuplicateDialogRoots();
    if (root && document.body && document.body.contains(root)) {
      return;
    }

    var existing = document.getElementById('ui-dialog-root');
    if (existing) {
      root = existing;
      backdrop = root.querySelector('.ui-dialog-backdrop');
      card = root.querySelector('.ui-dialog-card');
      iconEl = root.querySelector('.ui-dialog-icon');
      titleEl = root.querySelector('.ui-dialog-title');
      messageEl = root.querySelector('.ui-dialog-message');
      actionsEl = root.querySelector('.ui-dialog-actions');
      return;
    }

    root = document.createElement('div');
    root.id = 'ui-dialog-root';
    root.className = 'ui-dialog-root';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.hidden = true;

    backdrop = document.createElement('div');
    backdrop.className = 'ui-dialog-backdrop';
    backdrop.addEventListener('click', function () {
      if (root.dataset.closable === '1') close(true);
    });

    card = document.createElement('div');
    card.className = 'ui-dialog-card';

    iconEl = document.createElement('div');
    iconEl.className = 'ui-dialog-icon';
    iconEl.setAttribute('aria-hidden', 'true');

    titleEl = document.createElement('h2');
    titleEl.className = 'ui-dialog-title';

    messageEl = document.createElement('div');
    messageEl.className = 'ui-dialog-message';

    actionsEl = document.createElement('div');
    actionsEl.className = 'ui-dialog-actions';

    card.appendChild(iconEl);
    card.appendChild(titleEl);
    card.appendChild(messageEl);
    card.appendChild(actionsEl);
    root.appendChild(backdrop);
    root.appendChild(card);
    document.body.appendChild(root);
  }

  function btn(label, className, value) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ui-dialog-btn ' + className;
    b.textContent = label;
    b.addEventListener('click', function (e) {
      if (!root || !root.classList.contains('is-open') || b.disabled || dialogClosing) return;
      if (e && typeof e.preventDefault === 'function') e.preventDefault();
      if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
      actionsEl.querySelectorAll('.ui-dialog-btn').forEach(function (el) {
        el.disabled = true;
      });
      close(value);
    });
    return b;
  }

  function open(opts) {
    ensureDom();

    if (dialogBusy || dialogClosing || root.classList.contains('is-open')) {
      return new Promise(function (resolve) {
        enqueueDialog(opts, resolve);
      });
    }

    lastOpenSignature = dialogSignature(opts);
    lastOpenAt = Date.now();
    dialogClosing = false;

    var type = opts.type || 'info';
    var mode = opts.mode || 'alert';
    var theme = resolveTheme(opts);
    var title = resolveTitle(type, theme, opts.title);
    var message = opts.message || '';
    var okText = opts.okText || (theme === 'oracle' ? 'موافق' : 'حسنًا');
    var cancelText = opts.cancelText || 'إلغاء';
    var danger = !!opts.danger;

    lastFocus = document.activeElement;
    root.classList.toggle('ui-dialog--oracle', theme === 'oracle');
    root.dataset.type = type;
    root.dataset.mode = mode;
    root.dataset.closable = '0';
    iconEl.textContent = ICONS[type] || ICONS.info;
    titleEl.textContent = title;
    var useHtml =
      !!opts.html ||
      (typeof message === 'string' && /<[a-z][^>]*>/i.test(message));
    if (useHtml) {
      messageEl.innerHTML = message;
      messageEl.classList.add('ui-dialog-message--rich');
    } else {
      messageEl.textContent = message;
      messageEl.classList.remove('ui-dialog-message--rich');
    }
    actionsEl.innerHTML = '';

    if (mode === 'save-discard') {
      var saveText = opts.saveText || 'نعم، احفظ';
      var discardText = opts.discardText || 'لا، بدون حفظ';
      var stayText = opts.cancelText || 'إلغاء';
      actionsEl.appendChild(btn(saveText, 'ui-dialog-btn-primary', 'save'));
      actionsEl.appendChild(btn(discardText, 'ui-dialog-btn-secondary', 'discard'));
      actionsEl.appendChild(btn(stayText, 'ui-dialog-btn-secondary', 'cancel'));
    } else if (mode === 'confirm') {
      /* في RTL: الزر الأول يظهر يميناً — «نعم» (موافق) على اليمين */
      actionsEl.appendChild(
        btn(okText, danger ? 'ui-dialog-btn-danger' : 'ui-dialog-btn-primary', true)
      );
      actionsEl.appendChild(btn(cancelText, 'ui-dialog-btn-secondary', false));
    } else {
      actionsEl.appendChild(btn(okText, 'ui-dialog-btn-primary', true));
    }

    dialogBusy = true;
    root.hidden = false;
    lockPageBehindDialog();
    requestAnimationFrame(function () {
      if (!root || dialogClosing) {
        return;
      }
      root.classList.add('is-open');
      var primary = actionsEl.querySelector('.ui-dialog-btn-primary, .ui-dialog-btn-danger');
      if (primary) primary.focus();
    });

    document.addEventListener('keydown', onKeydown);

    return new Promise(function (resolve) {
      resolver = resolve;
    });
  }

  function onKeydown(e) {
    if (!root || !root.classList.contains('is-open')) return;
    if (e.key !== 'Escape') return;
    e.preventDefault();
    if (root.dataset.mode === 'save-discard') {
      close('cancel');
      return;
    }
    close(root.dataset.mode === 'confirm' ? false : true);
  }

  function close(result) {
    if (!root || dialogClosing) return;
    dialogClosing = true;
    document.removeEventListener('keydown', onKeydown);
    root.classList.remove('is-open');
    var done = resolver;
    resolver = null;
    var refocus = lastFocus;
    lastFocus = null;

    setTimeout(function () {
      if (!root) {
        dialogClosing = false;
        dialogBusy = false;
        unlockPageBehindDialog();
        if (typeof done === 'function') {
          done(result);
        }
        return;
      }
      root.classList.remove('ui-dialog--oracle');
      root.hidden = true;
      dialogBusy = false;
      dialogClosing = false;
      unlockPageBehindDialog();
      if (typeof done === 'function') {
        done(result);
      }
      if (refocus && typeof refocus.focus === 'function') {
        try {
          refocus.focus();
        } catch (e) {}
      }
      setTimeout(drainDialogQueue, 320);
    }, 240);
  }

  function alertDialog(message, options) {
    options = options || {};
    var theme = resolveTheme(options);
    return open({
      mode: 'alert',
      type: options.type || 'info',
      title: options.title,
      message: String(message),
      okText: options.okText || (theme === 'oracle' ? 'موافق' : 'حسنًا'),
      theme: theme,
    });
  }

  function confirmDialog(message, options) {
    options = options || {};
    var theme = resolveTheme(options);
    return open({
      mode: 'confirm',
      type: options.type || 'confirm',
      title: options.title || (theme === 'oracle' ? ORA_TITLES.confirm : TITLES.confirm),
      message: String(message),
      html: !!options.html,
      okText: options.okText || 'نعم',
      cancelText: options.cancelText || 'إلغاء',
      danger: options.danger,
      theme: theme,
    });
  }

  /** حفظ / بدون حفظ / إلغاء — للخروج من شاشة فيها تغييرات غير محفوظة */
  function confirmSaveDiscardDialog(message, options) {
    options = options || {};
    return open({
      mode: 'save-discard',
      type: options.type || 'confirm',
      title: options.title || 'حفظ التغييرات',
      message: String(message),
      saveText: options.saveText || 'نعم، احفظ',
      discardText: options.discardText || 'لا، بدون حفظ',
      cancelText: options.cancelText || 'إلغاء',
      theme: options.theme || 'oracle',
    });
  }

  /**
   * مربع حوار يطلب من المستخدم إدخال نصّ، ويُرجع Promise بالقيمة المُدخلة أو null عند الإلغاء.
   */
  function promptDialog(message, options) {
    options = options || {};
    ensureDom();
    root.classList.remove('ui-dialog--oracle');

    var title = options.title || 'إدخال';
    var okText = options.okText || 'حسنًا';
    var cancelText = options.cancelText || 'إلغاء';
    var placeholder = options.placeholder || '';
    var defaultValue = options.value || '';
    var inputType = String(options.inputType || 'text').toLowerCase();
    var multiline = options.multiline === true || (options.multiline !== false && inputType !== 'password');
    var type = 'info';

    lastFocus = document.activeElement;
    root.dataset.type = type;
    root.dataset.mode = 'prompt';
    root.dataset.closable = '0';
    iconEl.textContent = ICONS.info;
    titleEl.textContent = title;
    messageEl.textContent = String(message || '');
    actionsEl.innerHTML = '';

    // إنشاء حقل الإدخال داخل الـ card قبل الأزرار.
    var oldInput = card.querySelector('.ui-dialog-input-field');
    if (oldInput) oldInput.parentNode.removeChild(oldInput);
    var input;
    if (multiline) {
      input = document.createElement('textarea');
      input.rows = 3;
    } else {
      input = document.createElement('input');
      input.type = inputType === 'password' ? 'password' : 'text';
      if (inputType === 'password') {
        input.autocomplete = 'current-password';
      }
    }
    input.className = 'ui-dialog-input-field';
    input.placeholder = placeholder;
    input.value = defaultValue;
    input.style.cssText = 'display:block;width:100%;box-sizing:border-box;padding:0.55rem 0.7rem;margin:0.6rem 0 0.3rem;border:1px solid #cbd5e1;border-radius:6px;font:inherit;font-size:0.95rem;color:#0f172a;background:#fff;resize:vertical;';
    card.insertBefore(input, actionsEl);

    /* في RTL: الزر الأول يظهر يميناً */
    actionsEl.appendChild(btn(okText, 'ui-dialog-btn-primary', '__OK__'));
    actionsEl.appendChild(btn(cancelText, 'ui-dialog-btn-secondary', null));

    dialogBusy = true;
    root.hidden = false;
    requestAnimationFrame(function () {
      root.classList.add('is-open');
      try { input.focus(); } catch (_e) {}
    });

    document.addEventListener('keydown', onKeydown);

    return new Promise(function (resolve) {
      resolver = function (v) {
        if (v === '__OK__') {
          resolve(input.value);
        } else {
          resolve(null);
        }
      };
    });
  }

  var AppDialog = {
    alert: alertDialog,
    confirm: confirmDialog,
    confirmSaveDiscard: confirmSaveDiscardDialog,
    prompt: promptDialog,
    formatActionMessage: formatActionMessage,
    success: function (message, options) {
      options = options || {};
      options.type = 'success';
      return alertDialog(message, options);
    },
    error: function (message, options) {
      options = options || {};
      options.type = 'error';
      return alertDialog(message, options);
    },
    open: open,
  };

  global.AppDialog = AppDialog;
  AppDialog.__singleton = 2;
  global.alert = function (message) {
    alertDialog(message, { type: 'info' });
  };

  function isFlashAlert(el) {
    if (!el || !el.classList) return false;
    if (el.classList.contains('hr-ora-inline-msg')) return false;
    if (el.classList.contains('hr-pr-post-gate')) return false;
    var cls = el.classList;
    for (var i = 0; i < cls.length; i++) {
      if (cls[i].indexOf('-flash') !== -1) return true;
    }
    return !isOracleScreen();
  }

  function showPageFlashAlerts() {
    var selectors = [
      '.main-content .alert.alert-success',
      '.main-content .alert.alert-error',
      '.login-card .alert.alert-success',
      '.login-card .alert.alert-error',
      '.sales-inv-wrap > .alert.alert-success',
      '.sales-inv-wrap > .alert.alert-error',
    ];
    var alerts = document.querySelectorAll(selectors.join(', '));
    if (!alerts.length) return;

    var el = null;
    for (var j = 0; j < alerts.length; j++) {
      if (isFlashAlert(alerts[j])) {
        el = alerts[j];
        break;
      }
    }
    if (!el || el.classList.contains('ui-dialog-consumed')) return;

    var type = 'info';
    if (el.classList.contains('alert-success')) {
      type = 'success';
    } else if (el.classList.contains('alert-error')) {
      type = 'error';
    } else if (el.classList.contains('alert-warning')) {
      type = 'warning';
    }
    var msg = (el.textContent || '').trim();
    if (!msg) return;

    el.classList.add('ui-dialog-consumed');
    var theme = isOracleScreen() ? 'oracle' : '';
    alertDialog(msg, {
      type: type,
      title: resolveTitle(type, theme),
      theme: theme,
    });
  }

  function bindFormConfirms() {
    document.addEventListener(
      'submit',
      function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        var msg = form.getAttribute('data-confirm');
        if (!msg) return;

        if (form.dataset.uiConfirmOk === '1') {
          delete form.dataset.uiConfirmOk;
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        confirmDialog(msg, {
          type: 'confirm',
          title: isOracleScreen() ? ORA_TITLES.confirm : TITLES.confirm,
          theme: isOracleScreen() ? 'oracle' : '',
        }).then(function (ok) {
          if (!ok) return;
          form.dataset.uiConfirmOk = '1';
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        });
      },
      true
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bindFormConfirms();
      showPageFlashAlerts();
    });
  } else {
    bindFormConfirms();
    showPageFlashAlerts();
  }
})(typeof window !== 'undefined' ? window : globalThis);
