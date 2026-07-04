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
  var savedDir = page.getAttribute('data-backup-dir') || '';
  var recommendedDir = page.getAttribute('data-recommended-dir') || '';
  var isLinux = page.getAttribute('data-is-linux') === '1';

  function showBusy(show) {
    if (window.AppBusy) {
      if (show) {
        AppBusy.show('جاري إنشاء النسخة الاحتياطية...');
      } else {
        AppBusy.hide();
      }
      return;
    }
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

  function buildPathPromptMessage() {
    if (isLinux) {
      return (
        'حدّد مجلد حفظ النسخ على الخادم (Linux).\n\n' +
        'مثال:\n' + (recommendedDir || '/home/.../manager_backups') + '\n\n' +
        'ملاحظة: لا يمكن استخدام D:\\ على Linux.\n' +
        'بعد إنشاء النسخة يمكنك تنزيلها إلى جهازك من الأسفل أو تلقائياً.'
      );
    }
    return (
      'حدّد مجلد حفظ النسخ الاحتياطي على هذا الجهاز.\n\n' +
      'Windows مثال:\nD:\\Backups\\Manager'
    );
  }

  function promptBackupPath() {
    var defaultVal = savedDir || recommendedDir;
    var msg = buildPathPromptMessage();

    if (window.AppDialog && AppDialog.prompt) {
      return AppDialog.prompt(msg, {
        title: 'مكان النسخ الاحتياطي',
        value: defaultVal,
        placeholder: recommendedDir || 'مسار المجلد',
        okText: 'نعم، أخذ نسخة',
        cancelText: 'إلغاء',
        theme: 'oracle',
        multiline: false,
        inputType: 'text',
      }).then(function (path) {
        if (path === null || path === undefined) {
          return null;
        }
        path = String(path).trim();
        return path !== '' ? path : null;
      });
    }

    var raw = window.prompt(msg, defaultVal);
    if (raw === null) {
      return Promise.resolve(null);
    }
    raw = String(raw).trim();
    return Promise.resolve(raw !== '' ? raw : null);
  }

  function runBackup(backupDir) {
    if (!apiUrl || !csrf) {
      alertMsg('إعدادات النسخ غير مكتملة.', 'error');
      return;
    }
    if (!backupDir) {
      alertMsg('يجب تحديد مجلد النسخ الاحتياطي.', 'warning');
      return;
    }

    if (runBtn) {
      runBtn.disabled = true;
    }
    showBusy(true);

    var body = new FormData();
    body.append('_csrf', csrf);
    body.append('backup_dir', backupDir);

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
          savedDir = backupDir;
          page.setAttribute('data-backup-dir', backupDir);
          var msg = data.message || 'تم إنشاء النسخة الاحتياطية بنجاح.';
          if (data.download_url) {
            msg += '\n\nسيبدأ تنزيل النسخة إلى جهازك.';
          }
          alertMsg(msg, 'success').then(function () {
            if (data.download_url) {
              window.location.href = data.download_url;
              setTimeout(function () {
                window.location.reload();
              }, 1200);
              return;
            }
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
      if (runBtn.disabled) {
        return;
      }

      promptBackupPath().then(function (path) {
        if (!path) {
          return;
        }
        runBackup(path);
      });
    });
  }

  if (busyEl && busyEl.parentNode !== document.body) {
    document.body.appendChild(busyEl);
  }
})();
