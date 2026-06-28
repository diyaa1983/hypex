(function () {
    'use strict';

    var ROUTE = 'hr_payroll_slip_report';
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

    function getSlipNode() {
        return document.querySelector('.hr-pslip-rpt-preview-wrap .hr-pslip-doc.report-sales-print-area');
    }

    function getPrintAreaInnerHtml() {
        var area = getSlipNode();
        if (!area) {
            return '';
        }
        var clone = area.cloneNode(true);
        clone.querySelectorAll('.no-print').forEach(function (el) {
            el.remove();
        });
        var html = clone.outerHTML;
        if (companyLogoUrl && window.DocumentHeader && DocumentHeader.wrapPrintContent) {
            var inner = clone.innerHTML;
            inner = DocumentHeader.wrapPrintContent(inner, companyLogoUrl);
            clone.innerHTML = inner;
            html = clone.outerHTML;
        }
        return html;
    }

    function getSlipCssHref() {
        var link = document.querySelector('link[href*="hr-payroll-slip-report.css"]');
        if (!link || !link.href) {
            return '';
        }
        return link.href.split('?')[0];
    }

    function getDocHeaderCssHref() {
        var link = document.querySelector('link[href*="document-header.css"]');
        if (!link || !link.href) {
            return '';
        }
        return link.href.split('?')[0];
    }

    function getPrintFrameStyles() {
        var dh = window.DocumentHeader || {};
        return (
            docPrintWatermarkStyles() +
            (dh.css || '') +
            (dh.printBoldCss || '') +
            '@page{margin:8mm 10mm;size:A4;}' +
            'body{margin:0;padding:8px;background:#fff;direction:rtl;}' +
            '.hr-pslip-doc{background:#fff;border:none;padding:0;font-family:Arial,Helvetica,Tahoma,sans-serif;font-size:13px;font-weight:700;color:#0f172a;direction:rtl;max-width:none;}' +
            '.hr-pslip-header{text-align:center;margin-bottom:0.5rem;}' +
            '.hr-pslip-header-cols{display:grid;grid-template-columns:1fr auto 1fr;align-items:start;gap:0.5rem 1rem;margin-bottom:0.35rem;text-align:start;font-size:0.78rem;line-height:1.45;}' +
            '.hr-pslip-header-en{text-align:start;}' +
            '.hr-pslip-header-ar{text-align:end;}' +
            '.hr-pslip-co-en,.hr-pslip-co-ar{font-size:0.92rem;font-weight:800;margin-bottom:0.15rem;}' +
            '.hr-pslip-header-logo{display:flex;align-items:center;justify-content:center;min-width:72px;}' +
            '.hr-pslip-header-logo img{max-height:72px;max-width:72px;object-fit:contain;}' +
            '.hr-pslip-title{margin:0.35rem 0 0.15rem;font-size:1.35rem;font-weight:800;text-align:center;}' +
            '.hr-pslip-period-line{margin:0;font-size:0.95rem;text-align:center;}' +
            '.hr-pslip-rule{border:none;border-top:1px solid #334155;margin:0.65rem 0;}' +
            '.hr-pslip-rule--thick{border-top-width:3px;}' +
            '.hr-pslip-rule--double{border-top:3px double #334155;margin-top:1rem;}' +
            '.hr-pslip-emp-grid{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1.5rem;margin-bottom:0.75rem;}' +
            '.hr-pslip-emp-table{width:100%;border-collapse:collapse;font-size:0.88rem;}' +
            '.hr-pslip-emp-table th{text-align:start;font-weight:700;white-space:nowrap;padding:0.15rem 0 0.15rem 0.35rem;width:38%;vertical-align:top;}' +
            '.hr-pslip-emp-table td{padding:0.15rem 0;vertical-align:top;}' +
            '.hr-pslip-summary-cols{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;}' +
            '.hr-pslip-summary-h{margin:0 0 0.35rem;text-align:center;font-size:0.92rem;font-weight:800;text-decoration:underline;}' +
            '.hr-pslip-sum-table{width:100%;border-collapse:collapse;font-size:0.85rem;}' +
            '.hr-pslip-sum-table th,.hr-pslip-sum-table td{border:1px solid #64748b;padding:0.3rem 0.45rem;}' +
            '.hr-pslip-sum-table thead th{background:#e2e8f0;text-align:center;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pslip-sum-table td.num{text-align:left;font-variant-numeric:tabular-nums;white-space:nowrap;width:42%;}' +
            '.hr-pslip-sum-total td{background:#f1f5f9;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pslip-workdays{margin:0.4rem 0 0;font-size:0.82rem;text-align:start;}' +
            '.hr-pslip-net-box{margin-top:0.5rem;border:2px solid #334155;padding:0.35rem 0.55rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;background:#fff;}' +
            '.hr-pslip-net-label,.hr-pslip-net-value{font-weight:800;}' +
            '.hr-pslip-net-value{font-size:1.05rem;font-variant-numeric:tabular-nums;}' +
            '.hr-pslip-detail-cols{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:0.75rem;margin-inline-start:1.25rem;}' +
            '.hr-pslip-detail-h{margin:0 0 0.4rem;font-size:0.9rem;font-weight:800;text-decoration:underline;}' +
            '.hr-pslip-detail-list{list-style:none;margin:0;padding:0;font-size:0.82rem;line-height:1.55;}' +
            '.hr-pslip-detail-list li{display:flex;justify-content:space-between;gap:0.5rem;padding:0.12rem 0;border-bottom:1px dotted #cbd5e1;}' +
            '.hr-pslip-detail-amt{font-variant-numeric:tabular-nums;white-space:nowrap;}' +
            '.muted{color:#64748b;}' +
            '.hr-pslip-doc,.hr-pslip-summary-block,.hr-pslip-detail-block{page-break-inside:avoid;}'
        );
    }

    function buildStandaloneHtml() {
        var bodyAttrs =
            window.DocumentHeader && DocumentHeader.bodyPrintAttrs
                ? DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
                : '';
        var cssHref = getSlipCssHref();
        var docHeaderCssHref = getDocHeaderCssHref();
        var linkTag = cssHref ? '<link rel="stylesheet" href="' + cssHref + '">' : '';
        var docHeaderLinkTag = docHeaderCssHref
            ? '<link rel="stylesheet" href="' + docHeaderCssHref + '">'
            : '';
        return (
            '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قسيمة الراتب</title>' +
            linkTag +
            docHeaderLinkTag +
            '<style>' +
            getPrintFrameStyles() +
            '</style></head><body' +
            bodyAttrs +
            '>' +
            getPrintAreaInnerHtml() +
            '</body></html>'
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
        setTimeout(waitForImages, 350);
    }

    function closePrintPreview() {
        var overlay = document.getElementById('hr-pslip-print-overlay');
        if (overlay) {
            overlay.hidden = true;
        }
    }

    function openPrintPreview() {
        var preview = document.getElementById('hr-pslip-print-preview');
        var overlay = document.getElementById('hr-pslip-print-overlay');
        var title = overlay ? overlay.querySelector('.sales-inv-print-overlay-title') : null;

        if (!preview || !overlay) {
            printHtmlInFrame(buildStandaloneHtml());
            return;
        }
        if (!getSlipNode()) {
            alertMsg('اختر السنة والشهر والموظف ثم اضغط «عرض القسيمة».');
            return;
        }
        if (overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }
        preview.innerHTML = getPrintAreaInnerHtml();
        if (title) {
            title.textContent = 'معاينة الطباعة — اضغط «طباعة» في الشريط العلوي';
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
        var overlay = document.getElementById('hr-pslip-print-overlay');
        var previewOpen = overlay && !overlay.hidden;
        if (previewOpen) {
            runPrintFromPreview();
            return;
        }
        openPrintPreview();
    }

    function bindUi() {
        initEmployeePicker();

        var closeBtn = document.getElementById('hr-pslip-print-close');
        var overlay = document.getElementById('hr-pslip-print-overlay');
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
            var ov = document.getElementById('hr-pslip-print-overlay');
            if (ov && !ov.hidden) {
                closePrintPreview();
            }
        });
    }

    function initEmployeePicker() {
        var hidden = document.getElementById('hr-pslip-employee-id');
        var open = document.getElementById('hr-pslip-employee-id_open');
        var display = document.getElementById('hr-pslip-employee-id_display');
        if (!hidden || !open || !display) {
            return;
        }
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePicker, 40);
            return;
        }
        EmployeePickerModal.bind({
            hidden: 'hr-pslip-employee-id',
            open: 'hr-pslip-employee-id_open',
            display: 'hr-pslip-employee-id_display',
            jsonId: 'hr-pslip-picker-json',
            initialId: hidden.value || '',
            placeholder: 'اضغط لاختيار الموظف',
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

