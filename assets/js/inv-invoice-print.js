/**
 * مشترك — جدول بنود فاتورة المبيعات/الشراء عند الطباعة والمعاينة.
 */
(function (global) {
  'use strict';

  function parseNum(val) {
    return parseFloat(String(val == null ? '' : val).replace(/,/g, '')) || 0;
  }

  function rowHasItem(tr) {
    return parseInt(tr.getAttribute('data-item-id') || tr.dataset.itemId || '', 10) > 0;
  }

  function qtyExtraFromRow(tr) {
    var el = tr.querySelector('.js-qty-extra');
    return el ? parseNum(el.value) : 0;
  }

  function discountFromRow(tr) {
    var d = parseNum(tr.dataset.disc);
    if (d > 0.000001) {
      return d;
    }
    var share = parseNum(tr.dataset.headerDiscShare);
    if (share > 0.000001) {
      return share;
    }
    var inp = tr.querySelector('.js-discount');
    if (inp) {
      var raw = String(inp.value || '').trim();
      if (raw) {
        d = parseNum(raw);
        if (d > 0.000001) {
          return d;
        }
      }
    }
    return 0;
  }

  function hasQtyExtra(tbody) {
    if (!tbody) return false;
    var rows = tbody.querySelectorAll('tr[data-line-id]');
    for (var i = 0; i < rows.length; i++) {
      if (!rowHasItem(rows[i])) continue;
      if (qtyExtraFromRow(rows[i]) > 0.000001) return true;
    }
    return false;
  }

  function hasDiscount(tbody) {
    if (!tbody) return false;
    var rows = tbody.querySelectorAll('tr[data-line-id]');
    for (var i = 0; i < rows.length; i++) {
      if (!rowHasItem(rows[i])) continue;
      if (discountFromRow(rows[i]) > 0.000001) return true;
    }
    return false;
  }

  /** @return {{showQtyExtra:boolean,showDiscount:boolean,showUnitPriceIncl:boolean}} */
  function getLayout(tbody, opts) {
    opts = opts || {};
    var showQtyExtra = opts.showQtyExtra;
    if (showQtyExtra === undefined) {
      showQtyExtra = opts.alwaysShowQtyExtra ? true : hasQtyExtra(tbody);
    }
    var showDiscount = opts.showDiscount;
    if (showDiscount === undefined) {
      showDiscount = opts.alwaysShowDiscount ? true : hasDiscount(tbody);
    }
    return {
      showQtyExtra: !!showQtyExtra,
      showDiscount: !!showDiscount,
      showUnitPriceIncl: !!opts.showUnitPriceIncl,
      unitPriceExclLabel: opts.unitPriceExclLabel || 'الافرادي غ.ش',
      unitPriceInclLabel: opts.unitPriceInclLabel || 'الافرادي ش.',
    };
  }

  function lineColCount(layout) {
    layout = layout || {};
    var cols = 9;
    if (layout.showQtyExtra) cols += 1;
    if (layout.showUnitPriceIncl) cols += 1;
    if (layout.showDiscount) cols += 1;
    return cols;
  }

  function theadRow(layout) {
    layout = layout || { showQtyExtra: false, showDiscount: false, showUnitPriceIncl: false };
    var h =
      '<th>تسلسل</th><th>رقم المادة</th><th>اسم المادة</th><th>الكمية</th>';
    if (layout.showQtyExtra) {
      h += '<th>الكمية الإضافية</th>';
    }
    h += '<th>' + (layout.unitPriceExclLabel || 'الافرادي غ.ش') + '</th>';
    if (layout.showUnitPriceIncl) {
      h += '<th>' + (layout.unitPriceInclLabel || 'الافرادي ش.') + '</th>';
    }
    if (layout.showDiscount) {
      h += '<th>الخصم</th>';
    }
    h +=
      '<th>السعر الإجمالي</th><th>مبلغ الضريبة</th><th>نسبة الضريبة</th><th>الإجمالي مع الضريبة</th>';
    return h;
  }

  function formatDiscountCell(tr, ctx) {
    var amt = discountFromRow(tr);
    if (!(amt > 0.000001)) {
      return '—';
    }
    if (ctx && typeof ctx.fmtAmount === 'function') {
      return ctx.fmtAmount(amt);
    }
    return String(amt);
  }

  function buildLineRow(tr, layout, ctx) {
    layout = layout || { showQtyExtra: false, showDiscount: false };
    ctx = ctx || {};
    var escapeHtml = ctx.escapeHtml || function (s) {
      return String(s == null ? '' : s);
    };
    var getBarcodeFromRow = ctx.getBarcodeFromRow;
    var getLineSubDisplay = ctx.getLineSubDisplay;
    var getLineGrossDisplay = ctx.getLineGrossDisplay;

    var seqEl = tr.querySelector('.js-seq');
    var seq = seqEl ? seqEl.textContent : '';
    var skuEl = tr.querySelector('.js-sku');
    var skuCode =
      skuEl && skuEl.textContent
        ? skuEl.textContent
        : getBarcodeFromRow
          ? getBarcodeFromRow(tr)
          : '';
    var taxSel = tr.querySelector('.js-tax');
    var taxLab = '';
    if (taxSel && taxSel.options[taxSel.selectedIndex]) {
      taxLab = taxSel.options[taxSel.selectedIndex].text;
    }

    var html = '<tr>';
    html +=
      '<td>' +
      escapeHtml(seq) +
      '</td>' +
      '<td class="inv-print-cell-sku">' +
      escapeHtml(skuCode) +
      '</td>' +
      '<td class="inv-print-cell-item">' +
      escapeHtml(tr.dataset.nameAr || '') +
      '</td>' +
      '<td>' +
      escapeHtml((tr.querySelector('.js-qty') || { value: '' }).value) +
      '</td>';
    if (layout.showQtyExtra) {
      html +=
        '<td>' +
        escapeHtml((tr.querySelector('.js-qty-extra') || { value: '' }).value) +
        '</td>';
    }
    html +=
      '<td>' +
      escapeHtml(
        typeof ctx.fmtUnitPrice === 'function'
          ? ctx.fmtUnitPrice(tr)
          : (tr.querySelector('.js-price') || { value: '' }).value
      ) +
      '</td>';
    if (layout.showUnitPriceIncl) {
      html +=
        '<td>' +
        escapeHtml(
          typeof ctx.fmtUnitPriceIncl === 'function'
            ? ctx.fmtUnitPriceIncl(tr)
            : (tr.querySelector('.js-price-incl') || { value: '' }).value
        ) +
        '</td>';
    }
    if (layout.showDiscount) {
      html +=
        '<td class="inv-print-cell-disc">' +
        escapeHtml(formatDiscountCell(tr, ctx)) +
        '</td>';
    }
    html +=
      '<td>' +
      escapeHtml(getLineSubDisplay ? getLineSubDisplay(tr) : '') +
      '</td>' +
      '<td>' +
      escapeHtml(
        typeof ctx.getTaxAmtDisplay === 'function'
          ? ctx.getTaxAmtDisplay(tr)
          : (tr.querySelector('.js-tax-amt') || { textContent: '' }).textContent
      ) +
      '</td>' +
      '<td class="inv-print-cell-tax-pct">' +
      escapeHtml(taxLab) +
      '</td>' +
      '<td>' +
      escapeHtml(getLineGrossDisplay ? getLineGrossDisplay(tr) : '') +
      '</td>' +
      '</tr>';
    return html;
  }

  function buildPrintTotals(opts) {
    opts = opts || {};
    var escapeHtml = opts.escapeHtml || function (s) {
      return String(s == null ? '' : s);
    };
    var showDisc = !!opts.showDiscountTotal;
    var html = '<div class="sales-inv-print-tot">';
    if (opts.invoiceDiscountLabel) {
      var discTitle = opts.discountTitle || 'خصم الفاتورة';
      html +=
        '<div><span>' +
        escapeHtml(discTitle) +
        '</span><span>' +
        escapeHtml(opts.invoiceDiscountLabel) +
        '</span></div>';
    }
    if (showDisc) {
      html +=
        '<div><span>مجموع الخصم</span><span>' +
        escapeHtml(opts.discTotalText || '0') +
        '</span></div>';
    }
    html +=
      '<div><span>المجموع بدون ضريبة</span><span>' +
      escapeHtml(opts.subTotalText || '0') +
      '</span></div>' +
      '<div><span>مجموع الضريبة</span><span>' +
      escapeHtml(opts.taxTotalText || '0') +
      '</span></div>' +
      '<div class="g"><span>الإجمالي</span><span>' +
      escapeHtml(opts.grandTotalText || '0') +
      '</span></div></div>';
    return html;
  }

  function tablePrintCss() {
    return (
      /* بدون size:A4 — إجبار A4 مع طابعة حرارية يصغّر الصفحة (زوم بعيد) */
      '@page{margin:8mm;}' +
      'html,body{width:100%!important;height:auto!important;min-height:0!important;}' +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;' +
      'margin:0;padding:0;direction:rtl;box-sizing:border-box;}' +
      '*,*:before,*:after{box-sizing:border-box;}' +
      '.doc-print-watermark-root{position:relative;width:100%!important;min-height:0!important;height:auto!important;}' +
      '.doc-print-header{width:100%;break-inside:avoid;page-break-inside:avoid;}' +
      '.doc-print-header-co{font-size:1.15rem!important;}' +
      '.doc-print-header-title{font-size:1.15rem!important;}' +
      '.doc-print-meta{font-size:13px!important;}' +
      '.inv-print-header-row{width:100%!important;break-inside:avoid;page-break-inside:avoid;break-before:avoid;page-break-before:avoid;}' +
      '.sales-inv-print-tot{font-size:13px!important;break-inside:avoid;page-break-inside:avoid;}' +
      '.doc-print-signature-block{break-inside:avoid;page-break-inside:avoid;}' +
      'table.inv-print-lines{border-collapse:collapse;width:100%!important;margin-top:0.5rem;font-size:12px;}' +
      'table.inv-print-lines th{background:#f1f5f9;padding:0.32rem 0.4rem;border:1px solid #94a3b8;font-size:11px;font-weight:400!important;color:#475569;}' +
      'table.inv-print-lines td{padding:0.32rem 0.4rem;border:1px solid #cbd5e1;text-align:center;font-size:12px;font-weight:700!important;color:#0f172a;}' +
      'table.inv-print-lines td.inv-print-cell-item{text-align:start;}' +
      'table.inv-print-lines .inv-print-cell-sku{font-family:Arial,Helvetica,sans-serif;}' +
      'table.inv-print-lines .inv-print-cell-disc{color:#b45309;}' +
      'table.inv-print-lines .inv-print-cell-tax-pct{font-size:11px;}'
    );
  }

  /** QR الفوترة — أكبر قليلاً من الحجم الأصلي (84px) */
  var EINV_QR_IMG_PX = 136;
  var EINV_QR_BOX_PX = 148;
  var EINV_QR_HEADER_COL_PX = 158;
  var EINV_QR_SRC_PX = 384;

  function einvQrIsDirectImage(src) {
    src = String(src || '').trim();
    if (/^data:image\//i.test(src) || /^https?:\/\//i.test(src)) {
      return true;
    }
    if (/^iVBORw0KGgo/i.test(src) || /^\/9j\//.test(src)) {
      return true;
    }
    return false;
  }

  function einvQrNormalizeImageSrc(src) {
    src = String(src || '').trim();
    if (/^data:image\//i.test(src) || /^https?:\/\//i.test(src)) {
      return src;
    }
    if (/^iVBORw0KGgo/i.test(src)) {
      return 'data:image/png;base64,' + src;
    }
    if (/^\/9j\//.test(src)) {
      return 'data:image/jpeg;base64,' + src;
    }
    return src;
  }

  function einvQrRemoteUrl(data) {
    return (
      'https://api.qrserver.com/v1/create-qr-code/?size=' +
      EINV_QR_SRC_PX +
      'x' +
      EINV_QR_SRC_PX +
      '&format=png&margin=4&ecc=H&data=' +
      encodeURIComponent(String(data || ''))
    );
  }

  function einvQrGenerateOptions() {
    return {
      width: EINV_QR_SRC_PX,
      margin: 1,
      errorCorrectionLevel: 'H',
      type: 'image/png',
      color: { dark: '#000000', light: '#FFFFFF' },
    };
  }

  function einvQrResolveDataUrl(payload, cb) {
    payload = String(payload || '').trim();
    if (!payload) {
      cb('');
      return;
    }
    if (einvQrIsDirectImage(payload)) {
      cb(einvQrNormalizeImageSrc(payload));
      return;
    }
    if (typeof global.QRCode === 'undefined' || !global.QRCode.toDataURL) {
      try {
        cb(einvQrRemoteUrl(payload));
      } catch (_e) {
        cb('');
      }
      return;
    }
    try {
      global.QRCode.toDataURL(payload, einvQrGenerateOptions(), function (err, url) {
        if (err || !url) {
          try {
            cb(einvQrRemoteUrl(payload));
          } catch (_e2) {
            cb('');
          }
        } else {
          cb(url);
        }
      });
    } catch (_e) {
      try {
        cb(einvQrRemoteUrl(payload));
      } catch (_e2) {
        cb('');
      }
    }
  }

  function einvQrPrintCss() {
    return (
      '.inv-print-header-row td.inv-print-header-qr{width:' +
      EINV_QR_HEADER_COL_PX +
      'px;padding-inline-start:8px!important;text-align:center;}' +
      '.inv-print-qr-wrap{width:' +
      EINV_QR_BOX_PX +
      'px;text-align:center;margin-inline-start:auto;}' +
      '.inv-print-qr-box{border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:' +
      EINV_QR_BOX_PX +
      'px;height:' +
      EINV_QR_BOX_PX +
      'px;box-sizing:border-box;text-align:center;line-height:0;}' +
      '.inv-print-qr-img{display:inline-block;width:' +
      EINV_QR_IMG_PX +
      'px;height:' +
      EINV_QR_IMG_PX +
      'px;margin:0 auto;vertical-align:middle;}' +
      '.inv-print-qr-placeholder{display:inline-block;width:' +
      EINV_QR_IMG_PX +
      'px;height:' +
      EINV_QR_IMG_PX +
      'px;background:#f1f5f9;border-radius:6px;vertical-align:middle;}' +
      '@media print{' +
      'body{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
      '.inv-print-qr-box{width:' +
      EINV_QR_BOX_PX +
      'px!important;height:' +
      EINV_QR_BOX_PX +
      'px!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
      '.inv-print-qr-img{width:' +
      EINV_QR_IMG_PX +
      'px!important;height:' +
      EINV_QR_IMG_PX +
      'px!important;max-width:none!important;max-height:none!important;}' +
      '}'
    );
  }

  function buildEinvQrBoxHtml(imgDataUrl) {
    if (!imgDataUrl) return '';
    var wrapStyle =
      'width:' + EINV_QR_BOX_PX + 'px;text-align:center;margin:0;';
    var boxStyle =
      'border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:' +
      EINV_QR_BOX_PX +
      'px;height:' +
      EINV_QR_BOX_PX +
      'px;box-sizing:border-box;text-align:center;line-height:0;display:block;';
    var imgStyle =
      'width:' +
      EINV_QR_IMG_PX +
      'px;height:' +
      EINV_QR_IMG_PX +
      'px;display:inline-block;margin:0 auto;vertical-align:middle;';
    return (
      '<div class="inv-print-qr-wrap" style="' +
      wrapStyle +
      '">' +
      '<div class="inv-print-qr-box" style="' +
      boxStyle +
      '">' +
      '<img src="' +
      String(imgDataUrl) +
      '" alt="QR" class="inv-print-qr-img" width="' +
      EINV_QR_IMG_PX +
      '" height="' +
      EINV_QR_IMG_PX +
      '" style="' +
      imgStyle +
      '">' +
      '</div>' +
      '</div>'
    );
  }

  global.InvInvoicePrint = {
    hasQtyExtra: hasQtyExtra,
    hasDiscount: hasDiscount,
    getLayout: getLayout,
    lineColCount: lineColCount,
    theadRow: theadRow,
    buildLineRow: buildLineRow,
    buildPrintTotals: buildPrintTotals,
    tablePrintCss: tablePrintCss,
    EINV_QR_IMG_PX: EINV_QR_IMG_PX,
    EINV_QR_BOX_PX: EINV_QR_BOX_PX,
    EINV_QR_HEADER_COL_PX: EINV_QR_HEADER_COL_PX,
    EINV_QR_SRC_PX: EINV_QR_SRC_PX,
    einvQrIsDirectImage: einvQrIsDirectImage,
    einvQrRemoteUrl: einvQrRemoteUrl,
    einvQrGenerateOptions: einvQrGenerateOptions,
    einvQrResolveDataUrl: einvQrResolveDataUrl,
    einvQrPrintCss: einvQrPrintCss,
    buildEinvQrBoxHtml: buildEinvQrBoxHtml,
  };
})(typeof window !== 'undefined' ? window : this);
