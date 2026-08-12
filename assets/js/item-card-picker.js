/**
 * شاشة اختيار صغيرة لفتح بطاقة المادة.
 */
(function () {
  'use strict';

  var root = document.getElementById('ic-pick');
  if (!root) return;

  var qEl = document.getElementById('ic-pick-q');
  var listEl = document.getElementById('ic-pick-list');
  var hintEl = document.getElementById('ic-pick-hint');
  var timer = null;
  var seq = 0;
  var rows = [];
  var active = -1;

  function esc(s) {
    var d = document.createElement('span');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function codeOf(r) {
    return String(r.code || r.barcode || r.sku || '').trim() || '—';
  }

  function openItem(id) {
    var n = Number(id);
    if (!n) return;
    window.location.href = '/inventory/items/' + n;
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
    hintEl.textContent = meta || rows.length + ' مادة — اختر لفتح البطاقة';
    listEl.innerHTML = rows
      .map(function (r, i) {
        return (
          '<button type="button" class="ic-pick__row" role="option" data-id="' +
          esc(r.id) +
          '" data-i="' +
          i +
          '" aria-selected="false">' +
          '<span class="ic-pick__code" dir="ltr">' +
          esc(codeOf(r)) +
          '</span>' +
          '<span class="ic-pick__name">' +
          esc(r.name_ar || '') +
          (r.name_en
            ? '<small class="muted" dir="ltr">' + esc(r.name_en) + '</small>'
            : '') +
          '</span>' +
          '<span class="ic-pick__meta muted">' +
          esc(r.unit_name || '') +
          '</span>' +
          '</button>'
        );
      })
      .join('');
    setActive(0);
  }

  function search(q) {
    var my = ++seq;
    hintEl.textContent = 'جاري البحث…';
    fetch('/api/lookup/items?q=' + encodeURIComponent(q || ''))
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
      return;
    }
  });

  search('');
  try {
    qEl.focus();
  } catch (e) {
    /* ignore */
  }
})();
