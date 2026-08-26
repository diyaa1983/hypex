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
  var oraLoadSeq = 0;

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
    if (window.HxDec && typeof window.HxDec.roundAmount === 'function') {
      return window.HxDec.roundAmount(n);
    }
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }

  function rUnit(n) {
    if (window.HxDec && typeof window.HxDec.roundUnit === 'function') {
      return window.HxDec.roundUnit(n);
    }
    return r3(n);
  }

  /** سعر أقل وحدة × معامل وحدة الصرف (غير شامل ضريبة) */
  function unitSalePrice(baseSale, factor) {
    var f = Number(factor) > 0 ? Number(factor) : 1;
    return rUnit((Number(baseSale) || 0) * f);
  }

  /** سعر العميل: بيع (افتراضي) أو جملة حسب بطاقة العميل */
  var customerUsesWholesale = false;

  function itemListPrice(it) {
    if (!it) return 0;
    if (customerUsesWholesale) {
      return Number(it.base_wholesale != null ? it.base_wholesale : it.wholesale_price) || 0;
    }
    return Number(it.base_sale != null ? it.base_sale : it.sale_price) || 0;
  }

  function activeBaseOfLine(ln) {
    if (!ln) return 0;
    if (customerUsesWholesale) {
      return Number(ln.base_wholesale != null ? ln.base_wholesale : ln.base_sale) || 0;
    }
    return Number(ln.base_list_sale != null ? ln.base_list_sale : ln.base_sale) || 0;
  }

  function repriceOpenLines() {
    if (!state || !Array.isArray(state.lines)) return;
    state.lines.forEach(function (ln, idx) {
      if (!ln || !ln.item_id) return;
      var base = activeBaseOfLine(ln);
      ln.base_sale = base;
      ln.unit_price = unitSalePrice(base, ln.unit_factor);
      var tr =
        tbody && tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
      if (!tr) return;
      var baseEl = tr.querySelector('.js-base-sale');
      if (baseEl) baseEl.value = String(base);
      var pe = tr.querySelector('.js-price');
      if (pe) pe.value = String(ln.unit_price);
      var t = lineTotals(ln);
      var subEl = tr.querySelector('.js-sub');
      var grossEl = tr.querySelector('.js-gross');
      if (subEl) subEl.textContent = fmt(t.sub);
      if (grossEl) grossEl.textContent = fmt(t.gross);
    });
    recomputeFooter();
  }

  function setCustomerPriceMode(c, opts) {
    opts = opts || {};
    customerUsesWholesale = !!(c && (Number(c.use_wholesale_price) === 1 || c.use_wholesale_price === true));
    var hint = document.getElementById('inv_price_mode_hint');
    if (hint) {
      hint.textContent = customerUsesWholesale ? 'سعر الجملة' : 'سعر البيع';
      hint.title = customerUsesWholesale
        ? 'تسعير العميل: سعر الجملة'
        : 'تسعير العميل: سعر البيع';
      hint.hidden = false;
      hint.classList.toggle('is-wholesale', customerUsesWholesale);
    }
    if (opts.reprice === false || posted) return;
    repriceOpenLines();
  }

  function priceStep() {
    return window.HxDec && window.HxDec.unitStep ? window.HxDec.unitStep() : '0.001';
  }

  function qtyStep() {
    return window.HxDec && window.HxDec.amountStep ? window.HxDec.amountStep() : '0.001';
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

  function itemBarcodeOnly(it) {
    if (!it) return '';
    var b = String(it.barcode != null ? it.barcode : '').trim();
    if (b) return cleanBarcodeText(b);
    var c = String(it.code != null ? it.code : it.sku != null ? it.sku : '').trim();
    return cleanBarcodeText(c);
  }

  function itemSkuOnly(it) {
    if (!it) return '';
    return String(it.sku != null ? it.sku : '').trim();
  }

  function itemCodeSuggestLabel(it) {
    var sku = itemSkuOnly(it);
    var bc = itemBarcodeOnly(it);
    if (sku && bc && sku !== bc) return sku + ' · ' + bc;
    return sku || bc || itemNameOnly(it);
  }

  function itemExactCodeMatch(it, q) {
    q = String(q || '').trim().toLowerCase();
    if (!q || !it) return false;
    var keys = [it.sku, it.barcode, it.code, it.oracle_key];
    for (var i = 0; i < keys.length; i++) {
      var v = String(keys[i] != null ? keys[i] : '')
        .trim()
        .toLowerCase();
      if (v && v === q) return true;
    }
    return false;
  }

  function findExactItemInRows(rows, q) {
    if (!Array.isArray(rows) || !rows.length) return null;
    for (var i = 0; i < rows.length; i++) {
      if (itemExactCodeMatch(rows[i], q)) return rows[i];
    }
    return null;
  }

  function cleanBarcodeText(s) {
    s = String(s || '').trim();
    if (!s) return '';
    if (s.indexOf(' — ') >= 0) s = s.split(' — ')[0].trim();
    else if (s.indexOf(' - ') >= 0 && /^\d/.test(s)) s = s.split(' - ')[0].trim();
    return s;
  }

  function itemNameOnly(it) {
    if (!it) return '';
    var n = String(it.name_ar != null ? it.name_ar : it.name != null ? it.name : '').trim();
    if (!n) return '';
    if (n.indexOf(' — ') >= 0) {
      var parts = n.split(' — ');
      n = (parts[1] || parts[0] || '').trim();
    }
    n = n.replace(/\s*·\s*[\d.,]+$/, '').trim();
    return n;
  }

  /** عرض حقل رقمي يُدخلها المستخدم — فارغ بدل صفر تلقائي */
  function userNumAttr(v) {
    if (v === '' || v == null) return '';
    var n = Number(v);
    if (!Number.isFinite(n) || n === 0) return '';
    return String(v);
  }

  function applyItemToLine(ln, it) {
    ln = ln || {};
    ln.item_id = it.id;
    ln.item_barcode = itemBarcodeOnly(it);
    ln.item_code = ln.item_barcode;
    ln.name_ar = itemNameOnly(it);
    ln.base_list_sale = Number(it.base_sale != null ? it.base_sale : it.sale_price) || 0;
    ln.base_wholesale = Number(it.base_wholesale != null ? it.base_wholesale : it.wholesale_price) || 0;
    ln.base_sale = itemListPrice(it);
    ln.units = Array.isArray(it.units) ? it.units : [];
    var du = defaultUnitOf(it);
    ln.unit_id = du.unit_id || 0;
    ln.unit_name = du.name || 'قطعة';
    ln.unit_factor = Number(du.factor) > 0 ? Number(du.factor) : 1;
    if (customerUsesWholesale && du.wholesale_price != null) {
      ln.unit_price = rUnit(Number(du.wholesale_price) || 0);
    } else if (!customerUsesWholesale && du.sale_price != null) {
      ln.unit_price = rUnit(Number(du.sale_price) || 0);
    } else {
      ln.unit_price = unitSalePrice(ln.base_sale, ln.unit_factor);
    }
    if (ln.qty == null) ln.qty = '';
    if (ln.qty_extra == null) ln.qty_extra = '';
    if (ln.discount_pct == null) ln.discount_pct = '';
    if (it.tax_rate_percent != null && it.tax_rate_percent !== '') {
      ln.tax_rate_percent = Number(it.tax_rate_percent);
    } else if (ln.tax_rate_percent == null) {
      ln.tax_rate_percent = defaultTax;
    }
    if (window.HxOffers && typeof window.HxOffers.refreshLine === 'function') {
      window.HxOffers.refreshLine({
        ln: ln,
        onDone: function (updated) {
          if (updated) {
            ln.qty_extra = updated.qty_extra;
            ln.discount_pct = updated.discount_pct;
            ln._offer_driven = updated._offer_driven;
            ln._offer_driven_type = updated._offer_driven_type;
            ln._offer_hint = updated._offer_hint;
          }
          if (!tbody) return;
          var rows = tbody.querySelectorAll('tr[data-idx]');
          for (var ri = 0; ri < rows.length; ri++) {
            var hid = rows[ri].querySelector('.js-item-id');
            if (!hid || Number(hid.value) !== Number(ln.item_id)) continue;
            var tr = rows[ri];
            var qtyEx = tr.querySelector('.js-qty-extra');
            if (qtyEx && ln.qty_extra != null) qtyEx.value = String(ln.qty_extra);
            var discEl = tr.querySelector('.js-disc');
            if (discEl && ln.discount_pct != null) discEl.value = String(ln.discount_pct);
            var pe = tr.querySelector('.js-price');
            if (pe) pe.value = String(ln.unit_price != null ? ln.unit_price : 0);
            var t = lineTotals(ln);
            var subEl = tr.querySelector('.js-sub');
            var grossEl = tr.querySelector('.js-gross');
            if (subEl) subEl.textContent = fmt(t.sub);
            if (grossEl) grossEl.textContent = fmt(t.gross);
            recomputeFooter();
            break;
          }
        },
      });
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
    if (window.HxDec && typeof window.HxDec.fmt === 'function') {
      return window.HxDec.fmt(n);
    }
    return r3(n).toLocaleString('en-US', {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    });
  }

  function setMsg(text, type) {
    if (!msgEl) return;
    if (type === 'ok') {
      msgEl.textContent = '';
      msgEl.className = 'si-msg';
      if (text && window.HypexUI && typeof window.HypexUI.toast === 'function') {
        window.HypexUI.toast(text, 'ok', 1000);
      }
      return;
    }
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : '');
    if (text && type === 'error' && window.HypexUI && typeof window.HypexUI.toast === 'function') {
      window.HypexUI.toast(text, 'error', 4200);
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
    var codeEl = tr.querySelector('.js-item-code');
    var nameEl = tr.querySelector('.js-item-name');
    if (codeEl) {
      ln.item_barcode = cleanBarcodeText(codeEl.value);
      ln.item_code = ln.item_barcode;
    }
    if (nameEl) ln.name_ar = itemNameOnly({ name_ar: nameEl.value });
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
      var barcode = cleanBarcodeText(ln.item_barcode || ln.item_code || '');
      var nameOnly = itemNameOnly({ name_ar: ln.name_ar || '' });
      if (!nameOnly && String(ln.item_code || '').indexOf(' — ') >= 0) {
        var raw = String(ln.item_code);
        barcode = cleanBarcodeText(raw);
        nameOnly = itemNameOnly({ name_ar: raw.split(' — ')[1] || '' });
      }
      ln.item_barcode = barcode;
      ln.item_code = barcode;
      ln.name_ar = nameOnly;
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
        '<input type="hidden" class="js-base-sale" value="' +
        escAttr(ln.base_sale != null ? ln.base_sale : '') +
        '">' +
        '<input class="js-item-code" type="text" inputmode="search" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="باركود / رقم المادة" data-nav="1" title="' +
        escAttr(barcode) +
        '" value="' +
        escAttr(barcode) +
        '" ' +
        (posted ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td class="si-item-name-cell">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" placeholder="اسم المادة" data-nav="1" title="' +
        escAttr(nameOnly) +
        '" value="' +
        escAttr(nameOnly) +
        '" ' +
        (posted || ln.item_id ? 'readonly' : '') +
        '>' +
        '</td>' +
        '<td class="si-unit-cell">' +
        unitSelectHtml(ln, posted).replace('<select class="js-unit"', '<select class="js-unit" data-nav="1"') +
        '</td>' +
        '<td><input class="js-qty" type="number" step="' +
        qtyStep() +
        '" min="0" data-nav="1" placeholder="كمية" value="' +
        escAttr(userNumAttr(ln.qty)) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="' +
        qtyStep() +
        '" min="0" data-nav="1" placeholder="إضافية" value="' +
        escAttr(userNumAttr(ln.qty_extra)) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-price" type="number" step="' +
        priceStep() +
        '" min="0" value="' +
        escAttr(ln.unit_price) +
        '" readonly tabindex="-1" title="من بطاقة المادة (أقل وحدة × التعبئة)">' +
        '</td>' +
        '<td><input class="js-disc" type="number" step="' +
        qtyStep() +
        '" min="0" max="100" data-nav="1" placeholder="خصم %" value="' +
        escAttr(userNumAttr(ln.discount_pct)) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td>' +
        taxSelectHtml(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax, posted).replace(
          'class="js-tax"',
          'class="js-tax" data-nav="1"'
        ) +
        '</td>' +
        '<td class="js-sub si-num-out" dir="ltr">' +
        fmt(t.sub) +
        '</td>' +
        '<td class="js-gross si-num-out" dir="ltr">' +
        fmt(t.gross) +
        '</td>' +
        '<td class="si-col-del">' +
        (posted
          ? ''
          : '<button type="button" class="si-del js-del" title="حذف البند" aria-label="حذف البند" tabindex="-1">' +
            '<svg class="si-del-ico" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">' +
            '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16"/>' +
            '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>' +
            '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M18.5 7l-.7 12.2a1.5 1.5 0 0 1-1.5 1.4H7.7a1.5 1.5 0 0 1-1.5-1.4L5.5 7"/>' +
            '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" d="M10 11v6M14 11v6"/>' +
            '</svg></button>') +
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
    var mode =
      anchor && anchor.classList && anchor.classList.contains('js-item-name') ? 'name' : 'barcode';
    box.classList.remove('si-suggest--barcode', 'si-suggest--name');
    box.classList.add(mode === 'name' ? 'si-suggest--name' : 'si-suggest--barcode');
    box.setAttribute('data-mode', mode);
    box.style.width = width + 'px';
    box.style.left = left + 'px';
    box.style.top = r.bottom + 3 + 'px';
    box.style.right = 'auto';
  }

  function closeFloatSuggest(box) {
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.classList.remove('si-suggest--float', 'si-suggest--barcode', 'si-suggest--name');
    box.style.left = '';
    box.style.top = '';
    box.style.width = '';
    var cell = box.closest('.si-item-code-cell') || box.closest('.si-item-cell');
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
        if (cls === 'js-qty' && window.HxOffers) {
          var idx = Number(tr.getAttribute('data-idx'));
          var ln = state.lines[idx];
          window.HxOffers.refreshLine({
            idx: idx,
            ln: ln,
            tr: tr,
            onDone: function () {
              readLineFromRow(tr);
            },
          });
        }
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
          ln.unit_price = unitSalePrice(activeBaseOfLine(ln), ln.unit_factor);
          state.lines[idx] = ln;
          var priceEl = tr.querySelector('.js-price');
          if (priceEl) priceEl.value = String(ln.unit_price);
        }
        readLineFromRow(tr);
        if (window.HxOffers && Number(ln.item_id)) {
          window.HxOffers.refreshLine({
            idx: idx,
            ln: state.lines[idx],
            tr: tr,
            onDone: function () {
              readLineFromRow(tr);
            },
          });
        }
      });
    }
    // حذف البند — التفويض العام على tbody أدناه
    var codeInput = tr.querySelector('.js-item-code');
    var nameInput = tr.querySelector('.js-item-name');
    var suggest = tr.querySelector('.js-item-suggest');
    if (codeInput && suggest && !posted) {
      codeInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (state.lines[idx]) {
          state.lines[idx].item_id = 0;
          state.lines[idx].item_barcode = codeInput.value;
          state.lines[idx].item_code = codeInput.value;
        }
        if (nameInput && !nameInput.readOnly) nameInput.value = '';
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(codeInput.value, suggest, tr, codeInput);
        }, 200);
      });
      codeInput.addEventListener('focus', function () {
        searchItems(codeInput.value || '', suggest, tr, codeInput);
      });
      codeInput.addEventListener('click', function () {
        searchItems(codeInput.value || '', suggest, tr, codeInput);
      });
    }
    if (nameInput && suggest && !posted) {
      nameInput.addEventListener('input', function () {
        if (nameInput.readOnly) return;
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (state.lines[idx]) {
          state.lines[idx].item_id = 0;
          state.lines[idx].name_ar = nameInput.value;
        }
        clearTimeout(itemTimers['n' + idx]);
        itemTimers['n' + idx] = setTimeout(function () {
          searchItems(nameInput.value, suggest, tr, nameInput);
        }, 200);
      });
      nameInput.addEventListener('focus', function () {
        if (nameInput.readOnly) return;
        searchItems(nameInput.value || '', suggest, tr, nameInput);
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
    var cell = box.closest('.si-item-code-cell') || box.closest('.si-item-cell');
    if (cell) cell.classList.add('is-open');
    var fallback =
      anchor || tr.querySelector('.js-item-code') || tr.querySelector('.js-item');

    if (!data || !data.ok) {
      var err = document.createElement('div');
      err.className = 'si-suggest-empty';
      err.style.cssText = 'padding:.65rem .8rem;color:#b91c1c;font-size:.85rem';
      err.textContent = (data && data.error) || 'تعذر تحميل المواد';
      box.appendChild(err);
      box.hidden = false;
      box.removeAttribute('hidden');
      placeFloatSuggest(box, fallback);
      return;
    }

    var rows = data.rows || [];
    if (!rows.length) {
      box._hxRows = [];
      var empty = document.createElement('div');
      empty.className = 'si-suggest-empty';
      empty.style.cssText = 'padding:.65rem .8rem;color:#64748b;font-size:.85rem';
      empty.textContent = 'لا توجد مواد مطابقة. جرّب الباركود أو رقم المادة.';
      box.appendChild(empty);
      box.hidden = false;
      box.removeAttribute('hidden');
      placeFloatSuggest(box, fallback);
      return;
    }

    var mode =
      anchor && anchor.classList && anchor.classList.contains('js-item-name') ? 'name' : 'barcode';
    box._hxRows = rows;
    rows.slice(0, 40).forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      var code = itemBarcodeOnly(it);
      var sku = itemSkuOnly(it);
      var name = itemNameOnly(it);
      b.textContent =
        mode === 'name'
          ? name + (sku || code ? ' · ' + (sku || code) : '') + ' · ' + fmt(it.sale_price)
          : itemCodeSuggestLabel(it) + ' — ' + name + ' · ' + fmt(it.sale_price);
      b.setAttribute('data-barcode', code);
      b.setAttribute('data-sku', sku);
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();
      });
      b.addEventListener('click', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
        closeFloatSuggest(box);
        renderLines();
        window.setTimeout(function () {
          if (window.HxShortcuts && window.HxShortcuts.focusLineQty) {
            window.HxShortcuts.focusLineQty(idx, '#si-lines-body');
          } else {
            var row = tbody && tbody.querySelector('tr[data-idx="' + idx + '"]');
            var q = row && row.querySelector('.js-qty');
            if (q) {
              q.focus({ preventScroll: true });
              if (q.select) q.select();
            }
          }
        }, 40);
      });
      box.appendChild(b);
    });
    box.hidden = false;
    box.removeAttribute('hidden');
    placeFloatSuggest(box, fallback);
  }

  function addEmptyLine() {
    state.lines = state.lines || [];
    state.lines.push({
      item_id: 0,
      item_code: '',
      item_barcode: '',
      name_ar: '',
      qty: '',
      qty_extra: '',
      unit_price: 0,
      base_sale: 0,
      unit_id: 0,
      unit_name: 'قطعة',
      unit_factor: 1,
      units: [],
      discount_pct: '',
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
    if (window.HxShortcuts && window.HxShortcuts.focusLineQty) {
      window.HxShortcuts.focusLineQty(idx, '#si-lines-body');
    } else {
      setTimeout(function () {
        var tr = tbody && tbody.querySelector('tr[data-idx="' + idx + '"]');
        var q = tr && tr.querySelector('.js-qty');
        if (q) {
          q.focus({ preventScroll: true });
          if (q.select) q.select();
        }
      }, 40);
    }
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (posted || !document.getElementById('inv_customer')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    onCustomerSelected(c);
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

  function fmtArAmt(n) {
    var x = Number(n) || 0;
    return x.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  }

  function fmtArDate(iso) {
    iso = String(iso || '').trim();
    if (!iso) return '—';
    var m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '-' + m[2] + '-' + m[1];
    return iso;
  }

  function setOraStatus(text, kind) {
    var el = document.getElementById('inv-ora-ar-status');
    if (!el) return;
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.remove('is-error');
    if (kind === 'error') el.classList.add('is-error');
  }

  function renderOraCheques(list) {
    var tb = document.getElementById('inv-ora-ar-chq-body');
    if (!tb) return;
    tb.innerHTML = '';
    if (!list || !list.length) {
      tb.innerHTML =
        '<tr><td colspan="4" class="muted">لا شيكات قيد التحصيل لهذا العميل.</td></tr>';
      return;
    }
    list.forEach(function (ch) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td dir="ltr"></td><td dir="ltr"></td><td dir="ltr" class="col-money"></td><td dir="ltr"></td>';
      tr.cells[0].textContent = ch.chq_no || ch.check_no || '—';
      tr.cells[1].textContent = fmtArDate(ch.chq_date || ch.due_date || ch.check_date);
      tr.cells[2].textContent = fmtArAmt(ch.amount || ch.check_amount);
      tr.cells[3].textContent = fmtArDate(ch.receipt_date || ch.capture_date);
      tb.appendChild(tr);
    });
  }

  function loadCustomerAr(customerId, opts) {
    opts = opts || {};
    var panel = document.getElementById('inv-ora-ar-panel');
    var summary = document.getElementById('inv-ora-ar-summary');
    if (!panel) return;
    customerId = parseInt(customerId, 10) || 0;
    if (!(customerId > 0)) {
      panel.hidden = true;
      if (summary) summary.hidden = true;
      setOraStatus('اختر عميلاً لعرض الرصيد (مدين / دائن) والشيكات قيد التحصيل.', null);
      return;
    }
    panel.hidden = false;
    if (summary) summary.hidden = true;
    setOraStatus('جاري جلب رصيد العميل والشيكات…', null);
    var seq = ++oraLoadSeq;
    fetch('/api/sales/invoices/customer-ar?customer_id=' + encodeURIComponent(String(customerId)), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (x) {
        if (seq !== oraLoadSeq) return;
        if (!x || !x.ok) {
          setOraStatus((x && (x.message || x.error)) || 'تعذر جلب رصيد العميل.', 'error');
          if (summary) summary.hidden = true;
          return;
        }
        setOraStatus('', null);
        if (summary) summary.hidden = false;
        var nameEl = document.getElementById('inv-ora-ar-name');
        var balEl = document.getElementById('inv-ora-ar-balance');
        var debEl = document.getElementById('inv-ora-ar-debit');
        var creEl = document.getElementById('inv-ora-ar-credit');
        var totEl = document.getElementById('inv-ora-ar-chq-total');
        var metaEl = document.getElementById('inv-ora-ar-meta');
        var linkEl = document.getElementById('inv-ora-ar-full-link');
        if (nameEl) nameEl.textContent = x.name || x.account || '—';
        if (debEl) debEl.textContent = fmtArAmt(x.total_debit);
        if (creEl) creEl.textContent = fmtArAmt(x.total_credit);
        if (balEl) balEl.textContent = fmtArAmt(x.balance);
        if (totEl) totEl.textContent = fmtArAmt(x.cheque_total);
        if (metaEl) {
          metaEl.textContent =
            (x.account ? 'الحساب: ' + x.account + ' · ' : '') +
            'الفترة: ' +
            fmtArDate(x.from) +
            ' — ' +
            fmtArDate(x.to) +
            (x.cheque_count != null ? ' · شيكات: ' + String(x.cheque_count) : '');
        }
        if (linkEl) {
          if (x.statement_url) {
            linkEl.href = x.statement_url;
            linkEl.hidden = false;
          } else {
            linkEl.hidden = true;
          }
        }
        renderOraCheques(x.cheques || []);
        if (opts.scroll) {
          try {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          } catch (e) {
            /* ignore */
          }
        }
      })
      .catch(function () {
        if (seq !== oraLoadSeq) return;
        setOraStatus('فشل الاتصال أثناء جلب رصيد العميل.', 'error');
        if (summary) summary.hidden = true;
      });
  }

  function onCustomerSelected(c) {
    if (!c || !c.id) return;
    if (custId) custId.value = c.id;
    if (custInput) custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
    setCustomerPriceMode(c);
    if (custBox) {
      custBox.hidden = true;
      custBox.setAttribute('hidden', '');
    }
    loadCustomerAr(c.id, { scroll: true });
    focusFirstItemBarcode();
  }

  function focusFirstItemBarcode() {
    setTimeout(function () {
      if (posted) return;
      if (!(state.lines || []).length) addEmptyLine();
      focusLineField(0, '.js-item-code', true);
    }, 40);
  }

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
        onCustomerSelected(c);
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
      setCustomerPriceMode({ use_wholesale_price: 0 });
      loadCustomerAr(0);
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
      var cell = box.closest('.si-item-code-cell') || box.closest('.si-item-cell') || box.parentElement;
      var inp =
        (cell && (cell.querySelector('.js-item-code') || cell.querySelector('.js-item'))) || null;
      var nameInp = cell && cell.parentElement && cell.parentElement.querySelector('.js-item-name');
      if (
        !box.contains(e.target) &&
        e.target !== inp &&
        e.target !== nameInp &&
        !(cell && cell.contains(e.target)) &&
        !(nameInp && nameInp === e.target)
      ) {
        closeFloatSuggest(box);
      }
    });
  });
  window.addEventListener(
    'scroll',
    function () {
      document.querySelectorAll('.js-item-suggest:not([hidden])').forEach(function (box) {
        var cell = box.closest('.si-item-code-cell') || box.closest('.si-item-cell');
        var mode = box.getAttribute('data-mode') || 'barcode';
        var tr = box.closest('tr');
        var inp =
          mode === 'name'
            ? tr && tr.querySelector('.js-item-name')
            : cell && (cell.querySelector('.js-item-code') || cell.querySelector('.js-item'));
        if (inp && !box.hidden) placeFloatSuggest(box, inp);
      });
    },
    true
  );

  var disc = document.getElementById('inv_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  var invDateEl = document.getElementById('inv_date');
  if (invDateEl && window.HxOffers) {
    invDateEl.addEventListener('change', function () {
      state.lines.forEach(function (ln, idx) {
        if (!ln || !Number(ln.item_id)) return;
        var tr = document.querySelector('#si-lines-body tr[data-idx="' + idx + '"]') ||
          document.querySelector('tr[data-idx="' + idx + '"]');
        window.HxOffers.refreshLine({
          idx: idx,
          ln: ln,
          tr: tr,
          onDone: function () {
            if (tr) readLineFromRow(tr);
            else if (typeof renderLines === 'function') renderLines();
          },
        });
      });
    });
  }

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
    // معاينة الطباعة في نفس التبويب — حوار النظام بعد الضغط على «طباعة» في صفحة المعاينة
    var openPrintNav = window.__hypexOpenPrint || function (u) { window.location.assign(u); };
    openPrintNav('/sales/invoices/' + state.id + '/print');
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
      if (url) {
        var openPrintNav = window.__hypexOpenPrint || function (u) { window.location.assign(u); };
        openPrintNav(url);
      } else setMsg('احفظ الفاتورة أولاً لفتح الأرشيف/الطباعة.', 'error');
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
    if (window.AppFormat && AppFormat.parseDateToIso) {
      return AppFormat.parseDateToIso(s) || s;
    }
    if (window.AppDatePicker && AppDatePicker.parseDmYToIso) {
      return AppDatePicker.parseDmYToIso(s) || s;
    }
    return s;
  }

  /** عرض تاريخ يوم-شهر-سنة في الحقول */
  function dateDisplay(v) {
    var iso = dateIso(v);
    if (!iso) return '';
    if (window.AppFormat && AppFormat.formatDateDmY) return AppFormat.formatDateDmY(iso);
    if (window.AppDatePicker && AppDatePicker.formatIsoToDmY) {
      return AppDatePicker.formatIsoToDmY(iso) || iso;
    }
    var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '-' + m[2] + '-' + m[1];
    return iso;
  }

  function setDateField(el, v) {
    if (!el) return;
    el.value = dateDisplay(v);
    try {
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {
      /* ignore */
    }
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
        ? inv.lines.map(function (ln) {
            var barcode = cleanBarcodeText(ln.item_barcode || ln.item_code || '');
            var nameOnly = itemNameOnly({ name_ar: ln.name_ar || '' });
            if (!nameOnly && String(ln.item_code || '').indexOf(' — ') >= 0) {
              var raw = String(ln.item_code);
              barcode = cleanBarcodeText(raw);
              nameOnly = itemNameOnly({ name_ar: raw.split(' — ')[1] || '' });
            }
            return Object.assign({}, ln, {
              item_barcode: barcode,
              item_code: barcode,
              name_ar: nameOnly,
            });
          })
        : [
            {
              item_id: 0,
              item_code: '',
              item_barcode: '',
              name_ar: '',
              qty: '',
              qty_extra: '',
              unit_price: 0,
              discount_pct: '',
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
    if (dateEl) setDateField(dateEl, inv.invoice_date);
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
    setCustomerPriceMode({ use_wholesale_price: inv.use_wholesale_price }, { reprice: false });
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

    loadCustomerAr(state.customer_id, { scroll: false });
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
  setCustomerPriceMode({ use_wholesale_price: state.use_wholesale_price }, { reprice: false });

  try {
    window.scrollTo(0, 0);
  } catch (e) {
    /* ignore */
  }

  var initialCustomerId =
    Number((document.getElementById('inv_customer_id') || {}).value || state.customer_id || 0) || 0;
  if (initialCustomerId > 0) {
    loadCustomerAr(initialCustomerId, { scroll: false });
  }
  var refreshArBtn = document.getElementById('inv-ora-ar-refresh');
  if (refreshArBtn) {
    refreshArBtn.addEventListener('click', function () {
      var cid = Number((document.getElementById('inv_customer_id') || {}).value || 0) || 0;
      loadCustomerAr(cid, { scroll: false });
    });
  }

  function lineHasItem(ln) {
    return !!(ln && Number(ln.item_id) > 0);
  }

  function focusLineField(idx, cls, doSelect) {
    if (!tbody) return;
    var tr = tbody.querySelector('tr[data-idx="' + idx + '"]');
    if (!tr) return;
    var el = tr.querySelector(cls || '.js-item-code');
    if (!el) return;
    try {
      el.focus();
      if (doSelect && typeof el.select === 'function') el.select();
    } catch (e) {
      /* ignore */
    }
  }

  var LINE_NAV = ['.js-item-code', '.js-item-name', '.js-unit', '.js-qty', '.js-qty-extra', '.js-disc', '.js-tax'];

  function lineNavEls(tr) {
    var list = [];
    LINE_NAV.forEach(function (sel) {
      var el = tr.querySelector(sel);
      if (!el || el.disabled) return;
      if (el.readOnly && !el.classList.contains('js-item-name')) return;
      if (el.classList.contains('js-item-name') && el.readOnly) return;
      list.push(el);
    });
    return list;
  }

  function headerNavEls() {
    var ids = ['inv_date', 'inv_pay', 'inv_wh', 'inv_customer'];
    var list = [];
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el || el.disabled) return;
      list.push(el);
    });
    return list;
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

  function getOpenSuggest(fromEl) {
    if (!fromEl) return null;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    var box = tr && tr.querySelector('.js-item-suggest');
    if (box && !box.hidden && box.querySelector('button')) return box;
    if (fromEl.id === 'inv_customer') {
      var cs = document.getElementById('cust_suggest');
      if (cs && !cs.hidden && cs.querySelector('button')) return cs;
    }
    return null;
  }

  function suggestButtons(box) {
    return box ? Array.prototype.slice.call(box.querySelectorAll('button')) : [];
  }

  function setSuggestActive(box, idx) {
    var btns = suggestButtons(box);
    if (!btns.length) return;
    if (idx < 0) idx = btns.length - 1;
    if (idx >= btns.length) idx = 0;
    btns.forEach(function (b, i) {
      if (i === idx) b.classList.add('is-active');
      else b.classList.remove('is-active');
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
    var active = box.querySelector('button.is-active');
    if (!active) return false;
    active.click();
    return true;
  }

  function pickExactCodeSuggest(box, q) {
    if (!box || box.hidden) return false;
    var it = findExactItemInRows(box._hxRows || [], q);
    if (!it) return false;
    var tr = box.closest('tr[data-idx]');
    if (!tr) return false;
    var idx = Number(tr.getAttribute('data-idx'));
    box.dataset.hxUserNav = '1';
    state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
    closeFloatSuggest(box);
    renderLines();
    window.setTimeout(function () {
      if (window.HxShortcuts && window.HxShortcuts.focusLineQty) {
        window.HxShortcuts.focusLineQty(idx, '#si-lines-body');
      } else {
        var row = tbody && tbody.querySelector('tr[data-idx="' + idx + '"]');
        var qtyEl = row && row.querySelector('.js-qty');
        if (qtyEl) {
          qtyEl.focus({ preventScroll: true });
          if (qtyEl.select) qtyEl.select();
        }
      }
    }, 40);
    return true;
  }

  function goNextField(fromEl) {
    if (posted || !fromEl) return;
    var itemSug =
      fromEl.closest && fromEl.closest('tr[data-idx]')
        ? fromEl.closest('tr[data-idx]').querySelector('.js-item-suggest')
        : null;
    if (
      itemSug &&
      !itemSug.hidden &&
      itemSug.querySelector('button') &&
      (fromEl.classList.contains('js-item-code') || fromEl.classList.contains('js-item-name'))
    ) {
      if (pickActiveSuggest(itemSug)) return;
      if (fromEl.classList.contains('js-item-code') && pickExactCodeSuggest(itemSug, fromEl.value)) {
        return;
      }
      closeFloatSuggest(itemSug);
    } else if (fromEl.id === 'inv_customer') {
      var cs = document.getElementById('cust_suggest');
      if (cs && !cs.hidden && cs.querySelector('button')) {
        if (pickActiveSuggest(cs) || cs.querySelector('button')) {
          var btn = cs.querySelector('button.is-active') || cs.querySelector('button');
          if (btn) {
            btn.click();
            return;
          }
        }
      }
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
      var curLn = state.lines[idx];
      if (!lineHasItem(curLn)) {
        focusLineField(idx, '.js-item-code', true);
        setMsg('اختر المادة أولاً قبل الانتقال لسطر جديد.', 'error');
        return;
      }
      var nextIdx = idx + 1;
      if (nextIdx < (state.lines || []).length) {
        focusLineField(nextIdx, '.js-item-code', true);
      } else {
        addEmptyLine();
        focusLineField((state.lines || []).length - 1, '.js-item-code', true);
      }
      return;
    }

    var headers = headerNavEls();
    var hi = headers.indexOf(fromEl);
    if (hi >= 0 && hi < headers.length - 1) {
      focusElement(headers[hi + 1], true);
      return;
    }
    if (hi === headers.length - 1 || fromEl.id === 'inv_customer') {
      if (!(state.lines || []).length) addEmptyLine();
      focusLineField(0, '.js-item-code', true);
    }
  }

  function goPrevField(fromEl) {
    if (posted || !fromEl) return;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (tr && tbody && tbody.contains(tr)) {
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
      } else {
        var headers = headerNavEls();
        if (headers.length) focusElement(headers[headers.length - 1], true);
      }
      return;
    }
    var headers2 = headerNavEls();
    var hi = headers2.indexOf(fromEl);
    if (hi > 0) focusElement(headers2[hi - 1], true);
  }

  function goVerticalField(fromEl, dir) {
    if (posted || !fromEl || !tbody) return;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (!tr || !tbody.contains(tr)) return;
    var idx = Number(tr.getAttribute('data-idx'));
    var nextIdx = idx + (dir > 0 ? 1 : -1);
    if (nextIdx < 0) return;
    if (nextIdx >= (state.lines || []).length) {
      if (dir > 0) {
        if (!lineHasItem(state.lines[idx])) {
          setMsg('اختر المادة أولاً قبل إضافة سطر جديد.', 'error');
          return;
        }
        addEmptyLine();
        nextIdx = (state.lines || []).length - 1;
      } else return;
    }
    var cls = null;
    LINE_NAV.forEach(function (sel) {
      if (fromEl.matches && fromEl.matches(sel)) cls = sel;
      else if (fromEl.classList && fromEl.classList.contains(sel.slice(1))) cls = sel;
    });
    focusLineField(nextIdx, cls || '.js-item-code', true);
  }

  if (!document._siFieldNavBound) {
    document._siFieldNavBound = true;
    document.addEventListener(
      'keydown',
      function (e) {
        if (!document.getElementById('si-doc-bar')) return;
        var t = e.target;
        if (!t || !t.closest) return;
        if (!t.closest('.si-stage')) return;
        if (t.id === 'inv_no') return;
        if (t.tagName === 'TEXTAREA') return;
        if (posted) return;

        var openSug = getOpenSuggest(t);

        if (e.key === 'Escape' && openSug) {
          e.preventDefault();
          if (openSug.id === 'cust_suggest') {
            openSug.hidden = true;
            openSug.setAttribute('hidden', '');
          } else {
            closeFloatSuggest(openSug);
          }
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
        if (e.key === 'ArrowLeft' && !e.altKey && !e.ctrlKey) {
          var inLineL = t.closest && t.closest('tr[data-idx]');
          var inHeadL = t.getAttribute && t.getAttribute('data-nav') === '1';
          if (!inLineL && !inHeadL) return;
          if (
            (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') &&
            t.type !== 'number' &&
            t.type !== 'date' &&
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
        if (e.key === 'ArrowRight' && !e.altKey && !e.ctrlKey) {
          var inLineR = t.closest && t.closest('tr[data-idx]');
          var inHeadR = t.getAttribute && t.getAttribute('data-nav') === '1';
          if (!inLineR && !inHeadR) return;
          if (
            (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') &&
            t.type !== 'number' &&
            t.type !== 'date' &&
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
