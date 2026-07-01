(function (global) {
  'use strict';

  var ROUTE = 'hr_employee_attendance';

  function pageRoot() {
    return document.querySelector('.hr-att-wrap');
  }

  function batchForm() {
    return document.getElementById('hr-att-map-batch-form');
  }

  function saveBtn() {
    return document.querySelector('#master-toolbar [data-master-action="save"]');
  }

  function apiUrl() {
    var root = pageRoot();
    return root ? root.getAttribute('data-map-api') || '' : '';
  }

  function csrfToken() {
    var form = batchForm();
    var inp = form ? form.querySelector('input[name="_csrf"]') : null;
    return inp ? inp.value : '';
  }

  function mapInputs() {
    var form = batchForm();
    if (!form) {
      return [];
    }
    return Array.prototype.slice.call(form.querySelectorAll('input[name^="maps["]'));
  }

  function hasPendingMaps() {
    return mapInputs().some(function (inp) {
      return (parseInt(inp.value, 10) || 0) > 0;
    });
  }

  function collectMaps() {
    var items = [];
    mapInputs().forEach(function (inp) {
      var employeeId = parseInt(inp.value, 10) || 0;
      if (employeeId < 1) {
        return;
      }
      var match = inp.name.match(/^maps\[(\d+)\]$/);
      if (!match) {
        return;
      }
      items.push({
        zk_user_id: parseInt(match[1], 10),
        employee_id: employeeId,
      });
    });
    return items;
  }

  function updateToolbarSave() {
    var btn = saveBtn();
    if (!btn) {
      return;
    }
    var enabled = hasPendingMaps();
    btn.disabled = !enabled;
    btn.classList.toggle('is-inactive', !enabled);
    btn.title = enabled ? 'حفظ ربط الموظفين المختارين' : 'اختر موظفاً واحداً على الأقل للربط';
  }

  function showError(msg) {
    if (global.AppDialog && global.AppDialog.alert) {
      global.AppDialog.alert(msg, { title: 'تنبيه', theme: 'oracle' });
      return;
    }
    global.alert(msg);
  }

  function showSuccess(msg, onOk) {
    if (global.AppDialog && global.AppDialog.alert) {
      global.AppDialog.alert(msg, { title: 'تم', theme: 'oracle' }).then(onOk);
      return;
    }
    global.alert(msg);
    if (onOk) {
      onOk();
    }
  }

  function markDirty() {
    var root = pageRoot();
    if (root) {
      root.dataset.screenExitDirty = hasPendingMaps() ? '1' : '';
    }
    updateToolbarSave();
  }

  function saveAllMaps() {
    var items = collectMaps();
    if (!items.length) {
      showError('اختر موظفاً واحداً على الأقل للربط.');
      return;
    }

    var url = apiUrl();
    if (!url) {
      var form = batchForm();
      if (form) {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
      return;
    }

    var btn = saveBtn();
    if (btn) {
      btn.disabled = true;
    }
    if (global.AppBusy && global.AppBusy.show) {
      global.AppBusy.show('جاري حفظ الربط...');
    }

    var body = new FormData();
    body.append('_csrf', csrfToken());
    items.forEach(function (item) {
      body.append('maps[' + item.zk_user_id + ']', String(item.employee_id));
    });

    fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        return res.json().catch(function () {
          return { ok: false, error: 'استجابة غير متوقعة من الخادم.' };
        }).then(function (data) {
          if (!res.ok && data && !data.error) {
            data.error = 'تعذر حفظ الربط.';
          }
          return data;
        });
      })
      .then(function (data) {
        var saved = data && typeof data.saved === 'number' ? data.saved : 0;
        if (data && data.ok && saved > 0) {
          var root = pageRoot();
          if (root) {
            delete root.dataset.screenExitDirty;
          }
          if (global.ScreenExitGuard && typeof global.ScreenExitGuard.syncFor === 'function') {
            global.ScreenExitGuard.syncFor(root);
          }
          showSuccess(data.message || ('تم حفظ ' + saved + ' ربط.'), function () {
            global.location.reload();
          });
          return;
        }
        var err = (data && data.error) || 'تعذر حفظ الربط.';
        if (data && data.errors && data.errors.length) {
          err = data.errors.join('\n');
        }
        showError(err);
      })
      .catch(function () {
        showError('تعذر الاتصال بالخادم.');
      })
      .finally(function () {
        updateToolbarSave();
        if (global.AppBusy && global.AppBusy.hide) {
          global.AppBusy.hide();
        }
      });
  }

  function onMapFieldChange() {
    markDirty();
  }

  function bindMapFields() {
    var form = batchForm();
    if (!form || form.dataset.hrAttMapBound === '1') {
      return;
    }
    form.dataset.hrAttMapBound = '1';
    form.addEventListener('change', function (e) {
      var target = e.target;
      if (target && target.name && target.name.indexOf('maps[') === 0) {
        onMapFieldChange();
      }
    });
    form.addEventListener('input', function (e) {
      var target = e.target;
      if (target && target.name && target.name.indexOf('maps[') === 0) {
        onMapFieldChange();
      }
    });
  }

  function bindToolbar() {
    document.addEventListener(
      'master-toolbar',
      function (e) {
        if (!e.detail) {
          return;
        }
        var bar = document.getElementById('master-toolbar');
        if ((bar ? bar.getAttribute('data-active-route') : '') !== ROUTE) {
          return;
        }
        if (e.detail.action !== 'save') {
          return;
        }
        if (!batchForm()) {
          return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        saveAllMaps();
      },
      true
    );
  }

  function bindKeyboard() {
    document.addEventListener('keydown', function (e) {
      if (!batchForm()) {
        return;
      }
      if (!(e.ctrlKey || e.metaKey) || e.key !== 's') {
        return;
      }
      if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
        return;
      }
      e.preventDefault();
      saveAllMaps();
    });
  }

  function bindExitGuard() {
    var root = pageRoot();
    var form = batchForm();
    if (!root || !form || !global.ScreenExitGuard || typeof global.ScreenExitGuard.bind !== 'function') {
      return;
    }
    global.ScreenExitGuard.bind({
      page: root,
      route: ROUTE,
      exitUrl: root.getAttribute('data-exit-url') || '',
      isActive: function () {
        return hasPendingMaps();
      },
      getSnapshot: function () {
        var snap = {};
        mapInputs().forEach(function (inp) {
          snap[inp.name] = inp.value || '';
        });
        return snap;
      },
      onSave: saveAllMaps,
    });
  }

  function install() {
    if (!pageRoot()) {
      return;
    }
    if (!batchForm()) {
      var btn = saveBtn();
      if (btn) {
        btn.disabled = true;
        btn.classList.add('is-inactive');
      }
      return;
    }
    bindMapFields();
    bindToolbar();
    bindKeyboard();
    bindExitGuard();
    updateToolbarSave();

    global.setTimeout(function () {
      updateToolbarSave();
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})(typeof window !== 'undefined' ? window : self);
