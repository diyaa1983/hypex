/**
 * أسماء ملفات PDF للموبايل — رقم المستند + اسم العميل/الطرف.
 */
(function (global) {
  'use strict';

  function sanitizePart(s) {
    if (s == null) return '';
    var t = String(s).trim();
    if (!t) return '';
    t = t.replace(/[\x00-\x1f\\/:*?"<>|]/g, '_');
    t = t.replace(/\s+/g, ' ');
    return t;
  }

  function build(parts, ext) {
    ext = ext || '.pdf';
    var clean = [];
    (parts || []).forEach(function (p) {
      var s = sanitizePart(p);
      if (s) clean.push(s);
    });
    var name = clean.length ? clean.join(' - ') : 'document';
    var max = 180;
    if (name.length > max) {
      name = name.slice(0, max);
    }
    return name + ext;
  }

  global.MobilePdfFilename = {
    sanitize: sanitizePart,
    build: build,
    invoice: function (invoiceNo, customerName) {
      return build(['فاتورة', invoiceNo, customerName]);
    },
    receipt: function (voucherNo, customerName) {
      return build(['سند قبض', voucherNo, customerName]);
    },
    salesReturn: function (returnNo, customerName) {
      return build(['مرتجع مبيعات', returnNo, customerName]);
    },
    partyStatement: function (partyName, partyType, fromDmy, toDmy) {
      var kind = partyType === 'supplier' ? 'كشف مورد' : 'كشف حساب';
      var range = '';
      if (fromDmy && toDmy) {
        range = fromDmy + ' إلى ' + toDmy;
      } else if (fromDmy) {
        range = fromDmy;
      } else if (toDmy) {
        range = toDmy;
      }
      return build([kind, partyName, range]);
    },
  };
})(typeof window !== 'undefined' ? window : this);
