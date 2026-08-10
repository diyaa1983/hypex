(function () {
  'use strict';

  var root = document.getElementById('si-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var posted = !!state.is_posted;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var taxRates = Array.isArray(state.defaults && state.defaults.tax_rates)
    ? state.defaults.tax_rates
    : [];
  var msgEl = document.getElementById('si-msg');
  var tbody = document.getElementById('si-lines-body');
  var custTimer = null;
  var itemTimers = {};
  var busy = false;

  function rateClose(a, b) {
    return Math.abs(Number(a) - Number(b)) < 0.0001;
  }

  /** نص خيار الضريبة: 16% فقط، أو 0% — معفى إن كان الاسم وصفياً */
  function taxRateLabel(t, rate) {
    var pct =
      rate.toLocaleString('en-US', { maximumFractionDigits: 3 }) + '%';
    var name = String((t && t.name_ar) || '').trim();
    if (!name) return pct;
    var nameBare = name.replace(/%/g, '').replace(/,/g, '').replace(/\s+/g, '').trim();
    if (nameBare === '' || rateClose(nameBare, rate)) return pct;
    if (nameBare === pct.replace(/%/g, '').replace(/,/g, '').replace(/\s+/g, '')) return pct;
    return pct + ' — ' + name;
  }

  /** قائمة الضريبة من الإعدادات (sys_tax_rate) */
  function taxSelectHtml(selected, disabled) {
    var cur = Number(selected != null && selected !== '' ? selected : defaultTax);
    if (!Number.isFinite(cur)) cur = defaultTax;
    var opts = '';
    var found = false;
    for (var i = 0; i < taxRates.length; i++) {
      var t = taxRates[i];
      var rate = Number(t.rate_percent);
      if (!Number.isFinite(rate)) continue;
      var sel = rateClose(rate, cur);
      if (sel) found = true;
      var label = taxRateLabel(t, rate);
      opts +=
        '<option value="' +
        escAttr(String(rate)) +
        '"' +
        (sel ? ' selected' : '') +
        '>' +
        escAttr(label) +
        '</option>';
    }
    if (!opts || !found) {
      opts =
        '<option value="' +
        escAttr(String(cur)) +
        '" selected>' +
        escAttr(cur + '%') +
        '</option>' +
        opts;
    }
    return (
      '<select class="js-tax"' +
      (disabled ? ' disabled' : '') +
      ' title="نسبة الضريبة">' +
      opts +
      '</select>'
    );
  }

  function r3(n) {
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }

  /** سعر أقل وحدة × معامل وحدة الصرف (غير شامل ضريبة) */
  function unitSalePrice(baseSale, factor) {
    var f = Number(factor) > 0 ? Number(factor) : 1;
    return r3((Number(baseSale) || 0) * f);
  }

  function defaultUnitOf(it) {
    var units = (it && it.units) || [];
    if (!units.length) {
      return {
        unit_id: 0,
        name: 'قطعة',
        factor: 1,
        sale_price: Number(it && it.sale_price) || 0,
      };
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

  function applyItemToLine(ln, it) {
    ln = ln || {};
    ln.item_id = it.id;
    ln.item_code = it.code || it.sku || '';
    ln.name_ar = it.name_ar || '';
    ln.base_sale = Number(it.base_sale != null ? it.base_sale : it.sale_price) || 0;
    ln.units = Array.isArray(it.units) ? it.units : [];
    var du = defaultUnitOf(it);
    ln.unit_id = du.unit_id || 0;
    ln.unit_name = du.name || 'قطعة';
    ln.unit_factor = Number(du.factor) > 0 ? Number(du.factor) : 1;
    ln.unit_price = unitSalePrice(ln.base_sale, ln.unit_factor);
    if (!ln.qty) ln.qty = 1;
    if (it.tax_rate_percent != null && it.tax_rate_percent !== '') {
      ln.tax_rate_percent = Number(it.tax_rate_percent);
    } else if (ln.tax_rate_percent == null) {
      ln.tax_rate_percent = defaultTax;
    }
    return ln;
  }

  function unitSelectHtml(ln, disabled) {
    var units = Array.isArray(ln.units) && ln.units.length
      ? ln.units
      : [
          {
            unit_id: ln.unit_id || 0,
            name: ln.unit_name || 'قطعة',
            factor: ln.unit_factor || 1,
          },
        ];
    var curId = Number(ln.unit_id) || 0;
    var opts = units
      .map(function (u) {
        var uid = Number(u.unit_id) || 0;
        var fac = Number(u.factor) > 0 ? Number(u.factor) : 1;
        var label = (u.name || 'وحدة') + (fac > 1 ? ' × ' + fac : '');
        var sel = curId ? uid === curId : Number(ln.unit_factor || 1) === fac;
        return (
          '<option value="' +
          uid +
          '" data-factor="' +
          fac +
          '" data-name="' +
          escAttr(u.name || '') +
          '"' +
          (sel ? ' selected' : '') +
          '>' +
          escAttr(label) +
          '</option>'
        );
      })
      .join('');
    return (
      '<select class="js-unit" title="وحدة الصرف"' +
      (disabled || !ln.item_id ? ' disabled' : '') +
      '>' +
      opts +
      '</select>'
    );
  }

  function fmt(n) {
    return r3(n).toLocaleString('en-US', {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    });
  }

  function setMsg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
    if (text && window.HypexUI && typeof window.HypexUI.toast === 'function') {
      if (type === 'error' || type === 'ok') {
        window.HypexUI.toast(text, type === 'error' ? 'error' : 'ok', type === 'error' ? 4200 : 2800);
      }
    }
  }

  function lineTotals(ln) {
    var qty = Number(ln.qty) || 0;
    var price = Number(ln.unit_price) || 0;
    var discPct = Number(ln.discount_pct) || 0;
    var taxRate = Number(ln.tax_rate_percent) || 0;
    var sub = qty * price;
    var disc = discPct > 0 ? r3((sub * discPct) / 100) : 0;
    sub = r3(sub - disc);
    var tax = r3((sub * taxRate) / 100);
    return { sub: sub, tax: tax, gross: r3(sub + tax), disc: disc };
  }

  function headerDiscountAmount(sumSub, raw) {
    raw = String(raw || '').trim();
    if (!raw || sumSub <= 0) return 0;
    var d = 0;
    if (raw.endsWith('%')) {
      d = r3((sumSub * (parseFloat(raw) || 0)) / 100);
    } else if (raw.indexOf('.') === -1 && Number(raw) >= 1 && Number(raw) <= 100) {
      d = r3((sumSub * Number(raw)) / 100);
    } else {
      d = r3(parseFloat(raw) || 0);
    }
    return Math.min(d, sumSub);
  }

  function recomputeFooter() {
    var sumSub = 0;
    var sumTax = 0;
    var sumGross = 0;
    (state.lines || []).forEach(function (ln) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      sumSub += t.sub;
      sumTax += t.tax;
      sumGross += t.gross;
    });
    sumSub = r3(sumSub);
    sumTax = r3(sumTax);
    sumGross = r3(sumGross);
    var discInput = document.getElementById('inv_discount');
    var hDisc = headerDiscountAmount(sumSub, discInput ? discInput.value : '');
    if (hDisc > 0 && sumSub > 0) {
      var ratio = (sumSub - hDisc) / sumSub;
      sumTax = r3(sumTax * ratio);
      sumSub = r3(sumSub - hDisc);
      sumGross = r3(sumSub + sumTax);
    }
    var elSub = document.getElementById('sum_sub');
    var elTax = document.getElementById('sum_tax');
    var elGrand = document.getElementById('sum_grand');
    if (elSub) elSub.textContent = fmt(sumSub);
    if (elTax) elTax.textContent = fmt(sumTax);
    if (elGrand) elGrand.textContent = fmt(sumGross);
  }

  function readLineFromRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = state.lines[idx] || {};
    ln.qty = tr.querySelector('.js-qty').value;
    ln.qty_extra = tr.querySelector('.js-qty-extra').value;
    var unitSel = tr.querySelector('.js-unit');
    if (unitSel && unitSel.selectedOptions && unitSel.selectedOptions[0]) {
      var opt = unitSel.selectedOptions[0];
      ln.unit_id = Number(unitSel.value) || 0;
      ln.unit_factor = Number(opt.getAttribute('data-factor')) || 1;
      ln.unit_name = opt.getAttribute('data-name') || opt.textContent || '';
    }
    var baseEl = tr.querySelector('.js-base-sale');
    if (baseEl && baseEl.value !== '') ln.base_sale = Number(baseEl.value) || 0;
    // السعر محسوب دائماً — لا يُقرأ يدوياً
    if (ln.base_sale != null && Number(ln.base_sale) >= 0 && ln.unit_factor) {
      ln.unit_price = unitSalePrice(ln.base_sale, ln.unit_factor);
      var priceEl = tr.querySelector('.js-price');
      if (priceEl) priceEl.value = String(ln.unit_price);
    } else {
      ln.unit_price = tr.querySelector('.js-price') ? tr.querySelector('.js-price').value : ln.unit_price;
    }
    ln.discount_pct = tr.querySelector('.js-disc').value;
    ln.tax_rate_percent = tr.querySelector('.js-tax').value;
    var hid = tr.querySelector('.js-item-id');
    if (hid && hid.value) ln.item_id = Number(hid.value) || 0;
    state.lines[idx] = ln;
    var t = lineTotals(ln);
    tr.querySelector('.js-sub').textContent = fmt(t.sub);
    tr.querySelector('.js-gross').textContent = fmt(t.gross);
    recomputeFooter();
  }

  function syncLinesFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      readLineFromRow(tr);
    });
  }

  function buildPayload() {
    syncLinesFromDom();
    return {
      id: state.id || 0,
      invoice_no: (document.getElementById('inv_no') || {}).value || '',
      invoice_date: (document.getElementById('inv_date') || {}).value || '',
      customer_id: Number((document.getElementById('inv_customer_id') || {}).value || 0),
      payment_type: (document.getElementById('inv_pay') || {}).value || 'credit',
      warehouse_id: Number((document.getElementById('inv_wh') || {}).value || 0) || null,
      notes: (document.getElementById('inv_notes') || {}).value || '',
      invoice_discount: (document.getElementById('inv_discount') || {}).value || '',
      lines: (state.lines || []).filter(function (ln) {
        return ln && ln.item_id;
      }),
    };
  }

  function validatePayload(payload, opts) {
    opts = opts || {};
    if (!payload.customer_id) {
      setMsg('اختر العميل.', 'error');
      return false;
    }
    // السماح بحفظ بدون بنود لفاتورة مسجّلة (تفريغ البنود) — ليس للفواتير الجديدة
    if (!payload.lines.length && !(opts.allowEmptyLines && payload.id > 0)) {
      setMsg('أضف بنداً واحداً على الأقل.', 'error');
      return false;
    }
    for (var i = 0; i < (payload.lines || []).length; i++) {
      var ln = payload.lines[i];
      if (!ln || !ln.item_id) continue;
      if (!(Number(ln.unit_price) > 0)) {
        setMsg('سعر المادة في البطاقة صفر. حدّد سعر البيع من شاشة تعديل الأسعار.', 'error');
        return false;
      }
    }
    return true;
  }

  function setBtn(id, enabled) {
    var el = document.getElementById(id);
    if (!el) return;
    el.disabled = !enabled;
  }

  /** تفعيل/تعطيل أزرار الشريط حسب الحالة الحالية (بعد الحفظ دون reload) */
  function applyToolbarState() {
    var hasId = !!(Number(state.id) > 0);
    var bar = document.getElementById('si-doc-bar');
    var c = state.caps || {};

    function allowFlag(capKey, dataName, defaultOn) {
      if (c[capKey] != null) return !!c[capKey];
      if (bar && bar.hasAttribute('data-allow-' + dataName)) {
        return bar.getAttribute('data-allow-' + dataName) === '1';
      }
      return defaultOn !== false;
    }

    var allowPost = allowFlag('allowPost', 'post', true);
    var allowUnpost = allowFlag('allowUnpost', 'unpost', true);
    var allowDelete = allowFlag('allowDelete', 'delete', true);
    var allowArchive = allowFlag('allowArchive', 'archive', true);
    var allowEinvoice = allowFlag('allowEinvoice', 'einvoice', true);

    setBtn('si-save', !posted);
    setBtn('si-post', !posted && (hasId ? allowPost : true));
    setBtn('si-unpost', posted && allowUnpost);
    setBtn('si-delete', hasId && !posted && allowDelete);
    setBtn('si-print', hasId);
    setBtn('si-pdf', hasId);
    setBtn('si-excel', hasId);
    setBtn('si-email', hasId);
    setBtn('si-archive', hasId && allowArchive);
    setBtn('si-einvoice', posted && allowEinvoice);

    if (bar) {
      bar.setAttribute('data-invoice-id', String(state.id || 0));
      bar.setAttribute('data-posted', posted ? '1' : '0');
    }

    state.caps = Object.assign({}, c, {
      canSave: !posted,
      canPost: !posted && hasId && allowPost,
      canUnpost: posted && allowUnpost,
      canDelete: hasId && !posted && allowDelete,
      canPrint: hasId,
      canPdf: hasId,
      canExcel: hasId,
      canEmail: hasId,
      canArchive: hasId && allowArchive,
      canEinvoice: posted && allowEinvoice,
      allowPost: allowPost,
      allowUnpost: allowUnpost,
      allowDelete: allowDelete,
      allowArchive: allowArchive,
      allowEinvoice: allowEinvoice,
    });
  }

  function setBusy(on) {
    busy = !!on;
    ['si-save', 'si-post', 'si-unpost', 'si-delete', 'si-einvoice', 'si-add-line', 'si-print', 'si-pdf', 'si-excel', 'si-email', 'si-archive'].forEach(
      function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (on) {
          if (el.dataset.hxBusyPrev == null) {
            el.dataset.hxBusyPrev = el.disabled ? '1' : '0';
          }
          el.disabled = true;
        } else {
          delete el.dataset.hxBusyPrev;
        }
      }
    );
    // بعد انتهاء العملية أعد الحالة الصحيحة (طباعة/حذف… حسب وجود id)
    if (!on) applyToolbarState();
  }

  function renderLines() {
    if (!tbody) return;
    tbody.innerHTML = '';
    (state.lines || []).forEach(function (ln, idx) {
      var t = lineTotals(ln);
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input type="hidden" class="js-base-sale" value="' +
        escAttr(ln.base_sale != null ? ln.base_sale : '') +
        '">' +
        '<input class="js-item" type="search" placeholder="باركود / اسم" value="' +
        escAttr((ln.item_code ? ln.item_code + ' — ' : '') + (ln.name_ar || '')) +
        '" ' +
        (posted ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td>' +
        unitSelectHtml(ln, posted) +
        '</td>' +
        '<td><input class="js-qty" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty_extra || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-price" type="number" step="0.001" min="0" value="' +
        escAttr(ln.unit_price) +
        '" readonly title="من بطاقة المادة (أقل وحدة × التعبئة) — غير قابل للتعديل">' +
        '</td>' +
        '<td><input class="js-disc" type="number" step="0.001" min="0" max="100" value="' +
        escAttr(ln.discount_pct || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td>' +
        taxSelectHtml(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax, posted) +
        '</td>' +
        '<td class="js-sub si-num-out" dir="ltr">' +
        fmt(t.sub) +
        '</td>' +
        '<td class="js-gross si-num-out" dir="ltr">' +
        fmt(t.gross) +
        '</td>' +
        '<td>' +
        (posted ? '' : '<button type="button" class="si-del js-del" title="حذف">×</button>') +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    recomputeFooter();
  }

  function escAttr(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function placeFloatSuggest(box, anchor) {
    if (!box || !anchor) return;
    var r = anchor.getBoundingClientRect();
    var width = Math.max(r.width, 280);
    var left = r.left;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }
    box.classList.add('si-suggest--float');
    box.style.width = width + 'px';
    box.style.left = left + 'px';
    box.style.top = r.bottom + 3 + 'px';
    box.style.right = 'auto';
  }

  function closeFloatSuggest(box) {
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.classList.remove('si-suggest--float');
    box.style.left = '';
    box.style.top = '';
    box.style.width = '';
    var cell = box.closest('.si-item-cell');
    if (cell) cell.classList.remove('is-open');
  }

  function removeLineAt(idx) {
    if (posted) return;
    idx = Number(idx);
    if (!Number.isFinite(idx) || idx < 0) return;
    syncLinesFromDom();
    if (!state.lines || !state.lines[idx]) return;
    state.lines.splice(idx, 1);
    if (!state.lines.length) addEmptyLine();
    else renderLines();
    // حذف البند دون رسالة — تثبيت صامت في القاعدة إن وُجدت فاتورة
    if (state.id) {
      saveInvoice(null, { allowEmptyLines: true, silent: true });
    }
  }

  function bindRow(tr) {
    ['js-qty', 'js-qty-extra', 'js-disc', 'js-tax'].forEach(function (cls) {
      var el = tr.querySelector('.' + cls);
      if (!el) return;
      var ev = el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, function () {
        readLineFromRow(tr);
      });
    });
    var unitEl = tr.querySelector('.js-unit');
    if (unitEl) {
      unitEl.addEventListener('change', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        var ln = state.lines[idx] || {};
        var opt = unitEl.selectedOptions && unitEl.selectedOptions[0];
        if (opt) {
          ln.unit_id = Number(unitEl.value) || 0;
          ln.unit_factor = Number(opt.getAttribute('data-factor')) || 1;
          ln.unit_name = opt.getAttribute('data-name') || '';
          ln.unit_price = unitSalePrice(ln.base_sale, ln.unit_factor);
          state.lines[idx] = ln;
          var priceEl = tr.querySelector('.js-price');
          if (priceEl) priceEl.value = String(ln.unit_price);
        }
        readLineFromRow(tr);
      });
    }
    // حذف البند — التفويض العام على tbody أدناه
    var itemInput = tr.querySelector('.js-item');
    var suggest = tr.querySelector('.js-item-suggest');
    if (itemInput && suggest && !posted) {
      itemInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        // مسح اختيار المادة عند التعديل اليدوي
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(itemInput.value, suggest, tr, itemInput);
        }, 200);
      });
      itemInput.addEventListener('focus', function () {
        searchItems(itemInput.value || '', suggest, tr, itemInput);
      });
      itemInput.addEventListener('click', function () {
        searchItems(itemInput.value || '', suggest, tr, itemInput);
      });
    }
  }

  function searchItems(q, box, tr, anchor) {
    var urls = [
      '/api/items?q=' + encodeURIComponent(q || ''),
      '/api/lookup/items?q=' + encodeURIComponent(q || ''),
    ];
    function tryFetch(i) {
      if (i >= urls.length) {
        showItemSuggest(box, anchor, tr, {
          ok: false,
          error: 'تعذر تحميل المواد',
        });
        return;
      }
      fetch(urls[i], {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (data) {
          showItemSuggest(box, anchor, tr, data || { ok: false });
        })
        .catch(function () {
          tryFetch(i + 1);
        });
    }
    tryFetch(0);
  }

  function showItemSuggest(box, anchor, tr, data) {
    if (!box) return;
    box.innerHTML = '';
    var cell = box.closest('.si-item-cell');
    if (cell) cell.classList.add('is-open');

    if (!data || !data.ok) {
      var err = document.createElement('div');
      err.className = 'si-suggest-empty';
      err.style.cssText = 'padding:.65rem .8rem;color:#b91c1c;font-size:.85rem';
      err.textContent = (data && data.error) || 'تعذر تحميل المواد';
      box.appendChild(err);
      box.hidden = false;
      box.removeAttribute('hidden');
      placeFloatSuggest(box, anchor || tr.querySelector('.js-item'));
      return;
    }

    var rows = data.rows || [];
    if (!rows.length) {
      var empty = document.createElement('div');
      empty.className = 'si-suggest-empty';
      empty.style.cssText = 'padding:.65rem .8rem;color:#64748b;font-size:.85rem';
      empty.textContent = 'لا توجد مواد. أضف أصنافاً من المخزون → المواد والأصناف.';
      box.appendChild(empty);
      box.hidden = false;
      box.removeAttribute('hidden');
      placeFloatSuggest(box, anchor || tr.querySelector('.js-item'));
      return;
    }

    rows.slice(0, 40).forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent =
        (it.code || it.sku || '') +
        ' — ' +
        (it.name_ar || '') +
        ' · ' +
        fmt(it.sale_price);
      b.addEventListener('mousedown', function (e) {
        // قبل blur حتى لا تُغلق القائمة قبل الاختيار
        e.preventDefault();
      });
      b.addEventListener('click', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
        closeFloatSuggest(box);
        renderLines();
      });
      box.appendChild(b);
    });
    box.hidden = false;
    box.removeAttribute('hidden');
    placeFloatSuggest(box, anchor || tr.querySelector('.js-item'));
  }

  function addEmptyLine() {
    state.lines = state.lines || [];
    state.lines.push({
      item_id: 0,
      item_code: '',
      name_ar: '',
      qty: 1,
      qty_extra: 0,
      unit_price: 0,
      base_sale: 0,
      unit_id: 0,
      unit_name: 'قطعة',
      unit_factor: 1,
      units: [],
      discount_pct: 0,
      tax_rate_percent: defaultTax,
    });
    renderLines();
  }

  document.addEventListener('hx:item-picked', function (e) {
    if (posted || !document.getElementById('si-lines-body')) return;
    var it = e.detail;
    if (!it || !it.id) return;
    e.preventDefault();
    var idx = -1;
    for (var i = 0; i < (state.lines || []).length; i++) {
      if (!state.lines[i] || !state.lines[i].item_id) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      addEmptyLine();
      idx = state.lines.length - 1;
    }
    state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
    renderLines();
    setTimeout(function () {
      var tr = tbody && tbody.querySelector('tr[data-idx="' + idx + '"]');
      var q = tr && tr.querySelector('.js-qty');
      if (q) q.focus();
    }, 40);
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (posted || !document.getElementById('inv_customer')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    if (custInput) {
      custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
    }
    if (custId) custId.value = c.id;
    if (custBox) {
      custBox.hidden = true;
      custBox.setAttribute('hidden', '');
    }
    e.preventDefault();
  });

  document.addEventListener('hx:add-line', function (e) {
    if (posted) return;
    if (!document.getElementById('si-lines-body')) return;
    e.preventDefault();
    addEmptyLine();
  });

  document.addEventListener('hx:save', function (e) {
    if (!document.getElementById('si-save')) return;
    e.preventDefault();
    saveInvoice();
  });

  // customer search
  var custInput = document.getElementById('inv_customer');
  var custId = document.getElementById('inv_customer_id');
  var custBox = document.getElementById('cust_suggest');

  function renderCustomerSuggestions(data) {
    if (!custBox) return;
    custBox.innerHTML = '';
    var rows = (data && data.rows) || [];
    if (!data || !data.ok) {
      var err = document.createElement('div');
      err.className = 'si-suggest-empty';
      err.textContent = (data && data.error) || 'تعذر تحميل العملاء';
      err.style.cssText = 'padding:.65rem .8rem;color:#b91c1c;font-size:.85rem';
      custBox.appendChild(err);
      custBox.hidden = false;
      return;
    }
    if (!rows.length) {
      var empty = document.createElement('div');
      empty.className = 'si-suggest-empty';
      empty.textContent = 'لا يوجد عملاء مطابقون. أضف عميلاً من قائمة العملاء.';
      empty.style.cssText = 'padding:.65rem .8rem;color:#64748b;font-size:.85rem';
      custBox.appendChild(empty);
      custBox.hidden = false;
      return;
    }
    rows.slice(0, 25).forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = (c.code || '') + ' — ' + (c.name_ar || '');
      b.addEventListener('click', function () {
        if (custId) custId.value = c.id;
        custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
        custBox.hidden = true;
      });
      custBox.appendChild(b);
    });
    custBox.hidden = false;
  }

  function searchCustomers(q) {
    return fetch('/api/customers?q=' + encodeURIComponent(q || ''), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (data) {
        renderCustomerSuggestions(data);
        if (custBox) {
          custBox.hidden = false;
          custBox.removeAttribute('hidden');
        }
      })
      .catch(function () {
        renderCustomerSuggestions({ ok: false, error: 'تعذر الاتصال بخدمة العملاء' });
        if (custBox) {
          custBox.hidden = false;
          custBox.removeAttribute('hidden');
        }
      });
  }

  if (custInput && custBox && !posted) {
    custInput.addEventListener('focus', function () {
      searchCustomers(custInput.value || '');
    });
    custInput.addEventListener('click', function () {
      if (custBox.hidden) searchCustomers(custInput.value || '');
    });
    custInput.addEventListener('input', function () {
      if (custId) custId.value = '';
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        searchCustomers(custInput.value || '');
      }, 220);
    });
    document.addEventListener('click', function (e) {
      if (!custBox.contains(e.target) && e.target !== custInput) {
        custBox.hidden = true;
        custBox.setAttribute('hidden', '');
      }
    });
  }

  document.addEventListener('click', function (e) {
    document.querySelectorAll('.js-item-suggest').forEach(function (box) {
      var cell = box.closest('.si-item-cell') || box.parentElement;
      var inp = cell ? cell.querySelector('.js-item') : null;
      if (!box.contains(e.target) && e.target !== inp && !(cell && cell.contains(e.target))) {
        closeFloatSuggest(box);
      }
    });
  });
  window.addEventListener(
    'scroll',
    function () {
      document.querySelectorAll('.js-item-suggest:not([hidden])').forEach(function (box) {
        var cell = box.closest('.si-item-cell');
        var inp = cell && cell.querySelector('.js-item');
        if (inp && !box.hidden) placeFloatSuggest(box, inp);
      });
    },
    true
  );

  var disc = document.getElementById('inv_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  function saveInvoice(then, opts) {
    opts = opts || {};
    if (posted) {
      if (!opts.silent) setMsg('الفاتورة مرحّلة — الحفظ غير متاح.', 'error');
      return Promise.resolve(null);
    }
    var payload = buildPayload();
    if (!validatePayload(payload, opts)) return Promise.resolve(null);
    if (!opts.silent) setMsg('جاري الحفظ…');
    setBusy(true);
    return fetch('/api/sales/invoices', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          setBusy(false);
          setMsg(data.error || 'تعذر الحفظ', 'error');
          return null;
        }
        // حدّث id قبل فك القفل حتى applyToolbarState تُفعّل الطباعة فوراً
        state.id = data.id;
        if (data.invoice_no) {
          var noEl = document.getElementById('inv_no');
          if (noEl) noEl.value = data.invoice_no;
          state.invoice_no = data.invoice_no;
        }
        setBusy(false);
        if (!opts.silent) {
          setMsg(data.message || 'تم الحفظ بدون قيود محاسبية · ' + (data.invoice_no || ''), 'ok');
        } else if (msgEl) {
          msgEl.textContent = '';
          msgEl.className = 'si-msg';
        }
        // عنوان الصفحة + الرابط
        if (data.invoice_no) {
          try {
            document.title = document.title.replace(
              /^فاتورة مبيعات جديدة|^فاتورة\s+\S+/,
              'فاتورة ' + data.invoice_no
            );
            var h1 = document.querySelector('.si-hero h1');
            if (h1) h1.textContent = 'فاتورة ' + data.invoice_no;
          } catch (e) {
            /* ignore */
          }
        }
        if (typeof then === 'function') return then(data);
        // فاتورة جديدة: حدّث الرابط دون إعادة تحميل كاملة (لتبقى التعديلات متاحة فوراً)
        if (data.id && /\/sales\/invoices\/new\/?$/.test(location.pathname)) {
          try {
            history.replaceState({}, '', '/sales/invoices/' + data.id);
          } catch (e) {
            /* ignore */
          }
        }
        return data;
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
        return null;
      });
  }

  function postInvoice() {
    if (posted) {
      setMsg('الفاتورة مرحّلة مسبقاً.', 'error');
      return;
    }
    var payload = buildPayload();
    if (!validatePayload(payload)) return;
    var ask =
      window.HypexUI && window.HypexUI.confirm
        ? window.HypexUI.confirm(
            'سيتم: إنشاء القيود المحاسبية + خصم المستودع، ثم الإرسال إلى الفوترة الإلكترونية.',
            { title: 'ترحيل الفاتورة؟', okLabel: 'ترحيل', cancelLabel: 'إلغاء' }
          )
        : Promise.resolve(
            window.confirm(
              'ترحيل الفاتورة؟\nسيتم: إنشاء القيود المحاسبية + خصم المستودع، ثم الإرسال إلى الفوترة الإلكترونية.'
            )
          );
    ask.then(function (ok) {
      if (!ok) return;
      setMsg('جاري الحفظ ثم الترحيل…');
      setBusy(true);
      fetch('/api/sales/invoices', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (saved) {
          if (!saved.ok) {
            setBusy(false);
            setMsg(saved.error || 'تعذر الحفظ قبل الترحيل', 'error');
            return null;
          }
          state.id = saved.id;
          setMsg('تم الحفظ — جاري الترحيل والفوترة…');
          return fetch('/api/sales/invoices/' + saved.id + '/post', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ auto_einvoice: true }),
          }).then(function (r) {
            return r.json();
          });
        })
        .then(function (data) {
          setBusy(false);
          if (!data) return;
          if (!data.ok) {
            setMsg(data.error || data.message || 'تعذر الترحيل', 'error');
            return;
          }
          setMsg(data.message || 'تم الترحيل.', 'ok');
          setTimeout(function () {
            window.location.href = '/sales/invoices/' + (data.invoice_id || state.id);
          }, 600);
        })
        .catch(function () {
          setBusy(false);
          setMsg('تعذر الاتصال بالخادم', 'error');
        });
    });
  }

  function unpostInvoice() {
    if (!state.id || !posted) return;
    var ask =
      window.HypexUI && window.HypexUI.confirm
        ? window.HypexUI.confirm('فك ترحيل الفاتورة؟ (عكس القيود وإرجاع المخزون)', {
            title: 'فك الترحيل',
            okLabel: 'فك الترحيل',
            cancelLabel: 'إلغاء',
            danger: true,
          })
        : Promise.resolve(window.confirm('فك ترحيل الفاتورة؟ (عكس القيود وإرجاع المخزون)'));
    ask.then(function (ok) {
      if (!ok) return;
      setBusy(true);
      setMsg('جاري فك الترحيل…');
      fetch('/api/sales/invoices/' + state.id + '/unpost', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          setBusy(false);
          if (!data.ok) {
            setMsg(data.error || data.message || 'تعذر فك الترحيل', 'error');
            return;
          }
          setMsg(data.message || 'تم فك الترحيل', 'ok');
          setTimeout(function () {
            location.reload();
          }, 500);
        })
        .catch(function () {
          setBusy(false);
          setMsg('تعذر الاتصال', 'error');
        });
    });
  }

  function deleteInvoice() {
    if (!state.id || posted) {
      setMsg(posted ? 'لا يمكن حذف فاتورة مرحّلة.' : 'احفظ الفاتورة أولاً.', 'error');
      return;
    }
    var invNo = state.invoice_no || String(state.id);
    var warnMsg =
      'تحذير: سيتم أولاً حذف جميع بنود المواد من الفاتورة، ثم حذف الفاتورة نفسها نهائياً.\n\n' +
      'رقم الفاتورة: ' +
      invNo +
      '\n\n' +
      'لا يمكن التراجع عن هذه العملية.';
    var ask =
      window.HypexUI && window.HypexUI.confirm
        ? window.HypexUI.confirm(warnMsg, {
            title: 'حذف الفاتورة',
            okLabel: 'حذف نهائياً',
            cancelLabel: 'إلغاء',
            danger: true,
            kind: 'warn',
          })
        : Promise.resolve(window.confirm(warnMsg));
    ask.then(function (ok) {
      if (!ok) return;
      setBusy(true);
      setMsg('جاري حذف البنود ثم الفاتورة…');
      fetch('/api/sales/invoices/' + state.id + '/delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          setBusy(false);
          if (!data.ok) {
            setMsg(data.error || data.message || 'تعذر الحذف', 'error');
            return;
          }
          if (window.HypexUI && window.HypexUI.toast) {
            window.HypexUI.toast(data.message || 'تم حذف الفاتورة', 'ok', 2500);
          }
          window.location.href = '/sales/invoices';
        })
        .catch(function () {
          setBusy(false);
          setMsg('تعذر الاتصال', 'error');
        });
    });
  }

  function sendEinvoice() {
    if (!state.id || !posted) {
      setMsg('يجب ترحيل الفاتورة قبل الإرسال إلى الفوترة.', 'error');
      return;
    }
    var ask =
      window.HypexUI && window.HypexUI.confirm
        ? window.HypexUI.confirm('إرسال الفاتورة إلى الفوترة الإلكترونية؟', {
            title: 'الفوترة',
            okLabel: 'إرسال',
            cancelLabel: 'إلغاء',
          })
        : Promise.resolve(window.confirm('إرسال الفاتورة إلى الفوترة الإلكترونية؟'));
    ask.then(function (ok) {
      if (!ok) return;
      setBusy(true);
      setMsg('جاري الإرسال للفوترة…');
      fetch('/api/sales/invoices/' + state.id + '/einvoice', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          setBusy(false);
          if (!data.ok) {
            setMsg(data.error || data.message || 'فشل الإرسال', 'error');
            return;
          }
          setMsg(data.message || 'تم الإرسال', 'ok');
        })
        .catch(function () {
          setBusy(false);
          setMsg('تعذر الاتصال', 'error');
        });
    });
  }

  function exportExcel() {
    if (!state.id) {
      setMsg('احفظ الفاتورة أولاً.', 'error');
      return;
    }
    syncLinesFromDom();
    var rows = [
      ['#', 'رمز', 'مادة', 'كمية', 'إضافية', 'سعر', 'خصم%', 'ضريبة%', 'صافي', 'إجمالي'],
    ];
    (state.lines || []).forEach(function (ln, i) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      rows.push([
        i + 1,
        ln.item_code || '',
        ln.name_ar || '',
        ln.qty,
        ln.qty_extra || 0,
        ln.unit_price,
        ln.discount_pct || 0,
        ln.tax_rate_percent || 0,
        t.sub,
        t.gross,
      ]);
    });
    var csv = rows
      .map(function (r) {
        return r
          .map(function (c) {
            var s = String(c == null ? '' : c).replace(/"/g, '""');
            return '"' + s + '"';
          })
          .join(',');
      })
      .join('\r\n');
    var bom = '\uFEFF';
    var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'invoice_' + (state.invoice_no || state.id) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    setMsg('تم تصدير Excel (CSV).', 'ok');
  }

  function openPrint(pdf) {
    if (!state.id) {
      setMsg('احفظ الفاتورة أولاً.', 'error');
      return;
    }
    // معاينة الطباعة — حوار النظام يُفتح فقط بعد الضغط على «طباعة» في صفحة المعاينة
    window.open('/sales/invoices/' + state.id + '/print', '_blank');
  }

  // toolbar bindings
  if (tbody && !tbody._hxDelBound) {
    tbody._hxDelBound = true;
    tbody.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.js-del') : null;
      if (!btn || posted) return;
      e.preventDefault();
      e.stopPropagation();
      var tr = btn.closest('tr');
      if (!tr) return;
      removeLineAt(Number(tr.getAttribute('data-idx')));
    });
  }

  var saveBtn = document.getElementById('si-save');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    if (busy || posted) return;
    saveInvoice();
  });

  var postBtn = document.getElementById('si-post');
  if (postBtn) postBtn.addEventListener('click', function () {
    if (busy || posted) return;
    postInvoice();
  });

  var unpostBtn = document.getElementById('si-unpost');
  if (unpostBtn) unpostBtn.addEventListener('click', unpostInvoice);

  var delBtn = document.getElementById('si-delete');
  if (delBtn) delBtn.addEventListener('click', deleteInvoice);

  var einvBtn = document.getElementById('si-einvoice');
  if (einvBtn) einvBtn.addEventListener('click', sendEinvoice);

  var printBtn = document.getElementById('si-print');
  if (printBtn) printBtn.addEventListener('click', function () {
    openPrint(false);
  });

  var pdfBtn = document.getElementById('si-pdf');
  if (pdfBtn) pdfBtn.addEventListener('click', function () {
    openPrint(true);
  });

  var excelBtn = document.getElementById('si-excel');
  if (excelBtn) excelBtn.addEventListener('click', exportExcel);

  var searchBtn = document.getElementById('si-search');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      window.location.href = '/sales/invoices';
    });
  }

  var archiveBtn = document.getElementById('si-archive');
  if (archiveBtn) {
    archiveBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ الفاتورة أولاً.', 'error');
        return;
      }
      var url = (state.defaults && state.defaults.archiveUrl) || '';
      if (url) window.open(url, '_blank');
      else setMsg('الأرشيف متاح من واجهة PHP عند الحاجة.', 'error');
    });
  }

  var emailBtn = document.getElementById('si-email');
  if (emailBtn) {
    emailBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ الفاتورة أولاً.', 'error');
        return;
      }
      if (busy) return;

      function defaultToEmail() {
        if (state.customer_email) return String(state.customer_email).trim();
        var el = document.getElementById('inv_customer_email');
        return el && el.value ? String(el.value).trim() : '';
      }

      function askEmail() {
        var def = defaultToEmail();
        if (window.HypexUI && typeof window.HypexUI.prompt === 'function') {
          return window.HypexUI.prompt(
            'أدخل البريد الإلكتروني للمستلم. سيُرسل من إعدادات SMTP في شاشة الإعدادات.',
            {
              title: 'إرسال الفاتورة بالبريد',
              defaultValue: def,
              placeholder: 'name@example.com',
              inputLabel: 'بريد المستلم',
              okLabel: 'إرسال',
              cancelLabel: 'إلغاء',
            }
          );
        }
        var typed = window.prompt('بريد المستلم:', def);
        return Promise.resolve(typed == null ? null : typed);
      }

      askEmail().then(function (to) {
        if (to == null) return;
        to = String(to || '').trim();
        if (!to) {
          setMsg('أدخل بريداً إلكترونياً صالحاً.', 'error');
          return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
          setMsg('صيغة البريد الإلكتروني غير صالحة.', 'error');
          return;
        }
        setMsg('جاري إرسال البريد…');
        setBusy(true);
        fetch('/api/sales/invoices/' + state.id + '/email', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ to_email: to }),
        })
          .then(function (r) {
            return r.json().then(function (data) {
              return { status: r.status, data: data };
            });
          })
          .then(function (res) {
            setBusy(false);
            var data = res.data || {};
            if (!data.ok) {
              setMsg(data.error || 'تعذر إرسال البريد.', 'error');
              return;
            }
            state.customer_email = to;
            setMsg(data.message || 'تم إرسال البريد بنجاح.', 'ok');
            if (window.HypexUI && window.HypexUI.toast) {
              window.HypexUI.toast(data.message || 'تم الإرسال', 'ok');
            }
          })
          .catch(function () {
            setBusy(false);
            setMsg('تعذر الاتصال بالخادم لإرسال البريد.', 'error');
          });
      });
    });
  }

  function dateIso(v) {
    if (!v) return '';
    var s = String(v);
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
    return s;
  }

  function setLockedField(el, lock) {
    if (!el) return;
    if (el.tagName === 'SELECT' || el.tagName === 'BUTTON') {
      el.disabled = !!lock;
    } else {
      el.readOnly = !!lock;
    }
  }

  function updateHero(inv) {
    var h1 = document.querySelector('.si-hero h1');
    var no = inv.invoice_no || '';
    if (h1) h1.textContent = no ? 'فاتورة ' + no : 'فاتورة مبيعات';
    try {
      document.title = (no ? 'فاتورة ' + no : 'فاتورة') + ' · Hypex';
    } catch (e) {
      /* ignore */
    }
    var badge = document.querySelector('.si-hero-badge');
    if (badge) {
      if (inv.is_posted) {
        badge.innerHTML = '<span class="si-pill si-pill--lock">مرحّلة — قراءة فقط</span>';
      } else {
        badge.innerHTML = '<span class="si-pill si-pill--wait">مسودة</span>';
      }
    }
  }

  function applyInvoiceToForm(inv, nav, caps) {
    posted = !!inv.is_posted;
    state.id = Number(inv.id) || 0;
    state.invoice_no = inv.invoice_no || '';
    state.is_posted = posted;
    state.customer_id = Number(inv.customer_id) || 0;
    state.customer_email = inv.customer_email != null ? String(inv.customer_email) : '';
    state.lines =
      inv.lines && inv.lines.length
        ? inv.lines
        : [
            {
              item_id: 0,
              item_code: '',
              name_ar: '',
              qty: 1,
              qty_extra: 0,
              unit_price: 0,
              discount_pct: 0,
              tax_rate_percent: defaultTax,
            },
          ];
    if (nav) {
      state.prev_id = nav.prev_id || 0;
      state.next_id = nav.next_id || 0;
      state.first_id = nav.first_id || 0;
      state.last_id = nav.last_id || 0;
    }
    if (caps) {
      state.caps = Object.assign({}, state.caps || {}, caps);
    }
    if (state.defaults) {
      state.defaults.archiveUrl = state.id ? state.defaults.archiveUrl || '' : '';
      if (state.id && state.defaults.phpBase) {
        /* keep */
      }
    }

    var noEl = document.getElementById('inv_no');
    if (noEl) noEl.value = state.invoice_no;
    var dateEl = document.getElementById('inv_date');
    if (dateEl) dateEl.value = dateIso(inv.invoice_date);
    var payEl = document.getElementById('inv_pay');
    if (payEl) payEl.value = inv.payment_type || 'credit';
    var cid = document.getElementById('inv_customer_id');
    if (cid) cid.value = inv.customer_id || '';
    var cust = document.getElementById('inv_customer');
    if (cust) {
      var label =
        (inv.customer_code ? inv.customer_code + ' — ' : '') + (inv.customer_name || '');
      cust.value = label;
    }
    var wh = document.getElementById('inv_wh');
    if (wh) wh.value = inv.warehouse_id != null ? String(inv.warehouse_id) : '';
    var notes = document.getElementById('inv_notes');
    if (notes) notes.value = inv.notes || '';
    var disc = document.getElementById('inv_discount');
    if (disc) disc.value = inv.invoice_discount_input || '';

    setLockedField(dateEl, posted);
    setLockedField(payEl, posted);
    setLockedField(cust, posted);
    setLockedField(wh, posted);
    setLockedField(notes, posted);
    setLockedField(disc, posted);

    updateHero(inv);
    renderLines();
    applyToolbarState();

    if (docNavApi) {
      docNavApi.setState({
        firstId: state.first_id,
        prevId: state.prev_id,
        nextId: state.next_id,
        lastId: state.last_id,
        currentId: state.id,
        currentNo: state.invoice_no || '',
      });
    }

    try {
      history.replaceState({ invoiceId: state.id }, '', '/sales/invoices/' + state.id);
    } catch (e) {
      /* ignore */
    }
  }

  var docNavApi = null;
  var navLoading = false;

  function loadInvoiceSoft(id) {
    id = Number(id);
    if (!id || navLoading) return Promise.resolve();
    if (Number(state.id) === id) return Promise.resolve();
    navLoading = true;
    var stage = document.querySelector('.si-stage');
    if (stage) {
      stage.classList.add('is-nav-loading');
      stage.classList.remove('is-nav-flash');
    }
    return fetch('/api/sales/invoices/' + id, { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          throw new Error((data && data.error) || 'الفاتورة غير موجودة');
        }
        applyInvoiceToForm(data.invoice, data.nav, data.caps);
        if (stage) {
          stage.classList.remove('is-nav-loading');
          stage.classList.add('is-nav-flash');
          setTimeout(function () {
            stage.classList.remove('is-nav-flash');
          }, 240);
        }
        setMsg('', '');
      })
      .catch(function (err) {
        if (stage) stage.classList.remove('is-nav-loading');
        throw err;
      })
      .finally(function () {
        navLoading = false;
      });
  }

  renderLines();

  if (window.HypexDocNav) {
    docNavApi = window.HypexDocNav.bind({
      input: 'inv_no',
      firstBtn: 'inv_first',
      prevBtn: 'inv_prev',
      nextBtn: 'inv_next',
      lastBtn: 'inv_last',
      firstId: state.first_id,
      prevId: state.prev_id,
      nextId: state.next_id,
      lastId: state.last_id,
      currentId: state.id,
      openPath: '/sales/invoices',
      findApi: '/api/sales/invoices/by-no',
      currentNo: state.invoice_no || '',
      onOpen: function (id) {
        return loadInvoiceSoft(id);
      },
    });
  }
})();
