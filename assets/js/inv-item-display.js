(function (global) {
  'use strict';

  function materialNumber(barcode, sku) {
    var bc = String(barcode == null ? '' : barcode).trim();
    if (bc) return bc;
    return String(sku == null ? '' : sku).trim();
  }

  /** أرقام فقط — للباركود أولاً ثم من SKU إن لزم. */
  function materialNumberDigitsOnly(barcode, sku) {
    var bc = String(barcode == null ? '' : barcode).trim();
    if (bc) {
      var fromBc = bc.replace(/\D/g, '');
      if (fromBc) return fromBc;
    }
    return String(sku == null ? '' : sku).replace(/\D/g, '');
  }

  global.InvItemDisplay = {
    materialNumber: materialNumber,
    materialNumberDigitsOnly: materialNumberDigitsOnly,
  };
})(typeof window !== 'undefined' ? window : this);
