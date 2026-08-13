/**
 * تنقّل عام بين الحقول في كامل النظام
 * Enter     → الحقل التالي (أو اختيار عنصر من قائمة مفتوحة)
 * Shift+Enter → الحقل السابق
 * ↑ / ↓     → داخل القائمة المنسدلة إن وُجدت، وإلا الحقل السابق/التالي
 * ← / →     → الحقل السابق/التالي عند حافة النص أو في select/number/date
 *
 * تعطيل: data-hx-nav="off" على العنصر أو حاوية أب
 */
(function () {
  'use strict';

  if (window.__HYPEX_FIELD_NAV__) return;
  window.__HYPEX_FIELD_NAV__ = true;

  var FIELD_SEL = [
    'input:not([type="hidden"]):not([type="file"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="image"])',
    'select',
    'textarea',
    'button:not([type="submit"]):not(.si-del):not(.js-del)',
    'a.si-btn',
    '[role="combobox"]',
    '[contenteditable="true"]',
  ].join(',');

  var SUGGEST_SEL = [
    '.si-suggest:not([hidden])',
    '.pa-global-suggest:not([hidden])',
    '.imv-suggest:not([hidden])',
    '.report-cust-pick:not([hidden])',
    '[data-hx-suggest]:not([hidden])',
  ].join(',');

  var SKIP_IDS = {
    login_password: 1,
    login_user: 1,
  };

  function isVisible(el) {
    if (!el || el.disabled) return false;
    if (el.getAttribute('aria-disabled') === 'true') return false;
    if (el.hasAttribute('hidden') || el.hidden) return false;
    if (el.tabIndex < 0 && el.tagName !== 'A') return false;
    var st = window.getComputedStyle(el);
    if (!st || st.display === 'none' || st.visibility === 'hidden') return false;
    if (Number(st.opacity) === 0) return false;
    var r = el.getBoundingClientRect();
    if (r.width <= 0 && r.height <= 0) return false;
    return true;
  }

  function navOff(el) {
    if (!el || !el.closest) return true;
    if (el.getAttribute('data-hx-nav') === 'off') return true;
    if (el.closest('[data-hx-nav="off"]')) return true;
    if (el.closest('.sidebar, .app-check-bell-panel, .hx-modal, .ui-dialog, [role="dialog"]')) {
      /* الحوارات لها منطقها — لا نسرق Enter من الأزرار داخلها إلا للحقول */
      if (el.closest('.sidebar')) return true;
    }
    if (el.id && SKIP_IDS[el.id]) return true;
    return false;
  }

  function inMain(el) {
    if (!el || !el.closest) return false;
    return !!(
      el.closest('main') ||
      el.closest('.si-stage') ||
      el.closest('.main') ||
      el.closest('.app-content') ||
      el.closest('form')
    );
  }

  function collectFields(fromEl) {
    var root =
      (fromEl && fromEl.closest && (fromEl.closest('.si-stage') || fromEl.closest('main') || fromEl.closest('.main'))) ||
      document.querySelector('main') ||
      document.body;
    var nodes = root.querySelectorAll(FIELD_SEL);
    var out = [];
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (!isVisible(el)) continue;
      if (navOff(el)) continue;
      if (el.readOnly && el.tagName === 'INPUT' && el.type === 'hidden') continue;
      /* readonly للتنقل مسموح (أرقام مستندات، أرصدة…) */
      if (el.type === 'checkbox' || el.type === 'radio') {
        /* أبقِها للتنقل */
      }
      out.push(el);
    }
    return out;
  }

  function focusEl(el) {
    if (!el) return false;
    try {
      el.focus({ preventScroll: false });
    } catch (e) {
      try {
        el.focus();
      } catch (e2) {
        return false;
      }
    }
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      try {
        if (typeof el.select === 'function' && el.type !== 'checkbox' && el.type !== 'radio') {
          el.select();
        }
      } catch (e3) {
        /* ignore */
      }
    }
    return true;
  }

  function moveField(fromEl, dir) {
    var fields = collectFields(fromEl);
    if (!fields.length) return false;
    var idx = fields.indexOf(fromEl);
    if (idx < 0) {
      /* العنصر قد يكون button داخل label — ابحث الأقرب */
      for (var i = 0; i < fields.length; i++) {
        if (fields[i] === fromEl || (fromEl.contains && fromEl.contains(fields[i]))) {
          idx = i;
          break;
        }
      }
    }
    if (idx < 0) {
      return focusEl(dir > 0 ? fields[0] : fields[fields.length - 1]);
    }
    var next = idx + dir;
    if (next < 0 || next >= fields.length) return false;
    return focusEl(fields[next]);
  }

  function findOpenSuggest(anchor) {
    var list = document.querySelectorAll(SUGGEST_SEL);
    var best = null;
    for (var i = 0; i < list.length; i++) {
      var box = list[i];
      if (!isVisible(box) && box.getAttribute('hidden') != null) continue;
      if (box.hasAttribute('hidden') || box.hidden) continue;
      var st = window.getComputedStyle(box);
      if (st.display === 'none' || st.visibility === 'hidden') continue;
      /* فضّل القائمة المرتبطة بالحقل */
      if (anchor && box.parentElement && box.parentElement.contains(anchor)) {
        return box;
      }
      best = box;
    }
    /* قائمة عائمة عامة — إن وُجدت ظاهرة */
    return best;
  }

  function suggestButtons(box) {
    if (!box) return [];
    return Array.prototype.slice.call(
      box.querySelectorAll(
        'button[data-id], button.report-cust-pick-item, button.report-cust-pick-opt, .report-cust-pick-item[data-id], [role="option"]'
      )
    ).filter(function (b) {
      return isVisible(b);
    });
  }

  function moveSuggest(box, dir) {
    var btns = suggestButtons(box);
    if (!btns.length) return false;
    var cur = -1;
    for (var i = 0; i < btns.length; i++) {
      if (btns[i].classList.contains('is-active')) {
        cur = i;
        break;
      }
    }
    if (cur < 0) cur = dir > 0 ? -1 : 0;
    var next = cur + dir;
    if (next < 0) next = btns.length - 1;
    if (next >= btns.length) next = 0;
    btns.forEach(function (b, i) {
      b.classList.toggle('is-active', i === next);
    });
    try {
      btns[next].scrollIntoView({ block: 'nearest' });
    } catch (e) {
      /* ignore */
    }
    return true;
  }

  function pickSuggest(box) {
    var btns = suggestButtons(box);
    if (!btns.length) return false;
    var active = null;
    for (var i = 0; i < btns.length; i++) {
      if (btns[i].classList.contains('is-active')) {
        active = btns[i];
        break;
      }
    }
    var pick = active || btns[0];
    if (!pick) return false;
    try {
      pick.click();
    } catch (e) {
      return false;
    }
    return true;
  }

  function caretAllowsHorizontal(el, dir) {
    /* dir: -1 = Left/prev, +1 = Right/next */
    if (!el || (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA')) return true;
    var t = (el.type || '').toLowerCase();
    if (t === 'number' || t === 'date' || t === 'time' || t === 'month' || t === 'week' || t === 'checkbox' || t === 'radio' || t === 'range' || t === 'color') {
      return true;
    }
    if (typeof el.selectionStart !== 'number') return true;
    if (el.selectionStart !== el.selectionEnd) return false;
    if (dir < 0) return el.selectionStart <= 0;
    return el.selectionEnd >= String(el.value || '').length;
  }

  function isTextArea(el) {
    return el && el.tagName === 'TEXTAREA';
  }

  function isButtonLike(el) {
    if (!el) return false;
    if (el.tagName === 'BUTTON') return true;
    if (el.tagName === 'A' && el.classList.contains('si-btn')) return true;
    if (el.type === 'submit' || el.type === 'button') return true;
    return false;
  }

  function onKeyDown(e) {
    if (e.defaultPrevented) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    var t = e.target;
    if (!t || !t.closest) return;
    if (navOff(t)) return;
    if (!inMain(t) && t.tagName !== 'INPUT' && t.tagName !== 'SELECT' && t.tagName !== 'TEXTAREA') return;

    var key = e.key;
    var openSug = findOpenSuggest(t);

    if (key === 'Escape' && openSug) {
      e.preventDefault();
      openSug.hidden = true;
      openSug.setAttribute('hidden', '');
      openSug.style.display = 'none';
      return;
    }

    /* —— Enter —— */
    if ((key === 'Enter' || key === 'NumpadEnter') && !e.shiftKey) {
      if (isTextArea(t) && t.getAttribute('data-hx-nav') !== '1') return;
      if (isButtonLike(t)) return; /* اترك الزر يعمل طبيعياً */
      if (t.tagName === 'SELECT') return; /* app-list-keyboard */

      if (openSug && suggestButtons(openSug).length) {
        e.preventDefault();
        if (pickSuggest(openSug)) return;
      }

      e.preventDefault();
      moveField(t, 1);
      return;
    }

    if ((key === 'Enter' || key === 'NumpadEnter') && e.shiftKey) {
      if (isTextArea(t) && t.getAttribute('data-hx-nav') !== '1') return;
      if (isButtonLike(t)) return;
      if (t.tagName === 'SELECT') return;
      e.preventDefault();
      moveField(t, -1);
      return;
    }

    /* —— أسهم عمودية —— */
    if (key === 'ArrowDown') {
      if (openSug && moveSuggest(openSug, 1)) {
        e.preventDefault();
        return;
      }
      if (t.tagName === 'SELECT') return; /* app-list-keyboard أو المتصفح */
      if (isTextArea(t)) return;
      e.preventDefault();
      moveField(t, 1);
      return;
    }

    if (key === 'ArrowUp') {
      if (openSug && moveSuggest(openSug, -1)) {
        e.preventDefault();
        return;
      }
      if (t.tagName === 'SELECT') return;
      if (isTextArea(t)) return;
      e.preventDefault();
      moveField(t, -1);
      return;
    }

    /* —— أسهم أفقية —— */
    if (key === 'ArrowLeft') {
      if (t.tagName === 'SELECT') return;
      if (!caretAllowsHorizontal(t, -1)) return;
      e.preventDefault();
      moveField(t, -1);
      return;
    }

    if (key === 'ArrowRight') {
      if (t.tagName === 'SELECT') return;
      if (!caretAllowsHorizontal(t, 1)) return;
      e.preventDefault();
      moveField(t, 1);
    }
  }

  document.addEventListener('keydown', onKeyDown, false);

  window.HypexFieldNav = {
    next: function (el) {
      return moveField(el || document.activeElement, 1);
    },
    prev: function (el) {
      return moveField(el || document.activeElement, -1);
    },
    collect: collectFields,
  };
})();
