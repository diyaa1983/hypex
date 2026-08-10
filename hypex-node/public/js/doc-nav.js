/**
 * تنقل المستندات برقم السند / السابق / التالي
 * window.HypexDocNav.bind({...})
 */
(function () {
  'use strict';

  function toast(msg, kind) {
    if (window.HypexUI && window.HypexUI.toast) {
      window.HypexUI.toast(msg, kind || 'error', 2800);
      return;
    }
    if (msg) alert(msg);
  }

  /**
   * @param {object} o
   * @param {string|HTMLElement} o.input - حقل الرقم
   * @param {string|HTMLElement} [o.prevBtn]
   * @param {string|HTMLElement} [o.nextBtn]
   * @param {number} [o.prevId]
   * @param {number} [o.nextId]
   * @param {string} o.openPath - bas path مثل /sales/invoices/
   * @param {string} o.findApi - مثل /api/sales/invoices/by-no
   * @param {string} [o.currentNo]
   */
  function bind(o) {
    o = o || {};
    var input = typeof o.input === 'string' ? document.getElementById(o.input) : o.input;
    if (!input) return;

    var prevBtn = typeof o.prevBtn === 'string' ? document.getElementById(o.prevBtn) : o.prevBtn;
    var nextBtn = typeof o.nextBtn === 'string' ? document.getElementById(o.nextBtn) : o.nextBtn;
    var prevId = Number(o.prevId || 0) || 0;
    var nextId = Number(o.nextId || 0) || 0;
    var openPath = String(o.openPath || '');
    var findApi = String(o.findApi || '').split('?')[0];
    var currentNo = String(o.currentNo != null ? o.currentNo : input.value || '');

    function openId(id) {
      id = Number(id);
      if (!id) return;
      var base = openPath.replace(/\/?$/, '/');
      window.location.href = base + id;
    }

    function syncBtns() {
      if (prevBtn) {
        prevBtn.disabled = !(prevId > 0);
        prevBtn.setAttribute('aria-disabled', prevId > 0 ? 'false' : 'true');
      }
      if (nextBtn) {
        nextBtn.disabled = !(nextId > 0);
        nextBtn.setAttribute('aria-disabled', nextId > 0 ? 'false' : 'true');
      }
    }
    syncBtns();

    if (prevBtn) {
      prevBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (prevId > 0) openId(prevId);
        else toast('لا يوجد مستند سابق', 'error');
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (nextId > 0) openId(nextId);
        else toast('لا يوجد مستند تالٍ', 'error');
      });
    }

    input.addEventListener('focus', function () {
      if (input.readOnly) {
        input.dataset.hxWasReadonly = '1';
        input.readOnly = false;
      }
      try {
        input.select();
      } catch (e) {
        /* ignore */
      }
    });
    input.addEventListener('blur', function () {
      // لا تُبقِ رقماً مؤقتاً يفسد الحفظ — أعد الرقم الحالي إن لم يُنتقل
      if (input.dataset.hxNavLock === '1') return;
      if (currentNo) input.value = currentNo;
      else input.value = '';
      if (input.dataset.hxWasReadonly === '1') {
        input.readOnly = true;
      }
    });

    function goByNo() {
      var no = String(input.value || '').trim();
      if (!no) {
        toast('أدخل رقم المستند', 'error');
        return;
      }
      if (currentNo && no === currentNo) return;
      if (!findApi) return;

      input.dataset.hxNavLock = '1';
      fetch(findApi + '?no=' + encodeURIComponent(no), { headers: { Accept: 'application/json' } })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok || !data.id) {
            input.dataset.hxNavLock = '';
            toast((data && data.error) || 'لم يُعثر على المستند', 'error');
            if (currentNo) input.value = currentNo;
            else input.value = '';
            return;
          }
          openId(data.id);
        })
        .catch(function () {
          input.dataset.hxNavLock = '';
          toast('تعذر البحث', 'error');
        });
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        goByNo();
        return;
      }
      if (e.key === 'ArrowUp' || e.key === 'PageUp') {
        e.preventDefault();
        if (prevId > 0) openId(prevId);
        return;
      }
      if (e.key === 'ArrowDown' || e.key === 'PageDown') {
        e.preventDefault();
        if (nextId > 0) openId(nextId);
      }
    });
  }

  window.HypexDocNav = { bind: bind };
})();
