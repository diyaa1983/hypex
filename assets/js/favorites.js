(function () {
  'use strict';

  var STAR_SVG =
    '<svg class="app-screen-fav-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">' +
    '<path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"' +
    ' fill="currentColor" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>';

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
    if (document.body) {
      document.body.setAttribute('data-is-favorite', isFav ? '1' : '0');
    }
  }

  function bindButton(btn) {
    if (btn.__favBound) return;
    btn.__favBound = true;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
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
          document.querySelectorAll('[data-favorite-toggle]').forEach(function (other) {
            if (other !== btn && (other.getAttribute('data-screen-code') || '') === code) {
              setBtnState(other, !!data.favorited);
            }
          });
          if (window.AppDialog && AppDialog.toast) {
            AppDialog.toast(data.message || (data.favorited ? 'أُضيفت إلى المفضلة' : 'أُزيلت من المفضلة'));
          }
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

  function isVisible(el) {
    if (!el) return false;
    var style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    return el.getClientRects().length > 0;
  }

  function createFavButton(route, csrf, apiUrl, isFav) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'app-screen-fav-btn app-screen-fav-btn--on-blue no-print' + (isFav ? ' is-active' : '');
    btn.setAttribute('data-favorite-toggle', '');
    btn.setAttribute('data-screen-code', route);
    btn.setAttribute('data-csrf', csrf);
    btn.setAttribute('data-api-url', apiUrl);
    btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
    var title = isFav ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة';
    btn.setAttribute('aria-label', title);
    btn.setAttribute('title', title);
    btn.innerHTML = STAR_SVG;
    return btn;
  }

  function insertBeforeControls(bar, btn) {
    var controls =
      bar.querySelector('.ora12-title-bar__controls') ||
      bar.querySelector('.ora12-title-bar__close') ||
      bar.querySelector('.nav-screen-close') ||
      bar.querySelector('.dashboard-ora-screen-title__end');
    if (controls && controls.parentNode === bar) {
      bar.insertBefore(btn, controls);
      return;
    }
    if (controls) {
      controls.parentNode.insertBefore(btn, controls);
      return;
    }
    bar.appendChild(btn);
  }

  function hasVisibleToggle() {
    var toggles = document.querySelectorAll('[data-favorite-toggle]');
    for (var i = 0; i < toggles.length; i++) {
      var btn = toggles[i];
      var bar = btn.closest('.dashboard-ora-screen-title, .app-screen-title-bar, .report-ora12-screen-title');
      if (isVisible(btn) || (bar && isVisible(bar))) {
        return true;
      }
    }
    return false;
  }

  function injectMissingToggle() {
    var body = document.body;
    if (!body || body.getAttribute('data-fav-allowed') !== '1') return;
    if (hasVisibleToggle()) return;

    var route = body.getAttribute('data-active-route') || '';
    var csrf = body.getAttribute('data-csrf') || '';
    var apiUrl = body.getAttribute('data-fav-api') || '';
    if (!route || !csrf || !apiUrl) return;

    var isFav = body.getAttribute('data-is-favorite') === '1';
    var bars = document.querySelectorAll(
      '.dashboard-ora-screen-title, .app-screen-title-bar, .report-ora12-screen-title'
    );
    var target = null;
    for (var i = 0; i < bars.length; i++) {
      if (isVisible(bars[i]) && !bars[i].querySelector('[data-favorite-toggle]')) {
        target = bars[i];
        break;
      }
    }
    if (!target) return;

    var btn = createFavButton(route, csrf, apiUrl, isFav);
    insertBeforeControls(target, btn);
  }

  function init() {
    injectMissingToggle();
    document.querySelectorAll('[data-favorite-toggle]').forEach(bindButton);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
