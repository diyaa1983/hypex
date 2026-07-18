(function () {
  'use strict';

  var page = document.querySelector('.fin-outgoing-checks-page');
  if (!page) return;

  var printApi = page.getAttribute('data-print-check-api') || '';
  var printCssUrl = page.getAttribute('data-print-check-css') || '';
  var apiUrl = page.getAttribute('data-api-url') || '';
  var csrf = page.getAttribute('data-csrf') || '';
  var activeCheckHtml = '';
  var activeCheckTitle = 'شيك صادر';

  var clearModal = document.getElementById('fin-oc-clear-modal');
  var clearForm = document.getElementById('fin-oc-clear-modal-form');
  var clearCheckId = document.getElementById('fin-oc-clear-check-id');
  var clearActionDate = document.getElementById('fin-oc-clear-action-date');
  var clearErr = document.getElementById('fin-oc-clear-modal-error');
  var clearSubmit = document.getElementById('fin-oc-clear-modal-submit');
  var sumNo = document.getElementById('fin-oc-sum-no');
  var sumAmount = document.getElementById('fin-oc-sum-amount');
  var sumParty = document.getElementById('fin-oc-sum-party');
  var sumVoucher = document.getElementById('fin-oc-sum-voucher');
  var sumVdate = document.getElementById('fin-oc-sum-vdate');
  var sumDue = document.getElementById('fin-oc-sum-due');

  function alertMsg(msg, type) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, { type: type || 'warning' });
    } else {
      alert(msg);
    }
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

  function setClearSummary(btn) {
    if (!btn) return;
    var val = function (key) {
      return btn.getAttribute(key) || '—';
    };
    if (sumNo) sumNo.innerHTML = '<code>' + (val('data-check-no') || '—') + '</code>';
    if (sumAmount) sumAmount.textContent = val('data-check-amount');
    if (sumParty) sumParty.textContent = val('data-party-name');
    if (sumVoucher) sumVoucher.innerHTML = '<code>' + val('data-voucher-no') + '</code>';
    if (sumVdate) sumVdate.textContent = val('data-voucher-date');
    if (sumDue) sumDue.textContent = val('data-due-date');
  }

  function openClearModal(btn) {
    if (!clearModal || !btn) return;
    var checkId = btn.getAttribute('data-check-id');
    if (!checkId) return;
    clearCheckId.value = String(checkId);
    setClearSummary(btn);
    if (clearActionDate) {
      clearActionDate.value = todayDmY();
      clearActionDate.dispatchEvent(new Event('blur', { bubbles: true }));
    }
    if (clearErr) {
      clearErr.textContent = '';
      clearErr.style.display = 'none';
    }
    clearModal.hidden = false;
    clearModal.setAttribute('aria-hidden', 'false');
    clearModal.dataset.checkLabel = btn.getAttribute('data-check-label') || '';
  }

  function closeClearModal() {
    if (!clearModal) return;
    clearModal.hidden = true;
    clearModal.setAttribute('aria-hidden', 'true');
  }

  function postCheckAction(fd) {
    return fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function undoCheck(btn) {
    var checkId = parseInt(btn.getAttribute('data-check-id') || '0', 10) || 0;
    var undoLabel = btn.getAttribute('data-undo-label') || 'إلغاء الصرف';
    if (checkId < 1 || !apiUrl) return;
    dialogConfirm(undoLabel + ' وإعادة الشيك إلى «قيد»؟', undoLabel).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('action', 'undo');
      fd.append('check_id', String(checkId));
      postCheckAction(fd)
        .then(function (data) {
          if (data && data.ok) {
            dialogSuccess((data && data.message) || 'تم الإلغاء.');
            window.setTimeout(function () {
              window.location.reload();
            }, 400);
            return;
          }
          dialogError((data && data.message) || 'تعذر إلغاء الصرف.');
        })
        .catch(function () {
          dialogError('خطأ في الاتصال بالخادم.');
        });
    });
  }

  page.addEventListener('click', function (ev) {
    var actBtn = ev.target && ev.target.closest ? ev.target.closest('[data-check-action]') : null;
    if (actBtn) {
      var action = actBtn.getAttribute('data-check-action');
      if (action === 'undo') {
        ev.preventDefault();
        undoCheck(actBtn);
        return;
      }
      if (action === 'clear') {
        ev.preventDefault();
        dialogConfirm('فتح شاشة ترحيل صرف هذا الشيك؟', 'صرف الشيك').then(function (ok) {
          if (ok) openClearModal(actBtn);
        });
        return;
      }
    }
  });

  if (clearModal) {
    clearModal.querySelectorAll('[data-fin-oc-clear-close]').forEach(function (el) {
      el.addEventListener('click', closeClearModal);
    });
  }

  if (clearForm) {
    clearForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!apiUrl) return;
      var label = (clearModal && clearModal.dataset.checkLabel) || '';
      dialogConfirm('تأكيد ترحيل صرف الشيك؟\n' + label, 'صرف الشيك').then(function (ok) {
        if (!ok) return;
        if (clearSubmit) clearSubmit.disabled = true;
        if (clearErr) clearErr.style.display = 'none';
        var fd = new FormData(clearForm);
        fd.append('_csrf', csrf);
        postCheckAction(fd)
          .then(function (data) {
            if (data && data.ok) {
              closeClearModal();
              dialogSuccess((data && data.message) || 'تم صرف الشيك.');
              window.setTimeout(function () {
                window.location.reload();
              }, 400);
              return;
            }
            if (clearErr) {
              clearErr.textContent = (data && data.message) || 'تعذر صرف الشيك.';
              clearErr.style.display = '';
            }
          })
          .catch(function () {
            dialogError('خطأ في الاتصال بالخادم.');
          })
          .finally(function () {
            if (clearSubmit) clearSubmit.disabled = false;
          });
      });
    });
  }

  function isCheckPreviewOpen() {
    var overlay = document.getElementById('fin-oc-print-overlay');
    return overlay && !overlay.hidden;
  }

  function closeCheckPreview() {
    var overlay = document.getElementById('fin-oc-print-overlay');
    if (overlay) {
      overlay.hidden = true;
    }
    activeCheckHtml = '';
    activeCheckTitle = 'شيك صادر';
  }

  function buildDocumentHtml(innerHtml, title) {
    title = title || 'شيك صادر';
    var css = printCssUrl ? '<link rel="stylesheet" href="' + printCssUrl + '">' : '';
    var pageSize =
      '<style>@page{size:17.8cm 8.9cm;margin:0.35cm;}html,body{margin:0;padding:0;}</style>';
    return (
      '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">' +
      '<title>' +
      title +
      '</title>' +
      css +
      pageSize +
      '</head><body class="bank-check-page">' +
      innerHtml +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('fin-oc-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'fin-oc-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    return frame;
  }

  function printHtmlInFrame(fullHtml) {
    var frame = getPrintFrame();
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 250);
  }

  function fetchCheckPrintHtml(checkId) {
    if (!printApi || checkId < 1) {
      return Promise.resolve(null);
    }
    var url =
      printApi + (printApi.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(checkId)) + '&embed=1';
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function showCheckPrintPreview(html, title) {
    activeCheckHtml = html || '';
    activeCheckTitle = title || 'شيك صادر';
    var preview = document.getElementById('fin-oc-print-preview');
    var overlay = document.getElementById('fin-oc-print-overlay');
    var titleEl = document.getElementById('fin-oc-print-overlay-title');
    if (!preview || !overlay) {
      if (activeCheckHtml) {
        printHtmlInFrame(buildDocumentHtml(activeCheckHtml, activeCheckTitle));
      }
      return;
    }
    preview.innerHTML = activeCheckHtml;
    if (titleEl) {
      titleEl.textContent = 'معاينة الشيك — ' + activeCheckTitle;
    }
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    overlay.hidden = false;
    overlay.style.display = 'flex';
    overlay.style.zIndex = '10050';
  }

  function runCheckPrintFromPreview() {
    if (!activeCheckHtml) {
      alertMsg('لا يوجد شيك للطباعة.');
      return;
    }
    printHtmlInFrame(buildDocumentHtml(activeCheckHtml, activeCheckTitle));
  }

  function openCheckPrintPreview(checkId) {
    checkId = parseInt(checkId, 10) || 0;
    if (checkId < 1) return;
    fetchCheckPrintHtml(checkId).then(function (data) {
      if (!data || !data.ok || !data.html) {
        alertMsg((data && data.message) || 'تعذر تحميل نموذج الشيك.');
        return;
      }
      showCheckPrintPreview(data.html, data.title || 'شيك صادر');
    });
  }

  function printList() {
    if (isCheckPreviewOpen()) {
      runCheckPrintFromPreview();
      return;
    }
    var table = document.getElementById('fin-outgoing-checks-table');
    if (!table || !table.tBodies.length || !table.tBodies[0].rows.length) {
      alertMsg('لا توجد شيكات للطباعة.', 'info');
      return;
    }
    window.print();
  }

  var printListBtn = document.getElementById('fin-outgoing-checks-print-list');
  if (printListBtn) {
    printListBtn.addEventListener('click', function () {
      closeCheckPreview();
      printList();
    });
  }

  page.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('.fin-outgoing-check-print-one') : null;
    if (!btn) return;
    ev.preventDefault();
    openCheckPrintPreview(btn.getAttribute('data-check-id'));
  });

  var closeBtn = document.getElementById('fin-oc-print-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', closeCheckPreview);
  }

  var runBtn = document.getElementById('fin-oc-print-run');
  if (runBtn) {
    runBtn.addEventListener('click', runCheckPrintFromPreview);
  }

  var overlay = document.getElementById('fin-oc-print-overlay');
  if (overlay) {
    overlay.addEventListener('click', function (ev) {
      if (ev.target === overlay) {
        closeCheckPreview();
      }
    });
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'print') return;
    if (!document.getElementById('fin-outgoing-checks-print-area')) return;
    e.preventDefault();
    printList();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && isCheckPreviewOpen()) {
      closeCheckPreview();
    }
    if (ev.key === 'Escape' && clearModal && !clearModal.hidden) {
      closeClearModal();
    }
  });
})();
