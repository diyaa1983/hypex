/**
 * تعديل أسعار البيع — نفس سلوك شاشة طلب الشراء (باركود / اسم / تنقل / قائمة)
 */
(function () {
  'use strict';

  var root = document.getElementById('pa-root');
  if (!root) return;

  var posted = root.getAttribute('data-posted') === '1';
  var tbody = document.getElementById('pa-tbody');
  var form = document.getElementById('pa-form');
  var linesInput = document.getElementById('pa-lines-json');
  var msgEl = document.getElementById('pa-msg');
  var itemTimers = {};
  var lines = [];

  function emptyLine() {
    return {
      item_id: 0,
      item_code: '',
      item_barcode: '',
      item_name: '',
      old_sale_price: 0,
      new_sale_price: '',
      old_wholesale: 0,
      new_wholesale: '',
    };
  }

  function cleanBarcodeText(s) {
    s = String(s || '').trim();
    if (!s) return '';
    if (s.indexOf(' — ') >= 0) s = s.split(' — ')[0].trim();
    else if (s.indexOf(' - ') >= 0 && /^\d/.test(s)) s = s.split(' - ')[0].trim();
    return s;
  }

  try {
    lines = JSON.parse((document.getElementById('pa-initial') || {}).textContent || '[]') || [];
  } catch (e) {
    lines = [];
  }
  lines = (lines || []).map(function (ln) {
    ln = ln || emptyLine();
    return {
      item_id: Number(ln.item_id) || 0,
      item_code: cleanBarcodeText(ln.item_barcode || ln.item_code || ''),
      item_barcode: cleanBarcodeText(ln.item_barcode || ln.item_code || ''),
      item_name: String(ln.item_name || '').trim(),
      old_sale_price: Number(ln.old_sale_price) || 0,
      new_sale_price: ln.new_sale_price === '' || ln.new_sale_price == null ? '' : ln.new_sale_price,
      old_wholesale: Number(ln.old_wholesale) || 0,
      new_wholesale: ln.new_wholesale === '' || ln.new_wholesale == null ? '' : ln.new_wholesale,
    };
  });

  if (!lines.length) lines.push(emptyLine());

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function fmt(n) {
    if (window.HxDec && typeof window.HxDec.fmt === 'function') {
      return window.HxDec.fmt(n, window.HxDec.unitPlaces ? window.HxDec.unitPlaces() : undefined);
    }
    return (Math.round((Number(n) || 0) * 1000) / 1000).toLocaleString('en-US', {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    });
  }

  function priceStep() {
    return window.HxDec && window.HxDec.unitStep ? window.HxDec.unitStep() : '0.001';
  }

  function setMsg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.hidden = !text;
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
  }

  function itemBarcodeOnly(it) {
    if (!it) return '';
    var b = String(it.barcode != null ? it.barcode : '').trim();
    if (b) return cleanBarcodeText(b);
    return cleanBarcodeText(it.code || it.sku || '');
  }

  function itemNameOnly(it) {
    if (!it) return '';
    return String(it.name_ar != null ? it.name_ar : it.name != null ? it.name : it.item_name || '').trim();
  }

  function lineHasItem(ln) {
    return !!(ln && Number(ln.item_id) > 0);
  }

  function canAddNewLine(opts) {
    opts = opts || {};
    syncFromDom();
    for (var i = 0; i < lines.length; i++) {
      if (!lineHasItem(lines[i])) {
        if (!opts.silent) {
          focusLineField(i, '.js-item-code', true);
          setMsg('اختر المادة في السطر الحالي قبل إضافة سطر جديد.', 'error');
        }
        return false;
      }
    }
    return true;
  }

  function addEmptyLine(opts) {
    if (posted) return false;
    opts = opts || {};
    if (!opts.force && !canAddNewLine(opts)) return false;
    lines.push(emptyLine());
    render();
    return true;
  }

  function syncJson() {
    if (linesInput) linesInput.value = JSON.stringify(lines);
  }

  function syncFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-idx]').forEach(readRow);
  }

  function trashBtnHtml() {
    return (
      '<button type="button" class="si-del js-del" title="حذف البند" aria-label="حذف البند" tabindex="-1">' +
      '<svg class="si-del-ico" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
      '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16"/>' +
      '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>' +
      '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M18.5 7l-.7 12.2a1.5 1.5 0 0 1-1.5 1.4H7.7a1.5 1.5 0 0 1-1.5-1.4L5.5 7"/>' +
      '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" d="M10 11v6M14 11v6"/>' +
      '</svg></button>'
    );
  }

  function render(focusOpts) {
    if (!tbody) return;
    tbody.innerHTML = '';
    lines.forEach(function (ln, idx) {
      var barcode = cleanBarcodeText(ln.item_barcode || ln.item_code || '');
      var nameOnly = itemNameOnly({ name_ar: ln.item_name || '' });
      ln.item_barcode = barcode;
      ln.item_code = barcode;
      ln.item_name = nameOnly;

      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr" class="si-row-num">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-code-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input class="js-item-code" type="text" inputmode="search" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="باركود" data-nav="1" title="' +
        escAttr(barcode) +
        '" value="' +
        escAttr(barcode) +
        '" ' +
        (posted ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td class="si-item-name-cell">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="اسم المادة" data-nav="1" title="' +
        escAttr(nameOnly) +
        '" value="' +
        escAttr(nameOnly) +
        '" ' +
        (posted || ln.item_id ? 'readonly' : '') +
        '>' +
        '</td>' +
        '<td class="js-old-sale si-num-out pa-col-sale-old" dir="ltr">' +
        fmt(ln.old_sale_price) +
        '</td>' +
        '<td class="pa-col-sale-new"><input class="js-new-sale pa-input-new" type="number" step="' +
        priceStep() +
        '" min="0" data-nav="1" dir="ltr" placeholder="جديد" value="' +
        escAttr(ln.new_sale_price === '' || ln.new_sale_price == null ? '' : ln.new_sale_price) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td class="js-old-wh si-num-out pa-col-wh-old" dir="ltr">' +
        fmt(ln.old_wholesale) +
        '</td>' +
        '<td class="pa-col-wh-new"><input class="js-new-wh pa-input-new" type="number" step="' +
        priceStep() +
        '" min="0" data-nav="1" dir="ltr" placeholder="جديد" value="' +
        escAttr(ln.new_wholesale === '' || ln.new_wholesale == null ? '' : ln.new_wholesale) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td class="pa-col-act">' +
        (posted ? '' : trashBtnHtml()) +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    syncJson();
    window.setTimeout(fitAllLineFields, 0);
    if (focusOpts && focusOpts.idx != null) {
      window.setTimeout(function () {
        focusLineField(focusOpts.idx, focusOpts.cls || '.js-item-code', !!focusOpts.select);
      }, 0);
    }
  }

  function measureTextWidth(text, refEl) {
    if (!measureTextWidth._el) {
      var span = document.createElement('span');
      span.setAttribute('aria-hidden', 'true');
      span.style.cssText =
        'position:absolute;left:-99999px;top:0;visibility:hidden;white-space:pre;pointer-events:none;';
      document.body.appendChild(span);
      measureTextWidth._el = span;
    }
    var el = measureTextWidth._el;
    var cs = window.getComputedStyle(refEl);
    el.style.font = cs.font;
    el.style.fontSize = cs.fontSize;
    el.style.fontWeight = cs.fontWeight;
    el.style.fontFamily = cs.fontFamily;
    el.style.letterSpacing = cs.letterSpacing;
    el.textContent = text == null || text === '' ? 'م' : String(text);
    return Math.ceil(el.getBoundingClientRect().width);
  }

  /** توسيع الحقل حسب النص — معطّل: الأعمدة ثابتة عبر colgroup */
  function fitFieldToContent(el) {
    if (!el) return;
    el.style.width = '';
    el.style.maxWidth = '';
  }

  function fitRowFields(tr) {
    if (!tr) return;
    fitFieldToContent(tr.querySelector('.js-item-code'), { min: 100, max: 240, pad: 24 });
    fitFieldToContent(tr.querySelector('.js-item-name'), { min: 140, max: 520, pad: 28 });
  }

  function fitAllLineFields() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-idx]').forEach(fitRowFields);
  }

  function focusLineField(idx, selector, doSelect) {
    if (!tbody) return;
    var tr = tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (!tr) return;
    var el = tr.querySelector(selector);
    if (!el || el.disabled) return;
    if (el.readOnly && el.classList && el.classList.contains('js-item-name')) {
      el = tr.querySelector('.js-new-sale') || el;
    } else if (el.readOnly && el.tagName === 'INPUT' && el.classList.contains('js-item-name')) {
      return;
    }
    try {
      el.focus();
      if (doSelect && typeof el.select === 'function' && el.tagName === 'INPUT') el.select();
    } catch (e) {
      /* ignore */
    }
  }

  function focusElement(el, doSelect) {
    if (!el) return;
    try {
      el.focus();
      if (doSelect && typeof el.select === 'function' && el.tagName === 'INPUT') el.select();
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
    if (codeEl) {
      ln.item_barcode = cleanBarcodeText(codeEl.value);
      ln.item_code = ln.item_barcode;
    }
    if (nameEl) ln.item_name = itemNameOnly({ name_ar: nameEl.value });
    var ns = tr.querySelector('.js-new-sale');
    var nw = tr.querySelector('.js-new-wh');
    if (ns) ln.new_sale_price = ns.value;
    if (nw) ln.new_wholesale = nw.value;
    lines[idx] = ln;
    syncJson();
  }

  function applyItem(idx, it) {
    lines[idx] = lines[idx] || emptyLine();
    lines[idx].item_id = it.id;
    lines[idx].item_barcode = itemBarcodeOnly(it);
    lines[idx].item_code = lines[idx].item_barcode;
    lines[idx].item_name = itemNameOnly(it);
    lines[idx].old_sale_price = Number(it.sale_price != null ? it.sale_price : it.default_sale) || 0;
    lines[idx].old_wholesale =
      Number(it.wholesale_price != null ? it.wholesale_price : it.default_wholesale) || 0;
    if (lines[idx].new_sale_price === '' || lines[idx].new_sale_price == null) {
      lines[idx].new_sale_price = '';
    }
    if (lines[idx].new_wholesale === '' || lines[idx].new_wholesale == null) {
      lines[idx].new_wholesale = '';
    }
    render({ idx: idx, cls: '.js-new-sale', select: true });
    setMsg('تم اختيار المادة · اكتب السعر الجديد', 'ok');
  }

  function pickItemIntoRow(tr, it) {
    var idx = Number(tr.getAttribute('data-idx'));
    fetch('/api/inventory/price-adjust/item/' + it.id, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (d.ok && d.item) applyItem(idx, d.item);
        else
          applyItem(idx, {
            id: it.id,
            barcode: it.barcode,
            code: it.code,
            sku: it.sku,
            name_ar: it.name_ar,
            sale_price: it.sale_price || it.base_sale || 0,
            wholesale_price: it.wholesale_price || it.base_wholesale || 0,
          });
      })
      .catch(function () {
        applyItem(idx, {
          id: it.id,
          barcode: it.barcode,
          code: it.code,
          name_ar: it.name_ar,
          sale_price: it.sale_price || 0,
          wholesale_price: it.wholesale_price || 0,
        });
      });
  }

  /* ── قائمة مواد عالمية (ثابتة على body) ── */
  var gBox = document.getElementById('pa-global-suggest');
  if (!gBox) {
    gBox = document.createElement('div');
    gBox.id = 'pa-global-suggest';
    gBox.className = 'pa-global-suggest';
    gBox.setAttribute('hidden', '');
    document.body.appendChild(gBox);
  }
  var searchCtx = { tr: null, anchor: null, mode: 'barcode', token: 0 };

  function openSuggestForRow() {
    return gBox;
  }

  function getOpenItemSuggest(fromEl) {
    if (!gBox || gBox.hidden || gBox.style.display === 'none') return null;
    if (!gBox.querySelector('button.pa-sug-item')) return null;
    if (fromEl && searchCtx.tr) {
      var tr = fromEl.closest && fromEl.closest('tr[data-idx]');
      if (tr && tr !== searchCtx.tr) return null;
    }
    return gBox;
  }

  function suggestButtons(box) {
    return box ? Array.prototype.slice.call(box.querySelectorAll('button.pa-sug-item')) : [];
  }

  function setSuggestActive(box, idx) {
    var btns = suggestButtons(box);
    if (!btns.length) return;
    if (idx < 0) idx = btns.length - 1;
    if (idx >= btns.length) idx = 0;
    btns.forEach(function (b, i) {
      if (i === idx) {
        b.classList.add('is-active');
        b.style.background = 'rgba(3,105,161,0.12)';
      } else {
        b.classList.remove('is-active');
        b.style.background = 'transparent';
      }
    });
    box.dataset.hxUserNav = '1';
    try {
      btns[idx].scrollIntoView({ block: 'nearest' });
    } catch (e) {
      /* ignore */
    }
  }

  function moveSuggestActive(box, dir) {
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
    setSuggestActive(box, cur + dir);
    return true;
  }

  function pickActiveSuggest(box) {
    if (!box || box.hidden) return false;
    if (box.dataset.hxUserNav !== '1') return false;
    var active = box.querySelector('button.pa-sug-item.is-active');
    if (!active) return false;
    active.click();
    return true;
  }

  function placeFloatSuggest(box, anchor) {
    if (!box || !anchor) return;
    var r = anchor.getBoundingClientRect();
    var mode = searchCtx.mode || 'barcode';
    var minW = mode === 'name' ? 300 : 240;
    var width = Math.max(r.width + 16, minW);
    width = Math.min(Math.max(width, minW), Math.min(mode === 'name' ? 480 : 400, window.innerWidth - 16));
    var left = r.right - width;
    if (left < 8) left = 8;
    if (left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);
    var top = r.bottom + 5;
    var maxH = Math.min(320, window.innerHeight * 0.48);
    if (top + 140 > window.innerHeight && r.top > 180) {
      top = Math.max(8, r.top - 5 - maxH);
    }
    box.removeAttribute('hidden');
    box.hidden = false;
    box.style.cssText =
      'position:fixed;z-index:2147483000;display:block;left:' +
      left +
      'px;top:' +
      top +
      'px;width:' +
      width +
      'px;max-height:' +
      maxH +
      'px;overflow-x:hidden;overflow-y:auto;direction:rtl;text-align:right;' +
      'background:#ffffff;border:1px solid rgba(15,23,42,0.14);border-radius:10px;' +
      'box-shadow:0 14px 44px rgba(15,23,42,0.22);padding:0.28rem;margin:0;box-sizing:border-box;';
  }

  function closeItemSuggest(box) {
    box = box || gBox;
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.style.cssText = 'display:none';
    box.innerHTML = '';
    box.dataset.hxUserNav = '';
    searchCtx.tr = null;
    searchCtx.anchor = null;
  }

  function resolveItemRows(data) {
    if (!data) return [];
    if (Array.isArray(data.rows)) return data.rows;
    if (Array.isArray(data.items)) return data.items;
    if (Array.isArray(data)) return data;
    return [];
  }

  function isSystemItemModalOpen() {
    var m = document.getElementById('hx-lk');
    return !!(m && !m.hidden);
  }

  function searchItems(q, _ignoredBox, tr, anchor) {
    if (!gBox || !tr || !anchor || posted) return;
    // أثناء نافذة F3 لا نفتح قائمة الحقل (تجنّب قائمتين معاً)
    if (isSystemItemModalOpen()) {
      closeItemSuggest(gBox);
      return;
    }
    var token = (searchCtx.token = (searchCtx.token || 0) + 1);
    var mode = anchor.classList && anchor.classList.contains('js-item-name') ? 'name' : 'barcode';
    searchCtx.tr = tr;
    searchCtx.anchor = anchor;
    searchCtx.mode = mode;
    gBox.dataset.hxUserNav = '';
    gBox.innerHTML =
      '<div style="padding:.55rem .75rem;color:#64748b;font-size:.85rem;text-align:right">جاري التحميل…</div>';
    placeFloatSuggest(gBox, anchor);

    var qEnc = encodeURIComponent(q || '');
    var urls = [
      '/api/inventory/price-adjust/items?q=' + qEnc,
      '/api/lookup/items?q=' + qEnc,
      '/api/sales/customer-orders/items?q=' + qEnc,
    ];

    function failAll(msg) {
      if (token !== searchCtx.token) return;
      gBox.innerHTML =
        '<div style="padding:.55rem .75rem;color:#b91c1c;font-size:.85rem;text-align:right">' +
        (msg || 'تعذر تحميل قائمة المواد') +
        '</div>';
      placeFloatSuggest(gBox, anchor);
      setMsg(msg || 'تعذر تحميل قائمة المواد', 'error');
    }

    function tryFetch(i) {
      if (i >= urls.length) {
        failAll('تعذر تحميل قائمة المواد');
        return;
      }
      fetch(urls[i], { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (data) {
          if (token !== searchCtx.token) return;
          if (data && data.ok === false) throw new Error(data.error || 'fail');
          showSuggest(data || {}, gBox, tr, anchor, mode, q);
        })
        .catch(function () {
          if (token !== searchCtx.token) return;
          tryFetch(i + 1);
        });
    }
    tryFetch(0);
  }

  function showSuggest(data, box, tr, anchor, mode, q) {
    if (!box || !tr) return;
    box.innerHTML = '';
    box.dataset.hxUserNav = '';
    var rows = resolveItemRows(data);
    if (!rows.length) {
      box.innerHTML =
        '<div style="padding:.55rem .75rem;color:#64748b;font-size:.85rem;text-align:right">' +
        (q ? 'لا توجد نتائج مطابقة' : mode === 'name' ? 'اكتب اسم المادة…' : 'اكتب الباركود…') +
        '</div>';
      placeFloatSuggest(box, anchor);
      return;
    }
    rows.slice(0, 40).forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pa-sug-item';
      b.tabIndex = -1;
      var code = itemBarcodeOnly(it);
      var nm = itemNameOnly(it);
      if (mode === 'name') {
        b.textContent = nm || code || 'مادة';
        b.title = code ? 'باركود: ' + code : nm;
      } else {
        b.textContent = code || nm || 'مادة';
        b.title = nm || code;
      }
      b.style.cssText =
        'display:block;width:100%;text-align:right;direction:rtl;border:0;background:transparent;' +
        'padding:.48rem .75rem;font:inherit;font-size:.9rem;font-weight:600;cursor:pointer;' +
        'border-radius:7px;color:#0f172a;';
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();
      });
      b.addEventListener('mouseenter', function () {
        box.querySelectorAll('button.pa-sug-item').forEach(function (x) {
          x.classList.remove('is-active');
          x.style.background = 'transparent';
        });
        b.classList.add('is-active');
        b.style.background = 'rgba(3,105,161,0.12)';
        box.dataset.hxUserNav = '1';
      });
      b.addEventListener('click', function () {
        box.dataset.hxUserNav = '1';
        closeItemSuggest(box);
        pickItemIntoRow(tr, it);
      });
      box.appendChild(b);
    });
    placeFloatSuggest(box, anchor);
  }

  var LINE_NAV = ['.js-item-code', '.js-item-name', '.js-new-sale', '.js-new-wh'];

  function lineNavEls(tr) {
    var list = [];
    LINE_NAV.forEach(function (sel) {
      var el = tr.querySelector(sel);
      if (!el || el.disabled || el.readOnly) return;
      list.push(el);
    });
    return list;
  }

  function goNextField(fromEl) {
    if (posted || !fromEl) return;
    var itemSug =
      fromEl.closest && fromEl.closest('tr[data-idx]')
        ? openSuggestForRow(fromEl.closest('tr[data-idx]'))
        : null;
    if (
      itemSug &&
      !itemSug.hidden &&
      itemSug.querySelector('button') &&
      (fromEl.classList.contains('js-item-code') || fromEl.classList.contains('js-item-name'))
    ) {
      if (pickActiveSuggest(itemSug)) return;
      closeItemSuggest(itemSug);
    }

    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (tr && tbody && tbody.contains(tr)) {
      var rowEls = lineNavEls(tr);
      var i = rowEls.indexOf(fromEl);
      if (i >= 0 && i < rowEls.length - 1) {
        focusElement(rowEls[i + 1], true);
        return;
      }
      var idx = Number(tr.getAttribute('data-idx'));
      if (!lineHasItem(lines[idx])) {
        focusLineField(idx, '.js-item-code', true);
        setMsg('اختر المادة أولاً قبل الانتقال لسطر جديد.', 'error');
        return;
      }
      var nextIdx = idx + 1;
      if (nextIdx < lines.length) {
        focusLineField(nextIdx, '.js-item-code', true);
      } else if (addEmptyLine()) {
        focusLineField(lines.length - 1, '.js-item-code', true);
      }
    }
  }

  function goPrevField(fromEl) {
    if (posted || !fromEl) return;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (!tr || !tbody.contains(tr)) return;
    var rowEls = lineNavEls(tr);
    var i = rowEls.indexOf(fromEl);
    if (i > 0) {
      focusElement(rowEls[i - 1], true);
      return;
    }
    var idx = Number(tr.getAttribute('data-idx'));
    if (idx > 0) {
      var prevTr = tbody.querySelector('tr[data-idx="' + (idx - 1) + '"]');
      var prevEls = prevTr ? lineNavEls(prevTr) : [];
      if (prevEls.length) focusElement(prevEls[prevEls.length - 1], true);
    }
  }

  function goVerticalField(fromEl, dir) {
    if (posted || !fromEl || !tbody) return;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (!tr || !tbody.contains(tr)) return;
    var idx = Number(tr.getAttribute('data-idx'));
    var nextIdx = idx + (dir > 0 ? 1 : -1);
    if (nextIdx < 0) return;
    if (nextIdx >= lines.length) {
      if (dir > 0) {
        if (!lineHasItem(lines[idx])) {
          setMsg('اختر المادة أولاً قبل إضافة سطر جديد.', 'error');
          return;
        }
        if (!addEmptyLine()) return;
        nextIdx = lines.length - 1;
      } else return;
    }
    var cls = null;
    LINE_NAV.forEach(function (sel) {
      if (fromEl.matches && fromEl.matches(sel)) cls = sel;
    });
    focusLineField(nextIdx, cls || '.js-item-code', true);
  }

  function bindRow(tr) {
    if (posted) return;
    ['js-new-sale', 'js-new-wh'].forEach(function (cls) {
      var el = tr.querySelector('.' + cls);
      if (el)
        el.addEventListener('input', function () {
          readRow(tr);
        });
    });
    var codeInput = tr.querySelector('.js-item-code');
    var nameInput = tr.querySelector('.js-item-name');
    if (codeInput) {
      codeInput.addEventListener('input', function () {
        fitFieldToContent(codeInput, { min: 100, max: 240, pad: 24 });
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (nameInput) {
          nameInput.value = '';
          nameInput.readOnly = false;
        }
        if (lines[idx]) {
          lines[idx].item_id = 0;
          lines[idx].item_name = '';
          lines[idx].item_code = cleanBarcodeText(codeInput.value);
          lines[idx].item_barcode = lines[idx].item_code;
          lines[idx].old_sale_price = 0;
          lines[idx].old_wholesale = 0;
        }
        var oldS = tr.querySelector('.js-old-sale');
        var oldW = tr.querySelector('.js-old-wh');
        if (oldS) oldS.textContent = fmt(0);
        if (oldW) oldW.textContent = fmt(0);
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(codeInput.value, gBox, tr, codeInput);
        }, 160);
        syncJson();
      });
      codeInput.addEventListener('focus', function () {
        if (isSystemItemModalOpen()) return;
        searchItems(codeInput.value || '', gBox, tr, codeInput);
      });
      codeInput.addEventListener('click', function () {
        if (isSystemItemModalOpen()) return;
        if (gBox && !gBox.hidden && searchCtx.tr === tr) placeFloatSuggest(gBox, codeInput);
        else searchItems(codeInput.value || '', gBox, tr, codeInput);
      });
    }
    if (nameInput) {
      nameInput.addEventListener('input', function () {
        if (nameInput.readOnly) return;
        fitFieldToContent(nameInput, { min: 140, max: 520, pad: 28 });
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (lines[idx]) {
          lines[idx].item_id = 0;
          lines[idx].item_name = nameInput.value;
        }
        clearTimeout(itemTimers['n' + idx]);
        itemTimers['n' + idx] = setTimeout(function () {
          searchItems(nameInput.value, gBox, tr, nameInput);
        }, 160);
        syncJson();
      });
      nameInput.addEventListener('focus', function () {
        if (nameInput.readOnly) return;
        if (isSystemItemModalOpen()) return;
        searchItems(nameInput.value || '', gBox, tr, nameInput);
      });
      nameInput.addEventListener('click', function () {
        if (nameInput.readOnly) return;
        if (isSystemItemModalOpen()) return;
        if (gBox && !gBox.hidden && searchCtx.tr === tr) placeFloatSuggest(gBox, nameInput);
        else searchItems(nameInput.value || '', gBox, tr, nameInput);
      });
    }
    var del = tr.querySelector('.js-del');
    if (del) {
      del.addEventListener('click', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        closeItemSuggest(gBox);
        lines.splice(idx, 1);
        if (!lines.length) lines.push(emptyLine());
        render();
        setMsg('تم حذف السطر.', 'ok');
      });
    }
    fitRowFields(tr);
  }

  document.addEventListener('mousedown', function (e) {
    if (!document.getElementById('pa-root') || !gBox || gBox.hidden) return;
    if (gBox.contains(e.target)) return;
    var t = e.target;
    if (t && t.classList && (t.classList.contains('js-item-code') || t.classList.contains('js-item-name'))) {
      return;
    }
    window.setTimeout(function () {
      if (gBox.hidden) return;
      var ae = document.activeElement;
      if (ae && ae.classList && (ae.classList.contains('js-item-code') || ae.classList.contains('js-item-name'))) {
        return;
      }
      if (gBox.contains(ae)) return;
      closeItemSuggest(gBox);
    }, 20);
  });

  window.addEventListener(
    'scroll',
    function () {
      if (!document.getElementById('pa-root') || !gBox || gBox.hidden) return;
      if (searchCtx.anchor && document.contains(searchCtx.anchor)) {
        placeFloatSuggest(gBox, searchCtx.anchor);
      }
    },
    true
  );

  if (!document._paFieldNavBound) {
    document._paFieldNavBound = true;
    document.addEventListener(
      'keydown',
      function (e) {
        if (!document.getElementById('pa-root')) return;
        if (posted) return;
        var t = e.target;
        if (!t || !t.closest) return;
        if (!t.closest('#pa-root')) return;
        if (t.tagName === 'TEXTAREA') return;

        var openSug = getOpenItemSuggest(t);

        if (e.key === 'Escape' && openSug) {
          e.preventDefault();
          closeItemSuggest(openSug);
          return;
        }
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
          if (t.tagName === 'BUTTON') return;
          e.preventDefault();
          goNextField(t);
          return;
        }
        if (e.key === 'ArrowDown' && !e.altKey && !e.ctrlKey) {
          if (openSug && moveSuggestActive(openSug, 1)) {
            e.preventDefault();
            return;
          }
          if (t.closest && t.closest('tr[data-idx]')) {
            e.preventDefault();
            goVerticalField(t, 1);
          }
          return;
        }
        if (e.key === 'ArrowUp' && !e.altKey && !e.ctrlKey) {
          if (openSug && moveSuggestActive(openSug, -1)) {
            e.preventDefault();
            return;
          }
          if (t.closest && t.closest('tr[data-idx]')) {
            e.preventDefault();
            goVerticalField(t, -1);
          }
          return;
        }
        if (e.key === 'ArrowLeft' && !e.altKey && !e.ctrlKey && t.closest('tr[data-idx]')) {
          if (
            (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') &&
            t.type !== 'number' &&
            typeof t.selectionStart === 'number' &&
            t.selectionStart > 0 &&
            t.selectionStart === t.selectionEnd
          ) {
            return;
          }
          e.preventDefault();
          goPrevField(t);
          return;
        }
        if (e.key === 'ArrowRight' && !e.altKey && !e.ctrlKey && t.closest('tr[data-idx]')) {
          if (
            (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') &&
            t.type !== 'number' &&
            typeof t.selectionStart === 'number' &&
            t.selectionEnd < String(t.value || '').length &&
            t.selectionStart === t.selectionEnd
          ) {
            return;
          }
          e.preventDefault();
          goNextField(t);
        }
      },
      true
    );
  }

  var addBtn = document.getElementById('pa-add-line');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      if (addEmptyLine()) {
        focusLineField(lines.length - 1, '.js-item-code', true);
      }
    });
  }

  document.addEventListener('hx:add-line', function (e) {
    if (!document.getElementById('pa-root') || posted) return;
    e.preventDefault();
    if (addEmptyLine()) focusLineField(lines.length - 1, '.js-item-code', true);
  });

  document.addEventListener('hx:item-picked', function (e) {
    if (!document.getElementById('pa-root') || posted) return;
    var it = e.detail;
    if (!it || !it.id) return;
    e.preventDefault();
    var idx = -1;
    for (var i = 0; i < lines.length; i++) {
      if (!lineHasItem(lines[i])) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      if (!addEmptyLine()) return;
      idx = lines.length - 1;
    }
    var tr = tbody && tbody.querySelector('tr[data-idx="' + idx + '"]');
    if (tr) pickItemIntoRow(tr, it);
    else applyItem(idx, it);
  });

  if (form) {
    form.addEventListener('submit', function () {
      syncFromDom();
      syncJson();
    });
  }

  // رقم الحركة: أخضر بعد حفظ / أحمر بعد ترحيل
  var noEl = document.getElementById('pa_no');
  if (noEl) {
    noEl.classList.remove('is-saved', 'is-approved');
    if (posted) noEl.classList.add('is-approved');
    else if (root.getAttribute('data-has-id') === '1') noEl.classList.add('is-saved');
  }

  render();
})();
