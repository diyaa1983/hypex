/**
 * شاشة العرض — بنود المواد + نوع العرض
 */
(function () {
  'use strict';

  var root = document.getElementById('so-root');
  if (!root) return;

  var tbody = document.getElementById('so-tbody');
  var form = document.getElementById('so-form');
  var linesInput = document.getElementById('so-lines-json');
  var lines = [];
  var itemTimers = {};
  var suggestSeq = 0;
  var posted = root.getAttribute('data-posted') === '1';

  function emptyLine() {
    return {
      item_id: 0,
      item_code: '',
      item_name: '',
      offer_type: 'bonus',
      trigger_qty: 10,
      bonus_qty: 1,
      discount_pct: '',
      unit_id: 0,
      unit_factor: 1,
      unit_name: '',
      bonus_unit_id: 0,
      bonus_unit_factor: 1,
      bonus_unit_name: '',
      units: [],
    };
  }

  function cleanBarcodeText(s) {
    s = String(s || '').trim();
    if (!s) return '';
    if (s.indexOf(' — ') >= 0) s = s.split(' — ')[0].trim();
    return s;
  }

  function apiUrl(path) {
    if (typeof window.__hypexUrl === 'function') return window.__hypexUrl(path);
    var b = String(window.__HYPEX_BASE__ || '').replace(/\/$/, '');
    return b && path.charAt(0) === '/' ? b + path : path;
  }

  function defaultUnitOf(units) {
    units = Array.isArray(units) ? units : [];
    if (!units.length) {
      return { unit_id: 0, name: 'قطعة', factor: 1 };
    }
    return (
      units.find(function (u) {
        return u.is_default;
      }) ||
      units.find(function (u) {
        return u.is_base;
      }) ||
      units[0]
    );
  }

  function baseUnitOf(units) {
    units = Array.isArray(units) ? units : [];
    if (!units.length) return defaultUnitOf(units);
    return (
      units.find(function (u) {
        return u.is_base;
      }) || defaultUnitOf(units)
    );
  }

  try {
    lines = JSON.parse((document.getElementById('so-initial') || {}).textContent || '[]') || [];
  } catch (e) {
    lines = [];
  }
  lines = (lines || []).map(function (ln) {
    ln = ln || emptyLine();
    var units = Array.isArray(ln.units) ? ln.units : [];
    return {
      item_id: Number(ln.item_id) || 0,
      item_code: cleanBarcodeText(ln.item_code || ln.item_barcode || ''),
      item_name: String(ln.item_name || '').trim(),
      offer_type: String(ln.offer_type) === 'discount_pct' ? 'discount_pct' : 'bonus',
      trigger_qty: ln.trigger_qty != null && ln.trigger_qty !== '' ? ln.trigger_qty : 10,
      bonus_qty: ln.bonus_qty != null && ln.bonus_qty !== '' ? ln.bonus_qty : 1,
      discount_pct: ln.discount_pct != null && ln.discount_pct !== '' ? ln.discount_pct : '',
      unit_id: Number(ln.unit_id) || 0,
      unit_factor: Number(ln.unit_factor) > 0 ? Number(ln.unit_factor) : 1,
      unit_name: String(ln.unit_name || ''),
      bonus_unit_id: Number(ln.bonus_unit_id) || 0,
      bonus_unit_factor: Number(ln.bonus_unit_factor) > 0 ? Number(ln.bonus_unit_factor) : 1,
      bonus_unit_name: String(ln.bonus_unit_name || ''),
      units: units,
    };
  });
  if (!lines.length) lines.push(emptyLine());

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function syncJson() {
    if (linesInput) linesInput.value = JSON.stringify(lines);
  }

  function syncFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-idx]').forEach(readRow);
  }

  function trashBtnHtml() {
    if (posted) return '<span class="muted">—</span>';
    return (
      '<button type="button" class="si-del js-del" title="حذف" aria-label="حذف">' +
      '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" d="M4 7h16M9 7V5h6v2M18.5 7l-.7 12.2a1.5 1.5 0 0 1-1.5 1.4H7.7a1.5 1.5 0 0 1-1.5-1.4L5.5 7M10 11v6M14 11v6"/></svg>' +
      '</button>'
    );
  }

  function typeSelectHtml(ln) {
    var t = ln.offer_type === 'discount_pct' ? 'discount_pct' : 'bonus';
    return (
      '<select class="js-offer-type si-field" data-nav="1"' +
      (posted ? ' disabled' : '') +
      '>' +
      '<option value="bonus"' +
      (t === 'bonus' ? ' selected' : '') +
      '>كمية إضافية</option>' +
      '<option value="discount_pct"' +
      (t === 'discount_pct' ? ' selected' : '') +
      '>خصم %</option>' +
      '</select>'
    );
  }

  function unitSelectHtml(ln, which, disabled) {
    var units = Array.isArray(ln.units) ? ln.units : [];
    var cur =
      which === 'bonus' ? Number(ln.bonus_unit_id) || 0 : Number(ln.unit_id) || 0;
    var cls = which === 'bonus' ? 'js-bonus-unit' : 'js-unit';
    var dis = disabled || posted;
    if (!units.length) {
      return (
        '<select class="' +
        cls +
        ' si-field" data-nav="1"' +
        (dis ? ' disabled' : '') +
        '>' +
        '<option value="0">قطعة</option></select>'
      );
    }
    var opts = units
      .map(function (u) {
        var uid = Number(u.unit_id) || 0;
        var label = String(u.name || 'وحدة');
        var f = Number(u.factor) > 0 ? Number(u.factor) : 1;
        if (f !== 1) label += ' ×' + f;
        return (
          '<option value="' +
          uid +
          '"' +
          (uid === cur ? ' selected' : '') +
          ' data-factor="' +
          f +
          '">' +
          escAttr(label) +
          '</option>'
        );
      })
      .join('');
    return (
      '<select class="' +
      cls +
      ' si-field" data-nav="1"' +
      (dis ? ' disabled' : '') +
      '>' +
      opts +
      '</select>'
    );
  }

  function render(focusOpts) {
    if (!tbody) return;
    tbody.innerHTML = '';
    lines.forEach(function (ln, idx) {
      var isBonus = ln.offer_type !== 'discount_pct';
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr" class="si-row-num">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-code-cell so-col-code">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input class="js-item-code" type="text" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="باركود" data-nav="1" value="' +
        escAttr(ln.item_code || '') +
        '"' +
        (posted ? ' readonly' : '') +
        '>' +
        '</td>' +
        '<td class="si-item-name-cell so-col-name">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="اضغط للبحث عن المادة…" data-nav="1" value="' +
        escAttr(ln.item_name || '') +
        '"' +
        (posted ? ' readonly' : '') +
        '>' +
        '</td>' +
        '<td class="so-col-unit">' +
        unitSelectHtml(ln, 'trigger', false) +
        '</td>' +
        '<td class="so-col-qty"><input class="js-trigger" type="number" min="0.001" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.trigger_qty) +
        '" title="كمية العرض بوحدة المادة"' +
        (posted ? ' readonly' : '') +
        '></td>' +
        '<td class="so-col-type">' +
        typeSelectHtml(ln) +
        '</td>' +
        '<td class="so-col-bonus"><input class="js-bonus" type="number" min="0" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.bonus_qty) +
        '" ' +
        (isBonus && !posted ? '' : 'disabled') +
        ' title="كمية إضافية مجانية"></td>' +
        '<td class="so-col-bunit">' +
        unitSelectHtml(ln, 'bonus', !isBonus) +
        '</td>' +
        '<td class="so-col-disc"><input class="js-disc" type="number" min="0" max="100" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.discount_pct) +
        '" ' +
        (isBonus || posted ? 'disabled' : '') +
        '></td>' +
        '<td class="si-col-del">' +
        trashBtnHtml() +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    syncJson();
    if (focusOpts && focusOpts.idx != null) {
      window.setTimeout(function () {
        focusLineField(focusOpts.idx, focusOpts.cls || '.js-item-code', !!focusOpts.select);
      }, 0);
    }
  }

  function focusLineField(idx, selector, doSelect) {
    var tr = tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (!tr) return;
    var el = tr.querySelector(selector);
    if (!el || el.disabled) return;
    try {
      el.focus();
      if (doSelect && el.select) el.select();
    } catch (e) {
      /* ignore */
    }
  }

  function readRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = lines[idx] || emptyLine();
    var hid = tr.querySelector('.js-item-id');
    if (hid) ln.item_id = Number(hid.value) || 0;
    var codeEl = tr.querySelector('.js-item-code');
    var nameEl = tr.querySelector('.js-item-name');
    if (codeEl) ln.item_code = cleanBarcodeText(codeEl.value);
    if (nameEl) ln.item_name = String(nameEl.value || '').trim();
    var typ = tr.querySelector('.js-offer-type');
    if (typ) ln.offer_type = typ.value === 'discount_pct' ? 'discount_pct' : 'bonus';
    var tg = tr.querySelector('.js-trigger');
    var bn = tr.querySelector('.js-bonus');
    var dc = tr.querySelector('.js-disc');
    if (tg) ln.trigger_qty = tg.value;
    if (bn) ln.bonus_qty = bn.value;
    if (dc) ln.discount_pct = dc.value;
    var unitEl = tr.querySelector('.js-unit');
    if (unitEl) {
      ln.unit_id = Number(unitEl.value) || 0;
      var opt = unitEl.options[unitEl.selectedIndex];
      ln.unit_factor = opt ? Number(opt.getAttribute('data-factor')) || 1 : 1;
      ln.unit_name = opt ? String(opt.textContent || '').replace(/\s*×.*$/, '') : '';
    }
    var bUnitEl = tr.querySelector('.js-bonus-unit');
    if (bUnitEl) {
      ln.bonus_unit_id = Number(bUnitEl.value) || 0;
      var bopt = bUnitEl.options[bUnitEl.selectedIndex];
      ln.bonus_unit_factor = bopt ? Number(bopt.getAttribute('data-factor')) || 1 : 1;
      ln.bonus_unit_name = bopt ? String(bopt.textContent || '').replace(/\s*×.*$/, '') : '';
    }
    lines[idx] = ln;
    syncJson();
  }

  function applyItem(idx, it) {
    lines[idx] = lines[idx] || emptyLine();
    lines[idx].item_id = it.id;
    lines[idx].item_code = cleanBarcodeText(it.barcode || it.code || it.sku || '');
    lines[idx].item_name = String(it.name_ar || it.name || '').trim();
    lines[idx].units = Array.isArray(it.units) ? it.units : [];
    var du = defaultUnitOf(lines[idx].units);
    lines[idx].unit_id = du.unit_id || 0;
    lines[idx].unit_factor = Number(du.factor) > 0 ? Number(du.factor) : 1;
    lines[idx].unit_name = du.name || '';
    // افتراضياً نفس وحدة العرض حتى تكون الكمية الإضافية صحيحة (مثلاً 10+1 كرتون)
    lines[idx].bonus_unit_id = du.unit_id || 0;
    lines[idx].bonus_unit_factor = Number(du.factor) > 0 ? Number(du.factor) : 1;
    lines[idx].bonus_unit_name = du.name || '';
    hideSuggest();
    render({ idx: idx, cls: '.js-unit', select: false });
  }

  function getSuggestBox() {
    var el = document.getElementById('so-global-suggest');
    if (!el) {
      el = document.createElement('div');
      el.id = 'so-global-suggest';
      el.className = 'pa-global-suggest si-suggest si-suggest--pa si-suggest--float so-suggest';
      el.hidden = true;
      document.body.appendChild(el);
    }
    return el;
  }

  function hideSuggest() {
    var box = getSuggestBox();
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.innerHTML = '';
    box.style.display = 'none';
    box.classList.remove('so-suggest--open');
  }

  function placeSuggest(anchor) {
    var box = getSuggestBox();
    var rect = anchor.getBoundingClientRect();
    var width = Math.max(320, rect.width + 80);
    var left = rect.left;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }
    box.className = 'pa-global-suggest si-suggest si-suggest--pa si-suggest--float so-suggest so-suggest--open';
    box.style.position = 'fixed';
    box.style.zIndex = '99999';
    box.style.left = left + 'px';
    box.style.right = 'auto';
    box.style.top = rect.bottom + 4 + 'px';
    box.style.minWidth = width + 'px';
    box.style.width = width + 'px';
    box.style.maxHeight = 'min(18rem, 50vh)';
    box.style.overflow = 'auto';
    box.style.display = 'block';
    box.hidden = false;
    box.removeAttribute('hidden');
  }

  function showSuggest(anchor, rows, onPick, emptyMsg) {
    var box = getSuggestBox();
    if (!rows || !rows.length) {
      box.innerHTML =
        '<div class="si-suggest-empty">' + escAttr(emptyMsg || 'لا نتائج مطابقة') + '</div>';
      placeSuggest(anchor);
      return;
    }
    box.innerHTML = rows
      .map(function (r) {
        var code = r.code || r.barcode || r.sku || '';
        var name = r.name_ar || r.name || '';
        return (
          '<button type="button" data-id="' +
          escAttr(r.id) +
          '">' +
          '<span class="so-sug-code" dir="ltr">' +
          escAttr(code || '—') +
          '</span>' +
          '<span class="so-sug-name">' +
          escAttr(name || '—') +
          '</span>' +
          '</button>'
        );
      })
      .join('');
    placeSuggest(anchor);
    box.querySelectorAll('button[data-id]').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) {
        e.preventDefault();
      });
      btn.addEventListener('click', function () {
        var id = Number(btn.getAttribute('data-id'));
        var it = rows.find(function (x) {
          return Number(x.id) === id;
        });
        if (it) onPick(it);
      });
    });
  }

  function searchItems(q, cb) {
    var url = apiUrl('/api/lookup/items') + '?q=' + encodeURIComponent(q || '') + '&limit=60';
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (d) {
        cb(d && d.ok ? d.rows || [] : []);
      })
      .catch(function () {
        cb(null);
      });
  }

  function openItemList(fromEl, idx, qOverride) {
    var q = qOverride != null ? qOverride : String(fromEl.value || '').trim();
    /* إذا المادة مختارة والنص يحتوي فاصل قديم — اعرض القائمة كاملة */
    if (Number(lines[idx] && lines[idx].item_id) > 0 && q && fromEl.classList.contains('js-item-name')) {
      /* ابقِ النص لكن ابحث بالاسم الحالي أو أظهر الكل عند التركيز الفارغ المنطقي */
    }
    var seq = ++suggestSeq;
    var box = getSuggestBox();
    box.innerHTML = '<div class="si-suggest-empty">جاري البحث…</div>';
    placeSuggest(fromEl);
    searchItems(q, function (rows) {
      if (seq !== suggestSeq) return;
      if (rows == null) {
        showSuggest(fromEl, [], function () {}, 'تعذر تحميل المواد');
        return;
      }
      showSuggest(fromEl, rows, function (it) {
        applyItem(idx, it);
      });
    });
  }

  function moveSuggest(dir) {
    var box = getSuggestBox();
    if (box.hidden) return false;
    var btns = Array.prototype.slice.call(box.querySelectorAll('button[data-id]'));
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

  function bindItemField(el, tr, idx) {
    if (!el) return;

    el.addEventListener('focus', function () {
      openItemList(el, idx);
    });
    el.addEventListener('click', function () {
      openItemList(el, idx);
    });
    el.addEventListener('input', function () {
      var hid = tr.querySelector('.js-item-id');
      if (hid) hid.value = '';
      lines[idx].item_id = 0;
      clearTimeout(itemTimers[idx]);
      itemTimers[idx] = setTimeout(function () {
        openItemList(el, idx, String(el.value || '').trim());
      }, 160);
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        hideSuggest();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopPropagation();
        var box = getSuggestBox();
        if (box.hidden) openItemList(el, idx);
        else moveSuggest(1);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        e.stopPropagation();
        if (getSuggestBox().hidden) openItemList(el, idx);
        else moveSuggest(-1);
        return;
      }
      if (e.key === 'Enter') {
        var box2 = getSuggestBox();
        var active = box2.querySelector('button.is-active');
        var first = box2.querySelector('button[data-id]');
        if (!box2.hidden && (active || first)) {
          e.preventDefault();
          (active || first).click();
          return;
        }
        e.preventDefault();
        var q = el.value;
        searchItems(q, function (rows) {
          if (!rows) return;
          if (rows.length === 1) applyItem(idx, rows[0]);
          else showSuggest(el, rows, function (it) {
            applyItem(idx, it);
          });
        });
      }
    });
  }

  function bindRow(tr) {
    if (posted) return;
    var idx = Number(tr.getAttribute('data-idx'));

    tr.querySelector('.js-del') &&
      tr.querySelector('.js-del').addEventListener('click', function () {
        syncFromDom();
        lines.splice(idx, 1);
        if (!lines.length) lines.push(emptyLine());
        hideSuggest();
        render();
      });

    var typ = tr.querySelector('.js-offer-type');
    if (typ) {
      typ.addEventListener('change', function () {
        readRow(tr);
        render({ idx: idx, cls: typ.value === 'discount_pct' ? '.js-disc' : '.js-bonus' });
      });
    }

    ['.js-trigger', '.js-bonus', '.js-disc', '.js-unit', '.js-bonus-unit'].forEach(function (sel) {
      var el = tr.querySelector(sel);
      if (el)
        el.addEventListener('change', function () {
          readRow(tr);
        });
    });

    bindItemField(tr.querySelector('.js-item-code'), tr, idx);
    bindItemField(tr.querySelector('.js-item-name'), tr, idx);
  }

  var addBtn = document.getElementById('so-add-line');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      if (posted) return;
      syncFromDom();
      for (var i = 0; i < lines.length; i++) {
        if (!Number(lines[i].item_id)) {
          focusLineField(i, '.js-item-name', true);
          openItemList(
            tbody.querySelector('tr[data-idx="' + i + '"] .js-item-name'),
            i
          );
          return;
        }
      }
      lines.push(emptyLine());
      render({ idx: lines.length - 1, cls: '.js-item-name', select: true });
      window.setTimeout(function () {
        var el = tbody.querySelector(
          'tr[data-idx="' + (lines.length - 1) + '"] .js-item-name'
        );
        if (el) openItemList(el, lines.length - 1);
      }, 30);
    });
  }

  if (form) {
    form.addEventListener('submit', function () {
      syncFromDom();
      syncJson();
    });
  }

  document.addEventListener('mousedown', function (e) {
    var box = document.getElementById('so-global-suggest');
    if (!box || box.hidden) return;
    if (box.contains(e.target)) return;
    if (e.target && e.target.closest && e.target.closest('.js-item-code, .js-item-name')) return;
    hideSuggest();
  });

  window.addEventListener(
    'scroll',
    function () {
      var box = document.getElementById('so-global-suggest');
      if (!box || box.hidden) return;
      var active = document.activeElement;
      if (active && (active.classList.contains('js-item-code') || active.classList.contains('js-item-name'))) {
        placeSuggest(active);
      }
    },
    true
  );

  document.addEventListener('keydown', function (e) {
    if (e.key === 'F10') {
      e.preventDefault();
      if (posted) return;
      if (form) {
        syncFromDom();
        syncJson();
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
    }
  });

  render();

  if (window.HypexDocNav && root) {
    window.HypexDocNav.bind({
      input: 'so_no',
      firstBtn: 'so_first',
      prevBtn: 'so_prev',
      nextBtn: 'so_next',
      lastBtn: 'so_last',
      firstId: Number(root.getAttribute('data-first-id') || 0),
      prevId: Number(root.getAttribute('data-prev-id') || 0),
      nextId: Number(root.getAttribute('data-next-id') || 0),
      lastId: Number(root.getAttribute('data-last-id') || 0),
      currentId: Number(root.getAttribute('data-current-id') || 0),
      currentNo: root.getAttribute('data-offer-no') || '',
      openPath: '/sales/offers',
      findApi: '/api/sales/offers/by-no',
    });
  }
})();
