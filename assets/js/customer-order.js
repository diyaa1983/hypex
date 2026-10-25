(function () {
  'use strict';

  var root = document.getElementById('co-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');

  function nQty(v) {
    var n = Number(v);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }

  function unitKey(u) {
    var id = Number(u && u.unit_id) || 0;
    if (id) return String(id);
    return 'n:' + String((u && u.name) || '') + ':' + String((u && u.factor) || 1);
  }

  function sameUnit(a, b) {
    if (!a || !b) return false;
    if (Number(a.unit_id) > 0 && Number(a.unit_id) === Number(b.unit_id)) return true;
    return a.name === b.name && Number(a.factor) === Number(b.factor);
  }

  /** وحدات بطاقة المادة كما هي: الأكبر ثم الأصغر. بدون اختراع وحدة غير موجودة. */
  function splitUnits(ln) {
    var raw = ln && Array.isArray(ln.units) ? ln.units : [];
    var seen = {};
    var list = [];
    raw.forEach(function (u) {
      var fac = Number(u.factor) > 0 ? Number(u.factor) : 1;
      var name = String(u.name || '').trim() || 'وحدة';
      var row = {
        unit_id: Number(u.unit_id) || 0,
        name: name,
        factor: fac,
        is_base: !!(u.is_base || u.isBase) || fac <= 1.001,
        sale_price: u.sale_price,
        wholesale_price: u.wholesale_price,
      };
      var key = unitKey(row);
      if (seen[key]) return;
      seen[key] = 1;
      list.push(row);
    });
    if (!list.length) {
      var fac0 = Number(ln && ln.unit_factor) > 0 ? Number(ln.unit_factor) : 1;
      list.push({
        unit_id: Number(ln && ln.unit_id) || 0,
        name: String((ln && ln.unit_name) || 'قطعة'),
        factor: fac0,
        is_base: fac0 <= 1.001,
      });
    }
    list.sort(function (a, b) {
      return b.factor - a.factor;
    });
    var piece = null;
    list.forEach(function (u) {
      if (!(u.is_base || u.factor <= 1.001)) return;
      if (!piece || u.factor < piece.factor) piece = u;
    });
    if (!piece) piece = list[list.length - 1];
    var carton = null;
    list.forEach(function (u) {
      if (sameUnit(u, piece)) return;
      if (!carton || u.factor > carton.factor) carton = u;
    });
    return { piece: piece, carton: carton, list: list };
  }

  function qtyOf(ln, unit, kind) {
    var map = kind === 'extra' ? ln && ln.extra_by : ln && ln.qty_by;
    var key = unitKey(unit);
    if (map && Object.prototype.hasOwnProperty.call(map, key)) return map[key];
    var split = splitUnits(ln);
    if (kind === 'extra') {
      if (sameUnit(unit, split.piece)) return ln.extra_piece;
      if (sameUnit(unit, split.carton)) return ln.extra_carton;
    } else {
      if (sameUnit(unit, split.piece)) return ln.qty_piece;
      if (sameUnit(unit, split.carton)) return ln.qty_carton;
    }
    return '';
  }

  function hydrateQtySplit(ln) {
    ln = ln || {};
    if (!ln.qty_by || typeof ln.qty_by !== 'object') ln.qty_by = {};
    if (!ln.extra_by || typeof ln.extra_by !== 'object') ln.extra_by = {};
    if (
      ln.qty_piece != null ||
      ln.qty_carton != null ||
      ln.extra_piece != null ||
      ln.extra_carton != null ||
      Object.keys(ln.qty_by).length ||
      Object.keys(ln.extra_by).length
    ) {
      return ln;
    }
    var fac = Number(ln.unit_factor) > 0 ? Number(ln.unit_factor) : 1;
    var name = String(ln.unit_name || '');
    var isCarton = fac > 1.001 || name.indexOf('كرتون') >= 0;
    var key = Number(ln.unit_id) > 0 ? String(ln.unit_id) : 'n:' + name + ':' + fac;
    ln.qty_by[key] = ln.qty || '';
    ln.extra_by[key] = ln.qty_extra || '';
    ln.qty_piece = isCarton ? '' : ln.qty || '';
    ln.qty_carton = isCarton ? ln.qty || '' : '';
    ln.extra_piece = isCarton ? '' : ln.qty_extra || '';
    ln.extra_carton = isCarton ? ln.qty_extra || '' : '';
    return ln;
  }

  function mergeSplitLines(lines) {
    var out = [];
    function addMap(dst, src) {
      Object.keys(src || {}).forEach(function (k) {
        var sum = nQty(dst[k]) + nQty(src[k]);
        dst[k] = sum || '';
      });
    }
    (lines || []).forEach(function (raw) {
      var ln = hydrateQtySplit(Object.assign({}, raw));
      var prev = out.length ? out[out.length - 1] : null;
      if (prev && Number(prev.item_id) > 0 && Number(prev.item_id) === Number(ln.item_id)) {
        if (!prev.qty_by) prev.qty_by = {};
        if (!prev.extra_by) prev.extra_by = {};
        addMap(prev.qty_by, ln.qty_by);
        addMap(prev.extra_by, ln.extra_by);
        prev.qty_piece = nQty(prev.qty_piece) + nQty(ln.qty_piece) || '';
        prev.qty_carton = nQty(prev.qty_carton) + nQty(ln.qty_carton) || '';
        prev.extra_piece = nQty(prev.extra_piece) + nQty(ln.extra_piece) || '';
        prev.extra_carton = nQty(prev.extra_carton) + nQty(ln.extra_carton) || '';
        if (Array.isArray(ln.units) && ln.units.length) prev.units = ln.units;
        return;
      }
      out.push(ln);
    });
    return out;
  }

  state.lines = mergeSplitLines(state.lines || []);
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

  function hxPath(p) {
    if (typeof window.__hypexUrl === 'function') return window.__hypexUrl(p);
    var b = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
    if (!b || b === '/') return p;
    if (b.charAt(b.length - 1) === '/') b = b.slice(0, -1);
    if (!p || p.charAt(0) !== '/') return p;
    if (p === b || p.indexOf(b + '/') === 0) return p;
    return b + p;
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
      // لا نعرض «سعر البيع» بجانب العميل — الافتراضي معروف؛ الشارة فقط عند الجملة.
      if (customerUsesWholesale) {
        hint.textContent = 'سعر الجملة';
        hint.title = 'تسعير هذا العميل: سعر الجملة';
        hint.hidden = false;
        hint.classList.add('is-wholesale');
      } else {
        hint.textContent = '';
        hint.title = '';
        hint.hidden = true;
        hint.classList.remove('is-wholesale');
      }
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
    if (custInput) custInput.value = String(c.name_ar || c.name || '').trim() || String(c.code || '');
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
    if (ln.qty_piece == null) ln.qty_piece = '';
    if (ln.qty_carton == null) ln.qty_carton = '';
    if (ln.extra_piece == null) ln.extra_piece = '';
    if (ln.extra_carton == null) ln.extra_carton = '';
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
                var keepQtyFocus = state._holdQtyFocusIdx != null;
                patchLineRow(rows[ri], ln);
                if (keepQtyFocus) {
                  var focusIdx = Number(rows[ri].getAttribute('data-idx'));
                  if (Number.isFinite(focusIdx)) focusQtyAfterItemPick(focusIdx);
                }
                break;
              }
            }
          }
        },
      });
    }
    return ln;
  }

  function priceBoxHtml(ln) {
    var units = splitUnits(ln);
    var tax = Number(ln.tax_rate_percent);
    if (!Number.isFinite(tax)) tax = Number(defaultTax) || 0;
    function money(n) {
      return escAttr(fmt(n || 0));
    }
    function chip(kind, amount) {
      return (
        '<span class="co-price-chip"><i>' +
        kind +
        '</i><em dir="ltr">' +
        money(amount) +
        '</em></span>'
      );
    }
    function side(label, unit) {
      var ex = unit ? unitPriceOf(ln, unit) : 0;
      var inc = r3(ex * (1 + tax / 100));
      return (
        '<span class="co-price-side">' +
        '<b>' +
        escAttr(label) +
        '</b>' +
        '<span class="co-price-side__vals">' +
        chip('غ.ش', ex) +
        chip('ش', inc) +
        '</span></span>'
      );
    }
    var pieceEx = unitPriceOf(ln, units.piece) || Number(ln.unit_price) || 0;
    return (
      '<div class="co-price-box">' +
      side('كرتونة', units.carton) +
      side('قطعة', units.piece) +
      '<input type="hidden" class="js-price" value="' +
      escAttr(pieceEx) +
      '">' +
      '</div>'
    );
  }

  function qtyPairHtml(ln, kind, locked) {
    var units = splitUnits(ln);
    var piece = units.piece;
    var carton = units.carton;
    if (carton && piece && sameUnit(carton, piece)) carton = null;
    var extra = kind === 'extra';
    var ro = locked ? 'readonly' : '';
    function one(label, cls, unit, slotKey, fallback) {
      var val = unit ? qtyOf(ln, unit, extra ? 'extra' : 'qty') : fallback;
      var key = unit ? unitKey(unit) : slotKey;
      return (
        '<label class="co-qty-slot">' +
        '<span class="co-qty-slot__lab">' +
        escAttr(label) +
        '</span>' +
        '<input class="' +
        cls +
        '" type="number" step="1" min="0" inputmode="numeric" data-nav="1" data-unit-key="' +
        escAttr(key) +
        '" placeholder="0" value="' +
        escAttr(userNumAttr(val)) +
        '" ' +
        ro +
        '>' +
        '</label>'
      );
    }
    var pieceCls = extra ? 'js-uextra js-extra-piece' : 'js-uqty js-qty-piece js-qty';
    var cartonCls = extra ? 'js-uextra js-extra-carton' : 'js-uqty js-qty-carton';
    return (
      '<div class="co-qty-pair" title="' +
      (extra ? 'الكمية المجانية' : 'الكمية') +
      '">' +
      one(
        'كرتونة',
        cartonCls,
        carton,
        'slot:carton',
        extra ? ln.extra_carton : ln.qty_carton
      ) +
      one('قطعة', pieceCls, piece, 'slot:piece', extra ? ln.extra_piece : ln.qty_piece) +
      '</div>'
    );
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

  function setBusy(on, message) {
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
    if (window.AppBusy) {
      if (on && typeof window.AppBusy.show === 'function') {
        window.AppBusy.show(message || 'جاري التنفيذ…');
      } else if (!on && typeof window.AppBusy.hide === 'function') {
        window.AppBusy.hide(true);
      }
    }
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

  function unitPriceOf(ln, unit) {
    if (!unit) return 0;
    if (customerUsesWholesale && unit.wholesale_price != null && unit.wholesale_price !== '') {
      return rUnit(Number(unit.wholesale_price) || 0);
    }
    if (!customerUsesWholesale && unit.sale_price != null && unit.sale_price !== '') {
      return rUnit(Number(unit.sale_price) || 0);
    }
    return unitSalePrice(activeBaseOfLine(ln), unit.factor);
  }

  function lineTotals(ln) {
    var units = splitUnits(ln);
    var pieceP = unitPriceOf(ln, units.piece);
    var discPct = Number(ln.discount_pct) || 0;
    var taxRate = Number(ln.tax_rate_percent) || 0;
    var sub = 0;
    units.list.forEach(function (u) {
      sub += nQty(qtyOf(ln, u, 'qty')) * unitPriceOf(ln, u);
    });
    if (!sub && nQty(ln.qty)) sub = nQty(ln.qty) * (Number(ln.unit_price) || pieceP);
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
    var qtyEl = tr.querySelector('.js-qty-piece');
    var qtyCartonEl = tr.querySelector('.js-qty-carton');
    var qtyExtraEl = tr.querySelector('.js-extra-piece');
    var extraCartonEl = tr.querySelector('.js-extra-carton');
    if (!ln.qty_by || typeof ln.qty_by !== 'object') ln.qty_by = {};
    if (!ln.extra_by || typeof ln.extra_by !== 'object') ln.extra_by = {};
    tr.querySelectorAll('.js-uqty').forEach(function (el) {
      var key = el.getAttribute('data-unit-key') || '';
      if (key) ln.qty_by[key] = el.value;
    });
    tr.querySelectorAll('.js-uextra').forEach(function (el) {
      var key = el.getAttribute('data-unit-key') || '';
      if (key) ln.extra_by[key] = el.value;
    });
    var discEl = tr.querySelector('.js-disc');
    var taxEl = tr.querySelector('.js-tax');
    var codeEl = tr.querySelector('.js-item-code');
    var skuEl = tr.querySelector('.js-item-sku');
    var nameEl = tr.querySelector('.js-item-name');
    if (qtyEl) ln.qty_piece = qtyEl.value;
    if (qtyCartonEl) ln.qty_carton = qtyCartonEl.value;
    if (qtyExtraEl) ln.extra_piece = qtyExtraEl.value;
    if (extraCartonEl) ln.extra_carton = extraCartonEl.value;
    function sumMap(map) {
      var n = 0;
      Object.keys(map || {}).forEach(function (k) {
        n += nQty(map[k]);
      });
      return n;
    }
    ln.qty = sumMap(ln.qty_by) || nQty(ln.qty_piece) + nQty(ln.qty_carton);
    ln.qty_extra = sumMap(ln.extra_by) || nQty(ln.extra_piece) + nQty(ln.extra_carton);
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
    var priceCell = tr.querySelector('.co-price-cell');
    if (priceCell) priceCell.innerHTML = priceBoxHtml(ln);
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
        'placeholder="" data-nav="1" title="' +
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
        'placeholder="" data-nav="1" title="' +
        escAttr(barcode) +
        '" value="' +
        escAttr(barcode) +
        '" ' +
        (locked ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td class="si-item-name-cell">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" placeholder="" data-nav="1" title="' +
        escAttr(nameOnly) +
        '" value="' +
        escAttr(nameOnly) +
        '" ' +
        (locked || ln.item_id ? 'readonly' : '') +
        '>' +
        '</td>' +
        '<td class="si-unit-cell" hidden></td>' +
        '<td class="co-qty-cell">' +
        qtyPairHtml(ln, 'qty', locked) +
        '</td>' +
        '<td class="co-qty-cell">' +
        qtyPairHtml(ln, 'extra', locked) +
        '</td>' +
        '<td class="co-price-cell">' +
        priceBoxHtml(ln) +
        '</td>' +
        '<td><input class="js-disc" type="number" step="' +
        qtyStep() +
        '" min="0" max="100" data-nav="1" value="' +
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
    try {
      document.dispatchEvent(new CustomEvent('hx:lines-rendered', { bubbles: true }));
    } catch (e) {
      /* ignore */
    }
  }

  function focusLineField(idx, selector, doSelect) {
    if (!tbody) return;
    var tr = tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (!tr) return;
    var nodes = tr.querySelectorAll(selector);
    var el = null;
    for (var i = 0; i < nodes.length; i++) {
      var cand = nodes[i];
      if (!cand || cand.disabled) continue;
      if (cand.readOnly && cand.classList && cand.classList.contains('js-item-name')) continue;
      if (cand.readOnly && cand.tagName === 'INPUT') continue;
      el = cand;
      break;
    }
    if (!el) return;
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

  /** بعد اختيار المادة: التركيز على كمية الكرتونة والإبقاء عليه حتى يبدأ المستخدم بالكتابة */
  function focusQtyAfterItemPick(idx) {
    state._holdQtyFocusIdx = idx;
    function go() {
      if (state._holdQtyFocusIdx !== idx) return;
      focusLineField(idx, '.js-qty-carton', true);
      var tr = tbody && tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
      var ae = document.activeElement;
      if (tr && ae && tr.contains(ae) && ae.classList && ae.classList.contains('js-uqty')) return;
      focusLineField(idx, '.js-qty-piece', true);
      ae = document.activeElement;
      if (tr && ae && tr.contains(ae) && ae.classList && ae.classList.contains('js-uqty')) return;
      focusLineField(idx, '.js-uqty', true);
    }
    window.setTimeout(go, 0);
    window.setTimeout(go, 60);
  }

  function releaseQtyFocusHold(idx) {
    if (idx == null || state._holdQtyFocusIdx === idx) state._holdQtyFocusIdx = null;
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
    var qtyCell = tr.querySelectorAll('.co-qty-cell');
    if (qtyCell.length >= 2) {
      qtyCell[0].innerHTML = qtyPairHtml(ln, 'qty', locked);
      qtyCell[1].innerHTML = qtyPairHtml(ln, 'extra', locked);
    }
    var priceCell = tr.querySelector('.co-price-cell');
    if (priceCell) priceCell.innerHTML = priceBoxHtml(ln);
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
    if (focusNext) focusQtyAfterItemPick(idx);
  }

  /** ترتيب حقول التنقل Enter / الأسهم داخل السطر */
  var LINE_NAV = ['.js-item-sku', '.js-item-code', '.js-item-name', '.js-uqty', '.js-uextra', '.js-disc', '.js-tax'];

  function lineNavEls(tr) {
    var list = [];
    LINE_NAV.forEach(function (sel) {
      tr.querySelectorAll(sel).forEach(function (el) {
        if (!el || el.disabled) return;
        if (el.readOnly && !el.classList.contains('js-item-name')) return;
        if (el.classList.contains('js-item-name') && el.readOnly) return;
        list.push(el);
      });
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
      if (!lineHasQty(curLn)) {
        focusLineField(idx, '.js-uqty', true);
        hxAlert('أدخل الكمية أو الكمية المجانية قبل الانتقال لسطر جديد.', {
          title: 'الكمية مطلوبة',
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
    if (fromEl.classList && fromEl.classList.contains('js-qty')) {
      if (!enforceQtyBeforeLeave(fromEl)) return;
    }
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
    if (fromEl.classList && fromEl.classList.contains('js-qty')) {
      if (!enforceQtyBeforeLeave(fromEl)) return;
    }
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
    if (fromEl.classList && fromEl.classList.contains('js-qty')) {
      if (!enforceQtyBeforeLeave(fromEl)) return;
    }
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
        if (!lineHasQty(state.lines[idx])) {
          setMsg('أدخل الكمية أو الكمية المجانية أولاً قبل إضافة سطر جديد.', 'error');
          focusLineField(idx, '.js-uqty', true);
          hxAlert('أدخل الكمية أو الكمية المجانية قبل إضافة سطر جديد.', {
            title: 'الكمية مطلوبة',
            kind: 'warning',
          });
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

  function bindQtyInputs(tr) {
    if (!tr) return;
    tr.querySelectorAll('.js-uqty, .js-uextra, .js-disc, .js-tax').forEach(function (el) {
      if (el.getAttribute('data-co-on') === '1') return;
      el.setAttribute('data-co-on', '1');
      var ev = el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, function () {
        var idx = Number(tr.getAttribute('data-idx'));
        if (el.classList.contains('js-uqty') || el.classList.contains('js-uextra')) {
          releaseQtyFocusHold(idx);
        }
        readLineFromRow(tr);
        if (el.classList.contains('js-uqty') && window.HxOffers) {
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
      if (el.classList.contains('js-qty') && !locked) {
        el.addEventListener('blur', function () {
          if (!el.isConnected) return;
          enforceQtyBeforeLeave(el);
        });
      }
    });
  }

  function bindRow(tr) {
    if (!tr) return;
    bindQtyInputs(tr);
    if (tr.getAttribute('data-hx-bound') === '1') {
      bindUnitOnly(tr);
      bindItemPickButton(tr);
      return;
    }
    tr.setAttribute('data-hx-bound', '1');
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
    var catFilter = '';
    try {
      var catSel = document.getElementById('co-oracle-cat-filter');
      if (catSel && catSel.value) catFilter = String(catSel.value).trim();
    } catch (e) {}
    if (!catFilter && window.__coOracleCatFilter) {
      catFilter = String(window.__coOracleCatFilter || '').trim();
    }
    if (catFilter) {
      urls = urls.map(function (u) {
        return u + '&cat=' + encodeURIComponent(catFilter);
      });
    }

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

  function lineQtyValue(lnOrEl) {
    if (!lnOrEl) return 0;
    if (lnOrEl.tagName) {
      var n = Number(lnOrEl.value);
      return Number.isFinite(n) ? n : 0;
    }
    var q = Number(lnOrEl.qty);
    return Number.isFinite(q) ? q : 0;
  }

  function lineQtyExtraValue(ln) {
    if (!ln) return 0;
    var q = Number(ln.qty_extra);
    return Number.isFinite(q) ? q : 0;
  }

  function lineHasQty(ln) {
    return (
      nQty(ln && ln.qty_piece) +
        nQty(ln && ln.qty_carton) +
        nQty(ln && ln.extra_piece) +
        nQty(ln && ln.extra_carton) >
        0 || lineQtyValue(ln) > 0 || lineQtyExtraValue(ln) > 0
    );
  }

  function expandQtyLine(ln) {
    var units = splitUnits(ln);
    var rows = [];
    function push(unit, qty, extra) {
      var q = nQty(qty);
      var e = nQty(extra);
      if (q < 1 && e < 1) return;
      var copy = Object.assign({}, ln);
      copy.qty = q;
      copy.qty_extra = e;
      copy.unit_id = unit.unit_id || 0;
      copy.unit_name = unit.name || '';
      copy.unit_factor = unit.factor || 1;
      copy.unit_price = unitPriceOf(ln, unit);
      rows.push(copy);
    }
    units.list.forEach(function (u) {
      push(u, qtyOf(ln, u, 'qty'), qtyOf(ln, u, 'extra'));
    });
    if (!units.carton && (nQty(ln.qty_carton) || nQty(ln.extra_carton))) {
      push({ unit_id: 0, name: 'كرتونة', factor: 1 }, ln.qty_carton, ln.extra_carton);
    }
    return rows;
  }

  var qtyLeaveGuard = false;

  /** منع مغادرة حقل الكمية قبل إدخال كمية > 0 (عند وجود مادة) */
  function enforceQtyBeforeLeave(qtyEl, opts) {
    opts = opts || {};
    if (locked || !qtyEl || qtyLeaveGuard) return true;
    if (!qtyEl.isConnected) return true;
    if (!qtyEl.classList || !qtyEl.classList.contains('js-qty')) return true;
    var tr = qtyEl.closest ? qtyEl.closest('tr[data-idx]') : null;
    if (!tr) return true;
    var idx = Number(tr.getAttribute('data-idx'));
    if (state._holdQtyFocusIdx === idx) return true;
    try {
      readLineFromRow(tr);
    } catch (e) {
      /* ignore */
    }
    var ln = state.lines[idx];
    if (!lineHasItem(ln)) return true;
    if (lineHasQty(ln) || lineQtyValue(qtyEl) > 0 || lineQtyExtraValue(ln) > 0) return true;

    var msg = 'أدخل الكمية أو الكمية المجانية للبند قبل مغادرة الحقل.';
    setMsg(msg, 'error');
    qtyLeaveGuard = true;
    window.setTimeout(function () {
      try {
        qtyEl.focus();
        if (typeof qtyEl.select === 'function') qtyEl.select();
      } catch (e2) {
        /* ignore */
      }
      if (!opts.silentAlert) {
        hxAlert(msg, { title: 'الكمية مطلوبة', kind: 'warning' }).then(function () {
          qtyLeaveGuard = false;
          try {
            qtyEl.focus();
            if (typeof qtyEl.select === 'function') qtyEl.select();
          } catch (e3) {
            /* ignore */
          }
        });
      } else {
        qtyLeaveGuard = false;
      }
    }, 0);
    return false;
  }

  function findFirstLineMissingQty() {
    try {
      syncLinesFromDom();
    } catch (e) {
      /* ignore */
    }
    var lines = state.lines || [];
    for (var i = 0; i < lines.length; i++) {
      if (lineHasItem(lines[i]) && !lineHasQty(lines[i])) return i;
    }
    return -1;
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
      if (!lineHasQty(lines[i])) {
        if (!opts.silent) {
          focusLineField(i, '.js-uqty', true);
          setMsg('أدخل الكمية أو الكمية المجانية في السطر الحالي قبل إضافة سطر جديد.', 'error');
          hxAlert('أدخل الكمية أو الكمية المجانية قبل إضافة سطر جديد.', {
            title: 'الكمية مطلوبة',
            kind: 'warning',
          });
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
      focusQtyAfterItemPick(idx);
    } else {
      renderLines({ idx: idx, cls: '.js-qty-carton', select: true });
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
        if (e.key === 'Tab') {
          if (t.classList && t.classList.contains('js-qty') && !enforceQtyBeforeLeave(t, { silentAlert: false })) {
            e.preventDefault();
            e.stopPropagation();
            return;
          }
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
      delivery_date: (document.getElementById('co_delivery_date') || {}).value || '',
      notes: (document.getElementById('co_notes') || {}).value || '',
      invoice_discount: (document.getElementById('co_discount') || {}).value || '',
      lines: (state.lines || []).reduce(function (acc, ln) {
        if (!ln || !ln.item_id) return acc;
        var expanded = expandQtyLine(ln);
        return acc.concat(expanded.length ? expanded : [ln]);
      }, []),
    };
  }

  function saveOrder() {
    if (locked || busy) return Promise.resolve(null);
    var payload = collectPayload();
    if (!payload.customer_id) {
      hxAlert('اختر العميل.', { title: 'تنبيه', kind: 'warning' });
      return Promise.resolve(null);
    }
    if (!payload.sales_rep_id) {
      hxAlert('اختر المندوب. رقم الطلب يُولَّد من رمز المندوب مثل الموبايل.', {
        title: 'تنبيه',
        kind: 'warning',
      });
      var repEl = document.getElementById('co_rep');
      if (repEl) repEl.focus();
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
    var missingQtyIdx = findFirstLineMissingQty();
    if (missingQtyIdx >= 0) {
      hxAlert('أدخل الكمية أو الكمية المجانية لكل بند قبل الحفظ.', {
        title: 'الكمية مطلوبة',
        kind: 'warning',
      });
      focusLineField(missingQtyIdx, '.js-uqty', true);
      return Promise.resolve(null);
    }
    for (var pi = 0; pi < payload.lines.length; pi++) {
      var pq = Number(payload.lines[pi].qty) || 0;
      var pqe = Number(payload.lines[pi].qty_extra) || 0;
      if (!(pq > 0) && !(pqe > 0)) {
        hxAlert('أدخل الكمية أو الكمية المجانية للبند رقم ' + (pi + 1) + ' قبل الحفظ.', {
          title: 'الكمية مطلوبة',
          kind: 'warning',
        });
        focusLineField(pi, '.js-uqty', true);
        return Promise.resolve(null);
      }
      var bonusOnly = !(pq > 0) && pqe > 0;
      if (!(Number(payload.lines[pi].unit_price) > 0) && !bonusOnly) {
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
          if (titleEl) titleEl.textContent = 'طلب شراء عميل';
        }
        if (data.id && Number(data.id) !== Number(state.id || 0)) {
          if (window.history && window.history.replaceState) {
            state.id = data.id;
            state.order_no = data.order_no || '';
            var noEl = document.getElementById('co_no');
            if (noEl && data.order_no) noEl.value = data.order_no;
            updateDocNoStyle();
            window.history.replaceState({}, '', hxPath('/sales/orders/' + data.id));
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
            window.location.href = hxPath('/sales/orders/' + data.id);
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
        if (okRedirect) window.location.href = hxPath(okRedirect);
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
      ['#', 'رمز', 'مادة', 'كمية', 'مجاني', 'سعر', 'خصم%', 'ضريبة%', 'صافي', 'إجمالي'],
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

  var oracleUnpostBtn = document.getElementById('co-oracle-unpost');
  if (oracleUnpostBtn) {
    oracleUnpostBtn.addEventListener('click', function () {
      if (busy || !state.id) return;
      var vnum = Number(state.oracle_v_num || 0);
      var vyear = Number(state.oracle_vyear || 0);
      var label = vnum > 0 ? 'Oracle #' + vnum + (vyear > 0 ? ' / ' + vyear : '') : 'Oracle';
      hxConfirm(
        'سيتم حذف مسودة الفاتورة ' +
          label +
          ' من Oracle (إن كانت VOU_FLAG=18).\nبعدها يمكنك تعديل الطلب ثم «ترحيل إلى Oracle» من جديد.\n\nإذا حُفظت الفاتورة في INV00024 فلن يُسمح بالحذف من هنا.',
        {
          title: 'إلغاء ترحيل Oracle',
          okLabel: 'إلغاء الترحيل',
          danger: true,
        }
      ).then(function (ok) {
        if (!ok) return;
        setBusy(true);
        setMsg('جاري إلغاء ترحيل Oracle…');
        fetch('/api/sales/customer-orders/' + state.id + '/unpost-oracle', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: '{}',
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
              hxAlert(data.message || data.error || 'فشل إلغاء الترحيل.', {
                title: 'إلغاء ترحيل Oracle',
                kind: 'warning',
              });
              setMsg(data.message || data.error || 'فشل إلغاء الترحيل.', 'error');
              return;
            }
            state.oracle_v_num = 0;
            state.oracle_vyear = 0;
            hxAlert(data.message || 'تم إلغاء ترحيل Oracle. يمكنك إعادة الترحيل بعد التعديل.', {
              title: 'إلغاء ترحيل Oracle',
              kind: 'success',
            }).then(function () {
              refreshOrderPageSoon();
            });
          })
          .catch(function () {
            setBusy(false);
            hxAlert('تعذر الاتصال بالخادم.', { title: 'إلغاء ترحيل Oracle', kind: 'warning' });
          });
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
  var batchModalHome = batchModal ? batchModal.parentNode : null;
  var needReloadTimer = null;

  function fmtBatchQty(n) {
    n = roundBatchQty(Number(n) || 0);
    var dp = batchQtyDp();
    var s = n.toFixed(dp);
    return s.replace(/\.?0+$/, '') || '0';
  }

  function closeBatchModal() {
    if (needReloadTimer) {
      clearTimeout(needReloadTimer);
      needReloadTimer = null;
    }
    if (batchModal) {
      batchModal.hidden = true;
      batchModal.setAttribute('aria-hidden', 'true');
      if (batchModalHome && batchModal.parentNode !== batchModalHome) {
        batchModalHome.appendChild(batchModal);
      }
    }
    pickerData = null;
  }

  function portalBatchModal() {
    if (!batchModal) return;
    if (batchModal.parentNode !== document.body) {
      document.body.appendChild(batchModal);
    }
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

  function buildCatFixed(ln, categories) {
    var wrap = document.createElement('div');
    wrap.className = 'co-cat-fixed';
    wrap.dataset.srl = String(ln.srl || '');
    wrap.dataset.item = String(ln.item || '');

    var cat = String(ln.cat || '').trim();
    var name = '';
    function matchName(list) {
      if (!Array.isArray(list) || !cat) return;
      for (var i = 0; i < list.length; i++) {
        if (String(list[i].cat || '').trim() === cat) {
          name = String(list[i].name || '');
          return;
        }
      }
    }
    matchName(ln.cat_options);
    if (!name) matchName(categories);

    var hid = document.createElement('input');
    hid.type = 'hidden';
    hid.className = 'co-cat-select';
    hid.dataset.srl = String(ln.srl || '');
    hid.dataset.item = String(ln.item || '');
    hid.value = cat;

    var label = document.createElement('span');
    label.className = 'co-cat-fixed-label';
    label.title = 'فئة المادة من بطاقة Oracle (غير قابلة للتغيير)';
    label.textContent = cat
      ? catOptionLabel({ cat: cat, name: name })
      : '— بلا فئة —';

    wrap.appendChild(hid);
    wrap.appendChild(label);
    return wrap;
  }

  function collectCatPicks() {
    var picks = [];
    if (!batchRows) return picks;
    var seen = {};
    batchRows.querySelectorAll('.co-cat-select').forEach(function (el) {
      var srl = parseInt(el.dataset.srl || '0', 10);
      if (srl < 1 || seen[srl]) return;
      seen[srl] = true;
      var cat = (el.value || '').trim();
      if (!cat) return;
      picks.push({ srl: srl, cat: cat });
    });
    return picks;
  }

  function batchQtyDp() {
    if (window.HxDec && typeof window.HxDec.amountDecimals === 'function') {
      return Math.max(0, Number(window.HxDec.amountDecimals()) || 3);
    }
    return 3;
  }

  function roundBatchQty(n) {
    n = Number(n) || 0;
    var dp = batchQtyDp();
    var f = Math.pow(10, dp);
    return Math.round(n * f) / f;
  }

  function collectQtyOverrides() {
    var picks = [];
    if (!batchRows) return picks;
    var seen = {};
    batchRows.querySelectorAll('input.co-batch-qty-input').forEach(function (inp) {
      var srl = parseInt(inp.dataset.srl || '0', 10);
      var lineId = parseInt(inp.dataset.lineId || '0', 10);
      if (srl < 1 || seen[srl]) return;
      seen[srl] = true;
      var qty = Number(inp.value);
      var bonusInp = batchRows.querySelector('input.co-batch-bonus-input[data-srl="' + srl + '"]');
      var bonus = bonusInp ? Number(bonusInp.value) : 0;
      if (!isFinite(qty) || qty < 0) qty = 0;
      if (!isFinite(bonus) || bonus < 0) bonus = 0;
      if (qty <= 0 && bonus <= 0) return;
      var row = { srl: srl, qty: roundBatchQty(qty), bonus: roundBatchQty(bonus) };
      if (lineId > 0) row.line_id = lineId;
      picks.push(row);
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
    reloadBatchPicker();
  }

  function reloadBatchPicker() {
    if (!state.id || catReloadBusy) return;
    catReloadBusy = true;
    if (batchConfirm) batchConfirm.disabled = true;
    setBatchStatus('جاري تحديث التشغيلات…', '');
    fetch('/api/sales/customer-orders/' + state.id + '/oracle-batches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        cat_picks: collectCatPicks(),
        qty_overrides: collectQtyOverrides(),
      }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        catReloadBusy = false;
        if (!data.ok) {
          hxAlert(data.message || data.error || 'تعذر تحديث التشغيلات.', {
            title: 'ترحيل الكميات',
            kind: 'warning',
          });
          validateBatchModal();
          return;
        }
        openBatchModal(data);
      })
      .catch(function () {
        catReloadBusy = false;
        hxAlert('تعذر الاتصال بالخادم', { title: 'ترحيل الكميات', kind: 'warning' });
        validateBatchModal();
      });
  }

  function scheduleNeedReload() {
    if (needReloadTimer) clearTimeout(needReloadTimer);
    needReloadTimer = setTimeout(function () {
      needReloadTimer = null;
      reloadBatchPicker();
    }, 450);
  }

  function syncNeedFromQtyBonus(srl) {
    var qtyInp = batchRows.querySelector('input.co-batch-qty-input[data-srl="' + srl + '"]');
    var bonusInp = batchRows.querySelector('input.co-batch-bonus-input[data-srl="' + srl + '"]');
    var qty = qtyInp ? Number(qtyInp.value) : 0;
    var bonus = bonusInp ? Number(bonusInp.value) : 0;
    if (!isFinite(qty) || qty < 0) qty = 0;
    if (!isFinite(bonus) || bonus < 0) bonus = 0;
    var factor = 1;
    var tr = batchRows.querySelector('tr[data-srl="' + srl + '"]');
    if (tr && Number(tr.dataset.unitFactor) > 1.000001 && String(tr.dataset.multTr) === '1') {
      factor = Number(tr.dataset.unitFactor);
    }
    var need = roundBatchQty((qty + bonus) * factor);
    batchRows.querySelectorAll('tr[data-srl="' + srl + '"]').forEach(function (row) {
      row.dataset.need = String(need);
      var sel = row.querySelector('select.co-batch-select');
      if (sel) sel.dataset.need = String(need);
    });
    return need;
  }

  function buildQtyBonusInput(ln, kind) {
    var inp = document.createElement('input');
    inp.type = 'number';
    inp.className = kind === 'bonus' ? 'co-batch-bonus-input' : 'co-batch-qty-input';
    inp.step = qtyStep();
    inp.min = '0';
    inp.inputMode = 'decimal';
    var raw = kind === 'bonus' ? Number(ln.bonus != null ? ln.bonus : ln.qty_extra) || 0 : Number(ln.qty) || 0;
    inp.value = String(roundBatchQty(raw));
    inp.dataset.srl = String(ln.srl || '');
    inp.dataset.lineId = String(ln.line_id || '');
    inp.title = kind === 'bonus' ? 'الكمية المجانية (بونص)' : 'كمية الطلب — تُحدَّث عند الترحيل';
    inp.addEventListener('input', function () {
      syncNeedFromQtyBonus(inp.dataset.srl);
      validateBatchModal();
    });
    inp.addEventListener('change', function () {
      inp.value = String(roundBatchQty(Number(inp.value) || 0));
      syncNeedFromQtyBonus(inp.dataset.srl);
      scheduleNeedReload();
    });
    inp.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        inp.blur();
      }
    });
    return inp;
  }

  function buildBatchSelect(ln, alloc, batches) {
    var sel = document.createElement('select');
    sel.className = 'si-field co-batch-select';
    sel.dataset.srl = String(ln.srl || '');
    sel.dataset.item = String(ln.item || '');
    sel.dataset.need = String(roundBatchQty(Number(ln.need) || 0));

    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '— اختر التشغيلة —';
    sel.appendChild(opt0);

    (batches || []).forEach(function (b) {
      var o = document.createElement('option');
      o.value = b.batch || '';
      o.textContent = batchOptionLabel(b);
      o.dataset.qty = String(roundBatchQty(b.qty || 0));
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
    inp.step = qtyStep();
    inp.min = '0';
    inp.inputMode = 'decimal';
    inp.value = String(roundBatchQty(Number(alloc.take) || 0));
    inp.dataset.srl = String(ln.srl || '');
    inp.title = 'عدّل الكمية المأخوذة من هذه التشغيلة';
    inp.addEventListener('input', validateBatchModal);
    inp.addEventListener('change', function () {
      inp.value = String(roundBatchQty(Number(inp.value) || 0));
      validateBatchModal();
    });
    return inp;
  }

  function rowTakeValue(tr) {
    var inp = tr.querySelector('input.co-batch-take-input');
    if (inp) {
      var n = roundBatchQty(Number(inp.value));
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

    batchRows.querySelectorAll('.co-cat-select').forEach(function (el) {
      var srl = String(el.dataset.srl || '');
      if (!srl || !(el.value || '').trim()) {
        ok = false;
        if (!statusMsg) statusMsg = 'فئة المادة غير محددة في Oracle.';
      }
    });

    batchRows.querySelectorAll('input.co-batch-qty-input').forEach(function (inp) {
      var qty = Number(inp.value);
      if (!isFinite(qty) || qty <= 0) {
        ok = false;
        if (!statusMsg) statusMsg = 'أدخل كمية أكبر من صفر لكل مادة.';
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
      // مقارنة بعد التقريب المعروض — 1+1/12=1.0833… يظهر 1.083 فيُرفض خطأً بتحمّل 0.0001
      var need = roundBatchQty(needBySrl[srl]);
      var got = roundBatchQty(takeSumBySrl[srl] || 0);
      if (got + 0.0000001 < need) {
        ok = false;
        if (!statusMsg) {
          statusMsg =
            'الكميات من التشغيلات لا تغطي المطلوب (مجموع ' +
            fmtBatchQty(got) +
            ' من ' +
            fmtBatchQty(need) +
            '). عدّل الكميات أو اختر تشغيلات أخرى.';
        }
      } else if (got - need > 0.0000001) {
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
    var el = batchRows.querySelector('.co-cat-select[data-srl="' + srl + '"]');
    return el ? (el.value || '').trim() : '';
  }

  function openBatchModal(data) {
    pickerData = data;
    if (!batchRows || !batchModal) return;
    portalBatchModal();
    batchRows.innerHTML = '';
    if (batchSub) {
      batchSub.textContent = '';
      batchSub.hidden = true;
    }
    var rowNo = 0;
    (data.lines || []).forEach(function (ln) {
      var allocs = Array.isArray(ln.allocations) ? ln.allocations : [];
      var batches = Array.isArray(ln.batches) ? ln.batches : [];
      var itemHtml =
        '<span class="co-batch-item-line"><span class="co-batch-item-code">' +
        escAttr(String(ln.item || '')) +
        '</span><span class="co-batch-item-name">' +
        escAttr(String(ln.name || '')) +
        '</span></span>';

      if (!allocs.length) {
        rowNo += 1;
        var tr0 = document.createElement('tr');
        tr0.className = 'co-batch-row--nostock co-batch-row--invalid';
        tr0.dataset.srl = String(ln.srl || '');
        tr0.dataset.lineId = String(ln.line_id || '');
        tr0.dataset.need = String(roundBatchQty(Number(ln.need) || 0));
        tr0.dataset.unitFactor = String(ln.unit_factor || 1);
        tr0.dataset.shortfall = '1';
        tr0.innerHTML =
          '<td class="co-batch-col-idx">' +
          rowNo +
          '</td>' +
          '<td class="co-batch-col-item">' +
          itemHtml +
          '</td>' +
          '<td class="co-batch-col-cat"></td>' +
          '<td class="co-batch-col-qty"></td>' +
          '<td class="co-batch-col-bonus"></td>' +
          '<td class="co-batch-col-take">—</td>' +
          '<td class="co-batch-col-batch" colspan="2"><div class="co-batch-warn">التشغيلات لا تغطي المطلوب' +
          (Number(ln.shortfall) > 0 ? ' (نقص ' + fmtBatchQty(ln.shortfall) + ')' : '') +
          ' — لا يمكن الترحيل.</div></td>';
        var tdCat0 = tr0.querySelector('.co-batch-col-cat');
        if (tdCat0) tdCat0.appendChild(buildCatFixed(ln, data.categories || []));
        var tdQty0 = tr0.querySelector('.co-batch-col-qty');
        var tdBonus0 = tr0.querySelector('.co-batch-col-bonus');
        if (tdQty0) tdQty0.appendChild(buildQtyBonusInput(ln, 'qty'));
        if (tdBonus0) tdBonus0.appendChild(buildQtyBonusInput(ln, 'bonus'));
        batchRows.appendChild(tr0);
        return;
      }

      allocs.forEach(function (a, ai) {
        rowNo += 1;
        var tr = document.createElement('tr');
        tr.setAttribute('data-alloc-row', '1');
        tr.dataset.batches = JSON.stringify(batches);
        tr.dataset.srl = String(ln.srl || '');
        tr.dataset.lineId = String(ln.line_id || '');
        tr.dataset.need = String(roundBatchQty(Number(ln.need) || 0));
        tr.dataset.unitFactor = String(ln.unit_factor || 1);
        if (ai > 0) tr.classList.add('co-batch-row--cont');
        if (!ln.allocation_ok && Number(ln.shortfall) > 0) {
          tr.dataset.shortfall = '1';
          tr.classList.add('co-batch-row--invalid');
        }

        var tdIdx = document.createElement('td');
        tdIdx.className = 'co-batch-col-idx';
        tdIdx.textContent = String(rowNo);

        var tdName = document.createElement('td');
        tdName.className = 'co-batch-col-item';
        tdName.innerHTML = itemHtml;

        tr.appendChild(tdIdx);
        tr.appendChild(tdName);

        if (ai === 0) {
          var span = allocs.length > 1 ? allocs.length : 0;
          var tdCat = document.createElement('td');
          tdCat.className = 'co-batch-col-cat';
          if (span) tdCat.rowSpan = span;
          tdCat.appendChild(buildCatFixed(ln, data.categories || []));
          tr.appendChild(tdCat);

          var tdQty = document.createElement('td');
          tdQty.className = 'co-batch-col-qty';
          if (span) tdQty.rowSpan = span;
          tdQty.appendChild(buildQtyBonusInput(ln, 'qty'));
          tr.appendChild(tdQty);

          var tdBonus = document.createElement('td');
          tdBonus.className = 'co-batch-col-bonus';
          if (span) tdBonus.rowSpan = span;
          tdBonus.appendChild(buildQtyBonusInput(ln, 'bonus'));
          tr.appendChild(tdBonus);
        }

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
          '<td></td><td></td><td colspan="6"><div class="co-batch-warn">الرصيد المتوفر لا يكفي — نقص ' +
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
      body: JSON.stringify({
        batch_picks: batchPicks || [],
        qty_overrides: collectQtyOverrides(),
      }),
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

  function openOracleInvoiceTab(vnum, vyear) {
    var q = '/sales/reports/oracle-sales-invoice?invoice_no=' + vnum;
    if (vyear > 0) q += '&year=' + vyear;
    window.open(q, '_blank');
  }

  function promptOrphanOracleInvoice(status) {
    var vnum = Number((status && status.v_num) || state.oracle_v_num || 0);
    var vyear = Number((status && status.vyear) || state.oracle_vyear || 0);
    var label = vnum > 0 ? '#' + vnum + (vyear > 0 ? ' / ' + vyear : '') : '';
    var msg =
      'فاتورة Oracle ' +
      label +
      ' غير موجودة في النظام (يُحتمل أنها حُذفت من Oracle).\n' +
      'الطلب ما زال مرتبطاً بها.\n\n' +
      'اختر الإجراء المناسب:';

    function runCleanup(deleteOrder) {
      setBusy(true);
      setMsg(deleteOrder ? 'جاري إلغاء الربط وحذف الطلب…' : 'جاري إلغاء الربط…');
      return fetch('/api/sales/customer-orders/' + state.id + '/oracle-orphan-cleanup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ delete_order: !!deleteOrder }),
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
            hxAlert(data.message || data.error || 'فشل الإجراء.', {
              title: 'فاتورة Oracle',
              kind: 'warning',
            });
            return;
          }
          state.oracle_v_num = 0;
          state.oracle_vyear = 0;
          if (data.deleted) {
            formDirty = false;
            var goId = Number(data.redirect_id || data.next_id || data.prev_id || 0);
            hxAlert(data.message || 'تم إلغاء الربط وحذف الطلب.', {
              title: 'تم',
              kind: 'success',
            }).then(function () {
              if (goId > 0) window.location.href = hxPath('/sales/orders/' + goId);
              else window.location.href = hxPath('/sales/orders/new');
            });
            return;
          }
          hxAlert(data.message || 'تم إلغاء الربط. يمكنك إعادة الترحيل أو حذف الطلب.', {
            title: 'تم إلغاء الربط',
            kind: 'success',
          }).then(function () {
            refreshOrderPageSoon();
          });
        })
        .catch(function () {
          setBusy(false);
          hxAlert('تعذر الاتصال بالخادم.', { title: 'فاتورة Oracle', kind: 'warning' });
        });
    }

    if (window.HypexUI && typeof window.HypexUI.dialog === 'function') {
      return window.HypexUI.dialog({
        title: 'فاتورة Oracle غير موجودة',
        message: msg,
        kind: 'warning',
        closeOnBackdrop: false,
        buttons: [
          { label: 'إغلاق', value: 'close' },
          { label: 'إلغاء الربط فقط', value: 'unlink', primary: true },
          { label: 'إلغاء الربط وحذف الطلب', value: 'delete', danger: true },
        ],
      }).then(function (choice) {
        if (choice === 'unlink') return runCleanup(false);
        if (choice === 'delete') {
          return hxConfirm(
            'سيتم إلغاء الربط ثم فك الاعتماد وحذف طلب الشراء نهائياً.\nلا يمكن التراجع.',
            { title: 'حذف طلب الشراء', okLabel: 'حذف نهائياً', danger: true }
          ).then(function (ok) {
            if (ok) return runCleanup(true);
          });
        }
      });
    }

    return hxConfirm(
      msg + '\n\nموافق = إلغاء الربط فقط\nإلغاء = لا شيء',
      { title: 'فاتورة Oracle غير موجودة', okLabel: 'إلغاء الربط', danger: true }
    ).then(function (ok) {
      if (!ok) return;
      return runCleanup(false).then(function () {
        return hxConfirm('هل تريد أيضاً حذف طلب الشراء؟', {
          title: 'حذف الطلب',
          okLabel: 'حذف الطلب',
          danger: true,
        }).then(function (del) {
          if (del) return runCleanup(true);
          refreshOrderPageSoon();
        });
      });
    });
  }

  function checkOracleInvoiceLink(opts) {
    opts = opts || {};
    if (!state.id || Number(state.oracle_v_num || 0) < 1) {
      return Promise.resolve({ ok: true, linked: false });
    }
    return fetch('/api/sales/customer-orders/' + state.id + '/oracle-status', {
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          if (opts.openIfExists) {
            hxAlert((data && (data.message || data.error)) || 'تعذر التحقق من فاتورة Oracle.', {
              title: 'Oracle',
              kind: 'warning',
            });
          }
          return data || { ok: false };
        }
        if (data.linked && data.missing) {
          promptOrphanOracleInvoice(data);
          return data;
        }
        if (opts.openIfExists && data.exists) {
          openOracleInvoiceTab(Number(data.v_num || state.oracle_v_num || 0), Number(data.vyear || state.oracle_vyear || 0));
        }
        return data;
      })
      .catch(function () {
        if (opts.openIfExists) {
          hxAlert('تعذر الاتصال للتحقق من فاتورة Oracle.', { title: 'Oracle', kind: 'warning' });
        }
        return { ok: false };
      });
  }

  if (oracleBtn) {
    oracleBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ واعتمد الطلب أولاً.', 'error');
        return;
      }
      var vnum = Number(state.oracle_v_num || 0);
      if (vnum > 0) {
        checkOracleInvoiceLink({ openIfExists: true });
        return;
      }
      startOraclePost();
    });
  }

  // عند فتح طلب مرتبط بفاتورة Oracle: إن كانت محذوفة أظهر الإشعار فوراً
  if (state.id && Number(state.oracle_v_num || 0) > 0) {
    checkOracleInvoiceLink({ openIfExists: false });
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
            setMsg('جاري الحذف…');
            setBusy(true);
            fetch('/api/sales/customer-orders/' + state.id + '/delete', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: '{}',
            })
              .then(function (r) {
                return r.json();
              })
              .then(function (res) {
                setBusy(false);
                if (!res || !res.ok) {
                  showActionError(res || {});
                  return;
                }
                formDirty = false;
                var goId = Number(res.redirect_id || res.next_id || res.prev_id || 0);
                if (goId > 0) {
                  window.location.href = hxPath('/sales/orders/' + goId);
                } else {
                  window.location.href = hxPath('/sales/orders/new');
                }
              })
              .catch(function () {
                setBusy(false);
                setMsg('تعذر الاتصال بالخادم', 'error');
              });
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
    href = hxPath(href);
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
      firstBtn: 'co_first',
      prevBtn: 'co_prev',
      nextBtn: 'co_next',
      lastBtn: 'co_last',
      firstId: state.first_id,
      prevId: state.prev_id,
      nextId: state.next_id,
      lastId: state.last_id,
      openPath: hxPath('/sales/orders'),
      findApi: '/api/sales/customer-orders/by-no',
      currentNo: state.order_no || '',
      currentId: state.id || 0,
    });
  }
})();
