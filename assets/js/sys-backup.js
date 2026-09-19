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

  function isWindowsDrivePath(path) {
    return /^[a-zA-Z]:[\\/]/.test(String(path || '').trim());
  }

  function isLinuxServerPath(path) {
    path = String(path || '').trim();
    return path !== '' && path.charAt(0) === '/' && !isWindowsDrivePath(path);
  }

  /** مسار افتراضي صالح للخادم أو Windows */
  function resolveDefaultPath() {
    if (isLinux) {
      if (isLinuxServerPath(savedDir)) {
        return savedDir;
      }
      if (isLinuxServerPath(recommendedDir)) {
        return recommendedDir;
      }
      return recommendedDir || savedDir;
    }
    if (savedDir) {
      return savedDir;
    }
    return recommendedDir || '';
  }

  function buildPathPromptMessage() {
    if (isLinux) {
      return (
        'مجلد الحفظ على الخادم (Linux) — لا تستخدم D:\\\n\n' +
        'مثال:\n' +
        (recommendedDir || '/home/.../manager_backups') +
        '\n\nبعد إنشاء النسخة سيتم تنزيلها إلى جهازك تلقائياً.'
      );
    }
    return (
      'حدّد مجلد حفظ النسخ الاحتياطي على هذا الجهاز.\n\n' + 'Windows مثال:\nD:\\Backups\\Manager'
    );
  }

  function validateBackupPath(path) {
    path = String(path || '').trim();
    if (!path) {
      return { ok: false, message: 'يجب تحديد مجلد النسخ الاحتياطي.' };
    }
    if (isLinux && isWindowsDrivePath(path)) {
      return {
        ok: false,
        message:
          'مسار Windows (مثل D:\\Backups) لا يعمل على خادم Linux.\n\n' +
          'استخدم مسار الخادم، مثلاً:\n' +
          (recommendedDir || '/home/.../manager_backups') +
          '\n\nبعد النسخ يُنزَّل الملف إلى جهازك (Downloads).',
      };
    }
    if (isLinux && !isLinuxServerPath(path)) {
      return {
        ok: false,
        message:
          'المسار يجب أن يبدأ بـ / على Linux.\n\nمثال:\n' + (recommendedDir || '/home/.../manager_backups'),
      };
    }
    return { ok: true, path: path };
  }

  function promptBackupPath() {
    var defaultVal = resolveDefaultPath();
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

  function confirmLinuxBackup(path) {
    var msg =
      'سيتم حفظ النسخة على الخادم في:\n' +
      path +
      '\n\n' +
      'ثم تُنزَّل تلقائياً إلى جهازك (مجلد التنزيلات).\n\n' +
      'هل تريد المتابعة؟';

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

  function runBackup(backupDir) {
    if (!apiUrl || !csrf) {
      alertMsg('إعدادات النسخ غير مكتملة.', 'error');
      return;
    }

    var check = validateBackupPath(backupDir);
    if (!check.ok) {
      alertMsg(check.message, 'warning');
      return;
    }
    backupDir = check.path;

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

  function startBackupFlow() {
    if (isLinux) {
      var path = resolveDefaultPath();
      if (!isLinuxServerPath(path)) {
        path = recommendedDir;
      }
      if (!path) {
        alertMsg('تعذر تحديد مسار النسخ على الخادم.', 'error');
        return;
      }
      confirmLinuxBackup(path).then(function (ok) {
        if (ok) {
          runBackup(path);
        }
      });
      return;
    }

    promptBackupPath().then(function (path) {
      if (!path) {
        return;
      }
      var check = validateBackupPath(path);
      if (!check.ok) {
        alertMsg(check.message, 'warning');
        return;
      }
      runBackup(check.path);
    });
  }

  if (runBtn) {
    runBtn.addEventListener('click', function () {
      if (runBtn.disabled) {
        return;
      }
      startBackupFlow();
    });
  }

  if (busyEl && busyEl.parentNode !== document.body) {
    document.body.appendChild(busyEl);
  }
})();
