/**
 * اتجاه الطباعة (طولي / عرضي) — مشترك لكل معاينات الطباعة في النظام.
 * يحقن أزرار الاتجاه في .sales-inv-print-overlay ويضبط @page عند الطباعة.
 */
(function (global) {
  'use strict';

  var STORAGE_KEY = 'app_print_orientation';
  var STYLE_ID = 'app-print-orient-page';
  var activeOverlay = null;

  function normalize(orient) {
    return orient === 'landscape' ? 'landscape' : 'portrait';
  }

  function readStored() {
    try {
      var v = global.sessionStorage && global.sessionStorage.getItem(STORAGE_KEY);
      if (v === 'landscape' || v === 'portrait') return v;
    } catch (e) {}
    return 'portrait';
  }

  function writeStored(orient) {
    try {
      if (global.sessionStorage) {
        global.sessionStorage.setItem(STORAGE_KEY, normalize(orient));
      }
    } catch (e) {}
  }

  function shouldSkip(overlay) {
    if (!overlay) return true;
    if (overlay.getAttribute('data-print-orient') === 'skip') return true;
    if (overlay.classList.contains('fin-oc-print-overlay')) return true;
    return false;
  }

  function get(overlay) {
    var root = overlay || activeOverlay;
    if (root && root.getAttribute('data-print-orient-current')) {
      return normalize(root.getAttribute('data-print-orient-current'));
    }
    return readStored();
  }

  function pageCss(orient) {
    orient = normalize(orient || get());
    return '@page{size:A4 ' + orient + ';}';
  }

  function prepareHtml(html, orient) {
    if (!html || typeof html !== 'string') return html;
    orient = normalize(orient || get());
    var css =
      '<style id="' +
      STYLE_ID +
      '">' +
      pageCss(orient) +
      '</style>';
    if (new RegExp('id=["\']' + STYLE_ID + '["\']').test(html)) {
      return html.replace(
        new RegExp('<style id=["\']' + STYLE_ID + '["\']>[\\s\\S]*?<\\/style>', 'i'),
        css
      );
    }
    if (/<\/head>/i.test(html)) {
      return html.replace(/<\/head>/i, css + '</head>');
    }
    return css + html;
  }

  function sizeFrame(frame, orient) {
    if (!frame || !frame.style) return;
    orient = normalize(orient || get());
    if (orient === 'landscape') {
      frame.style.width = '297mm';
      frame.style.height = '210mm';
    } else {
      frame.style.width = '210mm';
      frame.style.height = '297mm';
    }
  }

  function syncButtons(overlay, orient) {
    var buttons = overlay.querySelectorAll('[data-print-orient]');
    Array.prototype.forEach.call(buttons, function (btn) {
      var active = btn.getAttribute('data-print-orient') === orient;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function syncLayout(overlay) {
    if (!overlay || shouldSkip(overlay)) return;
    var orient = get(overlay);
    overlay.classList.toggle('print-overlay--landscape', orient === 'landscape');
    overlay.classList.toggle('report-print-overlay--landscape', orient === 'landscape');
    var papers = overlay.querySelectorAll('.sales-inv-print-paper');
    Array.prototype.forEach.call(papers, function (paper) {
      paper.classList.toggle('sales-inv-print-paper--landscape', orient === 'landscape');
    });
    syncButtons(overlay, orient);
  }

  function set(orient, overlay, opts) {
    orient = normalize(orient);
    opts = opts || {};
    var root = overlay || activeOverlay;
    writeStored(orient);
    if (root) {
      root.setAttribute('data-print-orient-current', orient);
      syncLayout(root);
      var cb = opts.onChange || root._printOrientOnChange;
      if (typeof cb === 'function') {
        cb(orient, root);
      }
      try {
        var ev = new CustomEvent('printorientationchange', {
          detail: { orientation: orient, overlay: root },
        });
        root.dispatchEvent(ev);
        global.dispatchEvent(ev);
      } catch (e) {}
    }
    return orient;
  }

  function controlsHtml() {
    return (
      '<div class="print-orient report-print-orient" role="group" aria-label="اتجاه الطباعة">' +
      '<span class="print-orient__label report-print-orient__label">الاتجاه</span>' +
      '<button type="button" class="print-orient__btn report-print-orient__btn" data-print-orient="portrait" aria-pressed="false">طولي</button>' +
      '<button type="button" class="print-orient__btn report-print-orient__btn" data-print-orient="landscape" aria-pressed="false">عرضي</button>' +
      '</div>'
    );
  }

  function enhance(overlay, opts) {
    if (!overlay || shouldSkip(overlay)) return overlay;
    opts = opts || {};
    if (typeof opts.onChange === 'function') {
      overlay._printOrientOnChange = opts.onChange;
    }
    if (overlay.getAttribute('data-print-orient-ready') === '1') {
      if (opts.defaultOrient) {
        overlay.setAttribute('data-print-orient-current', normalize(opts.defaultOrient));
      }
      syncLayout(overlay);
      return overlay;
    }
    var actions = overlay.querySelector('.sales-inv-print-overlay-actions');
    if (!actions) return overlay;

    var existing = actions.querySelector('.print-orient, .report-print-orient');
    if (!existing) {
      actions.insertAdjacentHTML('afterbegin', controlsHtml());
    }

    var initial = opts.defaultOrient ? normalize(opts.defaultOrient) : readStored();
    overlay.setAttribute('data-print-orient-current', initial);
    overlay.setAttribute('data-print-orient-ready', '1');

    Array.prototype.forEach.call(overlay.querySelectorAll('[data-print-orient]'), function (btn) {
      if (btn.getAttribute('data-print-orient-bound') === '1') return;
      btn.setAttribute('data-print-orient-bound', '1');
      btn.addEventListener('click', function () {
        var next = btn.getAttribute('data-print-orient');
        if (get(overlay) === next) return;
        set(next, overlay, { onChange: opts.onChange });
      });
    });

    syncLayout(overlay);
    return overlay;
  }

  function enhanceAll() {
    var list = document.querySelectorAll('.sales-inv-print-overlay');
    Array.prototype.forEach.call(list, function (overlay) {
      enhance(overlay);
    });
  }

  function markActive(overlay) {
    if (!overlay || shouldSkip(overlay)) return;
    activeOverlay = overlay;
    enhance(overlay);
    syncLayout(overlay);
  }

  function observeOverlays() {
    if (!global.MutationObserver || !document.documentElement) return;
    var obs = new MutationObserver(function (mutations) {
      var i;
      for (i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.type !== 'childList') continue;
        Array.prototype.forEach.call(m.addedNodes || [], function (node) {
          if (!node || node.nodeType !== 1) return;
          if (node.classList && node.classList.contains('sales-inv-print-overlay')) {
            enhance(node);
          } else if (node.querySelectorAll) {
            Array.prototype.forEach.call(node.querySelectorAll('.sales-inv-print-overlay'), enhance);
          }
        });
        if (m.target && m.target.nodeType === 1) {
          var preview = null;
          if (m.target.id && String(m.target.id).indexOf('print-preview') !== -1) {
            preview = m.target;
          } else if (m.target.classList && m.target.classList.contains('sales-inv-print-preview-body')) {
            preview = m.target;
          }
          if (preview && preview.closest) {
            var ov = preview.closest('.sales-inv-print-overlay');
            if (ov) {
              markActive(ov);
              syncLayout(ov);
            }
          }
        }
      }
    });
    obs.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });
  }

  function boot() {
    enhanceAll();
    observeOverlays();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.PrintOrientation = {
    get: get,
    set: set,
    pageCss: pageCss,
    prepareHtml: prepareHtml,
    sizeFrame: sizeFrame,
    syncLayout: syncLayout,
    enhance: enhance,
    enhanceAll: enhanceAll,
    markActive: markActive,
    shouldSkip: shouldSkip,
  };
})(window);
