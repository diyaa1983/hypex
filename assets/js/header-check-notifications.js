(function (global) {
  'use strict';

  var wrap = document.querySelector('.app-check-bell-wrap');
  if (!wrap) {
    return;
  }

  var bell = wrap.querySelector('.js-app-check-bell');
  var panel = wrap.querySelector('.app-check-bell-panel');
  if (!bell || !panel) {
    return;
  }

  var viewportPad = 8;
  var gap = 6;

  if (panel.parentNode !== document.body) {
    document.body.appendChild(panel);
  }

  function positionPanel() {
    var rect = bell.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var width = panel.offsetWidth;
    var height = panel.offsetHeight;

    if (width < 1) {
      width = Math.min(320, vw - viewportPad * 2);
    }

    var left = rect.left;
    if (left + width > vw - viewportPad) {
      left = vw - width - viewportPad;
    }
    if (left < viewportPad) {
      left = viewportPad;
    }

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
    requestAnimationFrame(function () {
      positionPanel();
    });
  }

  function closePanel() {
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
  }

  function togglePanel() {
    if (panel.hidden) {
      openPanel();
    } else {
      closePanel();
    }
  }

  bell.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel();
  });

  document.addEventListener('click', function (e) {
    if (wrap.contains(e.target) || panel.contains(e.target)) {
      return;
    }
    closePanel();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) {
      closePanel();
    }
  });

  window.addEventListener('resize', function () {
    if (!panel.hidden) {
      positionPanel();
    }
  });

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', function () {
      if (!panel.hidden) {
        positionPanel();
      }
    });
  }

  window.addEventListener(
    'scroll',
    function () {
      if (!panel.hidden) {
        positionPanel();
      }
    },
    true
  );

  global.AppHeaderCheckNotify = { closePanel: closePanel, openPanel: openPanel };
})(typeof window !== 'undefined' ? window : this);
