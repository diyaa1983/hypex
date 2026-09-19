/**
 * حذف بند الفاتورة على الموبايل: ضغط مطوّل ثم سحب لليمين لإظهار سلة المهملات.
 */
(function (global) {
  'use strict';

  var REVEAL_PX = 76;
  var LONG_PRESS_MS = 420;
  var MOVE_CANCEL_PX = 14;
  var OPEN_RATIO = 0.38;

  function bind(container, options) {
    if (!container || container.getAttribute('data-inv-swipe-bound') === '1') return;
    container.setAttribute('data-inv-swipe-bound', '1');

    options = options || {};
    var onDelete = options.onDelete;
    var revealPx = options.revealWidth || REVEAL_PX;
    var longPressMs = options.longPressMs || LONG_PRESS_MS;

    var activeWrap = null;
    var touch = null;

    function closeAll(except) {
      container.querySelectorAll('.m-inv-line-swipe.is-open').forEach(function (w) {
        if (w !== except) {
          w.classList.remove('is-open', 'is-dragging', 'is-armed');
          var panel = w.querySelector('.m-inv-line__panel');
          if (panel) panel.style.transform = '';
          w.dataset.swipeOffset = '0';
        }
      });
      if (!except) activeWrap = null;
    }

    function setOffset(wrap, px) {
      var panel = wrap.querySelector('.m-inv-line__panel');
      if (!panel) return;
      var x = Math.max(0, Math.min(revealPx, px));
      wrap.dataset.swipeOffset = String(x);
      /* سحب لليمين يكشف السكة على يسار البطاقة */
      panel.style.transform = x > 0 ? 'translate3d(' + x + 'px,0,0)' : '';
    }

    function snap(wrap, px) {
      var open = px >= revealPx * OPEN_RATIO;
      if (open) {
        wrap.classList.add('is-open');
        setOffset(wrap, revealPx);
        activeWrap = wrap;
      } else {
        wrap.classList.remove('is-open');
        setOffset(wrap, 0);
        if (activeWrap === wrap) activeWrap = null;
      }
      wrap.classList.remove('is-dragging', 'is-armed');
    }

    function clearTouch() {
      if (touch && touch.timer) clearTimeout(touch.timer);
      touch = null;
    }

    function isBlockedTarget(el) {
      if (!el || !el.closest) return false;
      return !!el.closest(
        'input, select, textarea, button, a, .m-inv-swipe-delete, .m-inv-line-delete'
      );
    }

    function armWrap(wrap) {
      if (!touch || touch.wrap !== wrap) return;
      touch.armed = true;
      wrap.classList.add('is-armed');
      activeWrap = wrap;
      try {
        if (navigator.vibrate) navigator.vibrate(12);
      } catch (e) { /* ignore */ }
    }

    function getTouchPoint(e) {
      if (e.changedTouches && e.changedTouches.length) return e.changedTouches[0];
      if (e.touches && e.touches.length) return e.touches[0];
      return null;
    }

    function onTouchStart(e) {
      var wrap = e.target.closest('.m-inv-line-swipe');
      if (!wrap || !container.contains(wrap)) return;
      if (wrap.closest('.m-invoice-form--locked')) return;
      if (isBlockedTarget(e.target)) return;

      if (activeWrap && activeWrap !== wrap) closeAll(null);

      var t = getTouchPoint(e);
      if (!t) return;

      var w = wrap;
      touch = {
        wrap: w,
        startX: t.clientX,
        startY: t.clientY,
        baseOffset: w.classList.contains('is-open') ? revealPx : 0,
        armed: false,
        timer: setTimeout(function () {
          armWrap(w);
        }, longPressMs),
      };
    }

    function onTouchMove(e) {
      if (!touch) return;
      var t = getTouchPoint(e);
      if (!t) return;

      var dx = t.clientX - touch.startX;
      var dy = t.clientY - touch.startY;

      if (!touch.armed) {
        /* لا تلغِ الضغط المطوّل عند اهتزاز الإصبع — فقط عند تمرير عمودي واضح */
        if (Math.abs(dy) > MOVE_CANCEL_PX && Math.abs(dy) > Math.abs(dx) * 1.2) {
          clearTouch();
        }
        return;
      }

      if (e.cancelable) e.preventDefault();
      touch.wrap.classList.add('is-dragging');

      /* سحب لليمين */
      var dragRight = dx;
      var offset = touch.baseOffset + dragRight;
      if (offset < 0) offset = 0;
      setOffset(touch.wrap, offset);
    }

    function onTouchEnd() {
      if (!touch || !touch.wrap) return;
      var wrap = touch.wrap;
      var wasArmed = touch.armed;
      var tr = parseFloat(wrap.dataset.swipeOffset || '0') || 0;
      clearTouch();
      if (wasArmed) snap(wrap, tr);
    }

    container.addEventListener('touchstart', onTouchStart, { passive: true });
    container.addEventListener('touchmove', onTouchMove, { passive: false });
    container.addEventListener('touchend', onTouchEnd, { passive: true });
    container.addEventListener('touchcancel', onTouchEnd, { passive: true });

    container.addEventListener('click', function (e) {
      var delBtn = e.target.closest('.m-inv-swipe-delete');
      if (delBtn) {
        var wrap = delBtn.closest('.m-inv-line-swipe');
        if (!wrap) return;
        e.preventDefault();
        e.stopPropagation();
        var id = parseInt(wrap.getAttribute('data-line-id'), 10);
        closeAll(null);
        if (onDelete && id) onDelete(id);
        return;
      }
      if (!e.target.closest('.m-inv-line-swipe')) {
        closeAll(null);
      }
    });
  }

  global.MobileInvLineSwipe = { bind: bind, REVEAL_PX: REVEAL_PX };
})(typeof window !== 'undefined' ? window : this);
