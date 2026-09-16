/**
 * قائمة المواد لبطاقة المادة — نفس أعمدة طلبات الشراء:
 * رقم المادة | الباركود | اسم المادة
 * أسهم التنقّل أسفل القائمة؛ الاختيار يعرض البطاقة بالحقول.
 */
(function () {
  'use strict';

  var root = document.getElementById('ic-pick');
  if (!root) return;

  var qEl = document.getElementById('ic-pick-q');
  var listEl = document.getElementById('ic-pick-list');
  var hintEl = document.getElementById('ic-pick-hint');
  var selEl = document.getElementById('ic-list-sel');
  var navIdEl = document.getElementById('ic-card-nav-id');
  var timer = null;
  var seq = 0;
  var rows = [];
  var active = -1;
  var currentId = Number((listEl && listEl.getAttribute('data-current-id')) || 0) || 0;

  function esc(s) {
    var d = document.createElement('span');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function skuOf(r) {
    return String(r.sku != null ? r.sku : '').trim() || '—';
  }

  function barcodeOf(r) {
    var bc = String(r.barcode != null ? r.barcode : '').trim();
    if (!bc) {
      var code = String(r.code != null ? r.code : '').trim();
      if (code && code !== String(r.sku || '').trim()) bc = code;
    }
    return bc || '—';
  }

  function nameOf(r) {
    return String(r.name_ar != null ? r.name_ar : '').trim() || '—';
  }

  function openItem(id) {
    var n = Number(id);
    if (!n) return;
    if (n === currentId) {
      focusForm();
      return;
    }
    window.location.href = '/inventory/items/' + n;
  }

  function focusForm() {
    var el =
      document.querySelector('#item-form input[name="name_ar"]') ||
      document.querySelector('#item-form input[name="barcode"]');
    if (!el) return;
    try {
      el.focus();
      if (typeof el.select === 'function') el.select();
    } catch (e) {
      /* ignore */
    }
  }

  function focusListSearch() {
    if (!qEl) return;
    try {
      qEl.focus();
      qEl.select();
      root.scrollIntoView({ block: 'nearest' });
    } catch (e) {
      /* ignore */
    }
  }

  function setActive(i) {
    var buttons = listEl.querySelectorAll('.ic-pick__row');
    if (!buttons.length) {
      active = -1;
      return;
    }
    if (i < 0) i = 0;
    if (i >= buttons.length) i = buttons.length - 1;
    active = i;
    buttons.forEach(function (btn, idx) {
      var on = idx === active;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) {
        try {
          btn.scrollIntoView({ block: 'nearest' });
        } catch (e) {
          /* ignore */
        }
      }
    });
  }

  function render(list, meta) {
    rows = Array.isArray(list) ? list : [];
    if (!rows.length) {
      listEl.innerHTML = '';
      hintEl.textContent = meta || 'لا توجد نتائج';
      active = -1;
      return;
    }
    hintEl.textContent = meta || rows.length + ' مادة — اختر لعرض البطاقة بالأسفل';
    listEl.innerHTML = rows
      .map(function (r, i) {
        var id = Number(r.id) || 0;
        var isCur = currentId && id === currentId;
        return (
          '<button type="button" class="ic-pick__row ic-pick__row--item' +
          (isCur ? ' is-current' : '') +
          '" role="option" data-id="' +
          esc(id) +
          '" data-i="' +
          i +
          '" aria-selected="false" title="' +
          esc([skuOf(r), barcodeOf(r), nameOf(r)].join(' · ')) +
          '">' +
          '<span class="ic-pick__sku" dir="ltr">' +
          esc(skuOf(r)) +
          '</span>' +
          '<span class="ic-pick__bc" dir="ltr">' +
          esc(barcodeOf(r)) +
          '</span>' +
          '<strong class="ic-pick__name">' +
          esc(nameOf(r)) +
          '</strong>' +
          '</button>'
        );
      })
      .join('');

    var curIdx = -1;
    if (currentId) {
      for (var j = 0; j < rows.length; j++) {
        if (Number(rows[j].id) === currentId) {
          curIdx = j;
          break;
        }
      }
    }
    setActive(curIdx >= 0 ? curIdx : 0);
    if (curIdx >= 0 && selEl && rows[curIdx]) {
      selEl.textContent = nameOf(rows[curIdx]);
    }
  }

  function search(q) {
    var my = ++seq;
    hintEl.textContent = 'جاري البحث…';
    var url = '/api/lookup/items?q=' + encodeURIComponent(q || '') + '&limit=120';
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (my !== seq) return;
        if (!data || !data.ok) {
          render([], (data && data.error) || 'تعذّر البحث');
          return;
        }
        render(data.rows || [], '');
      })
      .catch(function () {
        if (my !== seq) return;
        render([], 'تعذّر الاتصال');
      });
  }

  function gotoByNavId(raw) {
    var s = String(raw || '').trim();
    if (!s) {
      focusListSearch();
      return;
    }
    var n = Number(s);
    if (Number.isFinite(n) && n > 0) {
      openItem(n);
      return;
    }
    if (qEl) {
      qEl.value = s;
      search(s);
      focusListSearch();
    }
  }

  listEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.ic-pick__row');
    if (!btn) return;
    openItem(btn.getAttribute('data-id'));
  });

  listEl.addEventListener('mouseover', function (e) {
    var btn = e.target.closest('.ic-pick__row');
    if (!btn) return;
    var i = Number(btn.getAttribute('data-i'));
    if (!isNaN(i)) setActive(i);
  });

  if (qEl) {
    qEl.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        search(qEl.value);
      }, 180);
    });

    qEl.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive(active + 1);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(active - 1);
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (active >= 0 && rows[active]) openItem(rows[active].id);
      }
    });
  }

  if (navIdEl) {
    navIdEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        gotoByNavId(navIdEl.value);
      }
    });
  }

  document.addEventListener('click', function (e) {
    var trigger =
      e.target && e.target.closest && e.target.closest('[data-hx-item-picker]');
    if (!trigger) return;
    e.preventDefault();
    focusListSearch();
  });

  window.HxItemPicker = {
    open: focusListSearch,
    close: function () {},
    search: search,
  };

  search('');
  if (!currentId && qEl) {
    setTimeout(focusListSearch, 40);
  }
})();
