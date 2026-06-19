(function (global) {
  'use strict';

  var LOGO_MAX_H = 130;
  var LOGO_MAX_W = 130;

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function logoImgStyle() {
    return (
      'max-height:' +
      LOGO_MAX_H +
      'px;max-width:' +
      LOGO_MAX_W +
      'px;width:auto;height:auto;object-fit:contain;display:block;'
    );
  }

  /**
   * @param {{ companyName?: string, logoUrl?: string, title?: string }} opts
   * @returns {string}
   */
  function build(opts) {
    opts = opts || {};
    var company = opts.companyName || '';
    var title = opts.title || '';
    var subtitle = opts.subtitle != null ? String(opts.subtitle).trim() : '';
    var logoUrl = opts.logoUrl || '';
    var logoInner = logoUrl
      ? '<img src="' + escapeHtml(logoUrl) + '" alt="" style="' + logoImgStyle() + '">'
      : '';
    var subtitleHtml =
      subtitle !== ''
        ? '<div class="doc-print-header-subtitle">' + escapeHtml(subtitle) + '</div>'
        : '';
    return (
      '<header class="doc-print-header" role="banner">' +
      '<div class="doc-print-header-top">' +
      '<div class="doc-print-header-brand">' +
      '<div class="doc-print-header-co">' +
      escapeHtml(company) +
      '</div>' +
      '<div class="doc-print-header-logo">' +
      logoInner +
      '</div>' +
      '</div>' +
      '</div>' +
      '<div class="doc-print-header-title">' +
      escapeHtml(title) +
      '</div>' +
      subtitleHtml +
      '</header>'
    );
  }

  function isPartyMetaLabel(label) {
    return label === 'العميل' || label === 'المورد';
  }

  function formatMetaValue(row) {
    var v = String(row.value);
    if (row.emphasis === true || isPartyMetaLabel(row.label)) {
      return (
        '<span class="doc-print-meta-value doc-print-meta-value--party">' +
        escapeHtml(v) +
        '</span>'
      );
    }
    return escapeHtml(v);
  }

  /**
   * جدول بيانات المستند تحت الترويسة — نفس فاتورة المبيعات.
   * @param {Array<{label: string, value: string|number, emphasis?: boolean}>} rows
   * @returns {string}
   */
  function buildMetaTable(rows) {
    var html = '<div class="doc-print-meta"><table>';
    (rows || []).forEach(function (row) {
      var v = row.value;
      if (v == null || v === '') return;
      // \u200F = RLM يضمن أن النقطتين تبقى ملتصقة بالنص العربي ولا تُنقَل لجانب الأرقام
      // <bdi> يعزل اتجاه القيمة (قد تكون أرقام لاتينية أو نص مختلط) عن الـ label
      html +=
        '<tr><td style="padding:0.2rem 0;direction:rtl;unicode-bidi:isolate;"><strong>' +
        escapeHtml(row.label) +
        ':\u200F</strong> <bdi>' +
        formatMetaValue(row) +
        '</bdi></td></tr>';
    });
    return html + '</table></div>';
  }

  function escapeCssUrl(url) {
    return String(url || '')
      .replace(/\\/g, '\\\\')
      .replace(/"/g, '\\"');
  }

  /** شفافية العلامة المائية — يُطابق DOCUMENT_WATERMARK_OPACITY في document_header.php */
  var WATERMARK_OPACITY = 0.04;

  /** :root{--doc-watermark-logo:...} لإطار الطباعة المنفصل */
  function watermarkRootCss(logoUrl) {
    if (!logoUrl) return '';
    return (
      ':root{--doc-watermark-logo:url("' +
      escapeCssUrl(logoUrl) +
      '");--doc-watermark-opacity:' +
      WATERMARK_OPACITY +
      ';}'
    );
  }

  var watermarkElementCss =
    '.has-doc-watermark,.doc-print-watermark-scope,.doc-print-watermark-root{position:relative;}' +
    'body.has-doc-watermark .main-bg-logo{visibility:hidden;}' +
    'body.has-doc-watermark .card:has(.report-sales-print-area),body.has-doc-watermark .report-sales-result{overflow:visible;}' +
    'body.has-doc-watermark::after{content:"";position:fixed;inset:0;width:100%;height:100%;margin:0;' +
    'background-image:var(--doc-watermark-logo);background-repeat:no-repeat;background-position:center center;' +
    'background-size:min(72vw,460px) auto;opacity:var(--doc-watermark-opacity,' +
    WATERMARK_OPACITY +
    ');z-index:50;pointer-events:none;}' +
    'body.doc-print-standalone::after{display:none;}' +
    '.doc-print-watermark-root{position:relative;}' +
    '.doc-print-watermark--overlay{position:absolute;inset:0;z-index:1000;display:flex;align-items:center;' +
    'justify-content:center;pointer-events:none;overflow:visible;min-height:100%;}' +
    '.doc-print-watermark--overlay img{width:min(72%,460px);max-width:460px;height:auto;' +
    'max-height:min(62vh,440px);object-fit:contain;opacity:var(--doc-watermark-opacity,' +
    WATERMARK_OPACITY +
    ');filter:grayscale(0.12) contrast(0.92);}';

  var watermarkPrintMediaCss =
    'body.has-doc-watermark::after{display:block!important;position:fixed!important;inset:0!important;' +
    'width:100%!important;height:100%!important;transform:none!important;z-index:9999;' +
    '-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    'body.doc-print-standalone::after{display:block!important;}' +
    '.doc-print-watermark--overlay{display:none!important;}' +
    '.doc-print-watermark:not(.doc-print-watermark--overlay){display:none!important;}';

  function watermarkHtml(logoUrl) {
    if (!logoUrl) return '';
    return (
      '<div class="doc-print-watermark doc-print-watermark--overlay" aria-hidden="true">' +
      '<img src="' +
      escapeHtml(logoUrl) +
      '" alt="">' +
      '</div>'
    );
  }

  function wrapPrintContent(html, logoUrl) {
    var inner = html || '';
    if (logoUrl && inner) {
      inner = '<div class="doc-print-watermark-root">' + watermarkHtml(logoUrl) + inner + '</div>';
    }
    return inner + buildPrintUserFooter();
  }

  function getPrintUserLabel() {
    if (typeof global.__PRINT_USER__ === 'string' && global.__PRINT_USER__.trim() !== '') {
      return global.__PRINT_USER__.trim();
    }
    var body = typeof document !== 'undefined' ? document.body : null;
    if (!body) return '';
    var v = body.getAttribute('data-print-user');
    return v ? String(v).trim() : '';
  }

  function buildPrintUserFooter(label) {
    var name = label != null ? String(label).trim() : getPrintUserLabel();
    if (!name) return '';
    return (
      '<footer class="doc-print-user-footer doc-print-only" aria-hidden="true">' +
      '<div class="doc-print-user-footer-line" aria-hidden="true"></div>' +
      '<div class="doc-print-user-footer-text">طبع بواسطة: ' +
      escapeHtml(name) +
      '</div></footer>'
    );
  }

  function ensurePrintUserFooter(doc) {
    doc = doc || (typeof document !== 'undefined' ? document : null);
    if (!doc || !doc.body) return;
    if (doc.body.querySelector('.doc-print-user-footer')) return;
    var html = buildPrintUserFooter();
    if (html) {
      doc.body.insertAdjacentHTML('beforeend', html);
    }
  }

  var printUserFooterCss =
    '.doc-print-user-footer{display:none;}' +
    '@media print{' +
    '.doc-print-user-footer{display:block!important;position:fixed;bottom:2mm;left:0;right:0;' +
    'margin:0;padding:0;z-index:10001;pointer-events:none;box-sizing:border-box;' +
    '-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
    '.doc-print-user-footer-line{display:block;width:100%;height:0;margin:0;border:0;border-top:1px solid #000;}' +
    '.doc-print-user-footer-text{display:block;margin:0;padding:2px 0 0 6mm;font-family:Arial,Helvetica,sans-serif;' +
    'font-size:7pt;font-weight:400;line-height:1.3;color:#000;text-align:left;direction:rtl;}' +
    '}';

  /**
   * @param {string} logoUrl
   * @returns {string}
   */
  function buildPrintWatermarkStyles(logoUrl) {
    if (!logoUrl) return '';
    return (
      watermarkRootCss(logoUrl) +
      watermarkElementCss +
      '@media print{' +
      watermarkPrintMediaCss +
      '}'
    );
  }

  function bodyPrintAttrs(logoUrl, standalone) {
    if (!logoUrl) return '';
    return standalone ? ' class="has-doc-watermark doc-print-standalone"' : ' class="has-doc-watermark"';
  }

  var css =
    '.doc-print-header{margin-top:0;margin-bottom:0.65rem;padding-top:0;}' +
    '.doc-print-header-top{padding-top:0;padding-bottom:0.5rem;border-bottom:1px solid #cbd5e1;}' +
    '.doc-print-header-brand{display:flex;flex-direction:row;align-items:center;justify-content:space-between;width:100%;gap:0.75rem;flex-wrap:wrap;direction:rtl;}' +
    '.doc-print-header-co{flex:1 1 auto;min-width:0;font-family:Arial,Helvetica,sans-serif;font-weight:800;font-size:1.1rem;color:#0f172a;text-align:start;line-height:1.3;}' +
    '.doc-print-header-logo{display:flex;align-items:center;justify-content:flex-end;flex-shrink:0;overflow:visible;padding:2px 0;}' +
    '.doc-print-header-logo img{max-height:' +
    LOGO_MAX_H +
    'px;max-width:' +
    LOGO_MAX_W +
    'px;width:auto;height:auto;object-fit:contain;display:block;}' +
    '.doc-print-header-title{text-align:center;font-family:Arial,Helvetica,sans-serif;font-weight:700;font-size:1.1rem;color:#1e293b;' +
    'padding-top:0.45rem;margin:0;}' +
    '.doc-print-header-subtitle{text-align:center;font-family:Arial,Helvetica,sans-serif;font-weight:700;font-size:1rem;' +
    'color:#334155;padding-top:0.2rem;margin:0 0 0.15rem;}' +
    '.doc-print-meta{margin:0.35rem 0 0.65rem;font-size:12px;font-weight:700;color:#334155;line-height:1.55;text-align:start;direction:rtl;}' +
    '.doc-print-meta table{width:100%;border-collapse:collapse;}' +
    '.doc-print-meta td{padding:0.2rem 0;border:none!important;text-align:start!important;font-weight:700;}' +
    '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}' +
    '.doc-print-signature-block{margin-top:2.25rem;padding-top:0.5rem;page-break-inside:avoid;max-width:280px;margin-inline-start:auto;margin-inline-end:0;}' +
    '.doc-print-signature-label{display:block;font-weight:700;font-size:13px;margin:0 0 0.25rem;color:#0f172a;}' +
    '.doc-print-signature-line{display:block;border-bottom:1.5px solid #334155;height:0;margin:2.5rem 0 0;}' +
    printUserFooterCss;

  /** مكان توقيع المستلم أسفل المستند المطبوع */
  function buildRecipientSignature() {
    return (
      '<div class="doc-print-signature-block" role="group" aria-label="توقيع المستلم">' +
      '<span class="doc-print-signature-label">توقيع المستلم</span>' +
      '<span class="doc-print-signature-line" aria-hidden="true"></span>' +
      '</div>'
    );
  }

  /** خط غامق لمعاينة الطباعة والإطار المطبوع */
  var printBoldCss =
    'body,table,th,td,.doc-print-meta,.doc-print-meta td,.sales-inv-print-tot,.sales-inv-print-tot div,.sales-inv-print-tot span{font-weight:700;}' +
    '.doc-print-header-co{font-weight:800;}.doc-print-header-title,.sales-inv-print-tot .g{font-weight:800;}' +
    '.doc-print-signature-label{font-weight:700;}';

  global.DocumentHeader = {
    build: build,
    buildMetaTable: buildMetaTable,
    buildRecipientSignature: buildRecipientSignature,
    buildPrintUserFooter: buildPrintUserFooter,
    getPrintUserLabel: getPrintUserLabel,
    ensurePrintUserFooter: ensurePrintUserFooter,
    css: css,
    printBoldCss: printBoldCss,
    printUserFooterCss: printUserFooterCss,
    watermarkRootCss: watermarkRootCss,
    watermarkElementCss: watermarkElementCss,
    watermarkPrintMediaCss: watermarkPrintMediaCss,
    watermarkHtml: watermarkHtml,
    wrapPrintContent: wrapPrintContent,
    buildPrintWatermarkStyles: buildPrintWatermarkStyles,
    bodyPrintAttrs: bodyPrintAttrs,
    escapeHtml: escapeHtml,
    logoMaxHeight: LOGO_MAX_H,
    logoMaxWidth: LOGO_MAX_W,
  };

  if (typeof window !== 'undefined') {
    window.addEventListener('beforeprint', function () {
      ensurePrintUserFooter(document);
    });
  }
})(typeof window !== 'undefined' ? window : this);
