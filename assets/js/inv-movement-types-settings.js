(function () {
  'use strict';

  var form = document.getElementById('inv-movement-types-form');
  if (!form) return;

  function postModeCheckboxes(tr) {
    if (!tr) return { auto: null, manual: null };
    return {
      auto: tr.querySelector('.inv-movement-types-post-auto'),
      manual: tr.querySelector('.inv-movement-types-post-manual'),
    };
  }

  function isPostModeCheckbox(el) {
    return (
      el &&
      el.type === 'checkbox' &&
      (el.classList.contains('inv-movement-types-post-auto') ||
        el.classList.contains('inv-movement-types-post-manual'))
    );
  }

  /** عند تفعيل أحدهما يُلغى الآخر (الحقول خارج form= ولا تصل change إلى النموذج). */
  function syncPostModeExclusive(changed) {
    var tr = changed.closest('tr');
    var boxes = postModeCheckboxes(tr);
    if (!boxes.auto || !boxes.manual) return;
    if (changed === boxes.auto && boxes.auto.checked) {
      boxes.manual.checked = false;
    } else if (changed === boxes.manual && boxes.manual.checked) {
      boxes.auto.checked = false;
    }
  }

  function normalizeAllPostModeRows() {
    document.querySelectorAll('.inv-movement-types-table tbody tr').forEach(function (tr) {
      var boxes = postModeCheckboxes(tr);
      if (boxes.auto && boxes.manual && boxes.auto.checked && boxes.manual.checked) {
        boxes.manual.checked = false;
      }
    });
  }

  normalizeAllPostModeRows();

  document.addEventListener('change', function (e) {
    if (isPostModeCheckbox(e.target)) {
      syncPostModeExclusive(e.target);
    }
  });

  document.addEventListener('click', function (e) {
    if (isPostModeCheckbox(e.target)) {
      window.setTimeout(function () {
        syncPostModeExclusive(e.target);
      }, 0);
    }
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'save') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
