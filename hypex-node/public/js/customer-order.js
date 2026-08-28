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
  var formDirty = false;
  var suppressDirtyMark = 0;

  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'co_no';
  }

  function markFormDirty() {
    if (suppressDirtyMark > 0 || locked) return;
    formDirty = true;
  }

  function markFormDirtyFromEvent(e) {
    if (e && e.target && isSearchOnlyField(e.target)) return;
    if (e && e.target && e.target.closest && e.target.closest('.js-item-pick, .si-del, .si-tb, .si-docno-btn')) {
      /* أزرار — dirty يُعلَّم من المنطق البرمجي عند الحاجة */
    }
    markFormDirty();
  }

  function clearFormDirty() {
    formDirty = false;
  }

  function runWithoutDirtyMark(fn) {
    suppressDirtyMark++;
    try {
      fn();
    } finally {
      suppressDirtyMark--;
    }
  }

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

  function loadCustomerAr(customerId, opts) {
    opts = opts || {};
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
        // لا نمرّر للأسفل عند فتح المستند — فقط عند اختيار عميل يدوياً
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
    if (custBox) custBox.hidden = true;
    loadCustomerAr(c.id, { scroll: true });
    focusFirstItemBarcode();
  }

  function focusFirstItemBarcode() {
    setTimeout(function () {
      if (locked) return;
      if (!(state.lines || []).length) addEmptyLine();
      focusLineField(0, '.js-item-sku', true);
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

  /** رقم المادة (SKU) */
  function itemSkuOnly(it) {
    if (!it) return '';
    return String(it.sku != null ? it.sku : '').trim();
  }

  /** نص القائمة لحقل الباركود/رقم المادة */
  function itemCodeSuggestLabel(it) {
    var sku = itemSkuOnly(it);
    var bc = itemBarcodeOnly(it);
    if (sku && bc && sku !== bc) return sku + ' · ' + bc;
    return sku || bc || itemNameOnly(it);
  }

  /** تطابق تام لباركود أو رقم مادة أو oracle_key */
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
    var exact = null;
    for (var i = 0; i < rows.length; i++) {
      if (itemExactCodeMatch(rows[i], q)) {
        exact = rows[i];
        break;
      }
    }
    return exact;
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
    ln.item_sku = itemSkuOnly(it);
    ln.item_barcode = itemBarcodeOnly(it);
    ln.item_code = ln.item_barcode || ln.item_sku;
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
          // تحديث السطر الحالي فقط — بدون إعادة بناء الجدول بالكامل
          if (tbody) {
            var rows = tbody.querySelectorAll('tr[data-idx]');
            for (var ri = 0; ri < rows.length; ri++) {
              var hid = rows[ri].querySelector('.js-item-id');
              if (hid && Number(hid.value) === Number(ln.item_id)) {
                patchLineRow(rows[ri], ln);
                break;
              }
            }
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
    text = String(text || '');
    if (/<!doctype html|<h2|database\.local\.php/i.test(text)) {
      text = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      if (/حدث خطأ داخلي|database\.local/i.test(text)) {
        text =
          'تعذر تشغيل سكربت الترحيل (PHP/MySQL). استخدم C:\\xampp\\php\\php.exe مع php.ini لـ XAMPP ثم أعد تشغيل: pm2 restart hypex-node';
      }
    }
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

  function hxAlert(msg, opts) {
    opts = opts || {};
    var kind = opts.kind || 'warning';
    var title = opts.title || (kind === 'error' ? 'تعذّر الإكمال' : kind === 'ok' ? 'تم' : 'تحذير');
    setMsg(msg || '', kind === 'ok' ? 'ok' : 'error');
    if (window.AppDialog) {
      if (kind === 'error' && typeof window.AppDialog.error === 'function') {
        return window.AppDialog.error(String(msg || ''), { title: title, theme: 'oracle' });
      }
      if (typeof window.AppDialog.alert === 'function') {
        return window.AppDialog.alert(String(msg || ''), {
          title: title,
          type: kind === 'ok' ? 'success' : kind === 'error' ? 'error' : 'warning',
          theme: 'oracle',
        });
      }
    }
    if (window.HypexUI && window.HypexUI.alert) {
      return window.HypexUI.alert(String(msg || ''), kind === 'ok' ? 'ok' : kind === 'error' ? 'error' : 'warning');
    }
    window.alert((title ? title + '\n' : '') + (msg || ''));
    return Promise.resolve(true);
  }

  function hxConfirm(msg, opts) {
    opts = opts || {};
    if (window.AppDialog && typeof window.AppDialog.confirm === 'function') {
      return window.AppDialog.confirm(String(msg || ''), {
        title: opts.title || 'تأكيد',
        okText: opts.okLabel || 'موافق',
        cancelText: opts.cancelLabel || 'إلغاء',
        danger: !!opts.danger,
        theme: 'oracle',
      });
    }
    if (window.HypexUI && window.HypexUI.confirm) {
      return window.HypexUI.confirm(msg, {
        title: opts.title || 'تأكيد',
        okLabel: opts.okLabel || 'موافق',
        cancelLabel: opts.cancelLabel || 'إلغاء',
      });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function isOrderLockError(text) {
    text = String(text || '');
    return (
      text.indexOf('فك الاعتماد') >= 0 ||
      text.indexOf('طلب معتمد') >= 0 ||
      text.indexOf('معتمد بالفعل') >= 0
    );
  }

  function refreshOrderPageSoon() {
    window.setTimeout(function () {
      window.location.reload();
    }, 400);
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

    var stockIssues = data && Array.isArray(data.stock_issues) ? data.stock_issues : [];
    var isStock =
      stockIssues.length > 0 ||
      String(text).indexOf('الكمية المتوفرة أقل من الكمية المباعة') >= 0 ||
      String(text).indexOf('رصيد Oracle') >= 0 ||
      String(text).indexOf('[STOCK-v3]') >= 0;

    // حوار النظام فقط (مثل باقي التنبيهات) — بدون شريط وردي طويل
    if (isStock) {
      setMsg('', '');
      var stockBody = '';
      if (stockIssues.length) {
        stockBody = stockIssues
          .map(function (iss) {
            if (iss && iss._line) return String(iss._line).replace(/\s*\[STOCK-v3\]\s*$/, '');
            var code = (iss && iss.item) || '';
            var name = (iss && iss.name) || '';
            var need = iss && iss.need != null ? iss.need : '';
            var avail = iss && iss.available != null ? iss.available : '';
            var store = iss && iss.store != null ? iss.store : '';
            return (
              (code ? code + (name ? ' — ' + name : '') : name || 'مادة') +
              '\nالمطلوب: ' +
              need +
              '\nرصيد Oracle' +
              (store !== '' ? ' (مستودع ' + store + ')' : '') +
              ': ' +
              avail
            );
          })
          .join('\n\n');
      } else {
        stockBody = String(text)
          .replace(/^تعذر الترحيل إلى Oracle[^\n]*\n?/, '')
          .replace(/^[•\s]+/gm, '')
          .replace(/\s*\[STOCK-v3\]\s*/g, '')
          .trim();
      }
      if (!stockBody) stockBody = 'الكمية المتوفرة أقل من الكمية المباعة.';
      hxAlert(stockBody, { title: 'تعذر الترحيل إلى Oracle', kind: 'error' });
      return;
    }

    setMsg(text, 'error');
    if (isUndef) {
      var body = items.length
        ? items.join('\n')
        : String(text)
            .replace(/^المادة غير معرفة على النظام\s*/, '')
            .trim();
      hxAlert(body, { title: 'المادة غير معرفة على النظام', kind: 'error' });
    } else if (String(text).length > 80 || String(text).indexOf('\n') >= 0) {
      hxAlert(text, { title: 'تنبيه النظام', kind: 'error' });
    }

    if (isOrderLockError(text)) {
      refreshOrderPageSoon();
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
    var skuEl = tr.querySelector('.js-item-sku');
    var nameEl = tr.querySelector('.js-item-name');
    if (qtyEl) ln.qty = qtyEl.value;
    if (qtyExtraEl) ln.qty_extra = qtyExtraEl.value;
    if (skuEl) ln.item_sku = String(skuEl.value || '').trim();
    if (codeEl) {
      var bc = cleanBarcodeText(codeEl.value);
      ln.item_barcode = bc;
      ln.item_code = bc || ln.item_sku || '';
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
    fitFieldToContent(tr.querySelector('.js-item-sku'));
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
    closeItemSuggest(getCoItemSuggest());
    tbody.innerHTML = '';
    (state.lines || []).forEach(function (ln, idx) {
      var t = lineTotals(ln);
      var barcode = cleanBarcodeText(ln.item_barcode || '');
      var sku = String(ln.item_sku || '').trim();
      if (!barcode && !sku) {
        barcode = cleanBarcodeText(ln.item_code || '');
      }
      var nameOnly = itemNameOnly({ name_ar: ln.name_ar || '' });
      if (!nameOnly && String(ln.item_code || '').indexOf(' — ') >= 0) {
        var raw = String(ln.item_code);
        barcode = cleanBarcodeText(raw);
        nameOnly = itemNameOnly({ name_ar: raw.split(' — ')[1] || '' });
      }
      ln.item_barcode = barcode;
      ln.item_sku = sku;
      ln.item_code = barcode || sku;
      ln.name_ar = nameOnly;
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr" class="si-row-num">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-sku-cell">' +
        '<div class="si-item-sku-wrap">' +
        '<input class="js-item-sku" type="text" inputmode="search" autocomplete="off" spellcheck="false" dir="ltr" ' +
        'placeholder="رقم المادة" data-nav="1" title="' +
        escAttr(sku) +
        '" value="' +
        escAttr(sku) +
        '" ' +
        (locked ? 'readonly' : '') +
        '>' +
        (locked
          ? ''
          : '<button type="button" class="si-item-pick js-item-pick" tabindex="-1" title="قائمة المواد (F3)" aria-label="قائمة المواد">▾</button>') +
        '</div>' +
        '</td>' +
        '<td class="si-item-code-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input type="hidden" class="js-base-sale" value="' +
        escAttr(ln.base_sale != null ? ln.base_sale : '') +
        '">' +
        '<input class="js-item-code" type="text" inputmode="search" autocomplete="off" spellcheck="false" dir="ltr" ' +
        'placeholder="الباركود" data-nav="1" title="' +
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
        focusLineField(focusOpts.idx, focusOpts.cls || '.js-item-sku', !!focusOpts.select);
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
      el.focus({ preventScroll: true });
      if (doSelect && typeof el.select === 'function' && el.tagName === 'INPUT') el.select();
    } catch (e) {
      try {
        el.focus();
        if (doSelect && typeof el.select === 'function' && el.tagName === 'INPUT') el.select();
      } catch (e2) {
        /* ignore */
      }
    }
  }

  /** تحديث صف موجود من بيانات البند دون إعادة بناء الجدول (يمنع الوميض). */
  function patchLineRow(tr, ln) {
    if (!tr || !ln) return;
    var barcode = cleanBarcodeText(ln.item_barcode || '');
    var sku = String(ln.item_sku || '').trim();
    var nameOnly = itemNameOnly({ name_ar: ln.name_ar || '' });
    ln.item_barcode = barcode;
    ln.item_sku = sku;
    ln.item_code = barcode || sku;
    ln.name_ar = nameOnly;

    var hid = tr.querySelector('.js-item-id');
    if (hid) hid.value = ln.item_id || '';
    var baseEl = tr.querySelector('.js-base-sale');
    if (baseEl) baseEl.value = ln.base_sale != null ? String(ln.base_sale) : '';
    var skuEl = tr.querySelector('.js-item-sku');
    if (skuEl) {
      skuEl.value = sku;
      skuEl.title = sku;
    }
    var codeEl = tr.querySelector('.js-item-code');
    if (codeEl) {
      codeEl.value = barcode;
      codeEl.title = barcode;
    }
    var nameEl = tr.querySelector('.js-item-name');
    if (nameEl) {
      nameEl.value = nameOnly;
      nameEl.title = nameOnly;
      if (ln.item_id) nameEl.readOnly = true;
    }
    var unitCell = tr.querySelector('.si-unit-cell');
    if (unitCell) {
      unitCell.innerHTML = unitSelectHtml(ln, locked).replace(
        '<select class="js-unit"',
        '<select class="js-unit" data-nav="1"'
      );
    }
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl && ln.qty != null) qtyEl.value = userNumAttr(ln.qty);
    var qtyEx = tr.querySelector('.js-qty-extra');
    if (qtyEx && ln.qty_extra != null) qtyEx.value = userNumAttr(ln.qty_extra);
    var pe = tr.querySelector('.js-price');
    if (pe) pe.value = String(ln.unit_price != null ? ln.unit_price : 0);
    var discEl = tr.querySelector('.js-disc');
    if (discEl && ln.discount_pct != null) discEl.value = userNumAttr(ln.discount_pct);
    var taxEl = tr.querySelector('.js-tax');
    if (taxEl && ln.tax_rate_percent != null) taxEl.value = userNumAttr(ln.tax_rate_percent);
    var t = lineTotals(ln);
    var subEl = tr.querySelector('.js-sub');
    var grossEl = tr.querySelector('.js-gross');
    if (subEl) subEl.textContent = fmt(t.sub);
    if (grossEl) grossEl.textContent = fmt(t.gross);
    // إعادة ربط أحداث الصف بعد استبدال قائمة الوحدات
    bindRow(tr);
    fitRowFields(tr);
    recomputeFooter();
  }

  function pickItemIntoRow(tr, it, focusNext) {
    var idx = Number(tr.getAttribute('data-idx'));
    state.lines[idx] = applyItemToLine(state.lines[idx] || {}, it);
    closeItemSuggest(openSuggestForRow(tr));
    patchLineRow(tr, state.lines[idx]);
    markFormDirty();
    if (focusNext) {
      window.setTimeout(function () {
        focusLineField(idx, '.js-qty', true);
      }, 0);
    }
  }

  /** ترتيب حقول التنقل Enter / الأسهم داخل السطر */
  var LINE_NAV = ['.js-item-sku', '.js-item-code', '.js-item-name', '.js-unit', '.js-qty', '.js-qty-extra', '.js-disc', '.js-tax'];

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

  var coGlobalSuggest = null;
  var coSuggestGuardUntil = 0;

  function getCoItemSuggest() {
    if (coGlobalSuggest && coGlobalSuggest.isConnected) return coGlobalSuggest;
    var existing = document.getElementById('co-global-item-suggest');
    if (existing) {
      coGlobalSuggest = existing;
      return coGlobalSuggest;
    }
    coGlobalSuggest = document.createElement('div');
    coGlobalSuggest.id = 'co-global-item-suggest';
    coGlobalSuggest.className = 'si-suggest js-item-suggest';
    coGlobalSuggest.hidden = true;
    coGlobalSuggest.setAttribute('hidden', '');
    document.body.appendChild(coGlobalSuggest);
    return coGlobalSuggest;
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
    if (!tr) return null;
    var box = getCoItemSuggest();
    box._hxRow = tr;
    return box;
  }

  function resolveSuggestBox(tr) {
    return openSuggestForRow(tr);
  }

  function getOpenItemSuggest(fromEl) {
    if (!fromEl) return null;
    var box = getCoItemSuggest();
    if (!box.hidden && box.querySelector('button')) {
      var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
      if (!tr || box._hxRow === tr) return box;
    }
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

  /** Enter على تطابق تام لرقم المادة أو الباركود — بدون أسهم */
  function pickExactCodeSuggest(box, q) {
    var it = findExactItemInRows((box && box._hxRows) || [], q);
    if (!it) return false;
    var tr =
      (box && box._hxRow) ||
      (box && box.closest && box.closest('tr[data-idx]')) ||
      null;
    if (!tr) return false;
    if (box) {
      box.dataset.hxUserNav = '1';
      closeItemSuggest(box);
    }
    pickItemIntoRow(tr, it, true);
    return true;
  }

  function closeItemSuggest(box) {
    box = box || coGlobalSuggest;
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.classList.remove('si-suggest--float', 'si-suggest--barcode', 'si-suggest--name', 'si-suggest--sku');
    box.style.left = '';
    box.style.right = '';
    box.style.top = '';
    box.style.width = '';
    box.style.minWidth = '';
    box.style.maxWidth = '';
    box.style.display = '';
    box.style.position = '';
    box.style.zIndex = '';
    box.style.inset = '';
    box.style.visibility = '';
    box.style.opacity = '';
    box.style.pointerEvents = '';
    box.removeAttribute('data-mode');
    box.dataset.hxUserNav = '';
    box._hxRows = [];
    box.innerHTML = '';
    box.querySelectorAll('button.is-active').forEach(function (b) {
      b.classList.remove('is-active');
    });
  }

  function isSystemItemModalOpen() {
    var m = document.getElementById('hx-lk');
    if (!m) return false;
    if (m.hidden || m.hasAttribute('hidden')) return false;
    var st = window.getComputedStyle(m);
    if (st.display === 'none' || st.visibility === 'hidden') return false;
    return true;
  }

  function ensureSuggestHome(box, tr) {
    if (!box) return;
    if (tr) box._hxRow = tr;
    box._hxHome = document.body;
  }

  function showSuggestLoading(box, tr, anchor) {
    if (!box || !anchor) return;
    ensureSuggestHome(box, tr);
    var mode = 'barcode';
    if (anchor.classList) {
      if (anchor.classList.contains('js-item-name')) mode = 'name';
      else if (anchor.classList.contains('js-item-sku')) mode = 'sku';
    }
    box.classList.remove('si-suggest--barcode', 'si-suggest--name', 'si-suggest--sku');
    box.classList.add(
      mode === 'name' ? 'si-suggest--name' : mode === 'sku' ? 'si-suggest--sku' : 'si-suggest--barcode'
    );
    box.setAttribute('data-mode', mode);
    box.innerHTML =
      '<div class="si-suggest-empty" style="padding:.55rem .75rem;color:#64748b;font-size:.82rem;text-align:right">جاري التحميل…</div>';
    placeFloatSuggest(box, anchor);
  }

  function placeFloatSuggest(box, anchor) {
    if (!box || !anchor) return;
    if (isSystemItemModalOpen()) {
      closeItemSuggest(box);
      return;
    }
    var tr =
      box._hxRow ||
      (anchor.closest && anchor.closest('tr[data-idx]')) ||
      null;
    ensureSuggestHome(box, tr);
    if (box.parentNode !== document.body) {
      document.body.appendChild(box);
    }

    var r = anchor.getBoundingClientRect();
    var mode = box.getAttribute('data-mode') || 'barcode';
    var width =
      mode === 'name'
        ? Math.min(Math.max(r.width, 280), Math.min(440, window.innerWidth - 16))
        : Math.min(Math.max(Math.round(r.width), 220), Math.min(340, window.innerWidth - 16));

    var left = Math.round(r.right - width);
    if (left < 8) left = 8;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }

    var top = Math.round(r.bottom + 3);
    var approxH = Math.min(260, window.innerHeight * 0.45);
    if (top + 100 > window.innerHeight && r.top > approxH + 8) {
      top = Math.max(8, Math.round(r.top - 3 - approxH));
    }

    coSuggestGuardUntil = Date.now() + 450;
    box.hidden = false;
    box.removeAttribute('hidden');
    box.classList.add('si-suggest--float');
    box.style.display = 'block';
    box.style.position = 'fixed';
    box.style.zIndex = '99999';
    box.style.width = width + 'px';
    box.style.minWidth = width + 'px';
    box.style.maxWidth = width + 'px';
    box.style.left = left + 'px';
    box.style.right = 'auto';
    box.style.top = top + 'px';
    box.style.inset = 'auto';
    box.style.visibility = 'visible';
    box.style.opacity = '1';
    box.style.pointerEvents = 'auto';
  }

  function openItemListForField(anchor) {
    if (!anchor || locked || isSystemItemModalOpen()) return;
    if (
      !anchor.classList.contains('js-item-sku') &&
      !anchor.classList.contains('js-item-code') &&
      !anchor.classList.contains('js-item-name')
    ) {
      return;
    }
    var tr = anchor.closest('tr[data-idx]');
    if (!tr || !tbody || !tbody.contains(tr)) return;
    var box = getCoItemSuggest();
    ensureSuggestHome(box, tr);
    coSuggestGuardUntil = Date.now() + 450;
    showSuggestLoading(box, tr, anchor);
    searchItems(anchor.value || '', box, tr, anchor);
  }

  function goToNextLineSku(fromIdx) {
    var idx = Number(fromIdx);
    if (!Number.isFinite(idx)) return false;
    var nextIdx = idx + 1;
    if (nextIdx < (state.lines || []).length) {
      window.setTimeout(function () {
        focusLineField(nextIdx, '.js-item-sku', true);
      }, 0);
      return true;
    }
    if (!addEmptyLine()) return false;
    var newIdx = (state.lines || []).length - 1;
    window.setTimeout(function () {
      focusLineField(newIdx, '.js-item-sku', true);
    }, 0);
    return true;
  }

  function lineMatchesTypedCode(ln, fromEl, q) {
    if (!ln || !Number(ln.item_id)) return false;
    q = String(q || '').trim().toLowerCase();
    if (!q) return false;
    if (fromEl.classList.contains('js-item-sku')) {
      return String(ln.item_sku || '').trim().toLowerCase() === q;
    }
    if (fromEl.classList.contains('js-item-code')) {
      var bc = String(ln.item_barcode || ln.item_code || '')
        .trim()
        .toLowerCase();
      return bc === q;
    }
    return false;
  }

  /**
   * عند Enter على رقم مادة/باركود: اختيار إن وُجدت، وإلا تحذير موحّد
   * @param {function(string)} done — empty|ok|picked|missing|error
   */
  function resolveItemCodeOnEnter(fromEl, tr, done) {
    var q = String(fromEl.value || '').trim();
    if (!q) {
      done('empty');
      return;
    }
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = state.lines[idx];
    if (lineMatchesTypedCode(ln, fromEl, q)) {
      done('ok');
      return;
    }
    var box = getCoItemSuggest();
    if (pickExactCodeSuggest(box, q)) {
      done('picked');
      return;
    }
    setMsg('جاري التحقق من المادة…');
    fetch('/api/lookup/items?q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        var rows = (data && data.ok && data.rows) || [];
        var exact = findExactItemInRows(rows, q);
        if (exact) {
          pickItemIntoRow(tr, exact, true);
          setMsg('', '');
          done('picked');
          return;
        }
        var msg = 'المادة غير موجودة في بطاقة المواد.\nالرقم المدخل: ' + q;
        hxAlert(msg, { title: 'مادة غير موجودة', kind: 'warning' }).then(function () {
          try {
            fromEl.focus();
            if (typeof fromEl.select === 'function') fromEl.select();
          } catch (e) {
            /* ignore */
          }
        });
        done('missing');
      })
      .catch(function () {
        hxAlert('تعذر التحقق من المادة. حاول مرة أخرى.', {
          title: 'تنبيه',
          kind: 'error',
        });
        done('error');
      });
  }

  function continueGoNextField(fromEl) {
    var tr = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (tr && tbody && tbody.contains(tr)) {
      var idx = Number(tr.getAttribute('data-idx'));
      var curLn = state.lines[idx];
      try {
        readLineFromRow(tr);
      } catch (e) {
        /* ignore */
      }
      var rowEls = lineNavEls(tr);
      var i = rowEls.indexOf(fromEl);
      if (i < 0) {
        for (var ri = 0; ri < rowEls.length; ri++) {
          if (rowEls[ri] === fromEl || (fromEl.contains && fromEl.contains(rowEls[ri]))) {
            i = ri;
            break;
          }
        }
      }
      if (i >= 0 && i < rowEls.length - 1) {
        focusElement(rowEls[i + 1], true);
        return;
      }
      if (!lineHasItem(curLn)) {
        focusLineField(idx, '.js-item-sku', true);
        hxAlert('اختر المادة أولاً قبل الانتقال لسطر جديد.', {
          title: 'تنبيه',
          kind: 'warning',
        });
        return;
      }
      goToNextLineSku(idx);
      return;
    }

    var headers = headerNavEls();
    var hi = headers.indexOf(fromEl);
    if (hi >= 0 && hi < headers.length - 1) {
      focusElement(headers[hi + 1], true);
      return;
    }
    if (hi === headers.length - 1 || fromEl.id === 'co_customer') {
      if (!(state.lines || []).length) addEmptyLine({ force: true });
      focusLineField(0, '.js-item-sku', true);
    }
  }

  function goNextField(fromEl) {
    if (locked || !fromEl) return;
    var itemSug =
      fromEl.closest && fromEl.closest('tr[data-idx]')
        ? openSuggestForRow(fromEl.closest('tr[data-idx]'))
        : null;
    if (
      itemSug &&
      !itemSug.hidden &&
      itemSug.querySelector('button') &&
      (fromEl.classList.contains('js-item-sku') ||
        fromEl.classList.contains('js-item-code') ||
        fromEl.classList.contains('js-item-name'))
    ) {
      if (pickActiveSuggest(itemSug)) return;
    } else if (fromEl.id === 'co_customer') {
      var suggest = getOpenItemSuggest(fromEl);
      if (suggest && !suggest.hidden && suggest.querySelector('button')) {
        if (pickActiveSuggest(suggest) || suggest.querySelector('button')) {
          var btn = suggest.querySelector('button.is-active') || suggest.querySelector('button');
          if (btn) {
            btn.click();
            return;
          }
        }
      }
    }

    var trItem = fromEl.closest ? fromEl.closest('tr[data-idx]') : null;
    if (
      trItem &&
      (fromEl.classList.contains('js-item-sku') || fromEl.classList.contains('js-item-code'))
    ) {
      resolveItemCodeOnEnter(fromEl, trItem, function (result) {
        if (result === 'empty' || result === 'ok') {
          if (itemSug && !itemSug.hidden) closeItemSuggest(itemSug);
          continueGoNextField(fromEl);
        }
        // picked / missing / error — لا تنتقل
      });
      return;
    }

    if (itemSug && !itemSug.hidden) closeItemSuggest(itemSug);
    continueGoNextField(fromEl);
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
    focusLineField(nextIdx, cls || '.js-item-sku', true);
  }

  function goHorizontalField(fromEl, dir) {
    // dir: +1 next (visual left in RTL table still sequential field order)
    if (dir > 0) goNextField(fromEl);
    else goPrevField(fromEl);
  }

  function onUnitChange(tr) {
    var unitEl = tr.querySelector('.js-unit');
    if (!unitEl) return;
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
  }

  function bindUnitOnly(tr) {
    var unitEl = tr.querySelector('.js-unit');
    if (!unitEl || unitEl.getAttribute('data-hx-bound') === '1') return;
    unitEl.setAttribute('data-hx-bound', '1');
    unitEl.addEventListener('change', function () {
      onUnitChange(tr);
    });
  }

  function openItemsPickerForRow(tr) {
    if (locked || !tr) return;
    var field = tr.querySelector('.js-item-sku') || tr.querySelector('.js-item-code');
    if (field) {
      try {
        field.focus();
      } catch (e) {
        /* ignore */
      }
    }
    window.setTimeout(function () {
      if (window.HypexShortcuts && typeof window.HypexShortcuts.items === 'function') {
        window.HypexShortcuts.items();
        return;
      }
      if (field) openItemListForField(field);
    }, 0);
  }

  function bindItemPickButton(tr) {
    if (!tr || locked) return;
    var btn = tr.querySelector('.js-item-pick');
    if (!btn || btn.getAttribute('data-co-pick') === '1') return;
    btn.setAttribute('data-co-pick', '1');
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      openItemsPickerForRow(tr);
    });
  }

  function bindRow(tr) {
    if (!tr) return;
    if (tr.getAttribute('data-hx-bound') === '1') {
      bindUnitOnly(tr);
      bindItemPickButton(tr);
      return;
    }
    tr.setAttribute('data-hx-bound', '1');
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
    bindUnitOnly(tr);
    var skuInput = tr.querySelector('.js-item-sku');
    var codeInput = tr.querySelector('.js-item-code');
    var nameInput = tr.querySelector('.js-item-name');

    function clearLineItemChoice(idx) {
      var hid = tr.querySelector('.js-item-id');
      if (hid) hid.value = '';
      if (nameInput) {
        nameInput.value = '';
        nameInput.readOnly = false;
      }
      if (state.lines[idx]) {
        state.lines[idx].item_id = 0;
        state.lines[idx].name_ar = '';
      }
    }

    if (skuInput && !locked) {
      skuInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        clearLineItemChoice(idx);
        if (codeInput) codeInput.value = '';
        if (state.lines[idx]) {
          state.lines[idx].item_sku = String(skuInput.value || '').trim();
          state.lines[idx].item_barcode = '';
          state.lines[idx].item_code = '';
        }
        clearTimeout(itemTimers['s' + idx]);
        itemTimers['s' + idx] = setTimeout(function () {
          if (String(skuInput.value || '').trim()) openItemListForField(skuInput);
          else closeItemSuggest(getCoItemSuggest());
        }, 160);
      });
    }

    if (codeInput && !locked) {
      codeInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        clearLineItemChoice(idx);
        if (skuInput) skuInput.value = '';
        if (state.lines[idx]) {
          state.lines[idx].item_code = cleanBarcodeText(codeInput.value);
          state.lines[idx].item_barcode = state.lines[idx].item_code;
          state.lines[idx].item_sku = '';
        }
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          if (String(codeInput.value || '').trim()) openItemListForField(codeInput);
          else closeItemSuggest(getCoItemSuggest());
        }, 160);
      });
    }
    if (nameInput && !locked) {
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
          if (String(nameInput.value || '').trim()) openItemListForField(nameInput);
          else closeItemSuggest(getCoItemSuggest());
        }, 160);
      });
    }
    bindItemPickButton(tr);
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
    markFormDirty();
  }

  function searchItems(q, box, tr, anchor) {
    if (!box) return;
    if (isSystemItemModalOpen()) {
      closeItemSuggest(box);
      return;
    }
    ensureSuggestHome(box, tr);
    var token = String((searchItems._seq = (searchItems._seq || 0) + 1));
    box._coSearchToken = token;
    // وضع القائمة: رقم مادة / باركود / اسم
    var mode = 'barcode';
    if (anchor && anchor.classList) {
      if (anchor.classList.contains('js-item-name')) mode = 'name';
      else if (anchor.classList.contains('js-item-sku')) mode = 'sku';
    }
    box.classList.remove('si-suggest--barcode', 'si-suggest--name', 'si-suggest--sku');
    box.classList.add(
      mode === 'name' ? 'si-suggest--name' : mode === 'sku' ? 'si-suggest--sku' : 'si-suggest--barcode'
    );
    box.setAttribute('data-mode', mode);
    box.dataset.hxUserNav = '';

    var urls = [
      '/api/sales/customer-orders/items?q=' + encodeURIComponent(q || ''),
      '/api/items?q=' + encodeURIComponent(q || ''),
      '/api/lookup/items?q=' + encodeURIComponent(q || ''),
    ];

    function finish(data) {
      if (box._coSearchToken !== token) return;
      if (isSystemItemModalOpen()) {
        closeItemSuggest(box);
        return;
      }
      box.innerHTML = '';
      box.dataset.hxUserNav = '';
      box._hxRows = [];
      if (!data || !data.ok) {
        var err = document.createElement('div');
        err.className = 'si-suggest-empty';
        err.style.cssText = 'padding:.55rem .75rem;color:#b91c1c;font-size:.82rem;text-align:right';
        err.textContent = (data && data.error) || 'تعذر تحميل المواد';
        box.appendChild(err);
        placeFloatSuggest(box, anchor || tr.querySelector('.js-item-sku') || tr.querySelector('.js-item-code'));
        return;
      }
      var rows = data.rows || data.items || [];
      box._hxRows = rows;
      if (!rows.length) {
        var empty = document.createElement('div');
        empty.className = 'si-suggest-empty';
        empty.style.cssText = 'padding:.55rem .75rem;color:#64748b;font-size:.82rem;text-align:right';
        empty.textContent = q
          ? 'لا توجد نتائج مطابقة'
          : mode === 'name'
            ? 'اكتب اسم المادة…'
            : mode === 'sku'
              ? 'اكتب رقم المادة…'
              : 'اكتب الباركود…';
        box.appendChild(empty);
        placeFloatSuggest(box, anchor || tr.querySelector('.js-item-sku') || tr.querySelector('.js-item-code'));
        return;
      }
      rows.slice(0, 30).forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.tabIndex = -1;
        var code = itemBarcodeOnly(it);
        var sku = itemSkuOnly(it);
        var nm = itemNameOnly(it);
        if (mode === 'name') {
          b.textContent = nm || sku || code;
          b.title = sku || code ? 'رقم/باركود: ' + (sku || code) : nm;
        } else if (mode === 'sku') {
          b.textContent = (sku || code || nm) + (nm && sku ? ' — ' + nm : '');
          b.title = nm ? nm + (code ? ' · باركود ' + code : '') : code || sku;
        } else {
          b.textContent = (code || sku || nm) + (nm && code ? ' — ' + nm : '');
          b.title = nm ? nm + (sku ? ' · رقم ' + sku : '') : sku || code;
        }
        b.setAttribute('data-item-id', String(it.id || ''));
        b.setAttribute('data-barcode', code);
        b.setAttribute('data-sku', sku);
        b.setAttribute('data-name', nm);
        b.addEventListener('mousedown', function (e) {
          e.preventDefault();
        });
        b.addEventListener('click', function () {
          box.dataset.hxUserNav = '1';
          closeItemSuggest(box);
          pickItemIntoRow(tr, it, true);
        });
        box.appendChild(b);
      });
      placeFloatSuggest(box, anchor || tr.querySelector('.js-item-sku') || tr.querySelector('.js-item-code'));
    }

    function tryFetch(i) {
      if (i >= urls.length) {
        finish({ ok: false, error: 'تعذر تحميل المواد' });
        return;
      }
      fetch(urls[i], { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (data) {
          finish(data || { ok: false });
        })
        .catch(function () {
          tryFetch(i + 1);
        });
    }
    tryFetch(0);
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
          focusLineField(i, '.js-item-sku', true);
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
      item_sku: '',
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
    markFormDirty();
    var tr = tbody && tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (tr) {
      patchLineRow(tr, state.lines[idx]);
      window.setTimeout(function () {
        focusLineField(idx, '.js-qty', true);
      }, 0);
    } else {
      renderLines({ idx: idx, cls: '.js-qty', select: true });
    }
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
  }

  document.addEventListener('click', function (e) {
    if (!document.getElementById('co-doc-bar')) return;
    if (custBox && custInput && !custBox.contains(e.target) && e.target !== custInput) {
      custBox.hidden = true;
      custBox.setAttribute('hidden', '');
    }
  });

  document.addEventListener('mousedown', function (e) {
    if (!document.getElementById('co-doc-bar')) return;
    var box = getCoItemSuggest();
    if (!box || box.hidden) return;
    if (box.contains(e.target)) return;
    var t = e.target;
    if (
      t &&
      t.classList &&
      (t.classList.contains('js-item-sku') ||
        t.classList.contains('js-item-code') ||
        t.classList.contains('js-item-name'))
    ) {
      return;
    }
    window.setTimeout(function () {
      if (!box || box.hidden) return;
      if (Date.now() < coSuggestGuardUntil) return;
      var ae = document.activeElement;
      if (
        ae &&
        ae.classList &&
        (ae.classList.contains('js-item-sku') ||
          ae.classList.contains('js-item-code') ||
          ae.classList.contains('js-item-name')) &&
        box._hxRow &&
        box._hxRow.contains(ae)
      ) {
        return;
      }
      if (box.contains(ae)) return;
      closeItemSuggest(box);
    }, 20);
  });

  window.addEventListener(
    'scroll',
    function () {
      if (!document.getElementById('co-doc-bar')) return;
      var box = getCoItemSuggest();
      if (box.hidden) return;
      var ae = document.activeElement;
      if (
        ae &&
        (ae.classList.contains('js-item-sku') ||
          ae.classList.contains('js-item-code') ||
          ae.classList.contains('js-item-name'))
      ) {
        placeFloatSuggest(box, ae);
      }
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
      hxAlert('اختر العميل.', { title: 'تنبيه', kind: 'warning' });
      return Promise.resolve(null);
    }
    if (!payload.warehouse_id) {
      hxAlert('اختر المستودع.', { title: 'تنبيه', kind: 'warning' });
      return Promise.resolve(null);
    }
    if (!payload.lines.length) {
      hxAlert('أضف بنداً واحداً على الأقل.', { title: 'تنبيه', kind: 'warning' });
      return Promise.resolve(null);
    }
    for (var pi = 0; pi < payload.lines.length; pi++) {
      if (!(Number(payload.lines[pi].unit_price) > 0)) {
        hxAlert('سعر المادة في البطاقة صفر. حدّد سعر البيع من شاشة تعديل الأسعار.', {
          title: 'تنبيه',
          kind: 'warning',
        });
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
          var saveErr = data.error || 'تعذر الحفظ';
          setMsg(saveErr, 'error');
          if (isOrderLockError(saveErr)) {
            refreshOrderPageSoon();
          }
          return null;
        }
        setMsg('تم الحفظ · ' + (data.order_no || ''), 'ok');
        clearFormDirty();
        if (data.order_no) {
          var titleEl = document.getElementById('co-screen-title');
          if (titleEl) titleEl.textContent = 'طلب ' + data.order_no;
        }
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
      var confirmMsg =
        'اعتماد هذا الطلب؟' +
        (formDirty ? '\n\nسيتم حفظ التعديلات الحالية أولاً ثم الاعتماد.' : '');
      hxConfirm(confirmMsg, { title: 'اعتماد', okLabel: 'اعتماد' }).then(function (ok) {
        if (!ok) return;
        var runApprove = function () {
          postAction(
            '/api/sales/customer-orders/' + state.id + '/approve',
            '/sales/orders/' + state.id
          );
        };
        if (formDirty) {
          saveOrder().then(function (data) {
            if (data && data.ok) runApprove();
          });
        } else {
          runApprove();
        }
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
  var batchModal = document.getElementById('co-batch-modal');
  var batchRows = document.getElementById('co-batch-rows');
  var batchSub = document.getElementById('co-batch-sub');
  var batchCancel = document.getElementById('co-batch-cancel');
  var batchConfirm = document.getElementById('co-batch-confirm');
  var pickerData = null;

  function fmtBatchQty(n) {
    n = Number(n) || 0;
    return String(n).indexOf('.') >= 0 ? n.toFixed(3).replace(/\.?0+$/, '') : String(n);
  }

  function closeBatchModal() {
    if (batchModal) {
      batchModal.hidden = true;
      batchModal.setAttribute('aria-hidden', 'true');
    }
    pickerData = null;
  }

  function batchOptionLabel(b) {
    var label = (b.batch || '?') + ' — رصيد ' + fmtBatchQty(b.qty);
    if (b.qty_stock != null && Number(b.qty_stock) >= 0 && Math.abs(Number(b.qty) - Number(b.qty_stock)) > 0.0001) {
      label += ' (STOCK ' + fmtBatchQty(b.qty_stock) + ')';
    }
    if (b.exp_date) label += ' — ' + b.exp_date;
    return label;
  }

  function findBatchMeta(batches, batchId) {
    batchId = String(batchId || '').trim();
    if (!batchId || !Array.isArray(batches)) return null;
    for (var i = 0; i < batches.length; i++) {
      if (String(batches[i].batch || '') === batchId) return batches[i];
    }
    return null;
  }

  function catOptionLabel(opt) {
    var code = String(opt.cat || '');
    var name = String(opt.name || '');
    if (code && name && name.indexOf(code) < 0) return code + ' — ' + name;
    return name || code || '—';
  }

  function buildCatSelect(ln, categories) {
    var sel = document.createElement('select');
    sel.className = 'si-field co-cat-select';
    sel.dataset.srl = String(ln.srl || '');
    sel.dataset.item = String(ln.item || '');

    var options = Array.isArray(ln.cat_options) && ln.cat_options.length ? ln.cat_options : [];
    if (!options.length && Array.isArray(categories)) {
      categories.forEach(function (c) {
        options.push(c);
      });
    }
    if (!options.length && ln.cat) {
      options.push({ cat: ln.cat, name: 'فئة ' + ln.cat });
    }

    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '— اختر الفئة —';
    sel.appendChild(opt0);

    options.forEach(function (opt) {
      var o = document.createElement('option');
      o.value = String(opt.cat || '');
      o.textContent = catOptionLabel(opt);
      sel.appendChild(o);
    });

    var cur = String(ln.cat || '');
    if (cur) {
      sel.value = cur;
      if (sel.value !== cur) {
        var extra = document.createElement('option');
        extra.value = cur;
        extra.textContent = cur + ' (غير موجودة بالقائمة)';
        sel.appendChild(extra);
        sel.value = cur;
      }
    }

    return sel;
  }

  function collectCatPicks() {
    var picks = [];
    if (!batchRows) return picks;
    var seen = {};
    batchRows.querySelectorAll('select.co-cat-select').forEach(function (sel) {
      var srl = parseInt(sel.dataset.srl || '0', 10);
      if (srl < 1 || seen[srl]) return;
      seen[srl] = true;
      var cat = (sel.value || '').trim();
      if (!cat) return;
      picks.push({ srl: srl, cat: cat });
    });
    return picks;
  }

  var catReloadBusy = false;

  function reloadBatchesForCatChange(changedSel) {
    if (!state.id || catReloadBusy) return;
    var cat = (changedSel.value || '').trim();
    if (!cat) {
      validateBatchModal();
      return;
    }
    catReloadBusy = true;
    if (batchConfirm) batchConfirm.disabled = true;
    var catPicks = collectCatPicks();
    fetch('/api/sales/customer-orders/' + state.id + '/oracle-batches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cat_picks: catPicks }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        catReloadBusy = false;
        if (!data.ok) {
          hxAlert(data.message || data.error || 'تعذر تحديث التشغيلات.', {
            title: 'الفئة',
            kind: 'warning',
          });
          validateBatchModal();
          return;
        }
        openBatchModal(data);
      })
      .catch(function () {
        catReloadBusy = false;
        hxAlert('تعذر الاتصال بالخادم', { title: 'الفئة', kind: 'warning' });
        validateBatchModal();
      });
  }

  function buildBatchSelect(ln, alloc, batches) {
    var sel = document.createElement('select');
    sel.className = 'si-field co-batch-select';
    sel.dataset.srl = String(ln.srl || '');
    sel.dataset.item = String(ln.item || '');
    sel.dataset.need = String(ln.need || '');

    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '— اختر التشغيلة —';
    sel.appendChild(opt0);

    (batches || []).forEach(function (b) {
      var o = document.createElement('option');
      o.value = b.batch || '';
      o.textContent = batchOptionLabel(b);
      o.dataset.qty = String(b.qty || '');
      sel.appendChild(o);
    });

    var cur = String(alloc.batch || '');
    if (cur) {
      sel.value = cur;
      if (sel.value !== cur) {
        var extra = document.createElement('option');
        extra.value = cur;
        extra.textContent = cur + ' (غير موجودة بالقائمة)';
        sel.appendChild(extra);
        sel.value = cur;
      }
    }

    return sel;
  }

  function buildTakeInput(ln, alloc) {
    var inp = document.createElement('input');
    inp.type = 'number';
    inp.className = 'co-batch-take-input';
    inp.step = '0.001';
    inp.min = '0';
    inp.inputMode = 'decimal';
    inp.value = String(Number(alloc.take) || 0);
    inp.dataset.srl = String(ln.srl || '');
    inp.title = 'عدّل الكمية المأخوذة من هذه التشغيلة';
    inp.addEventListener('input', validateBatchModal);
    inp.addEventListener('change', validateBatchModal);
    return inp;
  }

  function rowTakeValue(tr) {
    var inp = tr.querySelector('input.co-batch-take-input');
    if (inp) {
      var n = Number(inp.value);
      return isFinite(n) && n > 0 ? n : 0;
    }
    var sel = tr.querySelector('select.co-batch-select');
    return sel ? Number(sel.dataset.take) || 0 : 0;
  }

  function setBatchStatus(text, kind) {
    var el = document.getElementById('co-batch-status');
    if (!el) return;
    el.textContent = text || '';
    el.classList.remove('is-error', 'is-ok');
    if (kind === 'error') el.classList.add('is-error');
    if (kind === 'ok') el.classList.add('is-ok');
  }

  function validateBatchModal() {
    if (!batchRows || !batchConfirm) return true;
    var ok = true;
    var usageBySrl = {};
    var takeSumBySrl = {};
    var needBySrl = {};
    var statusMsg = '';

    batchRows.querySelectorAll('select.co-cat-select').forEach(function (sel) {
      var srl = String(sel.dataset.srl || '');
      if (!srl || !(sel.value || '').trim()) {
        ok = false;
        if (!statusMsg) statusMsg = 'اختر الفئة لكل مادة.';
      }
    });

    batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
      var sel = tr.querySelector('select.co-batch-select');
      if (!sel) return;
      var batch = (sel.value || '').trim();
      var take = rowTakeValue(tr);
      var srl = String(sel.dataset.srl || tr.dataset.srl || '');
      var need = Number(tr.dataset.need || sel.dataset.need) || 0;
      if (srl) {
        takeSumBySrl[srl] = (takeSumBySrl[srl] || 0) + take;
        if (need > 0) needBySrl[srl] = need;
      }
      if (!batch || !srl) return;
      if (!usageBySrl[srl]) usageBySrl[srl] = {};
      usageBySrl[srl][batch] = (usageBySrl[srl][batch] || 0) + take;
    });

    Object.keys(needBySrl).forEach(function (srl) {
      var need = needBySrl[srl];
      var got = takeSumBySrl[srl] || 0;
      if (got + 0.0001 < need) {
        ok = false;
        if (!statusMsg) {
          statusMsg =
            'الكميات من التشغيلات لا تغطي المطلوب (مجموع ' +
            fmtBatchQty(got) +
            ' من ' +
            fmtBatchQty(need) +
            '). عدّل الكميات أو اختر تشغيلات أخرى.';
        }
      } else if (got - need > 0.0001) {
        ok = false;
        if (!statusMsg) {
          statusMsg =
            'مجموع الكميات من التشغيلات أكبر من المطلوب (' +
            fmtBatchQty(got) +
            ' > ' +
            fmtBatchQty(need) +
            ').';
        }
      }
    });

    batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
      var sel = tr.querySelector('select.co-batch-select');
      var balEl = tr.querySelector('.co-batch-col-bal');
      var takeInp = tr.querySelector('input.co-batch-take-input');
      if (!sel) return;
      var batch = (sel.value || '').trim();
      var take = rowTakeValue(tr);
      var srl = String(sel.dataset.srl || tr.dataset.srl || '');
      var batches = [];
      try {
        batches = JSON.parse(tr.dataset.batches || '[]');
      } catch (e) {
        batches = [];
      }
      tr.classList.remove('co-batch-row--invalid');
      if (takeInp) takeInp.classList.remove('co-batch-take--bad');
      if (!batch || take <= 0) {
        ok = false;
        tr.classList.add('co-batch-row--invalid');
        if (takeInp && take <= 0) takeInp.classList.add('co-batch-take--bad');
        if (balEl && !batch) balEl.textContent = '—';
        if (!statusMsg) statusMsg = 'اختر تشغيلة وأدخل كمية أكبر من صفر لكل سطر.';
        return;
      }
      var meta = findBatchMeta(batches, batch);
      var usedOnBatch = (usageBySrl[srl] && usageBySrl[srl][batch]) || take;
      if (balEl) balEl.textContent = meta ? fmtBatchQty(meta.qty) : '—';
      if (!meta || Number(meta.qty) < usedOnBatch - 0.0001 || Number(meta.qty) < take - 0.0001) {
        ok = false;
        tr.classList.add('co-batch-row--invalid');
        if (takeInp) takeInp.classList.add('co-batch-take--bad');
        if (!statusMsg) {
          statusMsg =
            'الكمية أكبر من رصيد التشغيلة ' +
            batch +
            ' (رصيد ' +
            (meta ? fmtBatchQty(meta.qty) : '0') +
            ').';
        }
      }
    });

    if (!batchRows.querySelector('tr[data-alloc-row="1"]') && !statusMsg) {
      ok = false;
      statusMsg = 'لا توجد تشغيلات كافية للترحيل.';
    }

    batchConfirm.disabled = !ok;
    if (ok) setBatchStatus('جاهز للترحيل — مجموع الكميات يطابق المطلوب.', 'ok');
    else setBatchStatus(statusMsg || 'تحقق من الكميات والتشغيلات قبل الترحيل.', 'error');
    return ok;
  }

  function collectBatchAllocations() {
    var picks = [];
    if (!batchRows) return picks;
    batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
      var sel = tr.querySelector('select.co-batch-select');
      if (!sel) return;
      var batch = (sel.value || '').trim();
      if (!batch) return;
      picks.push({
        srl: parseInt(sel.dataset.srl || tr.dataset.srl || '0', 10),
        item: sel.dataset.item || '',
        batch: batch,
        take: rowTakeValue(tr),
        cat: catForSrl(parseInt(sel.dataset.srl || tr.dataset.srl || '0', 10)),
      });
    });
    return picks;
  }

  function catForSrl(srl) {
    if (!batchRows || srl < 1) return '';
    var sel = batchRows.querySelector('select.co-cat-select[data-srl="' + srl + '"]');
    return sel ? (sel.value || '').trim() : '';
  }

  function openBatchModal(data) {
    pickerData = data;
    if (!batchRows || !batchModal) return;
    batchRows.innerHTML = '';
    if (batchSub) {
      batchSub.textContent =
        'مستودع ' +
        (data.store || '—') +
        (data.warehouse_name ? ' — Oracle: ' + data.warehouse_name : '') +
        ' · المصدر: MAS.BALANCE (QTY_OH) مثل Forms · عدّل الكمية ثم رحّل.';
    }
    var rowNo = 0;
    (data.lines || []).forEach(function (ln) {
      var allocs = Array.isArray(ln.allocations) ? ln.allocations : [];
      var batches = Array.isArray(ln.batches) ? ln.batches : [];

      if (!allocs.length) {
        rowNo += 1;
        var tr0 = document.createElement('tr');
        tr0.className = 'co-batch-row--nostock';
        tr0.dataset.srl = String(ln.srl || '');
        tr0.dataset.shortfall = '1';
        tr0.innerHTML =
          '<td class="co-batch-col-idx">' +
          rowNo +
          '</td>' +
          '<td class="co-batch-col-item"><span class="co-batch-item-code">' +
          escAttr(String(ln.item || '')) +
          '</span><span class="co-batch-item-name">' +
          escAttr(String(ln.name || '')) +
          '</span></td>' +
          '<td class="co-batch-col-cat"></td>' +
          '<td class="co-batch-col-need">' +
          fmtBatchQty(ln.need) +
          '</td>' +
          '<td class="co-batch-col-take">—</td>' +
          '<td class="co-batch-col-batch" colspan="2"><div class="co-batch-warn">التشغيلات لا تغطي المطلوب' +
          (Number(ln.shortfall) > 0 ? ' (نقص ' + fmtBatchQty(ln.shortfall) + ')' : '') +
          ' — لا يمكن الترحيل.</div></td>';
        var tdCat0 = tr0.querySelector('.co-batch-col-cat');
        if (tdCat0) {
          var catSel0 = buildCatSelect(ln, data.categories || []);
          catSel0.addEventListener('change', function () {
            reloadBatchesForCatChange(catSel0);
          });
          tdCat0.appendChild(catSel0);
        }
        batchRows.appendChild(tr0);
        return;
      }

      allocs.forEach(function (a, ai) {
        rowNo += 1;
        var tr = document.createElement('tr');
        tr.setAttribute('data-alloc-row', '1');
        tr.dataset.batches = JSON.stringify(batches);
        tr.dataset.srl = String(ln.srl || '');
        tr.dataset.need = String(ln.need || '');
        if (!ln.allocation_ok && Number(ln.shortfall) > 0) {
          tr.dataset.shortfall = '1';
        }

        var tdIdx = document.createElement('td');
        tdIdx.className = 'co-batch-col-idx';
        tdIdx.textContent = String(rowNo);

        var tdName = document.createElement('td');
        tdName.className = 'co-batch-col-item';
        if (ai === 0) {
          tdName.innerHTML =
            '<span class="co-batch-item-code">' +
            escAttr(String(ln.item || '')) +
            '</span><span class="co-batch-item-name">' +
            escAttr(String(ln.name || '')) +
            '</span>';
        } else {
          tdName.innerHTML =
            '<span class="co-batch-item-cont muted">↳ استمرار نفس المادة</span>';
        }

        var tdCat = document.createElement('td');
        tdCat.className = 'co-batch-col-cat';
        if (ai === 0) {
          var catSel = buildCatSelect(ln, data.categories || []);
          catSel.addEventListener('change', function () {
            reloadBatchesForCatChange(catSel);
          });
          tdCat.appendChild(catSel);
        }

        var tdNeed = document.createElement('td');
        tdNeed.className = 'co-batch-col-need';
        tdNeed.textContent = ai === 0 ? fmtBatchQty(ln.need) : '';

        var tdTake = document.createElement('td');
        tdTake.className = 'co-batch-col-take';
        tdTake.appendChild(buildTakeInput(ln, a));

        var tdBatch = document.createElement('td');
        tdBatch.className = 'co-batch-col-batch';
        var sel = buildBatchSelect(ln, a, batches);
        sel.addEventListener('change', validateBatchModal);
        tdBatch.appendChild(sel);

        var tdBal = document.createElement('td');
        tdBal.className = 'co-batch-col-bal';
        var meta = findBatchMeta(batches, a.batch);
        tdBal.textContent = meta ? fmtBatchQty(meta.qty) : fmtBatchQty(a.batch_qty);

        tr.appendChild(tdIdx);
        tr.appendChild(tdName);
        tr.appendChild(tdCat);
        tr.appendChild(tdNeed);
        tr.appendChild(tdTake);
        tr.appendChild(tdBatch);
        tr.appendChild(tdBal);
        batchRows.appendChild(tr);
      });

      if (!ln.allocation_ok && Number(ln.shortfall) > 0) {
        var trW = document.createElement('tr');
        trW.className = 'co-batch-row--nostock';
        trW.dataset.shortfall = '1';
        trW.innerHTML =
          '<td></td><td></td><td colspan="5"><div class="co-batch-warn">الرصيد المتوفر لا يكفي — نقص ' +
          fmtBatchQty(ln.shortfall) +
          ' من المطلوب ' +
          fmtBatchQty(ln.need) +
          ' — عدّل الكميات/التشغيلات لتغطية المطلوب بالكامل، وإلا لن يُسمح بالترحيل.</div></td>';
        batchRows.appendChild(trW);
      }
    });

    validateBatchModal();
    batchModal.hidden = false;
    batchModal.setAttribute('aria-hidden', 'false');
  }

  function postOracleWithBatches(batchPicks) {
    if (!state.id) return;
    setMsg('جاري الترحيل إلى Oracle…');
    setBusy(true);
    fetch('/api/sales/customer-orders/' + state.id + '/post-oracle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ batch_picks: batchPicks || [] }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          showActionError(data);
          return;
        }
        closeBatchModal();
        window.location.reload();
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
      });
  }

  function startOraclePost() {
    if (!state.id) {
      setMsg('احفظ واعتمد الطلب أولاً.', 'error');
      return;
    }
    if (busy || !state.is_approved) {
      setMsg('اعتمد الطلب أولاً ثم رحّله إلى Oracle.', 'error');
      return;
    }
    setMsg('جاري جلب التشغيلات من Oracle…');
    setBusy(true);
    fetch('/api/sales/customer-orders/' + state.id + '/oracle-batches', {
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
          setMsg(data.message || data.error || 'تعذر جلب التشغيلات.', 'error');
          return;
        }
        setMsg('');
        openBatchModal(data);
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
      });
  }

  if (batchCancel) batchCancel.addEventListener('click', closeBatchModal);
  if (batchConfirm) {
    batchConfirm.addEventListener('click', function () {
      if (!pickerData || !pickerData.lines) return;
      if (!validateBatchModal()) {
        hxAlert(
          'لا يمكن الترحيل: التشغيلات يجب أن تغطي الكمية المطلوبة بالكامل، دون تجاوز رصيد أي تشغيلة.',
          {
            title: 'ترحيل الكميات',
            kind: 'warning',
          }
        );
        return;
      }
      var picks = collectBatchAllocations();
      var expected = batchRows ? batchRows.querySelectorAll('tr[data-alloc-row="1"]').length : 0;
      if (picks.length < expected) {
        hxAlert('اختر تشغيلة لكل سطر قبل الترحيل.', { title: 'ترحيل الكميات', kind: 'warning' });
        return;
      }
      hxConfirm('سيتم ترحيل الكميات بالتشغيلات المعروضة.\nهل تريد المتابعة؟', {
        title: 'ترحيل الكميات',
        okLabel: 'ترحيل الكميات',
      }).then(function (ok) {
        if (!ok) return;
        postOracleWithBatches(picks);
      });
    });
  }

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
      startOraclePost();
    });
  }

  var delBtn = document.getElementById('co-delete');
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      if (busy || !state.id || locked) return;
      fetch('/api/sales/customer-orders/' + state.id, {
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (data && data.ok && data.order && data.order.is_approved) {
            setMsg('الطلب معتمد — فك الاعتماد أولاً.', 'error');
            refreshOrderPageSoon();
            return;
          }
          hxConfirm(
            'تحذير: سيتم حذف بنود الطلب ثم حذف الطلب نهائياً.\nلا يمكن التراجع.',
            { title: 'حذف الطلب', okLabel: 'حذف نهائياً', danger: true }
          ).then(function (ok) {
            if (!ok) return;
            postAction('/api/sales/customer-orders/' + state.id + '/delete', '/sales/orders');
          });
        })
        .catch(function () {
          setMsg('تعذر التحقق من حالة الطلب', 'error');
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
      leaveTo('/sales/orders');
    });
  }

  var newBtn = document.getElementById('co-new');
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      leaveTo('/sales/orders/new');
    });
  }

  function leaveTo(href) {
    if (!href) return;
    if (window.ScreenExitGuard && typeof window.ScreenExitGuard.confirmLeave === 'function') {
      window.ScreenExitGuard.confirmLeave(function () {
        if (window.ScreenExitGuard.navigateExit) window.ScreenExitGuard.navigateExit(href);
        else window.location.href = href;
      });
      return;
    }
    window.location.href = href;
  }

  function confirmUnsavedChanges(onProceed, onCancel) {
    if (window.ScreenExitGuard && typeof window.ScreenExitGuard.confirmSaveDiscardLeave === 'function') {
      window.ScreenExitGuard.confirmSaveDiscardLeave({
        when: function () {
          return formDirty && !locked;
        },
        onSave: function (proceed) {
          saveOrder().then(function (data) {
            if (data && data.ok) {
              clearFormDirty();
              if (proceed) proceed();
            }
          });
        },
        onDiscard: function () {
          clearFormDirty();
        },
        onProceed: onProceed,
        onCancel: onCancel,
      });
      return;
    }
    if (!formDirty || locked) {
      if (onProceed) onProceed();
      return;
    }
    if (window.confirm('هناك تعديلات غير محفوظة. المتابعة بدون حفظ؟')) {
      clearFormDirty();
      if (onProceed) onProceed();
    } else if (onCancel) {
      onCancel();
    }
  }

  if (window.ScreenExitGuard && typeof window.ScreenExitGuard.registerScreenExitDeferred === 'function') {
    window.ScreenExitGuard.registerScreenExitDeferred({
      hasUnsaved: function () {
        return formDirty && !locked;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else if (window.ScreenExitGuard && typeof window.ScreenExitGuard.registerScreenExit === 'function') {
    window.ScreenExitGuard.registerScreenExit({
      hasUnsaved: function () {
        return formDirty && !locked;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  }

  window.addEventListener('beforeunload', function (e) {
    if (window.__managerAllowUnload) return;
    if (!formDirty || locked) return;
    e.preventDefault();
    e.returnValue = '';
  });

  var stageEl = document.querySelector('.si-stage');
  if (stageEl && !locked) {
    stageEl.addEventListener('input', markFormDirtyFromEvent, true);
    stageEl.addEventListener('change', markFormDirtyFromEvent, true);
  }

  renderLines();
  setCustomerPriceMode({ use_wholesale_price: state.use_wholesale_price }, { reprice: false });
  clearFormDirty();
  updateDocNoStyle();

  (function bindItemsHintClick() {
    var hint = document.querySelector('.si-surface-head .si-key-hint[title="قائمة المواد"]');
    if (!hint || locked) return;
    hint.style.cursor = 'pointer';
    hint.addEventListener('click', function () {
      var tr =
        (document.activeElement &&
          document.activeElement.closest &&
          document.activeElement.closest('tr[data-idx]')) ||
        (tbody && tbody.querySelector('tr[data-idx]'));
      if (tr) openItemsPickerForRow(tr);
    });
  })();

  try {
    window.scrollTo(0, 0);
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
  } catch (e) {
    /* ignore */
  }

  var initialCustomerId =
    Number((document.getElementById('co_customer_id') || {}).value || state.customer_id || 0) || 0;
  if (initialCustomerId > 0) {
    loadCustomerAr(initialCustomerId, { scroll: false });
  }

  var refreshArBtn = document.getElementById('co-ora-ar-refresh');
  if (refreshArBtn) {
    refreshArBtn.addEventListener('click', function () {
      var cid =
        Number((document.getElementById('co_customer_id') || {}).value || 0) || 0;
      loadCustomerAr(cid, { scroll: false });
    });
  }

  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted && state.id) {
      window.location.reload();
    }
  });

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
