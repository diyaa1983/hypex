(function (global) {
  'use strict';

  var wrap = document.querySelector('.app-check-bell-wrap');
  if (!wrap) return;

  var bell = wrap.querySelector('.js-app-check-bell');
  var panel = wrap.querySelector('.app-check-bell-panel, .js-check-bell-panel');
  if (!bell || !panel) return;

  var viewportPad = 8;
  var gap = 6;
  var refreshUrl = wrap.getAttribute('data-refresh-url') || '/api/notifications';

  if (panel.parentNode !== document.body) {
    document.body.appendChild(panel);
  }

  function positionPanel() {
    var rect = bell.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var width = panel.offsetWidth || Math.min(320, vw - viewportPad * 2);
    var height = panel.offsetHeight;

    var left = rect.left;
    if (left + width > vw - viewportPad) left = vw - width - viewportPad;
    if (left < viewportPad) left = viewportPad;

    var top = rect.bottom + gap;
    if (height > 0 && top + height > vh - viewportPad && rect.top - height - gap >= viewportPad) {
      top = rect.top - height - gap;
    } else if (top + height > vh - viewportPad) {
      top = Math.max(viewportPad, vh - height - viewportPad);
    }

    panel.style.top = Math.round(top) + 'px';
    panel.style.left = Math.round(left) + 'px';
    panel.style.right = 'auto';
    panel.style.bottom = 'auto';
    panel.classList.add('is-positioned');
  }

  function openPanel() {
    panel.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    positionPanel();
    requestAnimationFrame(positionPanel);
  }

  function closePanel() {
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
  }

  function togglePanel() {
    if (panel.hidden) openPanel();
    else closePanel();
  }

  function applyPayload(res) {
    if (!res || !res.ok) return;
    var count = parseInt(res.alert_count, 10) || 0;
    var badge = wrap.querySelector('.app-check-bell-badge, .js-check-bell-badge');
    if (badge) {
      if (count > 0) {
        badge.hidden = false;
        badge.removeAttribute('hidden');
        badge.textContent = res.badge || (count > 99 ? '99+' : String(count));
      } else {
        badge.hidden = true;
        badge.setAttribute('hidden', '');
        badge.textContent = '';
      }
    }
    if (count > 0) bell.classList.add('has-alerts');
    else bell.classList.remove('has-alerts');
    bell.setAttribute('aria-label', count > 0 ? 'التنبيهات — ' + count + ' تنبيه' : 'التنبيهات');
    bell.setAttribute('title', count > 0 ? 'التنبيهات (' + count + ')' : 'التنبيهات');
    if (typeof res.panel_html === 'string' && res.panel_html) {
      panel.innerHTML = res.panel_html;
      if (!panel.hidden) positionPanel();
    }
    wrap.setAttribute('data-needs-refresh', '0');
  }

  function refresh() {
    var url = refreshUrl;
    if (typeof global.__HYPEX_BASE__ === 'string' && global.__HYPEX_BASE__ && url.indexOf(global.__HYPEX_BASE__) !== 0) {
      if (url.charAt(0) === '/') url = global.__HYPEX_BASE__.replace(/\/$/, '') + url;
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(applyPayload)
      .catch(function () {});
  }

  bell.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel();
    if (!panel.hidden) refresh();
  });

  document.addEventListener('click', function (e) {
    if (wrap.contains(e.target) || panel.contains(e.target)) return;
    closePanel();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) closePanel();
  });

  window.addEventListener('resize', function () {
    if (!panel.hidden) positionPanel();
  });
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', function () {
      if (!panel.hidden) positionPanel();
    });
  }
  window.addEventListener(
    'scroll',
    function () {
      if (!panel.hidden) positionPanel();
    },
    true
  );

  global.AppHeaderCheckNotify = { closePanel: closePanel, openPanel: openPanel, refresh: refresh };

  function scheduleRefresh() {
    if (typeof requestIdleCallback === 'function') {
      requestIdleCallback(refresh, { timeout: 2500 });
    } else {
      setTimeout(refresh, 400);
    }
  }

  scheduleRefresh();
  setInterval(refresh, 120000);
})(typeof window !== 'undefined' ? window : this);
