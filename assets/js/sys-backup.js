(function () {
  'use strict';

  var page = document.querySelector('.sys-backup-page');
  if (!page) {
    return;
  }

  var runBtn = document.getElementById('sys-backup-run-btn');
  var busyEl = document.getElementById('sys-backup-busy');
  var apiUrl = page.getAttribute('data-backup-api') || '';
  var csrf = page.getAttribute('data-csrf') || '';

  function showBusy(show) {
    if (!busyEl) {
      return;
    }
    busyEl.hidden = !show;
    if (show) {
      busyEl.removeAttribute('hidden');
    }
    document.body.classList.toggle('sys-backup-is-busy', !!show);
  }

  function alertMsg(message, type) {
    if (window.AppDialog && AppDialog.alert) {
      return AppDialog.alert(message, {
        type: type || 'info',
        theme: 'oracle',
      });
    }
    window.alert(message);
    return Promise.resolve();
  }

  function confirmBackup() {
    var msg =
      'سيتم إنشاء نسخة احتياطية بتاريخ اليوم تتضمن قاعدة البيانات وملفات النظام.\n\n' +
      'قد تستغرق العملية عدة دقائق. هل تريد المتابعة؟';

    if (window.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(msg, {
        title: 'تأكيد النسخ الاحتياطي',
        okText: 'نعم، أخذ نسخة',
        cancelText: 'إلغاء',
        theme: 'oracle',
      });
    }

    return Promise.resolve(window.confirm(msg));
  }

  function runBackup() {
    if (!apiUrl || !csrf) {
      alertMsg('إعدادات النسخ غير مكتملة.', 'error');
      return;
    }

    if (runBtn) {
      runBtn.disabled = true;
    }
    showBusy(true);

    var body = new FormData();
    body.append('_csrf', csrf);

    fetch(apiUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
    })
      .then(function (res) {
        return res.json().catch(function () {
          return { ok: false, message: 'استجابة غير صالحة من الخادم.' };
        }).then(function (data) {
          if (!res.ok && data && data.message) {
            data.ok = false;
          }
          return data;
        });
      })
      .then(function (data) {
        showBusy(false);
        if (runBtn) {
          runBtn.disabled = false;
        }

        if (data && data.ok) {
          alertMsg(data.message || 'تم إنشاء النسخة الاحتياطية بنجاح.', 'success').then(function () {
            window.location.reload();
          });
          return;
        }

        alertMsg((data && data.message) || 'تعذر إنشاء النسخة الاحتياطية.', 'error');
      })
      .catch(function () {
        showBusy(false);
        if (runBtn) {
          runBtn.disabled = false;
        }
        alertMsg('تعذر الاتصال بالخادم أثناء النسخ الاحتياطي.', 'error');
      });
  }

  if (runBtn) {
    runBtn.addEventListener('click', function () {
      if (runBtn.disabled || runBtn.getAttribute('aria-disabled') === 'true') {
        return;
      }

      confirmBackup().then(function (ok) {
        if (ok) {
          runBackup();
        }
      });
    });
  }

  if (busyEl && busyEl.parentNode !== document.body) {
    document.body.appendChild(busyEl);
  }
})();
