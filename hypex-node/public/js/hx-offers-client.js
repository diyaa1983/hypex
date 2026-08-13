/**
 * تطبيق عروض المبيعات على بند (واجهة) — يملأ qty_extra أو discount_pct
 */
(function (w) {
  'use strict';
  var timers = {};

  function docDate() {
    var el =
      document.getElementById('order_date') ||
      document.getElementById('invoice_date') ||
      document.querySelector('input[name="order_date"]') ||
      document.querySelector('input[name="invoice_date"]');
    if (el && el.value) {
      var v = String(el.value).trim();
      // DD-MM-YYYY → ISO
      var m = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
      if (m) return m[3] + '-' + m[2] + '-' + m[1];
      if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
    }
    var d = new Date();
    return (
      d.getFullYear() +
      '-' +
      String(d.getMonth() + 1).padStart(2, '0') +
      '-' +
      String(d.getDate()).padStart(2, '0')
    );
  }

  function fetchEffect(itemId, qty, unitFactor, cb) {
    var url =
      (typeof window.__hypexUrl === 'function'
        ? window.__hypexUrl('/api/sales/offers/for-item')
        : '/api/sales/offers/for-item') +
      '?item_id=' +
      encodeURIComponent(itemId) +
      '&qty=' +
      encodeURIComponent(qty) +
      '&unit_factor=' +
      encodeURIComponent(unitFactor > 0 ? unitFactor : 1) +
      '&date=' +
      encodeURIComponent(docDate());
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        cb(d && d.ok ? d.effect : null);
      })
      .catch(function () {
        cb(null);
      });
  }

  /**
   * يحدّث كائن البند + حقول الصف إن وُجدت
   */
  function applyEffectToLine(ln, effect, tr) {
    if (!ln) return ln;
    if (!effect) return ln;
    if (effect.offer_type === 'bonus') {
      ln.qty_extra = effect.bonus_qty;
      if (tr) {
        var qe = tr.querySelector('.js-qty-extra');
        if (qe) qe.value = String(effect.bonus_qty);
      }
    } else if (effect.offer_type === 'discount_pct') {
      ln.discount_pct = effect.discount_pct;
      if (tr) {
        var d = tr.querySelector('.js-disc');
        if (d) d.value = String(effect.discount_pct);
      }
    }
    ln._offer_hint =
      (effect.offer_name || effect.offer_no || 'عرض') +
      (effect.offer_type === 'bonus'
        ? ' · إضافية ' + effect.bonus_qty
        : ' · خصم ' + effect.discount_pct + '%');
    return ln;
  }

  function refreshLine(opts) {
    opts = opts || {};
    var idx = opts.idx;
    var ln = opts.ln;
    var tr = opts.tr;
    var onDone = opts.onDone;
    if (!ln || !Number(ln.item_id)) {
      if (onDone) onDone(ln);
      return;
    }
    var key = String(idx != null ? idx : ln.item_id);
    clearTimeout(timers[key]);
    timers[key] = setTimeout(function () {
      fetchEffect(ln.item_id, ln.qty || 0, Number(ln.unit_factor) || 1, function (effect) {
        applyEffectToLine(ln, effect, tr);
        if (onDone) onDone(ln, effect);
      });
    }, 120);
  }

  w.HxOffers = {
    docDate: docDate,
    refreshLine: refreshLine,
    applyEffectToLine: applyEffectToLine,
  };
})(window);
