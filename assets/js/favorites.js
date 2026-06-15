(function () {
  'use strict';

  function showMessage(msg, type) {
    if (window.AppDialog) {
      if (type === 'error' && AppDialog.error) {
        AppDialog.error(msg);
      } else if (AppDialog.success) {
        AppDialog.success(msg);
      } else if (AppDialog.alert) {
        AppDialog.alert(msg, { type: type || 'info' });
      }
      return;
    }
    console.log(msg);
  }

  function setBtnState(btn, isFav) {
    btn.classList.toggle('is-active', !!isFav);
    btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
    var title = isFav ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة';
    btn.setAttribute('aria-label', title);
    btn.setAttribute('title', title);
  }

  function bindButton(btn) {
    if (btn.__favBound) return;
    btn.__favBound = true;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var code = btn.getAttribute('data-screen-code') || '';
      var csrf = btn.getAttribute('data-csrf') || '';
      var apiUrl = btn.getAttribute('data-api-url') || '';
      if (!code || !apiUrl) return;
      if (btn.__busy) return;
      btn.__busy = true;
      btn.disabled = true;
      var fd = new FormData();
      fd.append('screen', code);
      fd.append('_csrf', csrf);
      fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            showMessage((data && data.message) || 'تعذر حفظ المفضلة.', 'error');
            return;
          }
          setBtnState(btn, !!data.favorited);
          if (window.AppDialog && AppDialog.toast) {
            AppDialog.toast(data.message || (data.favorited ? 'أُضيفت إلى المفضلة' : 'أُزيلت من المفضلة'));
          }
          // إعادة تحميل القائمة الجانبية لإظهار/إخفاء المفضلة
          setTimeout(function () {
            try {
              window.location.reload();
            } catch (_) {}
          }, 250);
        })
        .catch(function () {
          showMessage('تعذر الاتصال بالخادم.', 'error');
        })
        .then(function () {
          btn.__busy = false;
          btn.disabled = false;
        });
    });
  }

  function init() {
    document.querySelectorAll('[data-favorite-toggle]').forEach(bindButton);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
