(function () {
    'use strict';

    var ROUTE = 'hr_payroll_dept_report';
    var companyLogoUrl = document.body.getAttribute('data-company-logo-url') || '';

    function alertMsg(msg) {
        if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(msg, { type: 'warning' });
        } else {
            window.alert(msg);
        }
    }

    function docPrintWatermarkStyles() {
        var dh = window.DocumentHeader;
        return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
            ? dh.buildPrintWatermarkStyles(companyLogoUrl)
            : '';
    }

    function getPrintAreaInnerHtml() {
        var area = document.querySelector('.hr-pr-dept-rpt-doc.report-sales-print-area');
        if (!area) {
            return '';
        }
        var clone = area.cloneNode(true);
        clone.querySelectorAll('.no-print').forEach(function (el) {
            el.remove();
        });
        var html = clone.innerHTML;
        if (companyLogoUrl && window.DocumentHeader && DocumentHeader.wrapPrintContent) {
            html = DocumentHeader.wrapPrintContent(html, companyLogoUrl);
        }
        return html;
    }

    function getPrintFrameStyles() {
        var dh = window.DocumentHeader || {};
        return (
            docPrintWatermarkStyles() +
            (dh.css || '') +
            (dh.printBoldCss || '') +
            '@page{margin:8mm 10mm;}' +
            'body{margin:0;padding:8px;background:#fff;}' +
            '.report-sales-print-area{font-size:12px;direction:rtl;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#0f172a;}' +
            '.hr-pr-dept-rpt-doc{box-shadow:none;border:none;max-width:none;padding:0;margin:0;}' +
            '.hr-pr-dept-rpt-doc .doc-print-header-title{font-size:1.55rem;font-weight:800;}' +
            '.hr-pr-dept-rpt-block{margin-bottom:1.25rem;page-break-inside:avoid;}' +
            '.hr-pr-dept-rpt-dept-name{margin:0 0 0.35rem;font-size:1rem;font-weight:700;}' +
            '.hr-pr-dept-rpt-table{width:100%;border-collapse:collapse;font-size:11px;}' +
            '.hr-pr-dept-rpt-table th,.hr-pr-dept-rpt-table td{border:1px solid #94a3b8;padding:4px 5px;text-align:right;}' +
            '.hr-pr-dept-rpt-table thead th{background:#d9d9d9!important;text-align:center;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pr-dept-rpt-table td.num{text-align:left;font-variant-numeric:tabular-nums;}' +
            '.hr-pr-dept-rpt-sum td{background:#e2e8f0;font-weight:700;}' +
            '.hr-pr-dept-rpt-grand{margin-top:0.75rem;display:flex;flex-direction:column;align-items:flex-end;}' +
            '.hr-pr-dept-rpt-grand-title{margin:0;font-size:1rem;font-weight:700;align-self:stretch;text-align:right;}' +
            '.hr-pr-dept-rpt-grand-table{border-collapse:collapse;min-width:480px;}' +
            '.hr-pr-dept-rpt-grand-table th,.hr-pr-dept-rpt-grand-table td{border:1px solid #64748b;padding:5px 7px;}' +
            '.hr-pr-dept-rpt-grand-table th{background:#d9d9d9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pr-dept-rpt-grand-highlight th,.hr-pr-dept-rpt-grand-highlight td{background:#f1f5f9!important;}' +
            '.hr-pr-dept-rpt-grand-total th,.hr-pr-dept-rpt-grand-total td{background:#e2e8f0!important;font-weight:800;}'
        );
    }

    function buildStandaloneHtml() {
        var bodyAttrs =
            window.DocumentHeader && DocumentHeader.bodyPrintAttrs
                ? DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
                : '';
        return (
            '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>كشف الرواتب للأقسام</title><style>' +
            getPrintFrameStyles() +
            '</style></head><body' +
            bodyAttrs +
            '><div class="report-sales-print-area hr-pr-dept-rpt-doc">' +
            getPrintAreaInnerHtml() +
            '</div></body></html>'
        );
    }

    function getPrintFrame() {
        var frame = document.getElementById('sales-inv-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'sales-inv-print-frame';
            frame.className = 'sales-inv-print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('tabindex', '-1');
            document.body.appendChild(frame);
        }
        return frame;
    }

    function printHtmlInFrame(fullHtml) {
        var frame = getPrintFrame();
        var win = frame.contentWindow;
        win.document.open();
        win.document.write(fullHtml);
        win.document.close();
        var triggerPrint = function () {
            try {
                win.focus();
                win.print();
            } catch (e) {
                /* ignored */
            }
        };
        var waitForImages = function () {
            try {
                var doc = win.document;
                var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
                var pending = imgs.filter(function (im) {
                    return im && !im.complete;
                });
                if (pending.length === 0) {
                    triggerPrint();
                    return;
                }
                var remaining = pending.length;
                var done = function () {
                    remaining--;
                    if (remaining <= 0) {
                        triggerPrint();
                    }
                };
                pending.forEach(function (im) {
                    im.addEventListener('load', done, { once: true });
                    im.addEventListener('error', done, { once: true });
                });
                setTimeout(triggerPrint, 4000);
            } catch (e) {
                triggerPrint();
            }
        };
        setTimeout(waitForImages, 200);
    }

    function closePrintPreview() {
        var overlay = document.getElementById('hr-pr-dept-print-overlay');
        if (overlay) {
            overlay.hidden = true;
        }
    }

    function openPrintPreview(forPdf) {
        var preview = document.getElementById('hr-pr-dept-print-preview');
        var overlay = document.getElementById('hr-pr-dept-print-overlay');
        var title = overlay ? overlay.querySelector('.sales-inv-print-overlay-title') : null;

        if (!preview || !overlay) {
            printHtmlInFrame(buildStandaloneHtml());
            return;
        }
        if (overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }
        preview.innerHTML = getPrintAreaInnerHtml();
        if (title) {
            title.textContent = forPdf
                ? 'معاينة — اختر «حفظ كـ PDF» من نافذة الطباعة'
                : 'معاينة الطباعة — اضغط «طباعة» في الشريط العلوي';
        }
        overlay.removeAttribute('hidden');
        overlay.hidden = false;
        overlay.style.display = 'flex';
        overlay.style.zIndex = '10050';
    }

    function runPrintFromPreview() {
        printHtmlInFrame(buildStandaloneHtml());
    }

    function handleToolbarPrint() {
        var overlay = document.getElementById('hr-pr-dept-print-overlay');
        var previewOpen = overlay && !overlay.hidden;
        if (previewOpen) {
            runPrintFromPreview();
            return;
        }
        openPrintPreview(false);
    }

    function bindUi() {
        var closeBtn = document.getElementById('hr-pr-dept-print-close');
        var overlay = document.getElementById('hr-pr-dept-print-overlay');
        if (closeBtn) {
            closeBtn.addEventListener('click', closePrintPreview);
        }
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closePrintPreview();
                }
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            var ov = document.getElementById('hr-pr-dept-print-overlay');
            if (ov && !ov.hidden) {
                closePrintPreview();
            }
        });
    }

    document.addEventListener(
        'master-toolbar',
        function (e) {
            if (!e.detail || e.detail.action !== 'print') {
                return;
            }
            var bar = document.getElementById('master-toolbar');
            var route = bar ? bar.getAttribute('data-active-route') || '' : '';
            if (route !== ROUTE) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            if (!document.querySelector('.hr-pr-dept-rpt-doc.report-sales-print-area')) {
                alertMsg('اختر القسم والشهر ثم اضغط «عرض الكشف».');
                return;
            }
            handleToolbarPrint();
        },
        true
    );

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUi);
    } else {
        bindUi();
    }
})();
