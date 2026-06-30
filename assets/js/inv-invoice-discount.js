/**
 * خصم الفاتورة والبند — دائماً على المبلغ قبل الضريبة:
 * - خصم السطر: على (كمية × السعر الإفرادي)
 * - خصم الفاتورة: على مجموع الفاتورة قبل الضريبة ثم يُوزَّع على البنود
 */
(function (global) {
  'use strict';

  function parseNum(val) {
    return parseFloat(String(val == null ? '' : val).replace(/,/g, '')) || 0;
  }

  function parseDiscountInput(raw) {
    var s = String(raw == null ? '' : raw).trim();
    if (!s) return null;
    s = s.replace(/\s+/g, '');
    var isPct = /[%٪]$/.test(s);
    if (isPct) {
      s = s.replace(/[%٪]+$/g, '');
    }
    s = s.replace(/[،]/g, '');
    var v = parseNum(s);
    if (!(v >= 0)) return null;
    if (isPct) {
      return { type: 'percent', value: Math.min(100, v) };
    }
    return { type: 'amount', value: v };
  }

  /** خصم الفاتورة: رقم صحيح 1–100 بدون % يُفسَّر كنسبة (مثل 10 = 10%)؛ مبلغ بفاصلة (1.000). */
  function parseHeaderInput(raw) {
    var s = String(raw == null ? '' : raw).trim();
    if (!s) return null;
    var compact = s.replace(/\s+/g, '');
    if (/[%٪]$/.test(compact)) {
      return parseDiscountInput(raw);
    }
    if (/[.,،]/.test(s)) {
      return parseDiscountInput(raw);
    }
    var p = parseDiscountInput(raw);
    if (!p || p.type !== 'amount') return p;
    var v = p.value;
    if (v >= 1 && v <= 100 && Math.abs(v - Math.round(v)) < 1e-9) {
      return { type: 'percent', value: Math.min(100, v) };
    }
    return p;
  }

  function roundMoney(n, roundFn) {
    if (typeof roundFn === 'function') return roundFn(n);
    return Math.round(n * 100) / 100;
  }

  function amountForBase(lineBase, rawInput, roundFn) {
    if (!(lineBase > 0)) return 0;
    var p = parseDiscountInput(rawInput);
    if (!p) return 0;
    if (p.type === 'percent') {
      return roundMoney((lineBase * p.value) / 100, roundFn);
    }
    return roundMoney(Math.min(p.value, lineBase), roundFn);
  }

  function amountForHeaderBase(lineBase, rawInput, roundFn) {
    if (!(lineBase > 0)) return 0;
    var p = parseHeaderInput(rawInput);
    if (!p) return 0;
    if (p.type === 'percent') {
      return roundMoney((lineBase * p.value) / 100, roundFn);
    }
    return roundMoney(Math.min(p.value, lineBase), roundFn);
  }

  function distributeProportional(totalDiscount, lineBases, roundFn) {
    var n = lineBases.length;
    var out = [];
    var i;
    for (i = 0; i < n; i++) out.push(0);
    if (!(totalDiscount > 0) || n === 0) return out;
    var sumBase = 0;
    for (i = 0; i < n; i++) sumBase += Math.max(0, parseNum(lineBases[i]));
    if (!(sumBase > 0)) return out;
    totalDiscount = Math.min(totalDiscount, roundMoney(sumBase, roundFn));
    var allocated = 0;
    var lastIdx = -1;
    for (i = 0; i < n; i++) {
      var base = Math.max(0, parseNum(lineBases[i]));
      if (!(base > 0)) continue;
      lastIdx = i;
      if (i === n - 1) break;
      var share = roundMoney(totalDiscount * (base / sumBase), roundFn);
      share = Math.min(share, base);
      out[i] = share;
      allocated += share;
    }
    if (lastIdx >= 0) {
      out[lastIdx] = roundMoney(Math.max(0, totalDiscount - allocated), roundFn);
      var cap = Math.max(0, parseNum(lineBases[lastIdx]));
      if (out[lastIdx] > cap) out[lastIdx] = roundMoney(cap, roundFn);
    }
    return out;
  }

  global.InvDiscount = {
    parseInput: parseDiscountInput,
    parseHeaderInput: parseHeaderInput,
    amountForBase: amountForBase,
    amountForHeaderBase: amountForHeaderBase,
    distribute: distributeProportional,
  };
})(typeof window !== 'undefined' ? window : this);
