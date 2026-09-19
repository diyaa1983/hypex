(function () {
  'use strict';

  var box = document.getElementById('app-pwa-install');
  var btn = document.getElementById('app-pwa-install-btn');
  var dismiss = document.getElementById('app-pwa-install-dismiss');
  if (!box || !btn) {
    return;
  }

  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
    box.classList.add('is-standalone');
    return;
  }

  var storageKey = 'manager_pwa_install_dismiss';
  if (window.localStorage && localStorage.getItem(storageKey) === '1') {
    return;
  }

  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    box.hidden = false;
  });

  btn.addEventListener('click', function () {
    if (!deferredPrompt) {
      alert(
        'لتثبيت التطبيق:\n\n'
          + 'Chrome: القائمة ⋮ ← «Install app» / «تثبيت التطبيق»\n'
          + 'Edge: Apps ← «Install this site as an app»'
      );
      return;
    }
    deferredPrompt.prompt();
    deferredPrompt.userChoice.finally(function () {
      deferredPrompt = null;
      box.hidden = true;
    });
  });

  if (dismiss) {
    dismiss.addEventListener('click', function () {
      box.hidden = true;
      try {
        localStorage.setItem(storageKey, '1');
      } catch (err) {
        // ignore
      }
    });
  }
})();
