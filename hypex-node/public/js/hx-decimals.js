/**
 * خانات عشرية من إعدادات الشركة (window.__HYPEX_DECIMALS__).
 * amount = decimal_places · unit = invoice_unit_price_decimal_places
 */
(function (w) {
  'use strict';

  function conf() {
    var c = w.__HYPEX_DECIMALS__ || {};
    var amount = Number(c.amount);
    var unit = Number(c.unit);
    if (!Number.isFinite(amount) || amount < 0) amount = 3;
    if (!Number.isFinite(unit) || unit < 0) unit = amount;
    if (amount > 6) amount = 6;
    if (unit > 6) unit = 6;
    return { amount: Math.floor(amount), unit: Math.floor(unit) };
  }

  function roundTo(n, dp) {
    var d = Math.max(0, Math.min(6, Number(dp) || 0));
    var x = Number(n) || 0;
    if (d <= 0) return Math.round(x);
    var f = Math.pow(10, d);
    return Math.round((x + Number.EPSILON) * f) / f;
  }

  function step(dp) {
    var d = Math.max(0, Math.min(6, Number(dp) || 0));
    if (d <= 0) return '1';
    return '0.' + new Array(d).join('0') + '1';
  }

  function fmt(n, dp) {
    var d = dp != null ? dp : conf().amount;
    d = Math.max(0, Math.min(6, Number(d) || 0));
    return (Number(n) || 0).toLocaleString('en-US', {
      minimumFractionDigits: d,
      maximumFractionDigits: d,
    });
  }

  function fmtInput(n, dp) {
    var d = dp != null ? dp : conf().amount;
    d = Math.max(0, Math.min(6, Number(d) || 0));
    var raw = Number(String(n == null ? '' : n).replace(/,/g, '').trim());
    if (!Number.isFinite(raw)) raw = 0;
    return roundTo(raw, d).toFixed(d);
  }

  /** حقول أسعار بطاقة المادة (كلفة / بيع / جملة) */
  function bindItemPriceInputs(root) {
    var scope = root || document;
    var names = ['default_cost', 'default_sale', 'default_wholesale'];
    var dp = conf().unit;
    names.forEach(function (name) {
      var el = scope.querySelector('input[name="' + name + '"]');
      if (!el || el.dataset.hxDecBound === '1') return;
      el.dataset.hxDecBound = '1';
      el.value = fmtInput(el.value, dp);
      el.addEventListener('blur', function () {
        el.value = fmtInput(el.value, conf().unit);
      });
    });
  }

  w.HxDec = {
    conf: conf,
    amountPlaces: function () {
      return conf().amount;
    },
    unitPlaces: function () {
      return conf().unit;
    },
    roundAmount: function (n) {
      return roundTo(n, conf().amount);
    },
    roundUnit: function (n) {
      return roundTo(n, conf().unit);
    },
    roundTo: roundTo,
    amountStep: function () {
      return step(conf().amount);
    },
    unitStep: function () {
      return step(conf().unit);
    },
    step: step,
    fmt: fmt,
    fmtInput: fmtInput,
    bindItemPriceInputs: bindItemPriceInputs,
  };

  function autoBind() {
    bindItemPriceInputs(document);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoBind);
  } else {
    autoBind();
  }
})(typeof window !== 'undefined' ? window : globalThis);
