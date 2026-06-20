(function () {
  'use strict';

  var screen = document.getElementById('fin-checks-screen');
  if (!screen) return;

  var apiUrl = screen.getAttribute('data-api-url') || '';
  var csrf = screen.getAttribute('data-csrf') || '';
  var modal = document.getElementById('fin-check-modal');
  var modalTitle = document.getElementById('fin-check-modal-title');
  var modalForm = document.getElementById('fin-check-modal-form');
  var modalAction = document.getElementById('fin-check-modal-action');
  var modalCheckId = document.getElementById('fin-check-modal-check-id');
  var modalActionDate = document.getElementById('fin-check-action-date');
  var modalAccountWrap = document.getElementById('fin-check-account-wrap');
  var modalAccount = document.getElementById('fin-check-account-id');
  var modalReasonWrap = document.getElementById('fin-check-reason-wrap');
  var modalReason = document.getElementById('fin-check-return-reason');
  var modalErr = document.getElementById('fin-check-modal-error');
  var modalSubmit = document.getElementById('fin-check-modal-submit');
  var modalCancel = document.getElementById('fin-check-modal-cancel');

  var sumDirection = document.getElementById('fin-check-sum-direction');
  var sumNo = document.getElementById('fin-check-sum-no');
  var sumAmount = document.getElementById('fin-check-sum-amount');
  var sumBank = document.getElementById('fin-check-sum-bank');
  var sumParty = document.getElementById('fin-check-sum-party');
  var sumVoucher = document.getElementById('fin-check-sum-voucher');
  var sumVdate = document.getElementById('fin-check-sum-vdate');
  var sumDue = document.getElementById('fin-check-sum-due');

  function todayIso() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function dialogConfirm(msg, title) {
    if (window.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(msg, { title: title || 'تأكيد' });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function dialogSuccess(msg) {
    if (window.AppDialog && AppDialog.success) {
      AppDialog.success(msg);
      return;
    }
    window.alert(msg);
  }

  function dialogError(msg) {
    if (window.AppDialog && AppDialog.error) {
      AppDialog.error(msg);
      return;
    }
    window.alert(msg);
  }

  function setSummary(btn) {
    if (!btn) return;
    var val = function (key) {
      return btn.getAttribute(key) || '—';
    };
    if (sumDirection) sumDirection.textContent = val('data-direction');
    if (sumNo) sumNo.innerHTML = '<code>' + (val('data-check-no') || '—') + '</code>';
    if (sumAmount) sumAmount.textContent = val('data-check-amount');
    if (sumBank) sumBank.textContent = val('data-bank-name') || '—';
    if (sumParty) sumParty.textContent = val('data-party-name');
    if (sumVoucher) sumVoucher.innerHTML = '<code>' + val('data-voucher-no') + '</code>';
    if (sumVdate) sumVdate.textContent = val('data-voucher-date');
    if (sumDue) sumDue.textContent = val('data-due-date');
  }

  function openModal(action, btn) {
    if (!modal || !btn) return;
    var checkId = btn.getAttribute('data-check-id');
    var label = btn.getAttribute('data-check-label') || '';
    if (!checkId) return;

    modalAction.value = action;
    modalCheckId.value = String(checkId);
    setSummary(btn);
    modalActionDate.value = todayIso();
    modalErr.textContent = '';
    modalErr.style.display = 'none';

    if (action === 'clear') {
      modalTitle.textContent = 'ترحيل صرف / تحصيل الشيك';
      modalAccountWrap.style.display = '';
      modalReasonWrap.style.display = 'none';
      modalReason.value = '';
      if (modalAccount) modalAccount.required = true;
      modalReason.required = false;
      if (modalSubmit) modalSubmit.textContent = 'ترحيل — صرف';
    } else {
      modalTitle.textContent = 'ترحيل إرجاع الشيك';
      modalAccountWrap.style.display = 'none';
      modalReasonWrap.style.display = '';
      if (modalAccount) modalAccount.required = false;
      modalReason.required = true;
      if (modalSubmit) modalSubmit.textContent = 'ترحيل — إرجاع';
    }

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.dataset.checkLabel = label;
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  screen.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-check-action]');
    if (!btn || btn.disabled) return;
    var action = btn.getAttribute('data-check-action');
    if (!action || !btn.getAttribute('data-check-id')) return;

    var intro = action === 'clear'
      ? 'فتح شاشة ترحيل صرف/تحصيل هذا الشيك؟'
      : 'فتح شاشة ترحيل إرجاع هذا الشيك؟';
    dialogConfirm(intro, 'ترحيل الشيك').then(function (ok) {
      if (ok) openModal(action, btn);
    });
  });

  if (modalCancel) {
    modalCancel.addEventListener('click', closeModal);
  }

  if (modal) {
    modal.querySelectorAll('[data-fin-check-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
  }

  var checkNoInput = document.getElementById('fin-checks-filter-no');
  var checksTable = document.getElementById('fin-checks-table');
  var checksCountEl = document.getElementById('fin-checks-count');
  var checksTotalEl = document.getElementById('fin-checks-total');

  function formatMoney(n) {
    var num = Number(n);
    if (!isFinite(num)) {
      return '0.00';
    }
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function applyCheckNoFilter() {
    if (!checksTable || !checkNoInput) {
      return;
    }
    var q = (checkNoInput.value || '').trim().toLowerCase();
    var rows = checksTable.querySelectorAll('tbody tr[data-check-id]');
    var count = 0;
    var sum = 0;

    rows.forEach(function (tr) {
      var no = (tr.getAttribute('data-check-no') || '').toLowerCase();
      var match = q === '' || no.indexOf(q) !== -1;
      tr.style.display = match ? '' : 'none';
      if (match) {
        count += 1;
        sum += parseFloat(tr.getAttribute('data-check-amount') || '0') || 0;
      }
    });

    if (checksCountEl) {
      checksCountEl.textContent = String(count);
    }
    if (checksTotalEl) {
      checksTotalEl.textContent = formatMoney(sum);
    }
  }

  if (checkNoInput) {
    checkNoInput.addEventListener('input', applyCheckNoFilter);
    if ((checkNoInput.value || '').trim() !== '') {
      applyCheckNoFilter();
    }
  }

  if (modalForm) {
    modalForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!apiUrl) return;

      var action = modalAction.value;
      var label = modal.dataset.checkLabel || '';
      var confirmMsg = action === 'clear'
        ? 'تأكيد ترحيل صرف/تحصيل الشيك؟\n' + label
        : 'تأكيد ترحيل إرجاع الشيك؟\n' + label;

      dialogConfirm(confirmMsg, 'ترحيل الشيك').then(function (ok) {
        if (!ok) return;

        modalSubmit.disabled = true;
        modalErr.style.display = 'none';

        var fd = new FormData(modalForm);
        fd.append('_csrf', csrf);

        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.ok) {
              closeModal();
              var msg = (data && data.message) ? data.message : 'تم ترحيل الشيك.';
              dialogSuccess(msg);
              window.setTimeout(function () { window.location.reload(); }, 400);
              return;
            }
            modalErr.textContent = (data && data.message) ? data.message : 'تعذر ترحيل الشيك.';
            modalErr.style.display = '';
          })
          .catch(function () {
            dialogError('خطأ في الاتصال بالخادم.');
          })
          .finally(function () {
            modalSubmit.disabled = false;
          });
      });
    });
  }
})();
