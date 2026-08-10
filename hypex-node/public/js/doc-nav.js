/**
 * تنقل المستندات: أول / سابق / رقم / تالٍ / آخر
 * window.HypexDocNav.bind({...})
 *
 * السهم الأيمن (» أو ArrowRight عند عدم وجود تالي مخصّص) = آخر مستند (أكبر id)
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

  function el(ref) {
    if (!ref) return null;
    return typeof ref === 'string' ? document.getElementById(ref) : ref;
  }

  /**
   * @param {object} o
   * @param {string|HTMLElement} o.input
   * @param {string|HTMLElement} [o.firstBtn]
   * @param {string|HTMLElement} [o.prevBtn]
   * @param {string|HTMLElement} [o.nextBtn]
   * @param {string|HTMLElement} [o.lastBtn]
   * @param {number} [o.firstId]
   * @param {number} [o.prevId]
   * @param {number} [o.nextId]
   * @param {number} [o.lastId]
   * @param {number} [o.currentId]
   * @param {string} o.openPath
   * @param {string} o.findApi
   * @param {string} [o.currentNo]
   */
  function bind(o) {
    o = o || {};
    var input = el(o.input);
    if (!input) return;

    var firstBtn = el(o.firstBtn);
    var prevBtn = el(o.prevBtn);
    var nextBtn = el(o.nextBtn);
    var lastBtn = el(o.lastBtn);

    var firstId = Number(o.firstId || 0) || 0;
    var prevId = Number(o.prevId || 0) || 0;
    var nextId = Number(o.nextId || 0) || 0;
    var lastId = Number(o.lastId || 0) || 0;
    var currentId = Number(o.currentId || 0) || 0;

    var openPath = String(o.openPath || '');
    var findApi = String(o.findApi || '').split('?')[0];
    var currentNo = String(o.currentNo != null ? o.currentNo : input.value || '');

    function openId(id) {
      id = Number(id);
      if (!id) return;
      if (currentId && id === currentId) return;
      var base = openPath.replace(/\/?$/, '/');
      window.location.href = base + id;
    }

    function setDisabled(btn, off) {
      if (!btn) return;
      btn.disabled = !!off;
      btn.setAttribute('aria-disabled', off ? 'true' : 'false');
    }

    function syncBtns() {
      // أول/آخر: لا نفعّل إذا كان المستند الحالي هو نفسه
      setDisabled(firstBtn, !(firstId > 0) || (currentId > 0 && currentId === firstId));
      setDisabled(prevBtn, !(prevId > 0));
      setDisabled(nextBtn, !(nextId > 0));
      setDisabled(lastBtn, !(lastId > 0) || (currentId > 0 && currentId === lastId));
    }
    syncBtns();

    function goFirst() {
      if (firstId > 0 && firstId !== currentId) openId(firstId);
      else toast('أنت على أول مستند', 'error');
    }
    function goPrev() {
      if (prevId > 0) openId(prevId);
      else toast('لا يوجد مستند سابق', 'error');
    }
    function goNext() {
      if (nextId > 0) openId(nextId);
      else toast('لا يوجد مستند تالٍ', 'error');
    }
    /** السهم الأيمن / آخر = أكبر رقم (آخر مستند) */
    function goLast() {
      if (lastId > 0 && lastId !== currentId) openId(lastId);
      else if (!lastId) toast('لا توجد مستندات', 'error');
      else toast('أنت على آخر مستند', 'error');
    }

    if (firstBtn) {
      firstBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goFirst();
      });
    }
    if (prevBtn) {
      prevBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goPrev();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goNext();
      });
    }
    if (lastBtn) {
      lastBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goLast();
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
      // يسار / أعلى / PageUp = السابق
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
        e.preventDefault();
        goPrev();
        return;
      }
      // يمين (→) = آخر فاتورة (أكبر رقم) — كما طُلب
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        goLast();
        return;
      }
      // أسفل / PageDown = التالي واحداً
      if (e.key === 'ArrowDown' || e.key === 'PageDown') {
        e.preventDefault();
        goNext();
        return;
      }
      if (e.key === 'Home') {
        e.preventDefault();
        goFirst();
        return;
      }
      if (e.key === 'End') {
        e.preventDefault();
        goLast();
      }
    });
  }

  window.HypexDocNav = { bind: bind };
})();
