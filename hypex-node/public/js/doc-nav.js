/**
 * تنقل المستندات: أول / سابق / رقم / تالٍ / آخر
 * يدعم onOpen(id) لتنقل سلس بدون reload كامل.
 * window.HypexDocNav.bind({...}) → { setState, go... }
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

  function bind(o) {
    o = o || {};
    var input = el(o.input);
    if (!input) return null;

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
    var navBusy = false;
    var onOpen = typeof o.onOpen === 'function' ? o.onOpen : null;

    function openId(id) {
      id = Number(id);
      if (!id || navBusy) return;
      if (currentId && id === currentId) return;

      if (onOpen) {
        navBusy = true;
        setDisabled(firstBtn, true);
        setDisabled(prevBtn, true);
        setDisabled(nextBtn, true);
        setDisabled(lastBtn, true);
        Promise.resolve()
          .then(function () {
            return onOpen(id);
          })
          .then(function () {
            navBusy = false;
            syncBtns();
          })
          .catch(function (err) {
            navBusy = false;
            syncBtns();
            toast((err && err.message) || 'تعذر فتح المستند', 'error');
          });
        return;
      }

      var base = openPath.replace(/\/?$/, '/');
      window.location.href = base + id;
    }

    function setDisabled(btn, off) {
      if (!btn) return;
      btn.disabled = !!off;
      btn.setAttribute('aria-disabled', off ? 'true' : 'false');
    }

    function syncBtns() {
      if (navBusy) return;
      setDisabled(firstBtn, !(firstId > 0) || (currentId > 0 && currentId === firstId));
      setDisabled(prevBtn, !(prevId > 0));
      setDisabled(nextBtn, !(nextId > 0));
      setDisabled(lastBtn, !(lastId > 0) || (currentId > 0 && currentId === lastId));
    }

    function setState(s) {
      s = s || {};
      if (s.firstId != null) firstId = Number(s.firstId) || 0;
      if (s.prevId != null) prevId = Number(s.prevId) || 0;
      if (s.nextId != null) nextId = Number(s.nextId) || 0;
      if (s.lastId != null) lastId = Number(s.lastId) || 0;
      if (s.currentId != null) currentId = Number(s.currentId) || 0;
      if (s.currentNo != null) {
        currentNo = String(s.currentNo || '');
        input.value = currentNo;
      }
      syncBtns();
    }

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
    function goLast() {
      if (lastId > 0 && lastId !== currentId) openId(lastId);
      else if (!lastId) toast('لا توجد مستندات', 'error');
      else toast('أنت على آخر مستند', 'error');
    }

    if (firstBtn && !firstBtn._hxNavBound) {
      firstBtn._hxNavBound = true;
      firstBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goFirst();
      });
    }
    if (prevBtn && !prevBtn._hxNavBound) {
      prevBtn._hxNavBound = true;
      prevBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goPrev();
      });
    }
    if (nextBtn && !nextBtn._hxNavBound) {
      nextBtn._hxNavBound = true;
      nextBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goNext();
      });
    }
    if (lastBtn && !lastBtn._hxNavBound) {
      lastBtn._hxNavBound = true;
      lastBtn.addEventListener('click', function (e) {
        e.preventDefault();
        goLast();
      });
    }

    if (!input._hxNavBound) {
      input._hxNavBound = true;
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
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          goByNo();
          return;
        }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
          e.preventDefault();
          goPrev();
          return;
        }
        if (e.key === 'ArrowRight') {
          e.preventDefault();
          goLast();
          return;
        }
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
          input.dataset.hxNavLock = '';
          if (!data || !data.ok || !data.id) {
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

    syncBtns();
    return {
      setState: setState,
      openId: openId,
      goFirst: goFirst,
      goPrev: goPrev,
      goNext: goNext,
      goLast: goLast,
    };
  }

  window.HypexDocNav = { bind: bind };
})();
