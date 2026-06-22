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
  var modalEndorseWrap = document.getElementById('fin-check-endorse-wrap');
  var modalPartyType = document.getElementById('fin-check-party-type');
  var modalEndorseNotes = document.getElementById('fin-check-endorse-notes');
  var modalErr = document.getElementById('fin-check-modal-error');
  var modalSubmit = document.getElementById('fin-check-modal-submit');
  var modalCancel = document.getElementById('fin-check-modal-cancel');

  var endorseSupplierHidden = document.getElementById('fin-check-endorse-supplier-id');
  var endorseSupplierOpen = document.getElementById('fin-check-endorse-supplier-id_open');
  var endorseSupplierDisplay = document.getElementById('fin-check-endorse-supplier-id_display');

  var sumDirection = document.getElementById('fin-check-sum-direction');
  var sumNo = document.getElementById('fin-check-sum-no');
  var sumAmount = document.getElementById('fin-check-sum-amount');
  var sumBank = document.getElementById('fin-check-sum-bank');
  var sumParty = document.getElementById('fin-check-sum-party');
  var sumVoucher = document.getElementById('fin-check-sum-voucher');
  var sumVdate = document.getElementById('fin-check-sum-vdate');
  var sumDue = document.getElementById('fin-check-sum-due');

  var endorsePickersBound = false;

  function todayDmY() {
    if (window.AppDatePicker && AppDatePicker.formatIsoToDmY) {
      var d = new Date();
      var iso =
        d.getFullYear() +
        '-' +
        String(d.getMonth() + 1).padStart(2, '0') +
        '-' +
        String(d.getDate()).padStart(2, '0');
      return AppDatePicker.formatIsoToDmY(iso);
    }
    var now = new Date();
    return (
      String(now.getDate()).padStart(2, '0') +
      '-' +
      String(now.getMonth() + 1).padStart(2, '0') +
      '-' +
      now.getFullYear()
    );
  }

  function setActionDateToday() {
    if (!modalActionDate) {
      return;
    }
    modalActionDate.value = todayDmY();
    modalActionDate.dispatchEvent(new Event('blur', { bubbles: true }));
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

  function resetEndorsePickers() {
    if (endorseSupplierHidden) endorseSupplierHidden.value = '';
    if (endorseSupplierDisplay) {
      endorseSupplierDisplay.textContent = 'اختر المورد';
      endorseSupplierDisplay.classList.add('is-placeholder');
    }
    if (modalPartyType) modalPartyType.value = 'supplier';
    if (modalEndorseNotes) modalEndorseNotes.value = '';
  }

  function bindEndorsePickers() {
    if (endorsePickersBound) return;
    if (window.SupplierPickerModal && endorseSupplierHidden && endorseSupplierOpen && endorseSupplierDisplay) {
      SupplierPickerModal.bind({
        hidden: endorseSupplierHidden,
        open: endorseSupplierOpen,
        display: endorseSupplierDisplay,
        jsonId: 'fin-checks-suppliers-json',
        placeholder: 'اختر المورد',
        allowClear: true,
        initialId: 0,
      });
    }
    endorsePickersBound = true;
  }

  function getEndorsePartyId() {
    return parseInt(endorseSupplierHidden && endorseSupplierHidden.value ? endorseSupplierHidden.value : '0', 10) || 0;
  }

  function openModal(action, btn) {
    if (!modal || !btn) return;
    var checkId = btn.getAttribute('data-check-id');
    var label = btn.getAttribute('data-check-label') || '';
    if (!checkId) return;

    modalAction.value = action;
    modalCheckId.value = String(checkId);
    setSummary(btn);
    setActionDateToday();
    modalErr.textContent = '';
    modalErr.style.display = 'none';

    if (action === 'clear') {
      modalTitle.textContent = 'ترحيل صرف / تحصيل الشيك';
      modalAccountWrap.style.display = '';
      modalReasonWrap.style.display = 'none';
      modalEndorseWrap.style.display = 'none';
      modalReason.value = '';
      if (modalAccount) modalAccount.required = true;
      modalReason.required = false;
      if (modalSubmit) modalSubmit.textContent = 'ترحيل — صرف';
    } else if (action === 'return') {
      modalTitle.textContent = 'ترحيل إرجاع الشيك';
      modalAccountWrap.style.display = 'none';
      modalReasonWrap.style.display = '';
      modalEndorseWrap.style.display = 'none';
      if (modalAccount) modalAccount.required = false;
      modalReason.required = true;
      if (modalSubmit) modalSubmit.textContent = 'ترحيل — إرجاع';
    } else {
      modalTitle.textContent = 'ترحيل تجيير الشيك';
      modalAccountWrap.style.display = 'none';
      modalReasonWrap.style.display = 'none';
      modalEndorseWrap.style.display = '';
      if (modalAccount) modalAccount.required = false;
      modalReason.required = false;
      resetEndorsePickers();
      bindEndorsePickers();
      if (modalSubmit) modalSubmit.textContent = 'ترحيل — تجيير';
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

  if (modalPartyType) {
    modalPartyType.value = 'supplier';
  }

  screen.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-check-action]');
    if (!btn || btn.disabled) return;
    var action = btn.getAttribute('data-check-action');
    if (!action || !btn.getAttribute('data-check-id')) return;

    if (action === 'undo') {
      return;
    }

    var intro =
      action === 'clear'
        ? 'فتح شاشة ترحيل صرف/تحصيل هذا الشيك؟'
        : action === 'return'
          ? 'فتح شاشة ترحيل إرجاع هذا الشيك؟'
          : 'فتح شاشة تجيير هذا الشيك إلى مورد؟';
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
      var confirmMsg =
        action === 'clear'
          ? 'تأكيد ترحيل صرف/تحصيل الشيك؟\n' + label
          : action === 'return'
            ? 'تأكيد ترحيل إرجاع الشيك؟\n' + label
            : 'تأكيد تجيير الشيك مع قيد محاسبي؟\n' + label;

      if (action === 'endorse') {
        var partyId = getEndorsePartyId();
        if (partyId < 1) {
          modalErr.textContent = 'اختر المورد المُجيَّر إليه.';
          modalErr.style.display = '';
          return;
        }
      }

      dialogConfirm(confirmMsg, 'ترحيل الشيك').then(function (ok) {
        if (!ok) return;

        modalSubmit.disabled = true;
        modalErr.style.display = 'none';

        var fd = new FormData(modalForm);
        fd.append('_csrf', csrf);
        if (action === 'endorse') {
          fd.set('party_id', String(getEndorsePartyId()));
        }

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
