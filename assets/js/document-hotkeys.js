/**
 * اختصارات المستندات العامة:
 * F10 حفظ — F7 قائمة العملاء — F3 قائمة المواد
 * تعمل على أي شاشة تحتوي الزر/القائمة المقابلة.
 */
(function (global) {
  'use strict';

  var custom = { f3: null, f7: null, f10: null };

  function isVisible(el) {
    if (!el || el.disabled) return false;
    if (el.getAttribute('aria-disabled') === 'true') return false;
    if (el.closest('[hidden]')) return false;
    if (el.closest('.is-readonly-display')) return false;
    var st = window.getComputedStyle(el);
    if (st.display === 'none' || st.visibility === 'hidden' || st.opacity === '0') {
      return false;
    }
    var r = el.getBoundingClientRect();
    return r.width > 0 || r.height > 0;
  }

  function dialogOpen() {
    return !!document.querySelector(
      [
        '.ui-dialog:not([hidden])',
        '.sales-inv-dlv-pick-modal:not([hidden])',
        '#app-customer-picker-modal.is-open',
        '#app-customer-picker-modal:not([hidden]).is-open',
        '.item-picker-modal.is-open',
        '.item-picker-modal:not([hidden]).is-open',
        '.hr-ora-pick-modal:not([hidden])',
        '.sales-inv-pick-dropdown:not([hidden]).is-open',
      ].join(', ')
    );
  }

  function clickFirst(list) {
    for (var i = 0; i < list.length; i++) {
      if (isVisible(list[i])) {
        list[i].click();
        return true;
      }
    }
    return false;
  }

  function triggerSave() {
    if (typeof custom.f10 === 'function') {
      custom.f10();
      return true;
    }
    var btn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (btn && isVisible(btn) && !btn.disabled) {
      btn.click();
      return true;
    }
    return false;
  }

  function triggerCustomer() {
    if (typeof custom.f7 === 'function') {
      custom.f7();
      return true;
    }
    var nodes = document.querySelectorAll(
      'button.sales-inv-cust-open:not([data-item-picker-open])'
    );
    var preferred = [];
    var fallback = [];
    for (var i = 0; i < nodes.length; i++) {
      var btn = nodes[i];
      if (!isVisible(btn)) continue;
      var id = String(btn.id || '').toLowerCase();
      if (id.indexOf('supplier') >= 0 || id.indexOf('_supp') >= 0) continue;
      if (id.indexOf('customer') >= 0 || id.indexOf('cust') >= 0) {
        preferred.push(btn);
      } else if (btn.closest('[data-customer-picker]')) {
        preferred.push(btn);
      } else {
        fallback.push(btn);
      }
    }
    if (clickFirst(preferred)) return true;
    return clickFirst(fallback);
  }

  function triggerItems() {
    if (typeof custom.f3 === 'function') {
      custom.f3();
      return true;
    }

    var stocktakeBtn = document.getElementById('stocktake-pick-items');
    if (stocktakeBtn && isVisible(stocktakeBtn)) {
      stocktakeBtn.click();
      return true;
    }

    var activeRow =
      (document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('tr')
        : null) || null;
    if (activeRow) {
      var activePick = activeRow.querySelector('.js-pick-open');
      if (activePick && isVisible(activePick)) {
        activePick.click();
        return true;
      }
    }

    if (clickFirst(document.querySelectorAll('tr.is-entry-row .js-pick-open'))) {
      return true;
    }

    var picks = document.querySelectorAll('.js-pick-open');
    for (var j = picks.length - 1; j >= 0; j--) {
      if (isVisible(picks[j])) {
        picks[j].click();
        return true;
      }
    }

    return clickFirst(
      document.querySelectorAll(
        '[data-report-item-single-picker] .sales-inv-cust-open, [data-item-picker-open]'
      )
    );
  }

  function onKeyDown(e) {
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.defaultPrevented) return;

    var key = e.key;
    var code = e.code;
    var isF10 = key === 'F10' || code === 'F10';
    var isF7 = key === 'F7' || code === 'F7';
    var isF3 = key === 'F3' || code === 'F3';
    if (!isF10 && !isF7 && !isF3) return;

    if (dialogOpen()) return;

    var ran = false;
    if (isF10) ran = triggerSave();
    else if (isF7) ran = triggerCustomer();
    else if (isF3) ran = triggerItems();

    if (ran) {
      e.preventDefault();
      e.stopPropagation();
    }
  }

  document.addEventListener('keydown', onKeyDown, true);

  global.DocumentHotkeys = {
    register: function (map) {
      if (!map || typeof map !== 'object') return;
      if (Object.prototype.hasOwnProperty.call(map, 'f3')) custom.f3 = map.f3;
      if (Object.prototype.hasOwnProperty.call(map, 'f7')) custom.f7 = map.f7;
      if (Object.prototype.hasOwnProperty.call(map, 'f10')) custom.f10 = map.f10;
    },
    clear: function (keys) {
      if (!keys) {
        custom.f3 = custom.f7 = custom.f10 = null;
        return;
      }
      (Array.isArray(keys) ? keys : [keys]).forEach(function (k) {
        if (k === 'f3' || k === 'f7' || k === 'f10') custom[k] = null;
      });
    },
  };
})(window);
