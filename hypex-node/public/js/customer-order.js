(function () {
  'use strict';

  var root = document.getElementById('co-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var locked = !!state.is_approved;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var taxRates = Array.isArray(state.defaults && state.defaults.tax_rates)
    ? state.defaults.tax_rates
    : [];
  var msgEl = document.getElementById('co-msg');
  var tbody = document.getElementById('co-lines-body');
  var custTimer = null;
  var itemTimers = {};
  var busy = false;

  function rateClose(a, b) {
    return Math.abs(Number(a) - Number(b)) < 0.0001;
  }

  function taxRateLabel(t, rate) {
    var pct =
      rate.toLocaleString('en-US', { maximumFractionDigits: 3 }) + '%';
    var name = String((t && t.name_ar) || '').trim();
    if (!name) return pct;
    var nameBare = name.replace(/%/g, '').replace(/,/g, '').replace(/\s+/g, '').trim();
    if (nameBare === '' || rateClose(nameBare, rate)) return pct;
    return pct + ' — ' + name;
  }

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

  function unitSalePrice(baseSale, factor) {
    var f = Number(factor) > 0 ? Number(factor) : 1;
    return rUnit((Number(baseSale) || 0) * f);
  }

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
    state.lines.forEach(function (ln) {
      if (!ln || !ln.item_id) return;
      var base = activeBaseOfLine(ln);
      ln.base_sale = base;
      ln.unit_price = unitSalePrice(base, ln.unit_factor);
    });
    if (typeof renderLines === 'function') {
      try {
        renderLines();
      } catch (e) {
        /* */
      }
    }
  }

  function setCustomerPriceMode(c, opts) {
    opts = opts || {};
    customerUsesWholesale = !!(c && (Number(c.use_wholesale_price) === 1 || c.use_wholesale_price === true));
    var hint = document.getElementById('co_price_mode_hint');
    if (hint) {
      hint.textContent = customerUsesWholesale ? 'سعر الجملة' : 'سعر البيع';
      hint.title = customerUsesWholesale
        ? 'تسعير العميل: سعر الجملة'
        : 'تسعير العميل: سعر البيع';
      hint.hidden = false;
      hint.classList.toggle('is-wholesale', customerUsesWholesale);
    }
    if (opts.reprice === false || locked) return;
    repriceOpenLines();
  }

  /* ─── رصيد العميل + الشيكات قيد التحصيل (Oracle) ─── */
  var oraLoadSeq = 0;

  function fmtArAmt(n) {
    var x = Number(n);
    if (!Number.isFinite(x)) x = 0;
    if (window.HxDec && typeof window.HxDec.fmt === 'function') {
      return window.HxDec.fmt(x);
    }
    if (window.AppFormat && typeof AppFormat.fmt === 'function') {
      return AppFormat.fmt(x);
    }
    try {
      return x.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    } catch (e) {
      return String(Math.round(x * 1000) / 1000);
    }
  }

  function fmtArDate(iso) {
    iso = String(iso || '').trim();
    if (!iso) return '—';
    if (window.AppFormat && AppFormat.formatDateDmY) {
      var d = AppFormat.formatDateDmY(iso);
      if (d) return d;
    }
    if (window.AppDatePicker && AppDatePicker.formatIsoToDmY) {
      var d2 = AppDatePicker.formatIsoToDmY(iso.slice(0, 10));
      if (d2) return d2;
    }
    var m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '-' + m[2] + '-' + m[1];
    return iso;
  }

  function setOraStatus(text, kind) {
    var el = document.getElementById('co-ora-ar-status');
    if (!el) return;
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.remove('is-error');
    if (kind === 'error') el.classList.add('is-error');
  }

  function renderOraCheques(list) {
    var tbody = document.getElementById('co-ora-ar-chq-body');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!list || !list.length) {
      tbody.innerHTML =
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
      tbody.appendChild(tr);
    });
  }

  function loadCustomerAr(customerId) {
    var panel = document.getElementById('co-ora-ar-panel');
    var summary = document.getElementById('co-ora-ar-summary');
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
    fetch('/api/sales/customer-orders/customer-ar?customer_id=' + encodeURIComponent(String(customerId)), {
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
        var nameEl = document.getElementById('co-ora-ar-name');
        var balEl = document.getElementById('co-ora-ar-balance');
        var debEl = document.getElementById('co-ora-ar-debit');
        var creEl = document.getElementById('co-ora-ar-credit');
        var totEl = document.getElementById('co-ora-ar-chq-total');
        var metaEl = document.getElementById('co-ora-ar-meta');
        var linkEl = document.getElementById('co-ora-ar-full-link');
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
        try {
          panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e) {
          /* ignore */
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
    if (custBox) custBox.hidden = true;
    loadCustomerAr(c.id);
    focusFirstItemBarcode();
  }

  function focusFirstItemBarcode() {
    setTimeout(function () {
      if (locked) return;
      if (!(state.lines || []).length) addEmptyLine();
      focusLineField(0, '.js-item-code', true);
    }, 40);
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

  /** باركود المادة فقط (بدون اسم) */
  function itemBarcodeOnly(it) {
    if (!it) return '';
    var b = String(it.barcode != null ? it.barcode : '').trim();
    if (b) return cleanBarcodeText(b);
    var c = String(it.code != null ? it.code : it.sku != null ? it.sku : '').trim();
    return cleanBarcodeText(c);
  }

  function cleanBarcodeText(s) {
    s = String(s || '').trim();
    if (!s) return '';
    // إن وصلت قيمة مركّبة "باركود — اسم" خذ الباركود فقط
    if (s.indexOf(' — ') >= 0) s = s.split(' — ')[0].trim();
    else if (s.indexOf(' - ') >= 0 && /^\d/.test(s)) s = s.split(' - ')[0].trim();
    return s;
  }

  /** اسم المادة فقط */
  function itemNameOnly(it) {
    if (!it) return '';
    var n = String(it.name_ar != null ? it.name_ar : it.name != null ? it.name : '').trim();
    if (!n) return '';
    if (n.indexOf(' — ') >= 0) {
      var parts = n.split(' — ');
      n = (parts[1] || parts[0] || '').trim();
    }
    // أزل السعر المرفق إن وُجد
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
    // حقل الباركود = باركود فقط · حقل الاسم = اسم فقط
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
          if (typeof renderLines === 'function') renderLines();
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
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
  }

  function setBusy(on) {
    busy = !!on;
    document.querySelectorAll('#co-doc-bar .si-tb').forEach(function (btn) {
      if (!btn) return;
      if (on) {
        if (!btn.hasAttribute('data-was-disabled')) {
          btn.setAttribute('data-was-disabled', btn.disabled ? '1' : '0');
        }
        btn.disabled = true;
      } else {
        var was = btn.getAttribute('data-was-disabled');
        if (was != null) {
          btn.disabled = was === '1';
          btn.removeAttribute('data-was-disabled');
        }
      }
    });
  }

  function hxConfirm(msg, opts) {
    opts = opts || {};
    if (window.HypexUI && window.HypexUI.confirm) {
      return window.HypexUI.confirm(msg, {
        title: opts.title || 'تأكيد',
        okLabel: opts.okLabel || 'موافق',
        cancelLabel: opts.cancelLabel || 'إلغاء',
      });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function hxAlert(msg, opts) {
    opts = opts || {};
    if (window.HypexUI && window.HypexUI.dialog) {
      return window.HypexUI.dialog({
        title: opts.title || 'تنبيه النظام',
        message: msg || '',
        kind: opts.kind || 'error',
        closeOnBackdrop: true,
        buttons: [{ label: 'حسناً', value: true, primary: true }],
      });
    }
    if (window.HypexUI && window.HypexUI.alert) {
      return window.HypexUI.alert(
        (opts.title ? opts.title + '\n' : '') + (msg || ''),
        opts.kind || 'error'
      );
    }
    window.alert((opts.title ? opts.title + '\n' : '') + (msg || ''));
    return Promise.resolve(true);
  }

  function showActionError(data) {
    var text = (data && (data.error || data.message)) || 'فشل الإجراء';
    var items = data && Array.isArray(data.items)
      ? data.items
          .map(function (n) {
            return String(n || '').trim();
          })
          .filter(Boolean)
      : [];
    var isUndef =
      (data && data.code === 'item_undefined') ||
      items.length > 0 ||
      String(text).indexOf('المادة غير معرفة على النظام') === 0;
    setMsg(text, 'error');
    if (isUndef) {
      var body = items.length
        ? items.join('\n')
        : String(text)
            .replace(/^المادة غير معرفة على النظام\s*/, '')
            .trim();
      hxAlert(body, { title: 'المادة غير معرفة على النظام', kind: 'error' });
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
    (state.lines || []).forEach(function (ln) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      sumSub += t.sub;
      sumTax += t.tax;
    });
    sumSub = r3(sumSub);
    sumTax = r3(sumTax);
    var discInput = document.getElementById('co_discount');
    var hDisc = headerDiscountAmount(sumSub, discInput ? discInput.value : '');
    if (hDisc > 0 && sumSub > 0) {
      var ratio = (sumSub - hDisc) / sumSub;
      sumTax = r3(sumTax * ratio);
      sumSub = r3(sumSub - hDisc);
    }
    var elSub = document.getElementById('sum_sub');
    var elTax = document.getElementById('sum_tax');
    var elGrand = document.getElementById('sum_grand');
    if (elSub) elSub.textContent = fmt(sumSub);
    if (elTax) elTax.textContent = fmt(sumTax);
    if (elGrand) elGrand.textContent = fmt(r3(sumSub + sumTax));
  }

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function syncLinesFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      readLineFromRow(tr);
    });
  }

  function readLineFromRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = state.lines[idx] || {};
    var qtyEl = tr.querySelector('.js-qty');
    var qtyExtraEl = tr.querySelector('.js-qty-extra');
    var discEl = tr.querySelector('.js-disc');
    var taxEl = tr.querySelector('.js-tax');
    var codeEl = tr.querySelector('.js-item-code');
    var nameEl = tr.querySelector('.js-item-name');
    if (qtyEl) ln.qty = qtyEl.value;
    if (qtyExtraEl) ln.qty_extra = qtyExtraEl.value;
    if (codeEl) {
      var bc = cleanBarcodeText(codeEl.value);
      ln.item_barcode = bc;
      ln.item_code = bc;
    }
    if (nameEl) {
      ln.name_ar = itemNameOnly({ name_ar: nameEl.value });
    }
    var unitSel = tr.querySelector('.js-unit');
    if (unitSel && unitSel.selectedOptions && unitSel.selectedOptions[0]) {
      var opt = unitSel.selectedOptions[0];
      ln.unit_id = Number(unitSel.value) || 0;
      ln.unit_factor = Number(opt.getAttribute('data-factor')) || 1;
      ln.unit_name = opt.getAttribute('data-name') || opt.textContent || '';
    }
    var baseEl = tr.querySelector('.js-base-sale');
    if (baseEl && baseEl.value !== '') ln.base_sale = Number(baseEl.value) || 0;
    if (ln.base_sale != null && ln.unit_factor) {
      ln.unit_price = unitSalePrice(ln.base_sale, ln.unit_factor);
      var priceEl = tr.querySelector('.js-price');
      if (priceEl) priceEl.value = String(ln.unit_price);
    }
    if (discEl) ln.discount_pct = discEl.value;
    if (taxEl) ln.tax_rate_percent = taxEl.value;
    var hid = tr.querySelector('.js-item-id');
    if (hid && hid.value) ln.item_id = Number(hid.value) || 0;
    state.lines[idx] = ln;
    var t = lineTotals(ln);
    var subEl = tr.querySelector('.js-sub');
    var grossEl = tr.querySelector('.js-gross');
    if (subEl) subEl.textContent = fmt(t.sub);
    if (grossEl) grossEl.textContent = fmt(t.gross);
    recomputeFooter();
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
    el.style.padding = '0';
    el.textContent = text == null || text === '' ? 'م' : String(text);
    return Math.ceil(el.getBoundingClientRect().width);
  }

  /** توسيع الحقل تلقائياً حسب طول الكتابة/النص — معطّل لجداول البنود (عرض ثابت + تمرير أفقي) */
  function fitFieldToContent(el) {
    if (!el) return;
    el.style.width = '';
    el.style.maxWidth = '';
  }

  function fitRowFields(tr) {
    if (!tr) return;
    fitFieldToContent(tr.querySelector('.js-item-code'));
    fitFieldToContent(tr.querySelector('.js-item-name'));
    fitFieldToContent(tr.querySelector('.js-unit'));
  }

  function fitAllLineFields() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-idx]').forEach(fitRowFields);
  }

  function renderLines(focusOpts) {
    if (!tbody) return;
    tbody.innerHTML = '';
    (state.lines || []).forEach(function (ln, idx) {
      var t = lineTotals(ln);
      // تنقية: حقل الباركود = باركود فقط · الاسم = اسم فقط
      var barcode = cleanBarcodeText(ln.item_barcode || ln.item_code || '');
      var nameOnly = itemNameOnly({ name_ar: ln.name_ar || '' });
      // استرجاع إن وُجد نص مركب قديم في الباركود
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
        'placeholder="باركود" data-nav="1" title="' +
        escAttr(barcode) +
        '" value="' +
        escAttr(barcode) +
        '" ' +
        (locked ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td class="si-item-name-cell">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" placeholder="اسم المادة" data-nav="1" title="' +
        escAttr(nameOnly) +
        '" value="' +
        escAttr(nameOnly) +
        '" ' +
        (locked || ln.item_id ? 'readonly' : '') +
        '>' +
        '</td>' +
        '<td class="si-unit-cell">' +
        unitSelectHtml(ln, locked).replace('<select class="js-unit"', '<select class="js-unit" data-nav="1"') +
        '</td>' +
        '<td><input class="js-qty" type="number" step="1" min="0" data-nav="1" placeholder="كمية" value="' +
        escAttr(userNumAttr(ln.qty)) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="1" min="0" data-nav="1" placeholder="إضافية" value="' +
        escAttr(userNumAttr(ln.qty_extra)) +
        '" ' +
        (locked ? 'readonly' : '') +
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
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td>' +
        taxSelectHtml(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax, locked).replace(
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
        (locked
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
    // بعد الرسم: وسع الحقول حسب النص
    window.setTimeout(function () {
      fitAllLineFields();
    }, 0);
    if (focusOpts && focusOpts.idx != null) {
      window.setTimeout(function () {
        focusLineField(focusOpts.idx, focusOpts.cls || '.js-item-code', !!focusOpts.select);
      }, 0);
    }
  }

  function focusLineField(idx, selector, doSelect) {
    if (!tbody) return;
    var tr = tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (!tr) return;
    var el = tr.querySelector(selector);
    if (!el || el.disabled) return;
    // تخطّي اسم المادة بعد الاختيار (readonly) فقط
    if (el.readOnly && el.classList && el.classList.contains('js-item-name')) {
      var unit = tr.querySelector('.js-unit');
      if (unit && !unit.disabled) el = unit;
      else return;
    } else if (el.readOnly && el.tagName === 'INPUT') {
      return;
    }
    try {
      el.focus();
      if (doSelect && typeof el.select === 'function' && el.tagName === 'INPUT') el.select();
    } catch (e) {
      /* ignore */
    }
  }

  function pickItemIntoRow(tr, it, focusNext) {
    var idx = Number(tr.getAttribute('data-idx'));
    state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
    closeItemSuggest(tr.querySelector('.js-item-suggest'));
    renderLines({
      idx: idx,
      cls: focusNext ? '.js-qty' : '.js-item-code',
      select: !focusNext,
    });
  }

  /** ترتيب حقول التنقل Enter / الأسهم داخل السطر */
  var LINE_NAV = ['.js-item-code', '.js-item-name', '.js-unit', '.js-qty', '.js-qty-extra', '.js-disc', '.js-tax'];

  function lineNavEls(tr) {
    var list = [];
    LINE_NAV.forEach(function (sel) {
      var el = tr.querySelector(sel);
      if (!el || el.disabled) return;
      if (el.readOnly && !el.classList.contains('js-item-name')) return;
      // اسم المادة: يُتجاوز إذا readonly (المختار)
      if (el.classList.contains('js-item-name') && el.readOnly) return;
      list.push(el);
    });
    return list;
  }

  function headerNavEls() {
    var ids = ['co_date', 'co_pay', 'co_rep', 'co_wh', 'co_customer'];
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

  function openSuggestForRow(tr) {
    return tr ? tr.querySelector('.js-item-suggest') : null;
  }

  function getOpenItemSuggest(fromEl) {
    if (!fromEl) return null;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    var box = openSuggestForRow(tr);
    if (box && !box.hidden && box.querySelector('button')) return box;
    if (fromEl.id === 'co_customer') {
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
    // الاختيار بالأسهم = اختيار صريح يسمح بـ Enter
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

  /** Enter يختار من القائمة فقط بعد تنقّل بالأسهم (اختيار صريح) */
  function pickActiveSuggest(box) {
    if (!box || box.hidden) return false;
    if (box.dataset.hxUserNav !== '1') return false;
    var active = box.querySelector('button.is-active');
    if (!active) return false;
    active.click();
    return true;
  }

  function closeItemSuggest(box) {
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.classList.remove('si-suggest--float', 'si-suggest--barcode', 'si-suggest--name');
    box.style.left = '';
    box.style.right = '';
    box.style.top = '';
    box.style.width = '';
    box.style.display = '';
    box.removeAttribute('data-mode');
    box.dataset.hxUserNav = '';
    box.querySelectorAll('button.is-active').forEach(function (b) {
      b.classList.remove('is-active');
    });
  }

  function isSystemItemModalOpen() {
    var m = document.getElementById('hx-lk');
    return !!(m && !m.hidden);
  }

  function placeFloatSuggest(box, anchor) {
    if (!box || !anchor) return;
    // لا تُظهر قائمة الحقل فوق نافذة F3
    if (isSystemItemModalOpen()) {
      closeItemSuggest(box);
      return;
    }
    var r = anchor.getBoundingClientRect();
    var mode = box.getAttribute('data-mode') || 'barcode';
    var minW = mode === 'name' ? 260 : 180;
    var width = Math.max(r.width, minW);
    if (mode === 'name') width = Math.min(Math.max(width, 280), Math.min(420, window.innerWidth - 16));
    else width = Math.min(Math.max(width, 160), Math.min(280, window.innerWidth - 16));

    // محاذاة من اليمين (RTL): حافة القائمة اليمنى مع حافة الحقل اليمنى
    var right = window.innerWidth - r.right;
    var left = r.right - width;
    if (left < 8) {
      left = 8;
      right = 'auto';
    } else {
      right = Math.max(8, right);
      left = 'auto';
    }
    box.classList.add('si-suggest--float');
    box.style.width = width + 'px';
    if (left === 'auto') {
      box.style.left = 'auto';
      box.style.right = right + 'px';
    } else {
      box.style.left = left + 'px';
      box.style.right = 'auto';
    }
    box.style.top = r.bottom + 4 + 'px';
  }

  function goNextField(fromEl) {
    if (locked || !fromEl) return;
    // قائمة مواد: Enter يختار فقط بعد الأسهم؛ وإلا أغلق وانتقل للحقل التالي
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
      // تابع التنقل للحقل التالي بدون اختيار مادة
    } else {
      // عميل وغيره: السلوك السابق (Enter يختار النشط إن وُجد)
      var suggest = getOpenItemSuggest(fromEl);
      if (suggest && fromEl.id === 'co_customer') {
        if (pickActiveSuggest(suggest) || suggest.querySelector('button')) {
          var btn = suggest.querySelector('button.is-active') || suggest.querySelector('button');
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
      // آخر حقل في السطر → سطر تالٍ (إن وُجد مادة) أو جديد بعد اختيار المادة
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
        if (addEmptyLine()) {
          focusLineField((state.lines || []).length - 1, '.js-item-code', true);
        }
      }
      return;
    }

    var headers = headerNavEls();
    var hi = headers.indexOf(fromEl);
    if (hi >= 0 && hi < headers.length - 1) {
      focusElement(headers[hi + 1], true);
      return;
    }
    if (hi === headers.length - 1 || fromEl.id === 'co_customer') {
      // إلى أول بند — لا تضف سطرًا إن وُجد بالفعل سطر فارغ
      if (!(state.lines || []).length) addEmptyLine({ force: true });
      focusLineField(0, '.js-item-code', true);
    }
  }

  function goPrevField(fromEl) {
    if (locked || !fromEl) return;
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
    if (locked || !fromEl || !tbody) return;
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (!tr || !tbody.contains(tr)) return;
    var idx = Number(tr.getAttribute('data-idx'));
    var nextIdx = idx + (dir > 0 ? 1 : -1);
    if (nextIdx < 0) return;
    if (nextIdx >= (state.lines || []).length) {
      if (dir > 0) {
        // سطر جديد فقط بعد اختيار مادة السطر الحالي
        if (!lineHasItem(state.lines[idx])) {
          setMsg('اختر المادة أولاً قبل إضافة سطر جديد.', 'error');
          return;
        }
        if (!addEmptyLine()) return;
        nextIdx = (state.lines || []).length - 1;
      } else return;
    }
    var cls = null;
    LINE_NAV.forEach(function (sel) {
      if (fromEl.matches && fromEl.matches(sel)) cls = sel;
      else if (fromEl.classList && sel.slice(1) && fromEl.classList.contains(sel.slice(1))) cls = sel;
    });
    if (!cls) {
      for (var c = 0; c < LINE_NAV.length; c++) {
        if (tr.querySelector(LINE_NAV[c]) === fromEl) {
          cls = LINE_NAV[c];
          break;
        }
      }
    }
    focusLineField(nextIdx, cls || '.js-item-code', true);
  }

  function goHorizontalField(fromEl, dir) {
    // dir: +1 next (visual left in RTL table still sequential field order)
    if (dir > 0) goNextField(fromEl);
    else goPrevField(fromEl);
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
          var pe = tr.querySelector('.js-price');
          if (pe) pe.value = String(ln.unit_price);
        }
        readLineFromRow(tr);
        fitRowFields(tr);
        if (window.HxOffers && Number((state.lines[idx] || {}).item_id)) {
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
    var codeInput = tr.querySelector('.js-item-code');
    var nameInput = tr.querySelector('.js-item-name');
    var suggest = tr.querySelector('.js-item-suggest');
    if (codeInput && suggest && !locked) {
      codeInput.addEventListener('input', function () {
        fitFieldToContent(codeInput, { min: 100, max: 240, pad: 24 });
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (nameInput && !nameInput.readOnly) nameInput.value = '';
        else if (nameInput) {
          nameInput.value = '';
          nameInput.readOnly = false;
          fitFieldToContent(nameInput, { min: 140, max: 640, pad: 28 });
        }
        if (state.lines[idx]) {
          state.lines[idx].item_id = 0;
          state.lines[idx].name_ar = '';
          state.lines[idx].item_code = cleanBarcodeText(codeInput.value);
          state.lines[idx].item_barcode = state.lines[idx].item_code;
        }
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          if (isSystemItemModalOpen()) return;
          searchItems(codeInput.value, suggest, tr, codeInput);
        }, 160);
      });
      codeInput.addEventListener('focus', function () {
        if (isSystemItemModalOpen()) return;
        searchItems(codeInput.value || '', suggest, tr, codeInput);
      });
      codeInput.addEventListener('click', function () {
        if (isSystemItemModalOpen()) return;
        if (!suggest.hidden) placeFloatSuggest(suggest, codeInput);
        else searchItems(codeInput.value || '', suggest, tr, codeInput);
      });
    }
    if (nameInput && suggest && !locked) {
      nameInput.addEventListener('input', function () {
        if (nameInput.readOnly) return;
        fitFieldToContent(nameInput, { min: 140, max: 640, pad: 28 });
        nameInput.title = nameInput.value || '';
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        if (state.lines[idx]) {
          state.lines[idx].item_id = 0;
          state.lines[idx].name_ar = nameInput.value;
        }
        clearTimeout(itemTimers['n' + idx]);
        itemTimers['n' + idx] = setTimeout(function () {
          if (isSystemItemModalOpen()) return;
          searchItems(nameInput.value, suggest, tr, nameInput);
        }, 160);
      });
      nameInput.addEventListener('focus', function () {
        if (nameInput.readOnly) return;
        if (isSystemItemModalOpen()) return;
        searchItems(nameInput.value || '', suggest, tr, nameInput);
      });
      nameInput.addEventListener('click', function () {
        if (nameInput.readOnly) return;
        if (isSystemItemModalOpen()) return;
        if (!suggest.hidden) placeFloatSuggest(suggest, nameInput);
        else searchItems(nameInput.value || '', suggest, tr, nameInput);
      });
    }
    // توسيع فوري عند ربط الصف
    fitRowFields(tr);
  }

  function removeLineAt(idx) {
    if (locked) return;
    idx = Number(idx);
    if (!Number.isFinite(idx) || idx < 0) return;
    syncLinesFromDom();
    if (!state.lines || !state.lines[idx]) return;
    state.lines.splice(idx, 1);
    if (!state.lines.length) addEmptyLine({ force: true, silent: true });
    else renderLines();
    setMsg('تم حذف البند من الجدول.', 'ok');
  }

  function searchItems(q, box, tr, anchor) {
    if (!box) return;
    if (isSystemItemModalOpen()) {
      closeItemSuggest(box);
      return;
    }
    var token = String((searchItems._seq = (searchItems._seq || 0) + 1));
    box._coSearchToken = token;
    // وضع القائمة: باركود ← يعرض الباركود فقط · اسم ← يعرض اسم المادة فقط
    var mode =
      anchor && anchor.classList && anchor.classList.contains('js-item-name') ? 'name' : 'barcode';
    box.classList.remove('si-suggest--barcode', 'si-suggest--name');
    box.classList.add(mode === 'name' ? 'si-suggest--name' : 'si-suggest--barcode');
    box.setAttribute('data-mode', mode);
    box.dataset.hxUserNav = '';

    fetch('/api/sales/customer-orders/items?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (box._coSearchToken !== token) return;
        // رد متأخر بعد فتح F3 → لا تُظهر القائمة المنسدلة
        if (isSystemItemModalOpen()) {
          closeItemSuggest(box);
          return;
        }
        box.innerHTML = '';
        box.dataset.hxUserNav = '';
        if (!data.ok) {
          closeItemSuggest(box);
          return;
        }
        var rows = data.rows || [];
        if (!rows.length) {
          var empty = document.createElement('div');
          empty.className = 'si-suggest-empty';
          empty.style.cssText = 'padding:.55rem .75rem;color:#64748b;font-size:.82rem;text-align:right';
          empty.textContent = q
            ? 'لا توجد نتائج مطابقة'
            : mode === 'name'
              ? 'اكتب اسم المادة…'
              : 'اكتب الباركود…';
          box.appendChild(empty);
          box.hidden = false;
          box.removeAttribute('hidden');
          placeFloatSuggest(box, anchor || tr.querySelector('.js-item-code'));
          return;
        }
        rows.slice(0, 30).forEach(function (it, i) {
          var b = document.createElement('button');
          b.type = 'button';
          b.tabIndex = -1;
          var code = itemBarcodeOnly(it);
          var nm = itemNameOnly(it);
          // قائمة الباركود → باركود فقط · قائمة الاسم → اسم فقط
          if (mode === 'name') {
            b.textContent = nm || code;
            b.title = code ? 'باركود: ' + code : nm;
          } else {
            b.textContent = code || nm;
            b.title = nm ? 'المادة: ' + nm : code;
          }
          b.setAttribute('data-item-id', String(it.id || ''));
          b.setAttribute('data-barcode', code);
          b.setAttribute('data-name', nm);
          b.addEventListener('mousedown', function (e) {
            e.preventDefault();
          });
          b.addEventListener('click', function () {
            // نقر الماوس = اختيار صريح
            box.dataset.hxUserNav = '1';
            closeItemSuggest(box);
            pickItemIntoRow(tr, it, true);
          });
          // لا نفعّل is-active تلقائياً — حتى لا يختار Enter أول باركود
          box.appendChild(b);
        });
        box.hidden = false;
        box.removeAttribute('hidden');
        placeFloatSuggest(box, anchor || tr.querySelector('.js-item-code'));
      })
      .catch(function () {
        if (box._coSearchToken !== token) return;
        closeItemSuggest(box);
      });
  }

  /** هل تم اختيار مادة في السطر؟ */
  function lineHasItem(ln) {
    return !!(ln && Number(ln.item_id) > 0);
  }

  /** سماح سطر جديد فقط بعد اختيار مادة في كل الأسطر الحالية (خصوصاً السطر الأول) */
  function canAddNewLine(opts) {
    opts = opts || {};
    try {
      syncLinesFromDom();
    } catch (e) {
      /* ignore */
    }
    var lines = state.lines || [];
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
    if (locked) return false;
    opts = opts || {};
    // force=true للصيانة فقط (حذف آخر سطر / تهيئة)
    if (!opts.force && !canAddNewLine(opts)) return false;
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
    return true;
  }

  document.addEventListener('hx:item-picked', function (e) {
    if (locked || !document.getElementById('co-lines-body')) return;
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
      if (!addEmptyLine()) return;
      idx = state.lines.length - 1;
    }
    state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
    renderLines({ idx: idx, cls: '.js-qty', select: true });
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (locked || !document.getElementById('co_customer')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    e.preventDefault();
    onCustomerSelected(c);
  });

  document.addEventListener('hx:add-line', function (e) {
    if (locked || !document.getElementById('co-doc-bar')) return;
    e.preventDefault();
    addEmptyLine();
  });

  var custInput = document.getElementById('co_customer');
  var custId = document.getElementById('co_customer_id');
  var custBox = document.getElementById('cust_suggest');

  function searchCustomers(q) {
    if (!custBox || locked) return;
    fetch('/api/sales/customer-orders/customers?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          custBox.innerHTML =
            '<div class="si-suggest-empty" style="padding:.65rem .8rem;color:#b91c1c;font-size:.85rem">' +
            ((data && (data.error || data.message)) || 'تعذر تحميل العملاء') +
            '</div>';
          custBox.hidden = false;
          custBox.removeAttribute('hidden');
          return;
        }
        custBox.innerHTML = '';
        var rows = data.rows || [];
        if (!rows.length) {
          custBox.innerHTML =
            '<div class="si-suggest-empty" style="padding:.65rem .8rem;color:#64748b;font-size:.85rem">لا يوجد عملاء مطابقون.</div>';
          custBox.hidden = false;
          custBox.removeAttribute('hidden');
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
        custBox.removeAttribute('hidden');
      })
      .catch(function () {
        custBox.innerHTML =
          '<div class="si-suggest-empty" style="padding:.65rem .8rem;color:#b91c1c;font-size:.85rem">تعذر الاتصال بخدمة العملاء</div>';
        custBox.hidden = false;
        custBox.removeAttribute('hidden');
      });
  }

  if (custInput && custBox && !locked) {
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
      document.querySelectorAll('.js-item-suggest').forEach(function (box) {
        if (box.hidden) return;
        var tr = box.closest('tr[data-idx]');
        var codeEl = tr && tr.querySelector('.js-item-code');
        var nameEl = tr && tr.querySelector('.js-item-name');
        if (box.contains(e.target)) return;
        if (e.target === codeEl || e.target === nameEl) return;
        closeItemSuggest(box);
      });
    });
  }

  window.addEventListener(
    'scroll',
    function () {
      if (!document.getElementById('co-doc-bar')) return;
      document.querySelectorAll('.js-item-suggest:not([hidden])').forEach(function (box) {
        var tr = box.closest('tr[data-idx]');
        if (!tr) return;
        var ae = document.activeElement;
        var anchor =
          ae && tr.contains(ae) && (ae.classList.contains('js-item-code') || ae.classList.contains('js-item-name'))
            ? ae
            : tr.querySelector('.js-item-code');
        if (anchor) placeFloatSuggest(box, anchor);
      });
    },
    true
  );

  /* Enter / أسهم: تنقل بين الحقول داخل الطلب */
  if (!document._coFieldNavBound) {
    document._coFieldNavBound = true;
    document.addEventListener(
      'keydown',
      function (e) {
        if (!document.getElementById('co-doc-bar')) return;
        var t = e.target;
        if (!t || !t.closest) return;
        if (!t.closest('.si-stage')) return;
        if (t.id === 'co_no') return; // تنقل المستندات عبر HypexDocNav
        if (t.tagName === 'TEXTAREA') return;
        if (t.readOnly && t.id !== 'co_customer' && !t.classList.contains('js-item-code')) {
          /* allow nav from some readonly */
        }

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
        if (e.key === 'ArrowLeft' && !e.altKey && !e.ctrlKey) {
          var inLineL = t.closest && t.closest('tr[data-idx]');
          var inHeadL = t.getAttribute && t.getAttribute('data-nav') === '1';
          if (!inLineL && !inHeadL) return;
          // في حقل نصّي: لا تتدخل إن كان المؤشر في وسط النص
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
          goHorizontalField(t, -1);
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
          goHorizontalField(t, 1);
        }
      },
      true
    );
  }

  function updateDocNoStyle() {
    var el = document.getElementById('co_no');
    if (!el) return;
    el.classList.remove('is-saved', 'is-approved');
    if (state.is_approved || locked) el.classList.add('is-approved');
    else if (state.id || state.order_no) el.classList.add('is-saved');
  }

  var disc = document.getElementById('co_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  var coDateEl = document.getElementById('co_date');
  if (coDateEl && window.HxOffers) {
    coDateEl.addEventListener('change', function () {
      state.lines.forEach(function (ln, idx) {
        if (!ln || !Number(ln.item_id)) return;
        var tr = document.querySelector('#co-lines-body tr[data-idx="' + idx + '"]') ||
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

  function collectPayload() {
    syncLinesFromDom();
    return {
      id: state.id || 0,
      order_date: (document.getElementById('co_date') || {}).value || '',
      customer_id: Number((document.getElementById('co_customer_id') || {}).value || 0),
      sales_rep_id: Number((document.getElementById('co_rep') || {}).value || 0) || null,
      warehouse_id: Number((document.getElementById('co_wh') || {}).value || 0) || null,
      payment_type: (document.getElementById('co_pay') || {}).value || 'credit',
      notes: (document.getElementById('co_notes') || {}).value || '',
      invoice_discount: (document.getElementById('co_discount') || {}).value || '',
      lines: (state.lines || []).filter(function (ln) {
        return ln && ln.item_id;
      }),
    };
  }

  function saveOrder() {
    if (locked || busy) return Promise.resolve(null);
    var payload = collectPayload();
    if (!payload.customer_id) {
      setMsg('اختر العميل.', 'error');
      return Promise.resolve(null);
    }
    if (!payload.warehouse_id) {
      setMsg('اختر المستودع.', 'error');
      return Promise.resolve(null);
    }
    if (!payload.lines.length) {
      setMsg('أضف بنداً واحداً على الأقل.', 'error');
      return Promise.resolve(null);
    }
    for (var pi = 0; pi < payload.lines.length; pi++) {
      if (!(Number(payload.lines[pi].unit_price) > 0)) {
        setMsg('سعر المادة في البطاقة صفر. حدّد سعر البيع من شاشة تعديل الأسعار.', 'error');
        return Promise.resolve(null);
      }
    }
    setMsg('جاري الحفظ…');
    setBusy(true);
    return fetch('/api/sales/customer-orders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || 'تعذر الحفظ', 'error');
          return null;
        }
        setMsg('تم الحفظ · ' + (data.order_no || ''), 'ok');
        if (data.id && Number(data.id) !== Number(state.id || 0)) {
          if (window.history && window.history.replaceState) {
            state.id = data.id;
            state.order_no = data.order_no || '';
            var noEl = document.getElementById('co_no');
            if (noEl && data.order_no) noEl.value = data.order_no;
            updateDocNoStyle();
            window.history.replaceState({}, '', '/sales/orders/' + data.id);
            var bar = document.getElementById('co-doc-bar');
            if (bar) bar.setAttribute('data-order-id', String(data.id));
            // تفعيل أزرار الطباعة/الاعتماد بعد أول حفظ
            ['co-print', 'co-pdf', 'co-excel'].forEach(function (id) {
              var el = document.getElementById(id);
              if (el) el.disabled = false;
            });
            var appr = document.getElementById('co-approve');
            if (appr && state.can_approve) appr.disabled = false;
            var del = document.getElementById('co-delete');
            if (del && state.can_approve) del.disabled = false;
          } else {
            window.location.href = '/sales/orders/' + data.id;
          }
        } else {
          state.id = data.id;
          var noEl2 = document.getElementById('co_no');
          if (noEl2 && data.order_no) noEl2.value = data.order_no;
          state.order_no = data.order_no || state.order_no;
          updateDocNoStyle();
        }
        return data;
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
        return null;
      });
  }

  function postAction(url, okRedirect) {
    setMsg('جاري التنفيذ…');
    setBusy(true);
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          showActionError(data);
          return;
        }
        if (okRedirect) window.location.href = okRedirect;
        else window.location.reload();
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
      });
  }

  function exportExcel() {
    if (!state.id) {
      setMsg('احفظ الطلب أولاً.', 'error');
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
    a.download = 'order_' + (state.order_no || state.id) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    setMsg('تم تصدير Excel (CSV).', 'ok');
  }

  function openPrint() {
    if (!state.id) {
      setMsg('احفظ الطلب أولاً.', 'error');
      return;
    }
    var openPrintNav = window.__hypexOpenPrint || function (u) { window.location.assign(u); };
    openPrintNav('/sales/orders/' + state.id + '/print');
  }

  if (tbody && !tbody._hxDelBound) {
    tbody._hxDelBound = true;
    tbody.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.js-del') : null;
      if (!btn || locked) return;
      e.preventDefault();
      e.stopPropagation();
      var tr = btn.closest('tr');
      if (!tr) return;
      removeLineAt(Number(tr.getAttribute('data-idx')));
    });
  }

  var saveBtn = document.getElementById('co-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      if (locked || busy) return;
      saveOrder();
    });
  }

  var approveBtn = document.getElementById('co-approve');
  if (approveBtn) {
    approveBtn.addEventListener('click', function () {
      if (busy || locked) return;
      if (!state.id) {
        setMsg('احفظ الطلب أولاً ثم اعتمد.', 'error');
        return;
      }
      hxConfirm('اعتماد هذا الطلب؟', { title: 'اعتماد', okLabel: 'اعتماد' }).then(function (ok) {
        if (!ok) return;
        postAction('/api/sales/customer-orders/' + state.id + '/approve', '/sales/orders/' + state.id);
      });
    });
  }

  var unapproveBtn = document.getElementById('co-unapprove');
  if (unapproveBtn) {
    unapproveBtn.addEventListener('click', function () {
      if (busy || !state.id) return;
      hxConfirm('فك اعتماد الطلب وإعادته لمسودة؟', {
        title: 'فك الاعتماد',
        okLabel: 'فك الاعتماد',
      }).then(function (ok) {
        if (!ok) return;
        postAction(
          '/api/sales/customer-orders/' + state.id + '/unapprove',
          '/sales/orders/' + state.id
        );
      });
    });
  }

  var oracleBtn = document.getElementById('co-oracle');
  if (oracleBtn) {
    oracleBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ واعتمد الطلب أولاً.', 'error');
        return;
      }
      var vnum = Number(state.oracle_v_num || 0);
      var vyear = Number(state.oracle_vyear || 0);
      if (vnum > 0) {
        var q = '/sales/reports/oracle-sales-invoice?invoice_no=' + vnum;
        if (vyear > 0) q += '&year=' + vyear;
        window.open(q, '_blank');
        return;
      }
      if (busy || !state.is_approved) {
        setMsg('اعتمد الطلب أولاً ثم رحّله إلى Oracle.', 'error');
        return;
      }
      hxConfirm(
        'ترحيل هذا الطلب إلى فاتورة بيع في Oracle؟\nستُنشأ في فواتير المبيعات للمراجعة ثم الحفظ.',
        { title: 'ترحيل إلى Oracle', okLabel: 'ترحيل' }
      ).then(function (ok) {
        if (!ok) return;
        postAction(
          '/api/sales/customer-orders/' + state.id + '/post-oracle',
          '/sales/orders/' + state.id
        );
      });
    });
  }

  var delBtn = document.getElementById('co-delete');
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      if (busy || !state.id || locked) return;
      hxConfirm(
        'تحذير: سيتم حذف بنود الطلب ثم حذف الطلب نهائياً.\nلا يمكن التراجع.',
        { title: 'حذف الطلب', okLabel: 'حذف نهائياً', danger: true }
      ).then(function (ok) {
        if (!ok) return;
        postAction('/api/sales/customer-orders/' + state.id + '/delete', '/sales/orders');
      });
    });
  }

  var printBtn = document.getElementById('co-print');
  if (printBtn) printBtn.addEventListener('click', openPrint);

  var pdfBtn = document.getElementById('co-pdf');
  if (pdfBtn) pdfBtn.addEventListener('click', openPrint);

  var excelBtn = document.getElementById('co-excel');
  if (excelBtn) excelBtn.addEventListener('click', exportExcel);

  var searchBtn = document.getElementById('co-search');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      window.location.href = '/sales/orders';
    });
  }

  renderLines();
  setCustomerPriceMode({ use_wholesale_price: state.use_wholesale_price }, { reprice: false });
  updateDocNoStyle();

  var initialCustomerId =
    Number((document.getElementById('co_customer_id') || {}).value || state.customer_id || 0) || 0;
  if (initialCustomerId > 0) {
    loadCustomerAr(initialCustomerId);
  }

  var refreshArBtn = document.getElementById('co-ora-ar-refresh');
  if (refreshArBtn) {
    refreshArBtn.addEventListener('click', function () {
      var cid =
        Number((document.getElementById('co_customer_id') || {}).value || 0) || 0;
      loadCustomerAr(cid);
    });
  }

  if (window.HypexDocNav) {
    window.HypexDocNav.bind({
      input: 'co_no',
      prevBtn: 'co_prev',
      nextBtn: 'co_next',
      prevId: state.prev_id,
      nextId: state.next_id,
      openPath: '/sales/orders',
      findApi: '/api/sales/customer-orders/by-no',
      currentNo: state.order_no || '',
      currentId: state.id || 0,
    });
  }
})();
