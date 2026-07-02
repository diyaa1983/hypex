(function (global) {
  'use strict';

  var ROUTE = 'hr_employee_attendance';

  function pageRoot() {
    return document.querySelector('.hr-att-wrap');
  }

  function batchForm() {
    return document.getElementById('hr-att-map-batch-form');
  }

  function linkRowsBody() {
    return document.getElementById('hr-att-link-rows');
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

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) {
      return [];
    }
    try {
      return JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
  }

  var employees = [];
  var zkUsers = [];

  function normalizeCode(value) {
    var v = String(value || '').trim();
    if (/^\d+$/.test(v)) {
      var trimmed = v.replace(/^0+/, '');
      return trimmed !== '' ? trimmed : '0';
    }
    return v;
  }

  function buildSelect(className, placeholder) {
    var sel = document.createElement('select');
    sel.className = 'input ' + className;
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = placeholder;
    sel.appendChild(opt0);
    return sel;
  }

  function fillEmployeeSelect(sel, selectedId) {
    while (sel.options.length > 1) {
      sel.remove(1);
    }
    employees.forEach(function (emp) {
      var opt = document.createElement('option');
      opt.value = String(emp.id);
      opt.textContent = emp.label;
      opt.dataset.code = emp.code || '';
      if (selectedId && String(emp.id) === String(selectedId)) {
        opt.selected = true;
      }
      sel.appendChild(opt);
    });
  }

  function fillZkSelect(sel, selectedZk) {
    while (sel.options.length > 1) {
      sel.remove(1);
    }
    zkUsers.forEach(function (zk) {
      var opt = document.createElement('option');
      opt.value = String(zk.zk_user_id);
      opt.textContent = zk.label;
      opt.dataset.badge = zk.badge || '';
      if (selectedZk && String(zk.zk_user_id) === String(selectedZk)) {
        opt.selected = true;
      }
      sel.appendChild(opt);
    });
  }

  function findEmployeeByCode(code) {
    var target = normalizeCode(code);
    if (!target) {
      return null;
    }
    for (var i = 0; i < employees.length; i += 1) {
      if (normalizeCode(employees[i].code) === target) {
        return employees[i];
      }
    }
    return null;
  }

  function updateRowMatchState(row) {
    if (!row) {
      return;
    }
    var empSel = row.querySelector('.hr-att-sel-employee');
    var zkSel = row.querySelector('.hr-att-sel-zk');
    if (!empSel || !zkSel) {
      return;
    }
    var empOpt = empSel.options[empSel.selectedIndex];
    var zkOpt = zkSel.options[zkSel.selectedIndex];
    var empCode = empOpt ? empOpt.dataset.code || '' : '';
    var badge = zkOpt ? zkOpt.dataset.badge || '' : '';
    var hasBoth = empSel.value !== '' && zkSel.value !== '';
    var isMatch = hasBoth && normalizeCode(empCode) === normalizeCode(badge);
    row.classList.toggle('is-match', isMatch);
    row.classList.toggle('is-mismatch', hasBoth && !isMatch);
  }

  function onZkChange(row) {
    var zkSel = row.querySelector('.hr-att-sel-zk');
    var empSel = row.querySelector('.hr-att-sel-employee');
    if (!zkSel || !empSel || empSel.value !== '') {
      updateRowMatchState(row);
      markDirty();
      return;
    }
    var zkOpt = zkSel.options[zkSel.selectedIndex];
    var badge = zkOpt ? zkOpt.dataset.badge || '' : '';
    var suggested = findEmployeeByCode(badge);
    if (suggested) {
      empSel.value = String(suggested.id);
    }
    updateRowMatchState(row);
    markDirty();
  }

  function addLinkRow(employeeId, zkUserId) {
    var tbody = linkRowsBody();
    if (!tbody) {
      return;
    }
    var tr = document.createElement('tr');
    tr.className = 'hr-att-link-row';

    var tdEmp = document.createElement('td');
    var empSel = buildSelect('hr-att-sel-employee', '— اختر موظفاً —');
    fillEmployeeSelect(empSel, employeeId || 0);
    empSel.addEventListener('change', function () {
      updateRowMatchState(tr);
      markDirty();
    });
    tdEmp.appendChild(empSel);

    var tdZk = document.createElement('td');
    var zkSel = buildSelect('hr-att-sel-zk', '— اختر رقم بصمة —');
    fillZkSelect(zkSel, zkUserId || 0);
    zkSel.addEventListener('change', function () {
      onZkChange(tr);
    });
    tdZk.appendChild(zkSel);

    var tdAct = document.createElement('td');
    tdAct.className = 'hr-att-col-actions';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-ghost btn-xs hr-att-remove-link-row';
    removeBtn.textContent = 'حذف';
    removeBtn.addEventListener('click', function () {
      tr.remove();
      if (tbody.querySelectorAll('.hr-att-link-row').length < 1) {
        addLinkRow();
      }
      markDirty();
    });
    tdAct.appendChild(removeBtn);

    tr.appendChild(tdEmp);
    tr.appendChild(tdZk);
    tr.appendChild(tdAct);
    tbody.appendChild(tr);
    updateRowMatchState(tr);
  }

  function linkRows() {
    var tbody = linkRowsBody();
    if (!tbody) {
      return [];
    }
    return Array.prototype.slice.call(tbody.querySelectorAll('.hr-att-link-row'));
  }

  function hasPendingMaps() {
    return linkRows().some(function (row) {
      var empSel = row.querySelector('.hr-att-sel-employee');
      var zkSel = row.querySelector('.hr-att-sel-zk');
      return empSel && zkSel && empSel.value !== '' && zkSel.value !== '';
    });
  }

  function collectMaps() {
    var items = [];
    var usedEmp = {};
    var usedZk = {};
    linkRows().forEach(function (row) {
      var empSel = row.querySelector('.hr-att-sel-employee');
      var zkSel = row.querySelector('.hr-att-sel-zk');
      if (!empSel || !zkSel) {
        return;
      }
      var employeeId = parseInt(empSel.value, 10) || 0;
      var zkUserId = parseInt(zkSel.value, 10) || 0;
      if (employeeId < 1 || zkUserId < 1) {
        return;
      }
      if (usedEmp[employeeId] || usedZk[zkUserId]) {
        return;
      }
      usedEmp[employeeId] = true;
      usedZk[zkUserId] = true;
      items.push({ zk_user_id: zkUserId, employee_id: employeeId });
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
    btn.title = enabled ? 'حفظ ربط الموظفين بأرقام البصمة' : 'اختر موظفاً ورقم بصمة واحداً على الأقل';
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
      showError('اختر موظفاً ورقم بصمة واحداً على الأقل للربط.');
      return;
    }

    var url = apiUrl();
    if (!url) {
      var form = batchForm();
      if (form) {
        Array.prototype.slice.call(form.querySelectorAll('input[name^="maps["]')).forEach(function (inp) {
          inp.remove();
        });
        items.forEach(function (item) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'maps[' + item.zk_user_id + ']';
          hidden.value = String(item.employee_id);
          form.appendChild(hidden);
        });
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
        return collectMaps();
      },
      onSave: saveAllMaps,
    });
  }

  function initLinkPanel() {
    employees = readJson('hr-att-link-employees-json');
    zkUsers = readJson('hr-att-link-zk-json');
    if (!linkRowsBody() || employees.length === 0 || zkUsers.length === 0) {
      return;
    }
    addLinkRow();
    var addBtn = document.getElementById('hr-att-add-link-row');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        addLinkRow();
        markDirty();
      });
    }
  }

  function install() {
    if (!pageRoot()) {
      return;
    }
    initLinkPanel();
    if (!batchForm()) {
      var btn = saveBtn();
      if (btn) {
        btn.disabled = true;
        btn.classList.add('is-inactive');
      }
      return;
    }
    bindToolbar();
    bindKeyboard();
    bindExitGuard();
    updateToolbarSave();
    global.setTimeout(updateToolbarSave, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})(typeof window !== 'undefined' ? window : self);
