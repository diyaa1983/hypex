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

  /** @return {{showQtyExtra:boolean,showDiscount:boolean}} */
  function getLayout(tbody) {
    return {
      showQtyExtra: hasQtyExtra(tbody),
      showDiscount: hasDiscount(tbody),
    };
  }

  function lineColCount(layout) {
    var cols = 9;
    if (layout && layout.showQtyExtra) cols += 1;
    if (layout && layout.showDiscount) cols += 1;
    return cols;
  }

  function theadRow(layout) {
    layout = layout || { showQtyExtra: false, showDiscount: false };
    var h =
      '<th>تسلسل</th><th>رقم المادة</th><th>اسم المادة</th><th>الكمية</th>';
    if (layout.showQtyExtra) {
      h += '<th>الكمية الإضافية</th>';
    }
    h += '<th>السعر الإفرادي</th>';
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
        escapeHtml((tr.querySelector('.js-qty-extra') || { value: '0' }).value) +
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
      html +=
        '<div><span>خصم الفاتورة</span><span>' +
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
      'body{font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#0f172a;margin:6mm 10mm 10mm;direction:rtl;}' +
      'table.inv-print-lines{border-collapse:collapse;width:100%;margin-top:0.5rem;font-size:10px;}' +
      'table.inv-print-lines th{background:#f1f5f9;padding:0.28rem 0.35rem;border:1px solid #94a3b8;font-size:10px;font-weight:400!important;color:#475569;}' +
      'table.inv-print-lines td{padding:0.28rem 0.35rem;border:1px solid #cbd5e1;text-align:center;font-size:10px;font-weight:700!important;color:#0f172a;}' +
      'table.inv-print-lines td.inv-print-cell-item{text-align:start;}' +
      'table.inv-print-lines .inv-print-cell-sku{font-family:Arial,Helvetica,sans-serif;}' +
      'table.inv-print-lines .inv-print-cell-disc{color:#b45309;}' +
      'table.inv-print-lines .inv-print-cell-tax-pct{font-size:9px;}'
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
  };
})(typeof window !== 'undefined' ? window : this);
