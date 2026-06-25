(function (global) {
  'use strict';

  var DECIMAL_PLACES_MAX = 10;

  function decimals() {
    var body = document.body;
    var fromBody = body ? parseInt(body.getAttribute('data-decimal-places') || '', 10) : NaN;
    if (!isNaN(fromBody) && fromBody >= 0 && fromBody <= DECIMAL_PLACES_MAX) {
      return fromBody;
    }
    var fromGlobal = parseInt(String(global.APP_DECIMAL_PLACES || ''), 10);
    if (!isNaN(fromGlobal) && fromGlobal >= 0 && fromGlobal <= DECIMAL_PLACES_MAX) {
      return fromGlobal;
    }
    return 2;
  }

  function fmt(n, dp) {
    var d = dp !== undefined && dp !== null ? parseInt(dp, 10) : decimals();
    if (isNaN(d) || d < 0) d = 2;
    if (d > DECIMAL_PLACES_MAX) d = DECIMAL_PLACES_MAX;
    var x = Number(n);
    if (!isFinite(x)) x = 0;
    return x.toLocaleString('en-US', {
      minimumFractionDigits: d,
      maximumFractionDigits: d,
    });
  }

  function round(n, dp) {
    var d = dp !== undefined && dp !== null ? parseInt(dp, 10) : decimals();
    if (isNaN(d) || d < 0) d = 2;
    var p = Math.pow(10, d);
    return Math.round(Number(n) * p) / p;
  }

  function inputStepForDecimals(d) {
    if (d <= 0) return '1';
    var s = '0.';
    for (var i = 1; i < d; i++) s += '0';
    return s + '1';
  }

  var DECIMAL_STORAGE_KEY = 'app_decimal_places';

  function clampDecimals(dp) {
    var d = parseInt(dp, 10);
    if (isNaN(d) || d < 0) return 0;
    if (d > DECIMAL_PLACES_MAX) return DECIMAL_PLACES_MAX;
    return d;
  }

  /** دقة عرض وتقريب مبالغ الفاتورة (إجمالي، ضريبة، قبل الضريبة) = إعداد النظام. */
  function invoiceAmountDecimals() {
    return decimals();
  }

  /** دقة سعر الوحدة في فواتير البيع/الشراء = إعداد منفصل. */
  function invoiceUnitPriceDecimals() {
    var body = document.body;
    var fromBody = body
      ? parseInt(body.getAttribute('data-invoice-unit-price-decimals') || '', 10)
      : NaN;
    if (!isNaN(fromBody) && fromBody >= 0 && fromBody <= DECIMAL_PLACES_MAX) {
      return fromBody;
    }
    var fromGlobal = parseInt(String(global.APP_INVOICE_UNIT_PRICE_DECIMAL_PLACES || ''), 10);
    if (!isNaN(fromGlobal) && fromGlobal >= 0 && fromGlobal <= DECIMAL_PLACES_MAX) {
      return fromGlobal;
    }
    return invoiceAmountDecimals();
  }

  function invoiceInputDecimals() {
    return invoiceAmountDecimals();
  }

  function roundInvoiceAmount(n) {
    return round(n, invoiceAmountDecimals());
  }

  function roundInvoiceUnitPrice(n) {
    return round(n, invoiceUnitPriceDecimals());
  }

  function roundInvoiceInput(n) {
    return round(n, invoiceInputDecimals());
  }

  function trimTrailingZeros(numStr) {
    return String(numStr)
      .replace(/(\.\d*?)0+$/, '$1')
      .replace(/\.$/, '');
  }

  /** عرض رقم بخانات عشرية ثابتة (بدون فواصل آلاف) — لحقول مبالغ الفاتورة. */
  function formatFixedDecimalPlain(n, dp) {
    var d = clampDecimals(dp);
    var x = Number(n);
    if (!isFinite(x)) {
      x = 0;
    }
    x = round(x, d);
    return x.toLocaleString('en-US', {
      minimumFractionDigits: d,
      maximumFractionDigits: d,
      useGrouping: false,
    });
  }

  /** عرض مبلغ فاتورة حسب إعداد الخانات العشرية. */
  function fmtInvoiceAmount(n) {
    var d = invoiceAmountDecimals();
    var x = roundInvoiceAmount(n);
    return x.toLocaleString('en-US', {
      minimumFractionDigits: d,
      maximumFractionDigits: d,
    });
  }

  /** عرض سعر الوحدة حسب إعداد السعر الافرادي في الفواتير. */
  function fmtInvoiceUnitPrice(n) {
    var d = invoiceUnitPriceDecimals();
    var x = roundInvoiceUnitPrice(n);
    return x.toLocaleString('en-US', {
      minimumFractionDigits: d,
      maximumFractionDigits: d,
    });
  }

  /** تحويل نص إدخال فاتورة (يدعم , أو . كفاصلة عشرية). */
  function normalizeInvoiceDecimalRaw(rawStr) {
    var raw = String(rawStr == null ? '' : rawStr).trim();
    if (!raw) return '';
    var lastComma = raw.lastIndexOf(',');
    var lastDot = raw.lastIndexOf('.');
    if (lastComma >= 0 && lastDot >= 0) {
      if (lastComma > lastDot) {
        return raw.replace(/\./g, '').replace(',', '.');
      }
      return raw.replace(/,/g, '');
    }
    if (lastComma >= 0) {
      return raw.replace(',', '.');
    }
    return raw.replace(/,/g, '');
  }

  function parseInvoiceDecimalInput(v) {
    if (v === '' || v === null || v === undefined) return 0;
    var s = normalizeInvoiceDecimalRaw(v);
    if (s === '' || s === '-' || s === '.') return 0;
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  /** عرض حقل فاتورة فارغاً بدل 0 عندما لا يوجد قيمة فعلية. */
  function invoiceInputShouldShowEmpty(n, rawStr) {
    if (!isFinite(n) || Math.abs(n) < 1e-12) {
      if (rawStr === undefined || rawStr === null) {
        return true;
      }
      var raw = String(rawStr).trim();
      if (raw === '') {
        return true;
      }
      return Math.abs(parseInvoiceDecimalInput(raw)) < 1e-12;
    }
    return false;
  }

  /** تنسيق كمية سطر فاتورة — فارغ عند الصفر. */
  function formatInvoiceQtyInput(n, rawStr) {
    if (rawStr !== undefined && rawStr !== null && String(rawStr).trim() !== '') {
      var s = String(rawStr).trim().replace(/,/g, '');
      if (/[.,]$/.test(s)) {
        s = s.replace(/,/g, '.');
        s = s.replace(/(\.\d*?)0+$/, '$1');
        return s;
      }
      if (/[.,]/.test(s)) {
        var xDec = parseFloat(s.replace(/,/g, '.'));
        if (isFinite(xDec)) {
          s = String(s).replace(/,/g, '.');
          s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
          if (invoiceInputShouldShowEmpty(xDec, rawStr)) {
            return '';
          }
          return s === '' ? '' : s;
        }
      }
    }
    var x = Number(n);
    if (!isFinite(x) || invoiceInputShouldShowEmpty(x, rawStr)) {
      return '';
    }
    if (Math.abs(x - Math.round(x)) < 1e-9) {
      return String(Math.round(x));
    }
    var out = String(x).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return out === '' ? '' : out;
  }

  function formatInvoiceDecimalInputWithDecimals(n, rawStr, maxDp) {
    var maxFrac =
      maxDp !== undefined && maxDp !== null ? clampDecimals(maxDp) : invoiceInputDecimals();

    if (rawStr !== undefined && rawStr !== null && String(rawStr).trim() !== '') {
      var raw = String(rawStr).trim();
      if (/[.,]$/.test(raw)) {
        var head = normalizeInvoiceDecimalRaw(raw.slice(0, -1));
        var intOnly = (head.split('.')[0] || '0').replace(/[^\d-]/g, '') || '0';
        return intOnly + '.';
      }
    }

    var x = round(n, maxFrac);
    if (invoiceInputShouldShowEmpty(x, rawStr)) {
      return '';
    }
    return formatFixedDecimalPlain(x, maxFrac);
  }

  /** تنسيق مبلغ فاتورة (قبل الضريبة، إجمالي، …) حسب إعداد النظام. */
  function formatInvoiceDecimalInput(n, rawStr) {
    return formatInvoiceDecimalInputWithDecimals(n, rawStr, invoiceInputDecimals());
  }

  /** تنسيق سعر الوحدة في الفاتورة حسب إعداد السعر الافرادي. */
  function formatInvoiceUnitPriceInput(n, rawStr) {
    return formatInvoiceDecimalInputWithDecimals(n, rawStr, invoiceUnitPriceDecimals());
  }

  /** السعر الإفرادي شامل الضريبة — يُقرب كمبلغ نقدي (خانات النظام). */
  function roundInvoiceUnitPriceIncl(n) {
    return roundInvoiceAmount(n);
  }

  function formatInvoiceUnitPriceInclInput(n, rawStr) {
    return formatInvoiceDecimalInputWithDecimals(n, rawStr, invoiceAmountDecimals());
  }

  function invoiceUnitPriceInclInputStep() {
    return inputStepForDecimals(invoiceAmountDecimals());
  }

  function invoicePriceInputStep() {
    return inputStepForDecimals(invoiceInputDecimals());
  }

  function invoiceUnitPriceInputStep() {
    return inputStepForDecimals(invoiceUnitPriceDecimals());
  }

  var UNIT_PRICE_DECIMAL_STORAGE_KEY = 'app_invoice_unit_price_decimal_places';

  function setInvoiceUnitPriceDecimals(dp, options) {
    options = options || {};
    dp = clampDecimals(dp);
    if (document.body) {
      document.body.setAttribute('data-invoice-unit-price-decimals', String(dp));
    }
    global.APP_INVOICE_UNIT_PRICE_DECIMAL_PLACES = dp;
    if (options.persist !== false) {
      try {
        localStorage.setItem(UNIT_PRICE_DECIMAL_STORAGE_KEY, String(dp));
      } catch (e) {
        /* ignore */
      }
    }
    if (options.silent !== true) {
      window.dispatchEvent(
        new CustomEvent('app:invoice-unit-price-decimals', { detail: { dp: dp } })
      );
    }
    return dp;
  }

  /** تحديث الخانات العشرية على الصفحة (وفي التبويبات الأخرى عبر التخزين المحلي). */
  function setDecimalPlaces(dp, options) {
    options = options || {};
    dp = clampDecimals(dp);
    if (document.body) {
      document.body.setAttribute('data-decimal-places', String(dp));
    }
    global.APP_DECIMAL_PLACES = dp;
    if (options.persist !== false) {
      try {
        localStorage.setItem(DECIMAL_STORAGE_KEY, String(dp));
      } catch (e) {
        /* ignore */
      }
    }
    if (options.silent !== true) {
      window.dispatchEvent(
        new CustomEvent('app:decimal-places', { detail: { dp: dp } })
      );
    }
    return dp;
  }

  function initDecimalPlacesStorageSync() {
    window.addEventListener('storage', function (e) {
      if (e.key === DECIMAL_STORAGE_KEY && e.newValue !== null) {
        setDecimalPlaces(e.newValue, { persist: false });
      }
      if (e.key === UNIT_PRICE_DECIMAL_STORAGE_KEY && e.newValue !== null) {
        setInvoiceUnitPriceDecimals(e.newValue, { persist: false });
      }
    });
  }

  /** Y-m-d أو d-m-Y → عرض d-m-Y */
  function formatDateDmY(value) {
    var s = String(value == null ? '' : value).trim();
    if (!s) return '';
    var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (iso) {
      return iso[3] + '-' + iso[2] + '-' + iso[1];
    }
    var dmy = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (dmy) {
      var d = ('0' + dmy[1]).slice(-2);
      var mo = ('0' + dmy[2]).slice(-2);
      return d + '-' + mo + '-' + dmy[3];
    }
    return s;
  }

  /** Y-m-d أو d-m-Y → Y-m-d أو '' */
  function parseDateToIso(value) {
    var s = String(value == null ? '' : value).trim();
    if (!s) return '';
    var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (iso) {
      var y = parseInt(iso[1], 10);
      var mo = parseInt(iso[2], 10);
      var d = parseInt(iso[3], 10);
      if (mo >= 1 && mo <= 12 && d >= 1 && d <= 31) {
        return iso[1] + '-' + iso[2] + '-' + iso[3];
      }
      return '';
    }
    var dmy = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (dmy) {
      var d2 = parseInt(dmy[1], 10);
      var mo2 = parseInt(dmy[2], 10);
      var y2 = parseInt(dmy[3], 10);
      if (mo2 >= 1 && mo2 <= 12 && d2 >= 1 && d2 <= 31) {
        return (
          String(y2) +
          '-' +
          ('0' + mo2).slice(-2) +
          '-' +
          ('0' + d2).slice(-2)
        );
      }
    }
    return '';
  }

  global.AppFormat = {
    decimals: decimals,
    fmt: fmt,
    round: round,
    invoiceAmountDecimals: invoiceAmountDecimals,
    invoiceUnitPriceDecimals: invoiceUnitPriceDecimals,
    invoiceInputDecimals: invoiceInputDecimals,
    roundInvoiceAmount: roundInvoiceAmount,
    roundInvoiceUnitPrice: roundInvoiceUnitPrice,
    roundInvoiceUnitPriceIncl: roundInvoiceUnitPriceIncl,
    roundInvoiceInput: roundInvoiceInput,
    fmtInvoiceAmount: fmtInvoiceAmount,
    fmtInvoiceUnitPrice: fmtInvoiceUnitPrice,
    formatInvoiceDecimalInput: formatInvoiceDecimalInput,
    formatInvoiceUnitPriceInput: formatInvoiceUnitPriceInput,
    formatInvoiceUnitPriceInclInput: formatInvoiceUnitPriceInclInput,
    formatInvoiceQtyInput: formatInvoiceQtyInput,
    parseInvoiceDecimalInput: parseInvoiceDecimalInput,
    setDecimalPlaces: setDecimalPlaces,
    setInvoiceUnitPriceDecimals: setInvoiceUnitPriceDecimals,
    invoicePriceInputStep: invoicePriceInputStep,
    invoiceUnitPriceInputStep: invoiceUnitPriceInputStep,
    invoiceUnitPriceInclInputStep: invoiceUnitPriceInclInputStep,
    formatDateDmY: formatDateDmY,
    parseDateToIso: parseDateToIso,
    inputStep: function () {
      return inputStepForDecimals(decimals());
    },
  };

  initDecimalPlacesStorageSync();
})(typeof window !== 'undefined' ? window : this);
