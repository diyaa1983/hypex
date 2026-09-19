(function () {
  'use strict';

  var root = document.getElementById('coa-tree-root');
  if (!root) return;

  var detail = document.getElementById('coa-detail');
  var actions = document.getElementById('coa-actions');
  var btnAdd = document.getElementById('coa-btn-add-child');
  var btnEdit = document.getElementById('coa-btn-edit');
  var delId = document.getElementById('coa-del-id');
  var BASE = '/accounting/chart';

  function selectRow(row) {
    root.querySelectorAll('.coa-tree-row.is-selected').forEach(function (el) {
      el.classList.remove('is-selected');
    });
    if (!row) {
      if (detail) {
        detail.innerHTML =
          '<p class="coa-detail-placeholder">اختر حساباً من الشجرة لعرض التفاصيل والإجراءات.</p>';
      }
      if (actions) actions.hidden = true;
      return;
    }
    row.classList.add('is-selected');
    var id = row.getAttribute('data-id');
    var code = row.getAttribute('data-code') || '';
    var name = row.getAttribute('data-name') || '';
    var typeLabel = row.getAttribute('data-type-label') || '';
    var leaf = row.getAttribute('data-leaf') === '1';
    var active = row.getAttribute('data-active') === '1';
    if (detail) {
      detail.innerHTML =
        '<dl>' +
        '<dt>الرقم</dt><dd dir="ltr">' +
        escapeHtml(code) +
        '</dd>' +
        '<dt>الاسم</dt><dd>' +
        escapeHtml(name) +
        '</dd>' +
        '<dt>النوع</dt><dd>' +
        escapeHtml(typeLabel) +
        '</dd>' +
        '<dt>المستوى</dt><dd>' +
        (leaf ? 'حساب نهائي (ورقة)' : 'حساب أب') +
        '</dd>' +
        '<dt>الحالة</dt><dd>' +
        (active ? 'نشط' : 'موقوف') +
        '</dd>' +
        '</dl>';
    }
    if (actions) {
      actions.hidden = false;
      if (btnAdd) btnAdd.href = BASE + '/add?parent_id=' + encodeURIComponent(id);
      if (btnEdit) btnEdit.href = BASE + '/edit?id=' + encodeURIComponent(id);
      if (delId) delId.value = id;
    }
    var li = row.closest('.coa-tree-li');
    while (li) {
      li.classList.add('is-open');
      li =
        li.parentElement && li.parentElement.closest
          ? li.parentElement.closest('.coa-tree-li')
          : null;
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  root.addEventListener('click', function (e) {
    var tog = e.target.closest('[data-toggle]');
    if (tog) {
      e.preventDefault();
      e.stopPropagation();
      var li = tog.closest('.coa-tree-li');
      if (li) li.classList.toggle('is-open');
      return;
    }
    var row = e.target.closest('.coa-tree-row');
    if (row) selectRow(row);
  });

  root.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = e.target.closest('.coa-tree-row');
    if (row) {
      e.preventDefault();
      selectRow(row);
    }
  });

  root.querySelectorAll(':scope > .coa-tree-ul > .coa-tree-li').forEach(function (li, i) {
    if (i < 6) li.classList.add('is-open');
  });

  if (root.querySelector('.is-match')) {
    root.querySelectorAll('.coa-tree-li').forEach(function (li) {
      if (li.querySelector('.is-match') || li.classList.contains('is-match')) {
        li.classList.add('is-open');
      }
    });
  }
})();
