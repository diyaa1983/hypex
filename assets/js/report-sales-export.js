(function () {
  'use strict';

  var REPORT_ROUTES = {
    report_sales: true,
    report_sales_between_dates: true,
    report_sales_by_rep: true,
    report_sales_by_item: true,
    report_customer_orders: true,
    report_sales_returns: true,
    report_sales_returns_totals: true,
    report_sales_qty_extra: true,
    report_sales_delivery: true,
    report_customers: true,
    report_purchases: true,
    report_purchases_by_item: true,
    report_purchase_orders: true,
    report_purchase_orders_by_item: true,
    report_purchase_orders_open: true,
    report_purchase_returns: true,
    report_party_statement: true,
    report_oracle_customer_statement: true,
    report_customer_statement: true,
    report_supplier_statement: true,
    report_receivables: true,
    report_receivables_aging: true,
    report_supplier_payables: true,
    item_stock_movements: true,
    report_inventory: true,
    report_warehouse_items: true,
    report_warehouse_zero_qty: true,
    report_warehouse_negative_qty: true,
    report_warehouse_financial: true,
    report_warehouse_moves: true,
    inventory_stocktake: true,
    report_invoice_tax: true,
    report_trial_balance: true,
    report_trial_balance_detailed: true,
    report_vouchers: true,
    report_cancelled_vouchers: true,
    report_incoming_checks: true,
    report_outgoing_checks: true,
    report_general_ledger: true,
    report_account_statement: true,
    report_chart_of_accounts: true,
    report_income_statement: true,
    report_income_statement_comprehensive: true,
    report_tax_declaration: true,
    report_vat_net_payable: true,
    report_balance_sheet: true,
    hr_payroll_ss_report: true,
    hr_payroll_income_tax_report: true,
    hr_payroll_bank_transfer_report: true,
    report_tax_ar3: true,
    report_hr_employees: true,
    report_hr_employees_by_department: true,
    report_hr_employees_by_nationality: true,
    report_hr_employees_resigned: true,
  };

  function isReportExportRoute() {
    var bar = document.getElementById('master-toolbar');
    if (!bar) return false;
    var route = bar.getAttribute('data-active-route') || '';
    if (REPORT_ROUTES[route]) return true;
    var page = document.querySelector('.report-sales-page');
    return !!(page && REPORT_ROUTES[page.getAttribute('data-report-route') || '']);
  }

  function alertMsg(msg, opts) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, opts || { type: 'warning' });
    } else {
      window.alert(msg);
    }
  }

    function initReportSalesExport() {
      var page = document.querySelector('.report-sales-page');
      if (!page || !isReportExportRoute()) return;

      if (
        page.getAttribute('data-receivables-mode') === 'summary' &&
        (page.getAttribute('data-report-route') === 'report_receivables' ||
          page.getAttribute('data-report-route') === 'report_supplier_payables' ||
          page.getAttribute('data-report-route') === 'report_receivables_aging')
      ) {
        window.addEventListener('beforeprint', function () {
          fitReceivablesSummaryPartyNames(document);
        });
      }

      if (isSalesCustomerNameFitReport()) {
        window.addEventListener('beforeprint', function () {
          fitSalesReportCustomerNames(document);
          setTimeout(function () {
            fitSalesReportCustomerNames(document);
          }, 0);
        });
        fitSalesReportCustomerNames(document);
      }

      if (isDeliveryReportRoute()) {
        window.addEventListener('beforeprint', function () {
          fitDeliveryReportPrintCells(document);
          setTimeout(function () {
            fitDeliveryReportPrintCells(document);
          }, 0);
        });
        fitDeliveryReportPrintCells(document);
      }

      if (isSalesItemNameFitReport()) {
        window.addEventListener('beforeprint', function () {
          fitSalesReportItemNames(document);
          setTimeout(function () {
            fitSalesReportItemNames(document);
          }, 0);
        });
        fitSalesReportItemNames(document);
      }

      if (isPurchasesAllSuppliersReport()) {
        window.addEventListener('beforeprint', function () {
          fitPurchasesReportSupplierNames(document);
        });
      }

      if (isItemStockLedgerReport()) {
        window.addEventListener('beforeprint', function () {
          fitItemStockLedgerPartyNames(document);
        });
      }

      if (isVoucherChecksReport()) {
        window.addEventListener('beforeprint', function () {
          fitIncomingChecksReportCells(document);
          setTimeout(function () {
            fitIncomingChecksReportCells(document);
          }, 0);
        });
      }

    var exportHost = document.getElementById('sales-inv-export-host');
    if (exportHost && exportHost.parentNode !== document.body) {
      document.body.appendChild(exportHost);
    }

    function hasReportData() {
      return !!document.querySelector('.report-sales-print-area');
    }

    /** فصل صف الإجمالي عن الجدول الرئيسي قبل الطباعة/PDF لتجنّب تداخله مع آخر صفحة */
    function normalizeSalesReportGrandTotal(root) {
      if (!root || !root.querySelectorAll) return;
      root.querySelectorAll('.report-sales-table').forEach(function (tbl) {
        if (tbl.classList.contains('report-sales-grand-total-table')) return;
        /* تقرير الموظفين: الإبقاء على tfoot داخل الجدول حتى لا يختل تخطيط الأعمدة في html2canvas */
        if (tbl.classList.contains('report-hr-employees-table')) return;
        var tfoot = tbl.querySelector('tfoot');
        if (!tfoot) return;
        var wrap = tbl.closest('.report-sales-table-wrap');
        if (!wrap) return;
        if (wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('report-sales-grand-total-wrap')) {
          tfoot.remove();
          return;
        }

        var totalWrap = document.createElement('div');
        totalWrap.className = wrap.className;
        if (totalWrap.className.indexOf('report-sales-grand-total-wrap') === -1) {
          totalWrap.className += ' report-sales-grand-total-wrap';
        }

        var totalTable = document.createElement('table');
        totalTable.className = tbl.className.replace(/\s*js-sortable-report\s*/g, ' ').trim();
        if (totalTable.className.indexOf('report-sales-grand-total-table') === -1) {
          totalTable.className += ' report-sales-grand-total-table';
        }

        var colgroup = tbl.querySelector('colgroup');
        if (colgroup) {
          totalTable.appendChild(colgroup.cloneNode(true));
        }

        var tbody = document.createElement('tbody');
        Array.prototype.slice.call(tfoot.rows).forEach(function (row) {
          tbody.appendChild(row.cloneNode(true));
        });
        totalTable.appendChild(tbody);
        totalWrap.appendChild(totalTable);
        tfoot.remove();

        var stack = wrap.closest('.report-sales-table-stack');
        if (stack) {
          stack.appendChild(totalWrap);
        } else {
          wrap.parentNode.insertBefore(totalWrap, wrap.nextSibling);
        }
      });
    }

    function isPeriodInvoiceReportRouteKey(routeKey) {
      return (
        routeKey === 'report_sales' ||
        routeKey === 'report_sales_between_dates' ||
        routeKey === 'report_purchases'
      );
    }

    function isTrialBalanceReportRoute(routeKey) {
      return routeKey === 'report_trial_balance' || routeKey === 'report_trial_balance_detailed';
    }

    function getPrintAreaHtml() {
      var area = document.querySelector('.report-sales-print-area');
      if (!area) return '';
      var routeKey = page.getAttribute('data-report-route') || '';
      if (routeKey === 'inventory_stocktake') {
        var stockClone = area.cloneNode(true);
        stockClone.querySelectorAll('.no-print').forEach(function (el) {
          el.remove();
        });
        return stockClone.innerHTML;
      }
      var clone = area.cloneNode(true);
      clone.querySelectorAll('.no-print').forEach(function (el) {
        el.remove();
      });
      normalizeSalesReportGrandTotal(clone);
      var html = clone.innerHTML;
      var logoUrl = getCompanyLogoUrl();
      if (window.DocumentHeader && window.DocumentHeader.wrapPrintContent) {
        html = window.DocumentHeader.wrapPrintContent(html, logoUrl || '');
      }
      return html;
    }

    function isReceivablesSummaryMode() {
      return page.getAttribute('data-receivables-mode') === 'summary';
    }

    function isReceivablesAgingReport() {
      return (page.getAttribute('data-report-route') || '') === 'report_receivables_aging';
    }

    function isReceivablesReportRoute(routeKey) {
      return (
        routeKey === 'report_receivables' ||
        routeKey === 'report_supplier_payables' ||
        routeKey === 'report_receivables_aging'
      );
    }

    function isSalesAllCustomersReport() {
      var route = page.getAttribute('data-report-route') || '';
      return (
        (route === 'report_sales' || route === 'report_sales_between_dates') &&
        page.getAttribute('data-sales-all-customers') === '1'
      );
    }

    function isDeliveryReportRoute() {
      return page.getAttribute('data-report-route') === 'report_sales_delivery';
    }

    function isDeliveryAllCustomersReport() {
      return (
        page.getAttribute('data-report-route') === 'report_sales_delivery' &&
        page.getAttribute('data-delivery-all-customers') === '1'
      );
    }

    function isSalesByRepReport() {
      return page.getAttribute('data-report-route') === 'report_sales_by_rep';
    }

    function isSalesByItemReport() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return routeKey === 'report_sales_by_item' || routeKey === 'report_purchases_by_item';
    }

    function isSalesReturnsReport() {
      return page.getAttribute('data-report-route') === 'report_sales_returns';
    }

    function isPurchaseReturnsReport() {
      return page.getAttribute('data-report-route') === 'report_purchase_returns';
    }

    function isSalesCustomerNameFitReport() {
      return (
        isSalesAllCustomersReport() ||
        isDeliveryAllCustomersReport() ||
        isSalesByRepReport() ||
        isSalesReturnsReport() ||
        isPurchaseReturnsReport()
      );
    }

    function isSalesItemNameFitReport() {
      return isSalesByItemReport();
    }

    function isReportSalesRoute() {
      var route = page.getAttribute('data-report-route') || '';
      return route === 'report_sales' || route === 'report_sales_between_dates';
    }

    function isPartyStatementReport() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return (
        routeKey === 'report_party_statement' ||
        routeKey === 'report_customer_statement' ||
        routeKey === 'report_supplier_statement' ||
        page.classList.contains('party-stmt-page')
      );
    }

    function isPurchasesAllSuppliersReport() {
      return (
        page.getAttribute('data-report-route') === 'report_purchases' &&
        page.getAttribute('data-purchases-all-suppliers') === '1'
      );
    }

    function isReportPurchasesRoute() {
      return page.getAttribute('data-report-route') === 'report_purchases';
    }

    function isPeriodInvoiceReportRoute() {
      return isReportSalesRoute() || isReportPurchasesRoute() || isDeliveryReportRoute();
    }

    /** أنماط عمود اسم العميل — طباعة/PDF (إطار مستقل بدون report-sales-page) */
    function getSalesCustomerNamePrintCss() {
      return (
        '.report-sales-print-area .report-sales-table--all-customers col.col-customer,.report-sales-print-area .report-sales-table--by-rep col.col-customer,.report-sales-print-area .report-sales-table--returns col.col-customer{width:34%!important;}' +
        '.report-sales-print-area .report-sales-table--all-customers td.col-customer,.report-sales-print-area .report-sales-table--by-rep td.col-customer,.report-sales-print-area .report-sales-table--returns td.col-customer{text-align:start!important;white-space:nowrap!important;overflow:hidden!important;word-break:keep-all!important;line-height:1.15!important;}' +
        '.report-sales-print-area .report-sales-table--returns td.col-customer{max-width:0!important;}' +
        '.report-sales-print-area .report-sales-table .report-sales-party-name{display:inline-block!important;white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;max-width:100%!important;vertical-align:middle!important;line-height:1.15!important;}'
      );
    }

    /** طباعة تقرير المرتجعات — اسم العميل في سطر واحد */
    function getReportSalesReturnsPrintCss() {
      return (
        '.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table th,.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-sales-table col.col-seq,.report-sales-table .col-seq{width:4mm!important;max-width:4mm!important;padding:2px 1px!important;text-align:center!important;}' +
        '.report-sales-table col.col-date,.report-sales-table .col-date{width:16mm!important;min-width:16mm!important;max-width:16mm!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:hidden!important;text-overflow:clip!important;}' +
        getSalesCustomerNamePrintCss() +
        '.report-sales-table col.col-inv-no{width:11%!important;}' +
        '.report-sales-table col.col-money{width:11%!important;}' +
        '.report-sales-table .col-inv-no,.report-sales-table .col-money{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-table .col-money{text-align:center!important;font-variant-numeric:tabular-nums!important;}'
      );
    }

    /** أنماط عمود اسم المادة — طباعة/PDF (إطار مستقل بدون report-sales-page) */
    function getSalesItemNamePrintCss() {
      return (
        '.report-sales-print-area .report-sales-table--by-item col.col-item{width:42%!important;}' +
        '.report-sales-print-area .report-sales-table--by-item th.col-item,.report-sales-print-area .report-sales-table--by-item td.col-item{text-align:start!important;white-space:nowrap!important;overflow:hidden!important;word-break:keep-all!important;line-height:1.15!important;}' +
        '.report-sales-print-area .report-sales-table--by-item td.col-item{max-width:0!important;}' +
        '.report-sales-print-area .report-sales-table .report-sales-item-name{display:inline-block!important;white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;max-width:100%!important;vertical-align:middle!important;line-height:1.15!important;}'
      );
    }

    /** طباعة تقرير المبيعات حسب المادة — اسم المادة في سطر واحد */
    function getReportSalesByItemPrintCss() {
      return (
        '.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table th,.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-sales-table col.col-seq,.report-sales-table .col-seq{width:4mm!important;max-width:4mm!important;padding:2px 1px!important;text-align:center!important;}' +
        '.report-sales-table col.col-date,.report-sales-table .col-date{width:14mm!important;min-width:14mm!important;max-width:14mm!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:hidden!important;text-overflow:clip!important;}' +
        getSalesItemNamePrintCss() +
        '.report-sales-table col.col-inv-no{width:12%!important;}' +
        '.report-sales-table col.col-money{width:11%!important;}' +
        '.report-sales-table .col-inv-no,.report-sales-table .col-money{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-table .col-money{text-align:center!important;font-variant-numeric:tabular-nums!important;}'
      );
    }

    /** طباعة تقرير المبيعات حسب المندوب — اسم العميل في سطر واحد */
    function getReportSalesByRepPrintCss() {
      return (
        '.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table th,.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-sales-table col.col-seq,.report-sales-table .col-seq{width:4mm!important;max-width:4mm!important;padding:2px 1px!important;text-align:center!important;}' +
        '.report-sales-table col.col-date,.report-sales-table .col-date{width:14mm!important;min-width:14mm!important;max-width:14mm!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:hidden!important;text-overflow:clip!important;}' +
        getSalesCustomerNamePrintCss() +
        '.report-sales-table col.col-pay,.report-sales-table col.col-posted{width:8%!important;}' +
        '.report-sales-table col.col-money{width:10%!important;}' +
        '.report-sales-table .col-pay,.report-sales-table .col-posted,.report-sales-table .col-money{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-table .col-money{text-align:center!important;font-variant-numeric:tabular-nums!important;}'
      );
    }

    /** طباعة/PDF تقرير سندات البضاعة — كل الخلايا في سطر واحد */
    function getDeliveryReportPrintCss() {
      var all = isDeliveryAllCustomersReport();
      var css =
        '.report-sales-table--delivery{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table--delivery th,.report-sales-table--delivery td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;white-space:nowrap!important;overflow:hidden!important;word-break:keep-all!important;vertical-align:middle!important;}' +
        '.report-sales-table--delivery col.col-seq,.report-sales-table--delivery .col-seq{width:4mm!important;max-width:4mm!important;text-align:center!important;}' +
        '.report-sales-table--delivery col.col-date,.report-sales-table--delivery .col-date{width:18mm!important;min-width:18mm!important;max-width:18mm!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:visible!important;text-overflow:clip!important;}' +
        '.report-sales-table--delivery th.col-date,.report-sales-table--delivery td.col-date{overflow:visible!important;text-overflow:clip!important;}' +
        '.report-sales-table--delivery col.col-inv-no{width:10%!important;}' +
        '.report-sales-table--delivery col.col-warehouse{width:10%!important;}' +
        '.report-sales-table--delivery col.col-posted{width:7%!important;}' +
        '.report-sales-table--delivery col.col-lines{width:6mm!important;}' +
        '.report-sales-table--delivery col.col-qty{width:9mm!important;}' +
        '.report-sales-table--delivery col.col-linked-inv{width:9%!important;}' +
        '.report-sales-table--delivery .col-inv-no code,.report-sales-table--delivery .col-linked-inv code{white-space:nowrap!important;font-size:inherit!important;word-break:keep-all!important;}' +
        '.report-sales-table--delivery .col-customer,.report-sales-table--delivery .report-sales-party-name,.report-sales-table--delivery .col-warehouse,.report-sales-table--delivery .col-notes{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;word-break:keep-all!important;text-align:start!important;}' +
        '.report-sales-table--delivery .col-lines,.report-sales-table--delivery .col-qty{text-align:center!important;font-variant-numeric:tabular-nums!important;}' +
        '.report-sales-table--delivery .col-posted .badge{padding:0 0.25rem!important;font-size:6.5pt!important;line-height:1.2!important;white-space:nowrap!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-sales-period-head{display:block!important;margin:0.35rem 0 0.65rem!important;padding:0.35rem 0.5rem!important;font-size:7.5pt!important;font-weight:700!important;text-align:center!important;border:1px solid #cbd5e1!important;background:#f8fafc!important;}';
      if (all) {
        css +=
          getSalesCustomerNamePrintCss() +
          '.report-sales-table--delivery.report-sales-table--all-customers col.col-customer{width:14%!important;}' +
          '.report-sales-table--delivery.report-sales-table--all-customers td.col-customer{width:14%!important;max-width:14%!important;}';
      }
      return css;
    }

    /** طباعة تقرير مبيعات العميل — تاريخ واسم عميل في سطر واحد */
    function getReportSalesPrintCss() {
      var all = isSalesAllCustomersReport();
      var routeKey = page.getAttribute('data-report-route') || '';
      var betweenDates = routeKey === 'report_sales_between_dates';
      var dateColWidth = betweenDates ? '16mm' : '14mm';
      var css =
        '.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table th,.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-sales-table col.col-seq,.report-sales-table .col-seq{width:4mm!important;max-width:4mm!important;padding:2px 1px!important;text-align:center!important;}' +
        '.report-sales-table col.col-date,.report-sales-table .col-date{width:' +
        dateColWidth +
        '!important;min-width:' +
        dateColWidth +
        '!important;max-width:' +
        dateColWidth +
        '!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:visible!important;text-overflow:clip!important;visibility:visible!important;}' +
        '.report-sales-table thead th.col-date,.report-sales-table tbody td.col-date{display:table-cell!important;visibility:visible!important;}' +
        '.report-sales-period-head{display:block!important;margin:0.35rem 0 0.65rem!important;padding:0.35rem 0.5rem!important;' +
        'font-size:7.5pt!important;font-weight:700!important;text-align:center!important;border:1px solid #cbd5e1!important;background:#f8fafc!important;}' +
        '.report-sales-period-sep{margin:0 0.35rem!important;color:#64748b!important;}' +
        '.report-sales-table .col-inv-no,.report-sales-table .col-rep,.report-sales-table .col-pay,.report-sales-table .col-posted,.report-sales-table .col-money{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-table th.col-date,.report-sales-table td.col-date{overflow:visible!important;text-overflow:clip!important;}' +
        '.report-sales-table .col-money{text-align:center!important;font-variant-numeric:tabular-nums!important;}';
      if (all) {
        css +=
          getSalesCustomerNamePrintCss() +
          '.report-sales-table col.col-inv-no{width:' +
          (betweenDates ? '10' : '11') +
          '%!important;}' +
          '.report-sales-table col.col-rep{width:' +
          (betweenDates ? '8' : '9') +
          '%!important;}' +
          '.report-sales-table col.col-pay{width:' +
          (betweenDates ? '7' : '8') +
          '%!important;}' +
          '.report-sales-table col.col-posted{width:' +
          (betweenDates ? '7' : '8') +
          '%!important;}' +
          '.report-sales-table col.col-money{width:' +
          (betweenDates ? '9.5' : '10') +
          '%!important;}';
        if (betweenDates) {
          css +=
            '.report-sales-print-area .report-sales-table--all-customers col.col-customer{width:30%!important;}' +
            '.report-sales-print-area .report-sales-table--all-customers td.col-customer{width:30%!important;max-width:30%!important;}';
        }
      } else {
        css +=
          '.report-sales-table col.col-inv-no{width:14%!important;}' +
          '.report-sales-table col.col-rep{width:12%!important;}' +
          '.report-sales-table col.col-pay{width:10%!important;}' +
          '.report-sales-table col.col-posted{width:10%!important;}' +
          '.report-sales-table col.col-money{width:12%!important;}';
      }
      css +=
        '.report-sales-table tr.report-sales-row--return td{color:#1d4ed8!important;background:#eff6ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-sales-table tbody tr.report-sales-row--return:nth-child(even) td{background:#dbeafe!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
      return css;
    }

    /** تصغير خط اسم العميل حتى يلائم الخلية في سطر واحد */
    function fitSalesReportCustomerNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll(
        '.report-sales-table--all-customers td.col-customer, .report-sales-table--by-rep td.col-customer, .report-sales-table--returns td.col-customer, .report-sales-table--delivery td.col-customer'
      );
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.report-sales-party-name');
        if (!el) {
          el = doc.createElement('span');
          el.className = 'report-sales-party-name';
          el.textContent = (td.textContent || '').trim();
          td.textContent = '';
          td.appendChild(el);
        }
        var table = td.closest('.report-sales-table');
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        td.style.wordBreak = 'keep-all';
        if (table && table.classList.contains('report-sales-table--returns')) {
          td.style.maxWidth = '0';
        }
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        el.style.overflow = 'hidden';
        el.style.lineHeight = '1.15';
        var pt = 7.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8 && table) {
          var tableW = table.getBoundingClientRect().width || table.clientWidth || table.offsetWidth;
          if (tableW > 0) {
            var ratio = 0.34;
            if (table.classList.contains('report-sales-table--by-rep')) {
              ratio = 0.36;
            } else if (table.classList.contains('report-sales-table--returns')) {
              ratio = 0.34;
            }
            cellW = Math.floor(tableW * ratio);
          }
        }
        if (cellW < 8) {
          cellW = 240;
        }
        while (el.scrollWidth > cellW && pt > 4.8 && guard < 60) {
          pt -= 0.12;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    /** تصغير خط خلايا سندات البضاعة (مستودع، رقم سند) لتبقى في سطر واحد */
    function fitDeliveryReportPrintCells(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll(
        '.report-sales-table--delivery td.col-warehouse, .report-sales-table--delivery td.col-inv-no, .report-sales-table--delivery td.col-linked-inv'
      );
      if (!cells.length) return;
      cells.forEach(function (td) {
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        td.style.wordBreak = 'keep-all';
        var el = td.querySelector('code') || td;
        if (el !== td) {
          el.style.display = 'inline-block';
          el.style.whiteSpace = 'nowrap';
          el.style.wordBreak = 'keep-all';
          el.style.maxWidth = '100%';
          el.style.overflow = 'hidden';
          el.style.lineHeight = '1.15';
        }
        var table = td.closest('.report-sales-table');
        var pt = 7.5;
        if (el.style) el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8 && table) {
          var tableW = table.getBoundingClientRect().width || table.clientWidth || table.offsetWidth;
          if (tableW > 0) {
            cellW = Math.floor(tableW * (td.classList.contains('col-inv-no') ? 0.1 : 0.1));
          }
        }
        if (cellW < 8) cellW = 120;
        while (el.scrollWidth > cellW && pt > 4.8 && guard < 60) {
          pt -= 0.12;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    /** تصغير خط اسم المادة حتى يلائم الخلية في سطر واحد */
    function fitSalesReportItemNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.report-sales-table--by-item td.col-item');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.report-sales-item-name');
        if (!el) {
          el = doc.createElement('span');
          el.className = 'report-sales-item-name';
          el.textContent = (td.textContent || '').trim();
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        td.style.wordBreak = 'keep-all';
        td.style.maxWidth = '0';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        el.style.overflow = 'hidden';
        el.style.lineHeight = '1.15';

        var table = td.closest('.report-sales-table');
        var pt = 7.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8 && table) {
          var tableW = table.getBoundingClientRect().width || table.clientWidth || table.offsetWidth;
          if (tableW > 0) {
            cellW = Math.floor(tableW * 0.42);
          }
        }
        if (cellW < 8) {
          cellW = 285;
        }
        while (el.scrollWidth > cellW && pt > 4.8 && guard < 60) {
          pt -= 0.12;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    function runAfterPrintLayout(win, cb, needsFit, delayOverride) {
      var delay =
        typeof delayOverride === 'number' ? delayOverride : needsFit ? 380 : 200;
      setTimeout(function () {
        try {
          if (win && typeof win.requestAnimationFrame === 'function') {
            win.requestAnimationFrame(function () {
              win.requestAnimationFrame(cb);
            });
            return;
          }
        } catch (e) {}
        cb();
      }, delay);
    }

    function isVoucherChecksReport() {
      var route = page.getAttribute('data-report-route') || '';
      return route === 'report_incoming_checks' || route === 'report_outgoing_checks';
    }

    /** طباعة/PDF تقارير الشيكات — عمودي، سطر واحد لكل صف */
    function getVoucherChecksPrintCss() {
      return (
        '@page{size:A4 portrait;margin:8mm 6mm 10mm 6mm;}' +
        '.report-sales-print-area{font-size:7pt!important;width:100%!important;max-width:none!important;}' +
        '.doc-print-meta{font-size:7pt!important;}' +
        '.report-incoming-checks-table,.report-incoming-checks-table.report-sales-table{font-size:6.5pt!important;table-layout:fixed!important;width:100%!important;border-collapse:collapse!important;}' +
        '.report-incoming-checks-table th,.report-incoming-checks-table td,.report-incoming-checks-table tfoot td{font-size:6.5pt!important;padding:1px 2px!important;line-height:1.1!important;white-space:nowrap!important;overflow:hidden!important;word-break:keep-all!important;vertical-align:middle!important;box-sizing:border-box!important;}' +
        '.report-incoming-checks-table thead th{font-size:6pt!important;white-space:nowrap!important;padding:2px 1px!important;}' +
        '.report-incoming-checks-table .col-seq{width:4mm!important;max-width:4mm!important;text-align:center!important;}' +
        '.report-incoming-checks-table th:nth-child(2),.report-incoming-checks-table td:nth-child(2){width:14mm!important;max-width:14mm!important;}' +
        '.report-incoming-checks-table th:nth-child(3),.report-incoming-checks-table td:nth-child(3){width:26mm!important;max-width:26mm!important;text-align:start!important;}' +
        '.report-incoming-checks-table th:nth-child(4),.report-incoming-checks-table td:nth-child(4),.report-incoming-checks-table th:nth-child(5),.report-incoming-checks-table td:nth-child(5){width:13mm!important;max-width:13mm!important;direction:ltr!important;unicode-bidi:embed!important;}' +
        '.report-incoming-checks-table th:nth-child(6),.report-incoming-checks-table td:nth-child(6){width:12mm!important;max-width:12mm!important;}' +
        '.report-incoming-checks-table th:nth-child(7),.report-incoming-checks-table td:nth-child(7){width:11mm!important;max-width:11mm!important;}' +
        '.report-incoming-checks-table th:nth-child(8),.report-incoming-checks-table td:nth-child(8){width:16mm!important;max-width:16mm!important;text-align:start!important;}' +
        '.report-incoming-checks-table th:nth-child(9),.report-incoming-checks-table td:nth-child(9){width:15mm!important;max-width:15mm!important;font-variant-numeric:tabular-nums!important;}' +
        '.report-incoming-checks-table .col-customer{text-align:start!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-incoming-checks-table .report-sales-party-name{display:inline-block!important;white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;text-overflow:ellipsis!important;max-width:100%!important;vertical-align:middle!important;}' +
        '.report-incoming-checks-table .report-incoming-checks-voucher{display:inline!important;white-space:nowrap!important;}' +
        '.report-incoming-checks-table .report-incoming-checks-voucher code{white-space:nowrap!important;background:transparent!important;padding:0!important;font-size:inherit!important;}'
      );
    }

    function fitIncomingChecksReportCells(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.report-incoming-checks-table td.col-customer');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.report-sales-party-name');
        if (!el) {
          el = doc.createElement('span');
          el.className = 'report-sales-party-name';
          el.textContent = (td.textContent || '').trim();
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        el.style.overflow = 'hidden';
        el.style.textOverflow = 'ellipsis';
        var pt = 6.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8) {
          cellW = 100;
        }
        while (el.scrollWidth > cellW && pt > 5 && guard < 40) {
          pt -= 0.12;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    /** طباعة تقرير المشتريات — تاريخ واسم مورد في سطر واحد */
    function getReportPurchasesPrintCss() {
      var all = isPurchasesAllSuppliersReport();
      var css =
        '.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.report-sales-table th,.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-sales-table col.col-seq,.report-sales-table .col-seq{width:4mm!important;max-width:4mm!important;padding:2px 1px!important;text-align:center!important;}' +
        '.report-sales-table col.col-date,.report-sales-table .col-date{width:14mm!important;min-width:14mm!important;max-width:14mm!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;overflow:hidden!important;text-overflow:clip!important;}' +
        '.report-sales-table .col-inv-no,.report-sales-table .col-pay,.report-sales-table .col-posted,.report-sales-table .col-money{white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-table .col-money{text-align:center!important;font-variant-numeric:tabular-nums!important;}';
      if (all) {
        css +=
          '.report-sales-table col.col-customer{width:32%!important;}' +
          '.report-sales-table .col-customer{text-align:start!important;white-space:nowrap!important;overflow:hidden!important;}' +
          '.report-sales-table .report-sales-party-name{display:inline-block!important;white-space:nowrap!important;word-break:keep-all!important;max-width:100%!important;vertical-align:middle!important;overflow:hidden!important;}' +
          '.report-sales-table col.col-inv-no{width:12%!important;}' +
          '.report-sales-table col.col-pay{width:9%!important;}' +
          '.report-sales-table col.col-posted{width:9%!important;}' +
          '.report-sales-table col.col-money{width:11%!important;}';
      } else {
        css +=
          '.report-sales-table col.col-inv-no{width:16%!important;}' +
          '.report-sales-table col.col-pay{width:11%!important;}' +
          '.report-sales-table col.col-posted{width:11%!important;}' +
          '.report-sales-table col.col-money{width:13%!important;}';
      }
      return css;
    }

    function isItemStockLedgerReport() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return routeKey === 'item_stock_movements' || routeKey === 'report_inventory';
    }

    function isWarehouseItemsReportRoute(routeKey) {
      return (
        routeKey === 'report_warehouse_items' ||
        routeKey === 'report_warehouse_zero_qty' ||
        routeKey === 'report_warehouse_negative_qty' ||
        routeKey === 'report_warehouse_financial' ||
        routeKey === 'report_warehouse_moves'
      );
    }

    /** طباعة/PDF كشف حركات مادة — أفقي، أعمدة بعرض ثابت، سطر واحد */
    function getItemStockLedgerPrintCss() {
      return (
        '@page{size:A4 landscape;margin:6mm 5mm 10mm 5mm;}' +
        '.item-stock-ledger-page .report-sales-print-area{font-size:8pt!important;}' +
        '.item-stock-ledger-result .party-stmt-report-head{margin:0.25rem 0 1rem!important;}' +
        '.item-stock-ledger-table,.report-sales-table.item-stock-ledger-table{font-size:6.5pt!important;table-layout:fixed!important;width:100%!important;border-collapse:collapse!important;}' +
        '.item-stock-ledger-table th,.item-stock-ledger-table td,.report-sales-table.item-stock-ledger-table th,.report-sales-table.item-stock-ledger-table td{font-size:6.5pt!important;padding:1px 2px!important;line-height:1.1!important;white-space:nowrap!important;overflow:hidden!important;vertical-align:middle!important;box-sizing:border-box!important;word-break:keep-all!important;}' +
        '.item-stock-ledger-table thead th{font-size:6pt!important;}' +
        '.item-stock-ledger-table col.col-seq,.item-stock-ledger-table .col-seq{width:5mm!important;max-width:5mm!important;min-width:5mm!important;padding:1px!important;text-align:center!important;}' +
        '.item-stock-ledger-table col.col-inv-no,.item-stock-ledger-table .col-inv-no,.item-stock-ledger-table col.col-item-name,.item-stock-ledger-table .col-item-name{display:none!important;}' +
        '.item-stock-ledger-table col.col-mov-type,.item-stock-ledger-table .col-mov-type{width:14mm!important;max-width:14mm!important;}' +
        '.item-stock-ledger-table col.col-party,.item-stock-ledger-table .col-party{width:44mm!important;max-width:44mm!important;text-align:start!important;}' +
        '.item-stock-ledger-table col.col-date-invoice,.item-stock-ledger-table .col-date-invoice{width:15mm!important;max-width:15mm!important;}' +
        '.item-stock-ledger-table col.col-datetime,.item-stock-ledger-table .col-datetime{width:21mm!important;max-width:21mm!important;}' +
        '.item-stock-ledger-table col.col-doc-no,.item-stock-ledger-table .col-doc-no{width:16mm!important;max-width:16mm!important;}' +
        '.item-stock-ledger-table col.col-qty,.item-stock-ledger-table .col-qty{width:11mm!important;max-width:11mm!important;}' +
        '.item-stock-ledger-table col.col-unit-price,.item-stock-ledger-table .col-unit-price{width:16mm!important;max-width:16mm!important;}' +
        '.item-stock-ledger-table col.col-line-total,.item-stock-ledger-table .col-line-total{width:16mm!important;max-width:16mm!important;}' +
        '.item-stock-ledger-table col.col-balance,.item-stock-ledger-table .col-balance{width:14mm!important;max-width:14mm!important;}' +
        '.item-stock-ledger-table .col-unit-price,.item-stock-ledger-table .col-line-total,.item-stock-ledger-table .col-balance,.item-stock-ledger-table .col-qty,.item-stock-ledger-table .col-datetime,.item-stock-ledger-table .col-date-invoice,.item-stock-ledger-table .col-doc-no{direction:ltr!important;unicode-bidi:isolate!important;text-align:center!important;font-variant-numeric:tabular-nums!important;text-overflow:clip!important;}' +
        '.item-stock-ledger-table .item-stock-ledger-party{display:inline-block!important;max-width:100%!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;vertical-align:middle!important;}'
      );
    }

    function fitItemStockLedgerPartyNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.item-stock-ledger-table td.col-party');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.item-stock-ledger-party');
        if (!el) {
          el = document.createElement('span');
          el.className = 'item-stock-ledger-party';
          el.textContent = td.textContent;
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        var pt = 6.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8) {
          cellW = 110;
        }
        while (el.scrollWidth > cellW && pt > 5 && guard < 40) {
          pt -= 0.15;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    function fitPurchasesReportSupplierNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.report-sales-table--all-suppliers td.col-customer');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.report-sales-party-name');
        if (!el) {
          el = document.createElement('span');
          el.className = 'report-sales-party-name';
          el.textContent = td.textContent;
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        var pt = 7.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8) return;
        while (el.scrollWidth > cellW && pt > 5.5 && guard < 40) {
          pt -= 0.15;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    function getReceivablesPageNumberCss() {
      if (isReceivablesSummaryMode()) {
        return (
          '@page{size:A4 portrait;margin:8mm 6mm 14mm 6mm;@bottom-center{content:counter(page)"\\\\"counter(pages);font-family:Arial,Helvetica,sans-serif;font-size:9pt;font-weight:700;color:#0f172a;}}'
        );
      }
      return (
        '@page{size:A4;margin:8mm 10mm 14mm 10mm;@bottom-center{content:counter(page)"\\\\"counter(pages);font-family:Arial,Helvetica,sans-serif;font-size:11pt;font-weight:700;color:#0f172a;}}'
      );
    }

    /** طباعة/PDF تقرير أعمار الذمم */
    function getReceivablesAgingPrintCss() {
      if (!isReceivablesAgingReport()) {
        return '';
      }
      if (isReceivablesSummaryMode()) {
        return (
          '@page{size:A4 landscape;margin:6mm 5mm 10mm 5mm;}' +
          '.report-receivables-aging-print .party-stmt-report-head{margin:0.25rem 0 0.75rem!important;}' +
          '.report-receivables-aging-print .report-sales-print-area{width:100%!important;max-width:none!important;}' +
          '.report-receivables-aging-summary-table,.report-receivables-aging-summary-table.report-sales-table{font-size:7pt!important;table-layout:fixed!important;width:100%!important;max-width:100%!important;border-collapse:collapse!important;}' +
          '.report-receivables-aging-summary-table th,.report-receivables-aging-summary-table td{font-size:7pt!important;padding:2px 2px!important;line-height:1.1!important;white-space:nowrap!important;vertical-align:middle!important;box-sizing:border-box!important;}' +
          '.report-receivables-aging-summary-table .col-seq{padding:1px!important;text-align:center!important;}' +
          '.report-receivables-aging-summary-table .col-customer,.report-receivables-aging-summary-table .col-rep{text-align:start!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
          '.report-receivables-aging-summary-table .report-receivables-party-name{display:inline-block!important;max-width:100%!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;vertical-align:middle!important;}' +
          '.report-receivables-aging-summary-table col.col-seq{width:4mm!important;}' +
          '.report-receivables-aging-summary-table col.col-customer{width:24%!important;}' +
          '.report-receivables-aging-summary-table col.col-rep{width:8%!important;}' +
          '.report-receivables-aging-summary-table col.col-money{width:10.5%!important;}' +
          '.report-receivables-aging-summary-table .col-money{text-align:center!important;direction:ltr!important;unicode-bidi:isolate!important;font-variant-numeric:tabular-nums!important;}' +
          '.report-receivables-aging-summary-table thead th{background:#f1f5f9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
          '.report-receivables-aging-summary-table tfoot td{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        );
      }
      return (
        '@page{size:A4 landscape;margin:6mm 5mm 10mm 5mm;}' +
        '.report-receivables-aging-print .party-stmt-report-head{margin:0.25rem 0 0.75rem!important;}' +
        '.report-receivables-aging-print .report-receivables-customer-block{break-inside:avoid;page-break-inside:avoid;margin:0 0 0.65rem!important;}' +
        '.report-receivables-aging-print .report-receivables-customer-title{font-size:9pt!important;font-weight:800!important;margin:0.35rem 0 0.25rem!important;text-align:start!important;line-height:1.25!important;}' +
        '.report-receivables-aging-detail-table,.report-receivables-aging-detail-table.report-sales-table{font-size:6.5pt!important;table-layout:fixed!important;width:100%!important;border-collapse:collapse!important;}' +
        '.report-receivables-aging-detail-table th,.report-receivables-aging-detail-table td{font-size:6.5pt!important;padding:1px 2px!important;line-height:1.1!important;vertical-align:middle!important;box-sizing:border-box!important;}' +
        '.report-receivables-aging-detail-table .col-date{width:10%!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:isolate!important;text-align:center!important;}' +
        '.report-receivables-aging-detail-table .col-doc{width:12%!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:isolate!important;text-align:center!important;}' +
        '.report-receivables-aging-detail-table .col-desc{width:30%!important;text-align:start!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-receivables-aging-detail-table .col-seq{width:8%!important;text-align:center!important;}' +
        '.report-receivables-aging-detail-table th:nth-child(5),.report-receivables-aging-detail-table td:nth-child(5){width:14%!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-receivables-aging-detail-table .col-money{width:12%!important;text-align:center!important;direction:ltr!important;unicode-bidi:isolate!important;font-variant-numeric:tabular-nums!important;white-space:nowrap!important;}' +
        '.report-receivables-aging-detail-table thead th{background:#f1f5f9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-receivables-aging-detail-table tfoot td{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      );
    }

    /** أنماط طباعة كشف الذمم إجمالي — تتجاوز font-size:12px الافتراضي في getPrintStyles */
    function getReceivablesSummaryPrintCss() {
      return (
        '.report-sales-print-area{width:100%!important;max-width:none!important;}' +
        '.report-receivables-summary-table-wrap{width:100%!important;max-width:none!important;}' +
        '.report-receivables-grand-total-wrap{width:100%!important;}' +
        '.report-receivables-summary-table,.report-receivables-summary-table.report-sales-table{font-size:7.5pt!important;table-layout:fixed!important;width:100%!important;max-width:100%!important;}' +
        '.report-receivables-summary-table th,.report-receivables-summary-table td,.report-receivables-summary-table.report-sales-table th,.report-receivables-summary-table.report-sales-table td{font-size:7.5pt!important;padding:2px 3px!important;line-height:1.15!important;white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;text-overflow:ellipsis!important;vertical-align:middle!important;}' +
        '.report-receivables-summary-table .col-seq{padding:2px 1px!important;text-align:center!important;font-size:7pt!important;}' +
        '.report-receivables-summary-table .col-customer,.report-receivables-summary-table .col-rep{text-align:start!important;white-space:nowrap!important;word-break:keep-all!important;}' +
        '.report-receivables-summary-table .report-receivables-party-name{display:inline-block!important;white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;text-overflow:ellipsis!important;max-width:100%!important;vertical-align:middle!important;font-size:inherit!important;}' +
        '.report-receivables-summary-table col.col-seq{width:4mm!important;}' +
        '.report-receivables-summary-table col.col-customer{width:34%!important;}' +
        '.report-receivables-summary-table col.col-rep{width:9%!important;}' +
        '.report-receivables-summary-table col.col-money{width:9.5%!important;}' +
        '.report-receivables-summary-table col.col-pct{width:7.5%!important;}' +
        '.report-receivables-grand-total-table--summary,.report-receivables-grand-total-table--summary.report-sales-table{width:100%!important;font-size:7.5pt!important;table-layout:fixed!important;}' +
        '.report-receivables-grand-total-table--summary th,.report-receivables-grand-total-table--summary td{font-size:7.5pt!important;padding:2px 3px!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-receivables-grand-total-table--summary col.col-seq{width:4mm!important;}' +
        '.report-receivables-grand-total-table--summary col.col-customer{width:34%!important;}' +
        '.report-receivables-grand-total-table--summary col.col-rep{width:9%!important;}' +
        '.report-receivables-grand-total-table--summary col.col-money{width:9.5%!important;}' +
        '.report-receivables-grand-total-table--summary col.col-pct{width:7.5%!important;}' +
        '.report-receivables-grand-total-table--summary .report-receivables-total-label{text-align:start!important;}' +
        '.report-receivables-grand-total-table--summary .report-receivables-sub-hint{font-size:6.5pt!important;font-weight:600!important;}' +
        '.report-receivables-grand-total-table--summary-7 col.col-customer{width:40%!important;}' +
        '.report-receivables-grand-total-table:not(.report-receivables-grand-total-table--summary),.report-receivables-grand-total-table:not(.report-receivables-grand-total-table--summary).report-sales-table{width:100%!important;font-size:9pt!important;}' +
        '.report-receivables-grand-total-table:not(.report-receivables-grand-total-table--summary) th,.report-receivables-grand-total-table:not(.report-receivables-grand-total-table--summary) td{font-size:9pt!important;padding:4px 5px!important;white-space:nowrap!important;}'
      );
    }

    /** تصغير خط اسم العميل حتى يلائم عرض الخلية في سطر واحد */
    function fitReceivablesSummaryPartyNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.report-receivables-summary-table td.col-customer');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.report-receivables-party-name');
        if (!el) {
          el = document.createElement('span');
          el.className = 'report-receivables-party-name';
          el.textContent = td.textContent;
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        var pt = 7.5;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8) return;
        while (el.scrollWidth > cellW && pt > 5.5 && guard < 40) {
          pt -= 0.15;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    function getCustomersPageCss() {
      return (
        '@page{size:A4 landscape;margin:8mm 10mm 12mm 10mm;}' +
        '.report-customers-table{font-size:9px!important;}' +
        '.report-customers-table th{font-size:8.5px!important;}' +
        '.report-customers-table .col-customer-name{width:22%!important;text-align:start!important;white-space:normal!important;word-break:break-word!important;}' +
        '.report-customers-table .col-address{width:18%!important;text-align:start!important;white-space:normal!important;}' +
        '.report-customers-table .col-seq{width:4%!important;}' +
        '.report-customers-table .col-inv-no{width:8%!important;}'
      );
    }

    function isHrEmployeesReport() {
      return (page.getAttribute('data-report-route') || '') === 'report_hr_employees';
    }

    function isHrEmployeesByNationalityReport() {
      return (page.getAttribute('data-report-route') || '') === 'report_hr_employees_by_nationality';
    }

    function getHrEmployeesByNationalityPrintCss() {
      return (
        '@page{size:A4 landscape;margin:8mm 10mm;}' +
        '.hr-emp-nat-rpt-doc,.report-sales-print-area{direction:rtl!important;width:100%!important;}' +
        '.hr-emp-nat-rpt-table{table-layout:auto!important;width:100%!important;border-collapse:collapse!important;font-size:8.5pt!important;}' +
        '.hr-emp-nat-rpt-table th,.hr-emp-nat-rpt-table td{' +
          'white-space:nowrap!important;overflow:visible!important;text-overflow:clip!important;' +
          'word-break:keep-all!important;padding:3px 5px!important;vertical-align:middle!important;' +
          'line-height:1.25!important;border:1px solid #94a3b8!important;' +
        '}' +
        '.hr-emp-nat-rpt-table .col-customer-name,.hr-emp-nat-rpt-table .hr-emp-nat-rpt-col-job{' +
          'white-space:nowrap!important;text-align:start!important;word-break:keep-all!important;' +
        '}' +
        '.hr-emp-nat-rpt-table thead th{background:#1e5a96!important;color:#fff!important;font-weight:700!important;text-align:center!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.hr-emp-nat-rpt-nat-name{margin:0 0 0.4rem!important;padding:0.25rem 0.4rem!important;font-size:10pt!important;background:#eef2f7!important;border:1px solid #c5ced9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.hr-emp-nat-rpt-block{margin:0 0 0.75rem!important;page-break-inside:avoid!important;}' +
        '.hr-emp-nat-rpt-sum td{background:#f8fafc!important;font-weight:700!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.hr-emp-nat-rpt-grand{margin-top:0.75rem!important;}' +
        '.hr-emp-nat-rpt-grand-table th,.hr-emp-nat-rpt-grand-table td{border:1px solid #333!important;padding:0.3rem 0.5rem!important;}'
      );
    }

    function getHrEmployeesPrintCss() {
      return (
        '@page{size:A4 portrait;margin:10mm;}' +
        'html,body{direction:rtl!important;margin:0!important;padding:0!important;}' +
        'body,body *{font-family:Tahoma,"Segoe UI",Arial,sans-serif!important;letter-spacing:normal!important;}' +
        '.report-hr-employees-print,.report-sales-print-area{' +
          'direction:rtl!important;width:100%!important;max-width:190mm!important;' +
          'overflow:visible!important;box-sizing:border-box!important;padding:0!important;margin:0!important;' +
        '}' +
        '.report-sales-table-wrap{overflow:visible!important;width:100%!important;max-width:100%!important;}' +
        '.doc-print-header{margin:0 0 0.4rem!important;}' +
        '.doc-print-header-brand{' +
          'display:flex!important;flex-direction:row!important;direction:rtl!important;' +
          'justify-content:space-between!important;align-items:center!important;width:100%!important;' +
          'gap:0.5rem!important;flex-wrap:nowrap!important;' +
        '}' +
        '.doc-print-header-co{flex:1 1 auto!important;min-width:0!important;text-align:right!important;direction:rtl!important;font-size:12pt!important;font-weight:700!important;}' +
        '.doc-print-header-logo{flex:0 0 auto!important;width:28mm!important;max-width:28mm!important;}' +
        '.doc-print-header-logo img{max-width:28mm!important;max-height:18mm!important;width:auto!important;height:auto!important;display:block!important;}' +
        '.doc-print-header-title{text-align:center!important;font-size:13pt!important;font-weight:700!important;margin:0.35rem 0 0!important;}' +
        '.doc-print-meta{margin:0.25rem 0 0.45rem!important;font-size:10pt!important;font-weight:700!important;}' +
        '.report-hr-employees-table{' +
          'table-layout:fixed!important;width:100%!important;max-width:100%!important;' +
          'border-collapse:collapse!important;font-size:9pt!important;direction:rtl!important;' +
        '}' +
        '.report-hr-employees-table th,.report-hr-employees-table td{' +
          'padding:3px 4px!important;vertical-align:middle!important;border:1px solid #94a3b8!important;' +
          'line-height:1.35!important;overflow:hidden!important;word-wrap:break-word!important;' +
        '}' +
        '.report-hr-employees-table thead th{' +
          'background:#1e5a96!important;color:#fff!important;font-weight:700!important;' +
          'text-align:center!important;font-size:8.5pt!important;' +
          '-webkit-print-color-adjust:exact;print-color-adjust:exact;' +
        '}' +
        '.report-hr-employees-table tbody td{background:#fff!important;color:#0f172a!important;font-weight:400!important;}' +
        '.report-hr-employees-table tbody tr:nth-child(even) td{background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-hr-employees-table col.col-seq,.report-hr-employees-table th.col-seq,.report-hr-employees-table td.col-seq{width:8%!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-hr-employees-table col.col-inv-no,.report-hr-employees-table th.col-inv-no,.report-hr-employees-table td.col-inv-no{width:10%!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-hr-employees-table .col-inv-no code{font-family:inherit!important;font-size:inherit!important;font-weight:700!important;background:none!important;border:0!important;padding:0!important;}' +
        '.report-hr-employees-table col.col-customer-name,.report-hr-employees-table th.col-customer-name,.report-hr-employees-table td.col-customer-name{width:30%!important;text-align:right!important;white-space:normal!important;}' +
        '.report-hr-employees-table col.col-date,.report-hr-employees-table th.col-date,.report-hr-employees-table td.col-date{width:14%!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-hr-employees-table col.col-qty,.report-hr-employees-table th.col-qty,.report-hr-employees-table td.col-qty{width:14%!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-hr-employees-table col.col-status,.report-hr-employees-table th.col-status,.report-hr-employees-table td.col-status{width:24%!important;text-align:center!important;white-space:normal!important;}' +
        '.report-hr-employees-table td.num{color:#0f172a!important;font-weight:700!important;}' +
        '.report-hr-employees-total td,.report-hr-employees-table tfoot td{' +
          'background:#e2e8f0!important;font-weight:700!important;color:#0f172a!important;' +
          '-webkit-print-color-adjust:exact;print-color-adjust:exact;' +
        '}'
      );
    }

    function getPartyStatementPrintCss() {
      return (
        '.party-stmt-page .report-sales-print-area{font-size:8pt!important;}' +
        '.party-stmt-table th,.party-stmt-table td{font-size:8pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.party-stmt-table .col-date{width:9%!important;}' +
        '.party-stmt-table .col-desc{width:18%!important;text-align:start!important;}' +
        '.party-stmt-table .col-doc{width:26%!important;}' +
        '.party-stmt-table .col-money{width:12%!important;}' +
        '.party-stmt-doc-cell{text-align:start!important;font-size:6.5pt!important;line-height:1.1!important;white-space:nowrap!important;word-break:keep-all!important;overflow:visible!important;}' +
        '.party-stmt-doc-kind,.party-stmt-doc-no-wrap,.party-stmt-doc-hint{display:inline!important;white-space:nowrap!important;margin:0!important;font-size:inherit!important;line-height:inherit!important;}' +
        '.party-stmt-doc-no{display:inline!important;font-size:inherit!important;font-weight:700!important;direction:ltr!important;unicode-bidi:isolate!important;font-variant-numeric:tabular-nums!important;background:transparent!important;padding:0!important;}'
      );
    }

    function isOracleCustomerStatementReport() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return routeKey === 'report_oracle_customer_statement' || page.classList.contains('ora-stmt-page');
    }

    /** طباعة كشف حساب تفصيلي Oracle — A4 أفقي، حدود واضحة، تكرار رأس الجدول */
    function getOracleCustomerStatementPrintCss() {
      return (
        '@page{size:A4 landscape;margin:8mm 10mm 10mm 10mm;}' +
        'body,html{margin:0!important;padding:0!important;}' +
        '.ora-stmt-page .report-sales-print-area,.ora-stmt-print{' +
          'font-size:8pt!important;width:100%!important;max-width:none!important;direction:rtl!important;' +
        '}' +
        '.doc-print-header{margin:0 0 0.35rem!important;}' +
        '.doc-print-header-title{font-size:13pt!important;font-weight:800!important;text-align:center!important;margin:0.2rem 0!important;}' +
        '.doc-print-header-subtitle{font-size:8pt!important;text-align:center!important;color:#334155!important;margin:0 0 0.2rem!important;}' +
        '.doc-print-header-co{font-size:11pt!important;font-weight:800!important;}' +
        '.doc-print-header-logo img{max-height:14mm!important;max-width:22mm!important;}' +
        '.ora-stmt-meta{' +
          'margin:0 0 0.45rem!important;padding:0.3rem 0.5rem!important;' +
          'border:1px solid #334155!important;background:#f1f5f9!important;border-radius:0!important;' +
          '-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-meta__row{display:flex!important;flex-wrap:wrap!important;gap:0.35rem 1rem!important;justify-content:space-between!important;}' +
        '.ora-stmt-meta__row--main{margin-bottom:0.25rem!important;padding-bottom:0.25rem!important;border-bottom:1px solid #94a3b8!important;}' +
        '.ora-stmt-meta__row--sub{font-size:8pt!important;font-weight:700!important;color:#0f172a!important;}' +
        '.ora-stmt-meta__label{font-size:7pt!important;font-weight:700!important;color:#475569!important;margin-bottom:0!important;display:inline!important;margin-inline-end:0.25rem!important;}' +
        '.ora-stmt-meta__acc-no{font-size:10pt!important;font-weight:800!important;direction:ltr!important;unicode-bidi:isolate!important;}' +
        '.ora-stmt-meta__acc-name{font-size:9.5pt!important;font-weight:800!important;}' +
        '.ora-stmt-table-wrap{overflow:visible!important;width:100%!important;}' +
        '.ora-stmt-table{' +
          'width:100%!important;table-layout:fixed!important;border-collapse:collapse!important;' +
          'font-size:7.5pt!important;direction:rtl!important;' +
        '}' +
        '.ora-stmt-table col.col-doc-no{width:7%!important;}' +
        '.ora-stmt-table col.col-doc-type{width:11%!important;}' +
        '.ora-stmt-table col.col-date{width:9%!important;}' +
        '.ora-stmt-table col.col-debit,.ora-stmt-table col.col-credit,.ora-stmt-table col.col-balance{width:9%!important;}' +
        '.ora-stmt-table col.col-desc{width:37%!important;}' +
        '.ora-stmt-table th,.ora-stmt-table td{' +
          'border:0.6pt solid #1e293b!important;padding:1.5px 3px!important;line-height:1.2!important;' +
          'font-size:7.5pt!important;font-weight:700!important;color:#0f172a!important;vertical-align:middle!important;' +
        '}' +
        '.ora-stmt-table thead{display:table-header-group!important;}' +
        '.ora-stmt-table tfoot{display:table-footer-group!important;}' +
        '.ora-stmt-table thead th{' +
          'background:#d1d5db!important;text-align:center!important;font-weight:800!important;' +
          '-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-table tbody tr{page-break-inside:avoid!important;break-inside:avoid!important;}' +
        '.ora-stmt-table tbody tr:nth-child(even) td{' +
          'background:#f8fafc!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-table .ora-stmt-opening td{' +
          'background:#e2e8f0!important;font-style:italic!important;' +
          '-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-table .col-doc-no,.ora-stmt-table .col-date,.ora-stmt-table .col-money{' +
          'text-align:center!important;white-space:nowrap!important;' +
          'font-variant-numeric:tabular-nums!important;direction:ltr!important;unicode-bidi:isolate!important;' +
        '}' +
        '.ora-stmt-table .col-doc-type{text-align:center!important;white-space:nowrap!important;}' +
        '.ora-stmt-table .ora-stmt-desc{' +
          'text-align:right!important;white-space:normal!important;word-break:break-word!important;' +
          'font-weight:600!important;font-size:7pt!important;' +
        '}' +
        '.ora-stmt-table tfoot .ora-stmt-totals td{' +
          'background:#cbd5e1!important;font-weight:800!important;border-top:1.4pt solid #0f172a!important;' +
          '-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-cheques{margin-top:0.55rem!important;page-break-inside:avoid!important;break-inside:avoid!important;}' +
        '.ora-stmt-cheques__title{' +
          'font-size:10pt!important;font-weight:800!important;text-align:center!important;' +
          'margin:0.3rem 0 0.35rem!important;padding:0.2rem 0!important;' +
          'border-top:1.2pt solid #0f172a!important;border-bottom:0.7pt solid #334155!important;' +
        '}' +
        '.ora-stmt-chq-table{' +
          'width:55%!important;max-width:none!important;margin:0 auto!important;' +
          'table-layout:fixed!important;border-collapse:collapse!important;font-size:8pt!important;' +
        '}' +
        '.ora-stmt-chq-table th,.ora-stmt-chq-table td{' +
          'border:0.6pt solid #1e293b!important;padding:2px 4px!important;text-align:center!important;' +
          'font-weight:700!important;' +
        '}' +
        '.ora-stmt-chq-table thead th{' +
          'background:#d1d5db!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}' +
        '.ora-stmt-chq-table tfoot td{' +
          'background:#cbd5e1!important;font-weight:800!important;' +
          '-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;' +
        '}'
      );
    }

    function getAccSummaryReportPrintCss() {
      return (
        '@page{size:A4 portrait;margin:10mm 12mm 14mm 12mm;@bottom-center{content:counter(page)" / "counter(pages);font-family:Arial,Helvetica,sans-serif;font-size:10pt;font-weight:700;color:#0f172a;}}' +
        '.pl-summary-hero{display:flex;flex-direction:column;align-items:center;gap:0.35rem;margin:0.75rem 0 1rem;padding:0.85rem;border:2px solid #cbd5e1;border-radius:8px;background:#f8fafc;text-align:center;break-inside:avoid;}' +
        '.pl-summary-hero--profit{border-color:#86efac;background:#ecfdf5;}' +
        '.pl-summary-hero--loss{border-color:#fca5a5;background:#fef2f2;}' +
        '.pl-summary-hero__label{font-size:10pt;font-weight:700;color:#64748b;}' +
        '.pl-summary-hero__amount{font-size:16pt;font-weight:800;line-height:1.2;}' +
        '.pl-summary-hero--profit .pl-summary-hero__amount{color:#15803d;}' +
        '.pl-summary-hero--loss .pl-summary-hero__amount{color:#b91c1c;}' +
        '.pl-summary-table .col-seq{width:8%!important;}' +
        '.pl-summary-table .col-desc{text-align:start!important;}' +
        '.pl-summary-table .col-money{text-align:center!important;}' +
        '.pl-summary-row--subtotal td{font-weight:700;background:#f1f5f9!important;}' +
        '.pl-summary-row--total td{font-weight:800;background:#e2e8f0!important;}' +
        '.pl-deduction-amount{color:#b45309;}' +
        '.tax-decl-meta{display:grid;gap:0.35rem;max-width:100%;margin:0.5rem 0 0.85rem;padding:0.65rem 0.85rem;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;}' +
        '.tax-decl-meta__row{display:flex;flex-wrap:wrap;gap:0.25rem 0.65rem;align-items:baseline;}' +
        '.tax-decl-meta__label{min-width:7rem;color:#64748b;font-size:9pt;}' +
        '.tax-decl-net-hero{display:flex;flex-direction:column;align-items:center;gap:0.3rem;margin:0.75rem 0;padding:0.85rem;border:2px solid #93c5fd;border-radius:8px;background:#eff6ff;text-align:center;break-inside:avoid;}' +
        '.tax-decl-net-hero--refund{border-color:#86efac;background:#ecfdf5;}' +
        '.tax-decl-net-hero__label{font-size:10pt;font-weight:700;color:#64748b;}' +
        '.tax-decl-net-hero__amount{font-size:16pt;font-weight:800;}' +
        '.tax-decl-net-hero--refund .tax-decl-net-hero__amount{color:#15803d;}' +
        '.tax-decl-table .col-money{text-align:center!important;}' +
        '.tax-decl-section-row td{font-weight:800;background:#e2e8f0!important;}' +
        '.tax-decl-row--subtotal td{font-weight:700;background:#f1f5f9!important;}' +
        '.tax-decl-deduction{color:#b45309;}' +
        '.tax-decl-net-row td{font-weight:800;background:#dbeafe!important;}' +
        '.tax-decl-counts{margin-top:0.65rem;font-size:9pt;color:#64748b;}' +
        '.tax-decl-bydate-title{margin:0.85rem 0 0.4rem;font-size:11pt;font-weight:800;}' +
        '.tax-decl-bydate-table .col-date{text-align:center;white-space:nowrap;}' +
        '.tax-decl-bydate-table{font-size:8.5pt;}' +
        '.report-acc-total td{font-weight:800;background:#e2e8f0!important;}'
      );
    }

    function getVatNetPayablePrintCss() {
      return (
        getAccSummaryReportPrintCss() +
        '.report-vat-net-page .report-sales-print-area,.report-vat-net-pdf-root.report-vat-net-page{font-size:8pt!important;width:100%!important;max-width:none!important;}' +
        '.report-vat-net-page .report-acc-table,.report-vat-net-pdf-root .report-acc-table{max-width:100%!important;width:100%!important;margin-bottom:0.5rem!important;}' +
        '.report-vat-net-summary-hero{padding:0.55rem 0.75rem!important;margin:0.5rem 0!important;}' +
        '.report-vat-net-summary-hero__amount{font-size:13pt!important;line-height:1.2!important;}' +
        '.report-vat-net-summary-hero__label{font-size:8pt!important;}' +
        '.report-vat-net-summary-formula{font-size:7pt!important;line-height:1.4!important;text-align:center!important;}' +
        '.report-vat-net-summary-formula div{margin:0.12rem 0!important;}' +
        '.report-vat-net-page .report-sales-table-wrap,.report-vat-net-pdf-root .report-sales-table-wrap{overflow:visible!important;width:100%!important;max-width:100%!important;min-width:0!important;}' +
        '.report-vat-net-detail-table,.report-vat-net-detail-totals-table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed!important;border-collapse:collapse!important;font-size:6.5pt!important;}' +
        '.report-vat-net-detail-table th,.report-vat-net-detail-table td,.report-vat-net-detail-totals-table td{font-size:6.5pt!important;padding:1px 2px!important;line-height:1.1!important;vertical-align:middle!important;border:1px solid #94a3b8!important;overflow:hidden!important;text-overflow:ellipsis!important;box-sizing:border-box!important;}' +
        '.report-vat-net-detail-table col.col-seq{width:8%!important;}' +
        '.report-vat-net-detail-table col.col-type{width:10%!important;}' +
        '.report-vat-net-detail-table col.col-date{width:14%!important;}' +
        '.report-vat-net-detail-table col.col-inv-no{width:16%!important;}' +
        '.report-vat-net-detail-table col.col-tax-rate{width:13%!important;}' +
        '.report-vat-net-detail-table col.col-total{width:19%!important;}' +
        '.report-vat-net-detail-table col.col-tax-amt{width:20%!important;}' +
        '.report-vat-net-detail-totals-table col.col-seq{width:8%!important;}' +
        '.report-vat-net-detail-totals-table col.col-type{width:10%!important;}' +
        '.report-vat-net-detail-totals-table col.col-date{width:14%!important;}' +
        '.report-vat-net-detail-totals-table col.col-inv-no{width:16%!important;}' +
        '.report-vat-net-detail-totals-table col.col-tax-rate{width:13%!important;}' +
        '.report-vat-net-detail-totals-table col.col-total{width:19%!important;}' +
        '.report-vat-net-detail-totals-table col.col-tax-amt{width:20%!important;}' +
        '.report-vat-net-detail-table .col-seq{text-align:center!important;white-space:nowrap!important;}' +
        '.report-vat-net-detail-table .col-date,.report-vat-net-detail-table .col-inv-no{direction:ltr!important;unicode-bidi:isolate!important;text-align:center!important;white-space:nowrap!important;}' +
        '.report-vat-net-detail-table .col-money{direction:ltr!important;unicode-bidi:isolate!important;text-align:center!important;font-variant-numeric:tabular-nums!important;white-space:nowrap!important;font-size:6pt!important;}' +
        '.report-vat-net-detail-table code{background:transparent!important;padding:0!important;border:0!important;font-size:inherit!important;}' +
        '.report-vat-net-detail-table thead{display:table-header-group!important;}' +
        '.report-vat-net-detail-table thead th{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-vat-net-detail-totals-wrap{break-inside:avoid!important;page-break-inside:avoid!important;margin-top:0!important;}' +
        '.report-vat-net-detail-totals-table .report-sales-tfoot td{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-weight:700!important;}' +
        '.report-vat-net-detail-title{font-size:9pt!important;margin:0.5rem 0 0.25rem!important;}' +
        '.report-vat-net-detail-meta{font-size:7.5pt!important;margin:0 0 0.35rem!important;}'
      );
    }

    function isVatNetPayableReport(routeKey) {
      return routeKey === 'report_vat_net_payable';
    }

    function isVatNetDetailView() {
      return page && page.getAttribute('data-report-view') === 'detail';
    }

    function pdfNeedsPageNumbers(routeKey) {
      return isVatNetPayableReport(routeKey);
    }

    function getPdfCaptureElement(rootEl) {
      if (!rootEl) {
        return null;
      }
      if (rootEl.classList && rootEl.classList.contains('report-sales-print-area')) {
        return rootEl;
      }
      if (rootEl.querySelector) {
        return rootEl.querySelector('.report-sales-print-area') || rootEl;
      }
      return rootEl;
    }

    function measurePdfCaptureElement(rootEl) {
      var el = getPdfCaptureElement(rootEl);
      if (!el) {
        return { element: null, width: 794, height: 600 };
      }
      var width = Math.max(el.scrollWidth || 0, el.offsetWidth || 0, el.clientWidth || 0, 720);
      if (page && isVatNetPayableReport(page.getAttribute('data-report-route') || '') && isVatNetDetailView()) {
        width = Math.max(width, 1060);
      }
      if (page && isHrEmployeesReport()) {
        width = 794;
      }
      var height = Math.max(el.scrollHeight || 0, el.offsetHeight || 0, el.clientHeight || 0, 600);
      return { element: el, width: width, height: height };
    }

    function isAccSummaryReportRoute(routeKey) {
      return (
        routeKey === 'report_income_statement_comprehensive' ||
        routeKey === 'report_tax_declaration' ||
        routeKey === 'report_vat_net_payable'
      );
    }

    function isHrPayrollIncomeTaxReport() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return routeKey === 'hr_payroll_income_tax_report';
    }

    function getHrPayrollIncomeTaxPrintCss() {
      return (
        '@page{size:A4 portrait;margin:8mm 10mm 14mm 10mm;}' +
        '.hr-pr-it-rpt-table{font-size:7pt!important;table-layout:fixed!important;width:100%!important;}' +
        '.hr-pr-it-rpt-table th,.hr-pr-it-rpt-table td{padding:2px 3px!important;line-height:1.15!important;}' +
        '.hr-pr-it-rpt-table col.col-seq{width:5mm!important;}' +
        '.hr-pr-it-rpt-table col.col-emp-code{width:11mm!important;}' +
        '.hr-pr-it-rpt-table col.col-emp-name{width:30%!important;}' +
        '.hr-pr-it-rpt-table .col-seq{width:5mm!important;max-width:5mm!important;padding:1px!important;text-align:center!important;}' +
        '.hr-pr-it-rpt-table .col-emp-code{width:11mm!important;max-width:11mm!important;padding:1px 2px!important;text-align:center!important;font-weight:800!important;color:#1e3a8a!important;background:#f8fafc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.hr-pr-it-rpt-table thead th.col-emp-code{background:#dbeafe!important;color:#1e3a8a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.hr-pr-it-rpt-table .col-emp-name,.hr-pr-it-rpt-table .hr-pr-it-rpt-emp-name{white-space:nowrap!important;overflow:hidden!important;word-break:keep-all!important;text-overflow:clip!important;}' +
        '.hr-pr-it-rpt-table .col-emp-code,.hr-pr-it-rpt-table .col-national-id,.hr-pr-it-rpt-table .col-marital,.hr-pr-it-rpt-table .col-count,.hr-pr-it-rpt-table td.col-money{white-space:nowrap!important;}'
      );
    }

    function fitHrPayrollIncomeTaxReportNames(doc) {
      doc = doc || document;
      var cells = doc.querySelectorAll('.hr-pr-it-rpt-table .col-emp-name');
      if (!cells.length) return;
      cells.forEach(function (td) {
        var el = td.querySelector('.hr-pr-it-rpt-emp-name');
        if (!el) {
          el = doc.createElement('span');
          el.className = 'hr-pr-it-rpt-emp-name';
          el.textContent = (td.textContent || '').trim();
          td.textContent = '';
          td.appendChild(el);
        }
        td.style.whiteSpace = 'nowrap';
        td.style.overflow = 'hidden';
        el.style.display = 'inline-block';
        el.style.whiteSpace = 'nowrap';
        el.style.wordBreak = 'keep-all';
        el.style.maxWidth = '100%';
        el.style.verticalAlign = 'middle';
        var pt = 8;
        el.style.fontSize = pt + 'pt';
        var guard = 0;
        var cellW = td.clientWidth || td.offsetWidth;
        if (cellW < 8) return;
        while (el.scrollWidth > cellW && pt > 6 && guard < 40) {
          pt -= 0.15;
          el.style.fontSize = pt + 'pt';
          guard += 1;
        }
      });
    }

    function getHrTaxAr3PrintCss() {
      return (
        '@page{size:A4 portrait;margin:5mm 7mm 7mm 7mm;}' +
        '.hr-tax-ar3-doc{border:1px solid #000!important;box-shadow:none!important;padding:4mm 5mm 5mm!important;font-family:"Traditional Arabic","Simplified Arabic",Arial,Tahoma,sans-serif!important;font-size:8pt!important;color:#000!important;}' +
        '.hr-tax-ar3-doc .doc-print-watermark,.hr-tax-ar3-doc .doc-print-watermark--overlay{display:none!important;}' +
        '.tax-ar3-official-head{display:grid;grid-template-columns:1fr 2.2fr 1fr;gap:4px;margin-bottom:2px;}' +
        '.tax-ar3-official-head__center{text-align:center;}' +
        '.tax-ar3-official-head__side--right{text-align:end;padding-top:6px;}' +
        '.tax-ar3-emblem{width:72px!important;max-height:48px!important;object-fit:contain!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.tax-ar3-kingdom,.tax-ar3-ministry,.tax-ar3-department{font-weight:800;font-size:8.5pt;}' +
        '.tax-ar3-form-code{font-weight:800;font-size:8.5pt;}' +
        '.tax-ar3-official-title{text-align:center;font-size:9.5pt;font-weight:800;margin:2px 0 1px;}' +
        '.tax-ar3-official-subtitle{text-align:center;font-size:7pt;font-weight:700;margin:0 0 4px;line-height:1.45;}' +
        '.tax-ar3-grid-table,.tax-ar3-employer-table,.tax-ar3-financial-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:-1px;}' +
        '.tax-ar3-grid-table td,.tax-ar3-grid-table th,.tax-ar3-employer-table td,.tax-ar3-financial-table th,.tax-ar3-financial-table td{border:1px solid #000;padding:1px 3px;vertical-align:middle;text-align:center;}' +
        '.tax-ar3-label-row td{font-size:6.5pt;font-weight:800;}' +
        '.tax-ar3-value-row td{font-size:8pt;font-weight:700;}' +
        '.tax-ar3-section-head,.tax-ar3-value-head th,.tax-ar3-sub-head th{font-weight:800;font-size:6.5pt;}' +
        '.tax-ar3-row-label{font-size:6.5pt;line-height:1.2;font-weight:700;}' +
        '.tax-ar3-money{text-align:center!important;direction:ltr;font-variant-numeric:tabular-nums;font-size:7pt;font-weight:700;}' +
        '.tax-ar3-declaration{margin:0 0 4px;padding:4px 6px;border:1px solid #000;font-size:7pt;line-height:1.55;text-align:center;}' +
        '.tax-ar3-sign-space{min-height:52px;font-size:7pt;font-weight:800;}' +
        '.tax-ar3-doc-footer{display:flex;justify-content:space-between;font-size:7.5pt;font-weight:700;margin-top:4px;}' +
        '.no-print{display:none!important;}'
      );
    }

    function getWarehouseItemsPageCss() {
      return (
        '@page{size:A4 landscape;margin:8mm 10mm 12mm 10mm;}' +
        '.report-warehouse-items-page .report-sales-print-area,.report-warehouse-zero-qty-page .report-sales-print-area,.report-warehouse-negative-qty-page .report-sales-print-area,.report-warehouse-financial-page .report-sales-print-area{font-size:8pt!important;}' +
        '.report-warehouse-items-table th,.report-warehouse-items-table td,.report-warehouse-zero-qty-table th,.report-warehouse-zero-qty-table td,.report-warehouse-negative-qty-table th,.report-warehouse-negative-qty-table td,.report-warehouse-financial-table th,.report-warehouse-financial-table td{font-size:8pt!important;padding:2px 3px!important;line-height:1.15!important;}' +
        '.report-warehouse-items-print .party-stmt-report-head,.report-warehouse-zero-qty-print .party-stmt-report-head,.report-warehouse-negative-qty-print .party-stmt-report-head,.report-warehouse-financial-print .party-stmt-report-head{margin:0.25rem 0 1rem!important;}' +
        '.report-warehouse-items-table .col-seq{width:3%!important;max-width:2.5rem!important;}' +
        '.report-warehouse-items-table .col-inv-no{width:10%!important;}' +
        '.report-warehouse-items-table .col-item-name{width:34%!important;text-align:start!important;white-space:normal!important;word-break:break-word!important;}' +
        '.report-warehouse-items-table .col-qty,.report-warehouse-items-table .col-price{width:9%!important;}' +
        '.report-warehouse-items-table .col-category{width:11%!important;text-align:start!important;}' +
        '.report-warehouse-items-table .col-unit{width:8%!important;}'
      );
    }

    function getStocktakePrintCss() {
      return (
        '.report-sales-print-area .stocktake-print-table{table-layout:fixed!important;width:100%!important;font-size:9pt!important;}' +
        '.report-sales-print-area .stocktake-print-table th,.report-sales-print-area .stocktake-print-table td{font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;padding:3px 2px!important;line-height:1.1!important;overflow:hidden!important;text-overflow:clip!important;box-sizing:border-box!important;}' +
        '.report-sales-print-area .stocktake-print-table thead th{white-space:normal!important;overflow:visible!important;text-overflow:unset!important;line-height:1.1!important;font-size:8pt!important;padding:2px 1px!important;}' +
        '.report-sales-print-area .stocktake-print-table tbody td{white-space:nowrap!important;font-size:8.6pt!important;}' +
        '.report-sales-print-area .stocktake-print-table th:nth-child(1),.report-sales-print-area .stocktake-print-table td:nth-child(1){width:4%!important;}' +
        '.report-sales-print-area .stocktake-print-table th:nth-child(2),.report-sales-print-area .stocktake-print-table td:nth-child(2){width:9%!important;}' +
        '.report-sales-print-area .stocktake-print-table th:nth-child(3),.report-sales-print-area .stocktake-print-table td:nth-child(3){width:27%!important;text-align:start!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}' +
        '.report-sales-print-area .stocktake-print-table th:nth-child(4),.report-sales-print-area .stocktake-print-table td:nth-child(4),.report-sales-print-area .stocktake-print-table th:nth-child(5),.report-sales-print-area .stocktake-print-table td:nth-child(5),.report-sales-print-area .stocktake-print-table th:nth-child(6),.report-sales-print-area .stocktake-print-table td:nth-child(6),.report-sales-print-area .stocktake-print-table th:nth-child(7),.report-sales-print-area .stocktake-print-table td:nth-child(7),.report-sales-print-area .stocktake-print-table th:nth-child(8),.report-sales-print-area .stocktake-print-table td:nth-child(8){width:12%!important;}' +
        '.report-sales-print-area .stocktake-print-table td:nth-child(4),.report-sales-print-area .stocktake-print-table td:nth-child(5),.report-sales-print-area .stocktake-print-table td:nth-child(6),.report-sales-print-area .stocktake-print-table td:nth-child(7),.report-sales-print-area .stocktake-print-table td:nth-child(8){direction:ltr!important;unicode-bidi:isolate!important;font-variant-numeric:tabular-nums!important;text-align:center!important;letter-spacing:-0.1px!important;}' +
        '.report-sales-print-area .stocktake-print-table td.col-item-name{white-space:nowrap!important;word-break:keep-all!important;overflow:hidden!important;text-overflow:ellipsis!important;}'
      );
    }

    function getGeneralLedgerPrintCss() {
      return (
        '.report-gl-page .report-sales-print-area{font-size:8pt!important;}' +
        '.report-gl-page .report-acc-summary-grid{margin:0.35rem 0 0.5rem!important;gap:0.35rem!important;}' +
        '.report-gl-table{table-layout:fixed!important;width:100%!important;font-size:7.5pt!important;}' +
        '.report-gl-table th,.report-gl-table td{padding:2px 3px!important;line-height:1.2!important;vertical-align:top!important;overflow:hidden!important;}' +
        '.report-gl-table thead th{vertical-align:middle!important;text-align:center!important;background:#f1f5f9!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
        '.report-gl-table .col-date{width:10%!important;white-space:nowrap!important;direction:ltr!important;unicode-bidi:embed!important;font-size:7pt!important;}' +
        '.report-gl-table .col-entry-no{width:15%!important;text-align:start!important;direction:ltr!important;unicode-bidi:isolate!important;word-break:break-all!important;overflow-wrap:anywhere!important;font-size:6.5pt!important;line-height:1.15!important;}' +
        '.report-gl-table .col-entry-no code{font-size:inherit!important;background:transparent!important;padding:0!important;word-break:break-all!important;white-space:normal!important;display:inline!important;font-weight:700!important;}' +
        '.report-gl-table .col-desc{width:37%!important;text-align:start!important;white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;line-height:1.25!important;font-size:7pt!important;}' +
        '.report-gl-table .col-money{width:12.5%!important;text-align:left!important;direction:ltr!important;unicode-bidi:embed!important;white-space:nowrap!important;font-size:6.5pt!important;font-variant-numeric:tabular-nums!important;padding-inline:2px!important;}' +
        '.report-gl-table .report-acc-opening-row td,.report-gl-table .report-acc-totals td{vertical-align:middle!important;}' +
        '.report-gl-table tfoot td{background:#eef2ff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
        '.report-gl-table .no-print{display:none!important;}'
      );
    }

    function getTrialBalancePrintCss() {
      return (
        '@page{size:A4 landscape;margin:8mm 8mm 14mm 8mm;@bottom-center{content:counter(page) " / " counter(pages);font-family:Arial,Helvetica,sans-serif;font-size:9pt;font-weight:700;color:#0f172a;}}' +
        '@media print{' +
        'html,body{margin:0!important;padding:0!important;}' +
        '.report-sales-print-area{margin:0!important;padding:0!important;}' +
        '.report-sales-print-area .doc-print-header{margin:0 0 0.35rem!important;padding:0!important;}' +
        '.report-sales-print-area .party-stmt-report-head{margin:0 0 0.35rem!important;}' +
        '.report-acc-grid-table thead{display:table-header-group!important;}' +
        '.report-acc-grid-table thead tr{page-break-inside:avoid!important;break-inside:avoid!important;}' +
        '}' +
        '.report-acc-grid-wrap{border:none!important;border-radius:0!important;overflow:visible!important;background:#fff!important;}' +
        '.report-acc-grid-table,.report-trial-balance-table,.tb-vat-inner-table{width:100%!important;border-collapse:collapse!important;table-layout:fixed!important;font-size:9pt!important;font-weight:700!important;}' +
        '.report-acc-grid-table th,.report-acc-grid-table td,.report-trial-balance-table th,.report-trial-balance-table td,.tb-vat-inner-table th,.tb-vat-inner-table td{border:1px solid #0f172a!important;padding:3px 4px!important;vertical-align:middle!important;background:#fff!important;}' +
        '.report-acc-grid-table thead th,.report-trial-balance-table thead th{background:#d9d9d9!important;color:#0f172a!important;text-align:center!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
        '.report-acc-grid-table tfoot td,.report-trial-balance-table tfoot td{background:#eef2ff!important;font-weight:700!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
        '.report-trial-balance-table .col-acc-code{width:8.5%!important;text-align:center!important;}' +
        '.report-trial-balance-table .col-acc-name{width:22%!important;text-align:start!important;white-space:normal!important;word-break:break-word!important;}' +
        '.report-trial-balance-table .col-acc-type{width:6.5%!important;text-align:center!important;}' +
        '.report-trial-balance-table .col-money{width:8.5%!important;text-align:center!important;direction:ltr!important;unicode-bidi:isolate!important;font-variant-numeric:tabular-nums!important;}' +
        '.report-tb-detail-page .report-trial-balance-table tr.tb-detail-group-row td{background:rgba(99,102,241,0.12)!important;font-weight:700!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}' +
        '.report-acc-grid-table .tb-vat-detail-row>td{background:#f8fafc!important;padding:4px 6px!important;text-align:start!important;}' +
        '.report-acc-grid-table tbody td{text-align:start!important;}' +
        '.report-acc-grid-table .col-acc-code,.report-acc-grid-table .col-acc-type,.report-acc-grid-table .col-money{text-align:center!important;}' +
        '.report-acc-grid-table .col-acc-name{text-align:start!important;}' +
        '.report-acc-grid-table .tb-vat-inner-table td:first-child{text-align:start!important;}'
      );
    }

    function getCompanyLogoUrl() {
      return document.body.getAttribute('data-company-logo-url') || '';
    }

    function docPrintWatermarkStyles() {
      var logoUrl = getCompanyLogoUrl();
      var dh = window.DocumentHeader;
      return dh && logoUrl && dh.buildPrintWatermarkStyles
        ? dh.buildPrintWatermarkStyles(logoUrl)
        : '';
    }

    function getPrintStyles(pdfOrientation) {
      pdfOrientation = pdfOrientation || 'portrait';
      var hdr =
        window.DocumentHeader && window.DocumentHeader.css
          ? window.DocumentHeader.css
          : '';
      var bold =
        window.DocumentHeader && window.DocumentHeader.printBoldCss
          ? window.DocumentHeader.printBoldCss
          : '';
      var routeKey = page.getAttribute('data-report-route') || '';
      var receivablesPageCss =
        (routeKey === 'report_receivables' || routeKey === 'report_supplier_payables')
          ? getReceivablesPageNumberCss()
          : '';
      var receivablesSummaryCss =
        (routeKey === 'report_receivables' || routeKey === 'report_supplier_payables') &&
        isReceivablesSummaryMode()
          ? getReceivablesSummaryPrintCss()
          : '';
      var receivablesAgingPrintCss = getReceivablesAgingPrintCss();
      var customersPageCss =
        routeKey === 'report_customers' ? getCustomersPageCss() : '';
      var hrEmployeesPrintCss = isHrEmployeesReport() ? getHrEmployeesPrintCss() : '';
      var hrEmployeesByNatPrintCss = isHrEmployeesByNationalityReport()
        ? getHrEmployeesByNationalityPrintCss()
        : '';
      var partyStatementPrintCss = isPartyStatementReport()
        ? getPartyStatementPrintCss()
        : '';
      var oracleStatementPrintCss = isOracleCustomerStatementReport()
        ? getOracleCustomerStatementPrintCss()
        : '';
      var accSummaryPrintCss =
        routeKey === 'report_vat_net_payable'
          ? getVatNetPayablePrintCss()
          : isAccSummaryReportRoute(routeKey)
            ? getAccSummaryReportPrintCss()
            : '';
      var hrTaxAr3PrintCss = routeKey === 'report_tax_ar3' ? getHrTaxAr3PrintCss() : '';
      var reportSalesCss = isReportSalesRoute() ? getReportSalesPrintCss() : '';
      var reportSalesByRepCss = isSalesByRepReport() ? getReportSalesByRepPrintCss() : '';
      var reportSalesByItemCss = isSalesByItemReport() ? getReportSalesByItemPrintCss() : '';
      var reportDeliveryCss = isDeliveryReportRoute() ? getDeliveryReportPrintCss() : '';
      var reportSalesReturnsCss =
        isSalesReturnsReport() || isPurchaseReturnsReport() ? getReportSalesReturnsPrintCss() : '';
      var reportPurchasesCss = isReportPurchasesRoute() ? getReportPurchasesPrintCss() : '';
      var warehouseItemsPageCss = isWarehouseItemsReportRoute(routeKey)
          ? getWarehouseItemsPageCss()
          : '';
      var stocktakeNoWatermarkCss =
        routeKey === 'inventory_stocktake'
          ? 'body.has-doc-watermark::after{display:none!important;}.doc-print-watermark,.doc-print-watermark--overlay{display:none!important;}'
          : '';
      var trialBalancePageCss = isTrialBalanceReportRoute(routeKey)
        ? getTrialBalancePrintCss()
        : '';
      var itemStockLedgerCss = isItemStockLedgerReport() ? getItemStockLedgerPrintCss() : '';
      var incomingChecksPrintCss = isVoucherChecksReport() ? getVoucherChecksPrintCss() : '';
      var stocktakePrintCss =
        routeKey === 'inventory_stocktake' ? getStocktakePrintCss() : '';
      var generalLedgerPageCss =
        routeKey === 'report_general_ledger' || routeKey === 'report_account_statement'
          ? getGeneralLedgerPrintCss()
          : '';
      var summaryPrint =
        ((routeKey === 'report_receivables' || routeKey === 'report_supplier_payables') &&
          isReceivablesSummaryMode()) ||
        (routeKey === 'report_receivables_aging' && isReceivablesSummaryMode());
      var agingPrint = routeKey === 'report_receivables_aging';
      var periodInvoicePrint =
        isPeriodInvoiceReportRoute() ||
        isSalesByRepReport() ||
        isSalesByItemReport() ||
        isSalesReturnsReport() ||
        isPurchaseReturnsReport();
      var itemStockPrint = isItemStockLedgerReport();
      var incomingChecksPrint = isVoucherChecksReport();
      var trialBalancePrint = isTrialBalanceReportRoute(routeKey);
      var incomeTaxPrint = isHrPayrollIncomeTaxReport();
      var vatNetPrint = isVatNetPayableReport(routeKey);
      var bodyMarginTop = trialBalancePrint ? '0' : '6mm';
      var bodyMarginSides = summaryPrint ? '5mm' : itemStockPrint ? '5mm' : agingPrint ? '5mm' : trialBalancePrint ? '0' : '12mm';
      var bodyMarginBottom =
        routeKey === 'report_receivables' && !summaryPrint
          ? '16mm'
          : trialBalancePrint
            ? '0'
            : '12mm';
      return (
        docPrintWatermarkStyles() +
        hdr +
        bold +
        receivablesPageCss +
        receivablesSummaryCss +
        receivablesAgingPrintCss +
        customersPageCss +
        hrEmployeesPrintCss +
        hrEmployeesByNatPrintCss +
        partyStatementPrintCss +
        oracleStatementPrintCss +
        accSummaryPrintCss +
        hrTaxAr3PrintCss +
        reportSalesCss +
        reportSalesByRepCss +
        reportPurchasesCss +
        warehouseItemsPageCss +
        stocktakeNoWatermarkCss +
        trialBalancePageCss +
        itemStockLedgerCss +
        incomingChecksPrintCss +
        stocktakePrintCss +
        generalLedgerPageCss +
        '.report-acc-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:0.5rem;margin:0.5rem 0 0.75rem;}' +
        '.report-acc-summary-card{padding:0.35rem 0;}' +
        'body,html{-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        'body{font-family:Arial,Helvetica,sans-serif;font-size:' +
        (summaryPrint || periodInvoicePrint || itemStockPrint || incomingChecksPrint || vatNetPrint
          ? '7pt'
          : '13px') +
        ';font-weight:700;color:#0f172a;margin:' +
        bodyMarginTop +
        ' ' +
        bodyMarginSides +
        ' ' +
        bodyMarginBottom +
        ' ' +
        bodyMarginSides +
        ';direction:rtl;}' +
        (trialBalancePrint
          ? 'html,body{margin:0!important;padding:0!important;}.report-sales-print-area{margin:0!important;padding:0!important;}'
          : '') +
        (summaryPrint || periodInvoicePrint
          ? '.report-sales-print-area{font-size:7.5pt!important;width:100%!important;}.doc-print-meta{font-size:7.5pt!important;}'
          : vatNetPrint
            ? '.report-sales-print-area{font-size:8pt!important;width:100%!important;max-width:none!important;}.doc-print-meta{font-size:8pt!important;}'
            : '') +
        (itemStockPrint
          ? '.report-sales-print-area{font-size:7pt!important;width:100%!important;}.doc-print-meta{font-size:7pt!important;}'
          : incomingChecksPrint
            ? '.report-sales-print-area{font-size:7pt!important;width:100%!important;max-width:none!important;}.doc-print-meta{font-size:7pt!important;}'
            : '') +
        '.report-sales-print-area,.report-sales-print-area *{font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#0f172a;}' +
        '.report-sales-table{width:100%;border-collapse:collapse;direction:rtl;table-layout:fixed;font-size:12px;font-weight:700;}' +
        '.report-sales-table th{background:#f1f5f9;padding:0.45rem;border:1px solid #94a3b8;text-align:center;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-sales-table td{padding:0.4rem;border:1px solid #cbd5e1;text-align:center;background:#fff;font-weight:700;}' +
        '.report-receivables-print .report-sales-table thead th,.report-receivables-page .report-sales-table thead th,.report-receivables-print .report-receivables-table thead th{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-receivables-print .report-receivables-table tr.report-receivables-customer-total td,.report-receivables-print tr.report-receivables-customer-total td,.report-receivables-print .report-receivables-table tr.report-sales-group-total.report-receivables-customer-total td,.report-receivables-table td.report-receivables-total-cell,.report-receivables-print .report-sales-table tfoot td,.report-receivables-page .report-sales-table tfoot td{background:#e2e8f0!important;background-color:#e2e8f0!important;border-top:1px solid #94a3b8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-receivables-print tr.report-receivables-customer-row .report-receivables-customer-balance,.report-receivables-page tr.report-receivables-customer-row .report-receivables-customer-balance{background:#f1f5f9!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-receivables-print th.report-receivables-col-balance,.report-receivables-page th.report-receivables-col-balance{background:#e2e8f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.report-sales-table .col-posted{white-space:nowrap;}' +
        '.report-sales-table .col-money{text-align:center;font-variant-numeric:tabular-nums;}' +
        '.report-sales-table tfoot td,.report-sales-grand-total-table td{background:#e2e8f0;}' +
        '.report-sales-page[data-report-route="report_sales"] .report-sales-table:not(.report-sales-grand-total-table) tfoot{display:table-row-group!important;}' +
        '.report-sales-table-stack{width:100%;}' +
        '.report-sales-table-stack .report-sales-table{width:100%!important;table-layout:fixed!important;}' +
        '.report-sales-table-stack .report-sales-grand-total-wrap{margin-top:0!important;}' +
        '.report-sales-grand-total-wrap{break-inside:avoid;page-break-inside:avoid;}' +
        (periodInvoicePrint
          ? '@page{size:A4 portrait;margin:6mm 10mm 10mm 10mm;}' +
            '.report-sales-table thead{display:table-header-group!important;}' +
            '.report-sales-table tbody tr{page-break-inside:auto!important;break-inside:auto!important;}' +
            '.report-sales-grand-total-wrap,.report-sales-grand-total-table{break-inside:avoid!important;page-break-inside:avoid!important;}'
          : '') +
        '.doc-print-header-co,.doc-print-header-title{font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#0f172a;}' +
        '.doc-print-meta{margin:0.35rem 0 0.65rem;font-size:12px;}' +
        '.doc-print-meta td{border:none;padding:0.2rem 0;text-align:start;}' +
        '.party-stmt-report-head{text-align:center;margin:0.25rem 0 1rem;}' +
        '.party-stmt-report-customer{margin:0 0 0.4rem;font-size:13pt;font-weight:800;}' +
        '.party-stmt-report-dates{margin:0;font-size:11pt;font-weight:600;color:#334155;}' +
        '.party-stmt-report-dates-sep{margin:0 0.5rem;color:#94a3b8;}' +
        reportSalesByItemCss +
        reportDeliveryCss +
        reportSalesReturnsCss +
        (incomeTaxPrint ? getHrPayrollIncomeTaxPrintCss() : '') +
        getPdfCaptureSafetyCss(pdfOrientation) +
        (isHrEmployeesReport() ? getHrEmployeesPdfOverrideCss() : '') +
        /* اتجاه الطباعة المختار من المعاينة يتجاوز أي @page ثابت في أنماط التقرير */
        '@page{size:A4 ' +
        (pdfOrientation === 'landscape' ? 'landscape' : 'portrait') +
        ';}'
      );
    }

    /** يتجاوز أنماط الترويسة العامة (row-reverse) التي تقصّ الشعار وعمود الحالة في تقرير الموظفين */
    function getHrEmployeesPdfOverrideCss() {
      return (
        'html,body{margin:0!important;padding:8mm!important;box-sizing:border-box!important;width:100%!important;max-width:794px!important;overflow:visible!important;}' +
        'body,body *{font-family:Tahoma,"Segoe UI",Arial,sans-serif!important;letter-spacing:normal!important;}' +
        '.report-sales-print-area,.report-hr-employees-print{max-width:100%!important;width:100%!important;padding:0!important;margin:0!important;overflow:visible!important;}' +
        '.doc-print-header-brand{display:flex!important;flex-direction:row!important;direction:rtl!important;justify-content:space-between!important;align-items:center!important;width:100%!important;gap:0.5rem!important;flex-wrap:nowrap!important;padding:0!important;}' +
        '.doc-print-header-co{flex:1 1 auto!important;min-width:0!important;text-align:right!important;direction:rtl!important;unicode-bidi:normal!important;font-size:12pt!important;}' +
        '.doc-print-header-logo{flex:0 0 90px!important;width:90px!important;min-width:90px!important;max-width:90px!important;overflow:visible!important;}' +
        '.doc-print-header-logo img{max-width:90px!important;max-height:70px!important;width:auto!important;height:auto!important;display:block!important;}' +
        '.report-hr-employees-table{width:100%!important;max-width:100%!important;table-layout:fixed!important;}' +
        '.report-hr-employees-table col.col-status,.report-hr-employees-table th.col-status,.report-hr-employees-table td.col-status{width:24%!important;display:table-cell!important;visibility:visible!important;}'
      );
    }

    /** منع قصّ الشعار والترويسة عند html2canvas (إطار PDF) */
    function getPdfCaptureSafetyCss(pdfOrientation) {
      pdfOrientation = pdfOrientation || 'portrait';
      var hostW = pdfOrientation === 'landscape' ? '277mm' : '190mm';
      var logoMaxH =
        window.DocumentHeader && window.DocumentHeader.logoMaxHeight
          ? window.DocumentHeader.logoMaxHeight
          : 130;
      var logoMaxW =
        window.DocumentHeader && window.DocumentHeader.logoMaxWidth
          ? window.DocumentHeader.logoMaxWidth
          : 130;
      return (
        'html,body{overflow:visible!important;height:auto!important;min-height:0!important;}' +
        'body.has-doc-watermark.doc-print-standalone{margin:0!important;padding:8mm 10mm 10mm 16mm!important;box-sizing:border-box!important;width:100%!important;}' +
        '.report-sales-print-area,.doc-print-watermark-root,.doc-print-header,.doc-print-header-top,.doc-print-header-brand,.doc-print-header-logo{overflow:visible!important;box-sizing:border-box!important;}' +
        '.report-sales-print-area{padding:0!important;width:100%!important;max-width:100%!important;}' +
        '.doc-print-header-top{padding-top:4px!important;overflow:visible!important;}' +
        '.doc-print-header-brand{display:flex!important;flex-direction:row-reverse!important;direction:ltr!important;justify-content:space-between!important;align-items:center!important;width:100%!important;gap:0.75rem!important;flex-wrap:nowrap!important;padding:0 2px!important;}' +
        '.doc-print-header-co{flex:1 1 auto!important;min-width:0!important;text-align:right!important;direction:rtl!important;unicode-bidi:plaintext!important;}' +
        '.doc-print-header-logo{flex:0 0 auto!important;display:flex!important;align-items:center!important;justify-content:center!important;width:' +
        logoMaxW +
        'px!important;min-width:' +
        logoMaxW +
        'px!important;max-width:' +
        logoMaxW +
        'px!important;overflow:visible!important;padding:0!important;}' +
        '.doc-print-header-logo img{display:block!important;max-height:' +
        logoMaxH +
        'px!important;max-width:' +
        logoMaxW +
        'px!important;width:auto!important;height:auto!important;object-fit:contain!important;object-position:center center!important;margin:0 auto!important;}' +
        '.doc-print-watermark--overlay{display:none!important;}' +
        '.sales-inv-export-host{padding:8mm 10mm 14mm 10mm!important;box-sizing:border-box!important;overflow:visible!important;width:' +
        hostW +
        '!important;max-width:' +
        hostW +
        '!important;}'
      );
    }

    function applyPdfCloneDocumentFixes(clonedDoc, routeKey) {
      routeKey = routeKey || (page && page.getAttribute('data-report-route')) || '';
      if (!clonedDoc || !clonedDoc.body) return;
      var body = clonedDoc.body;
      var isHrEmp = routeKey === 'report_hr_employees';
      body.style.margin = '0';
      body.style.padding = isHrEmp ? '8mm' : '8mm 10mm 10mm 16mm';
      body.style.boxSizing = 'border-box';
      body.style.overflow = 'visible';
      body.style.width = isHrEmp ? '794px' : '100%';
      body.style.maxWidth = isHrEmp ? '794px' : '100%';

      var brand = clonedDoc.querySelector('.doc-print-header-brand');
      if (brand) {
        brand.style.display = 'flex';
        brand.style.flexDirection = isHrEmp ? 'row' : 'row-reverse';
        brand.style.direction = isHrEmp ? 'rtl' : 'ltr';
        brand.style.justifyContent = 'space-between';
        brand.style.alignItems = 'center';
        brand.style.width = '100%';
        brand.style.overflow = 'visible';
        brand.style.boxSizing = 'border-box';
        brand.style.flexWrap = 'nowrap';
        brand.style.padding = '0 2px';
      }

      var co = clonedDoc.querySelector('.doc-print-header-co');
      if (co) {
        co.style.textAlign = 'right';
        co.style.direction = 'rtl';
        co.style.flex = '1 1 auto';
        co.style.minWidth = '0';
      }

      var logoBox = clonedDoc.querySelector('.doc-print-header-logo');
      var logoMaxW = isHrEmp
        ? 90
        : window.DocumentHeader && window.DocumentHeader.logoMaxWidth
          ? window.DocumentHeader.logoMaxWidth
          : 130;
      var logoMaxH = isHrEmp
        ? 70
        : window.DocumentHeader && window.DocumentHeader.logoMaxHeight
          ? window.DocumentHeader.logoMaxHeight
          : 130;
      if (logoBox) {
        logoBox.style.flex = '0 0 auto';
        logoBox.style.width = logoMaxW + 'px';
        logoBox.style.minWidth = logoMaxW + 'px';
        logoBox.style.maxWidth = logoMaxW + 'px';
        logoBox.style.overflow = 'visible';
        logoBox.style.display = 'flex';
        logoBox.style.alignItems = 'center';
        logoBox.style.justifyContent = 'center';
      }

      var logoImg = clonedDoc.querySelector('.doc-print-header-logo img');
      if (logoImg) {
        logoImg.style.display = 'block';
        logoImg.style.maxWidth = logoMaxW + 'px';
        logoImg.style.maxHeight = logoMaxH + 'px';
        logoImg.style.width = 'auto';
        logoImg.style.height = 'auto';
        logoImg.style.objectFit = 'contain';
        logoImg.style.margin = '0 auto';
      }

      var printArea = clonedDoc.querySelector('.report-sales-print-area');
      if (printArea) {
        printArea.style.overflow = 'visible';
        printArea.style.width = '100%';
        printArea.style.maxWidth = '100%';
        printArea.style.padding = '0';
        printArea.style.boxSizing = 'border-box';
      }

      normalizeSalesReportGrandTotal(clonedDoc.body);

      if (isVatNetPayableReport(routeKey)) {
        clonedDoc.querySelectorAll('.report-acc-table').forEach(function (tbl) {
          tbl.style.maxWidth = '100%';
          tbl.style.width = '100%';
        });
        clonedDoc.querySelectorAll('.report-vat-net-detail-table, .report-vat-net-detail-totals-table').forEach(function (tbl) {
          tbl.style.width = '100%';
          tbl.style.maxWidth = '100%';
          tbl.style.tableLayout = 'fixed';
        });
        clonedDoc.querySelectorAll('.report-sales-table-wrap').forEach(function (wrap) {
          wrap.style.overflow = 'visible';
          wrap.style.width = '100%';
          wrap.style.maxWidth = '100%';
        });
      }

      if (routeKey === 'report_hr_employees') {
        clonedDoc.querySelectorAll('.report-sales-table-wrap').forEach(function (wrap) {
          wrap.style.overflow = 'visible';
          wrap.style.width = '100%';
          wrap.style.maxWidth = '100%';
        });
        clonedDoc.querySelectorAll('.report-hr-employees-table').forEach(function (tbl) {
          tbl.style.width = '100%';
          tbl.style.maxWidth = '100%';
          tbl.style.tableLayout = 'fixed';
          tbl.style.direction = 'rtl';
        });
        clonedDoc.querySelectorAll('.report-hr-employees-table thead th').forEach(function (th) {
          th.style.background = '#1e5a96';
          th.style.color = '#ffffff';
          th.style.visibility = 'visible';
        });
        clonedDoc.querySelectorAll(
          '.report-hr-employees-table td.col-status, .report-hr-employees-table th.col-status'
        ).forEach(function (cell) {
          cell.style.display = 'table-cell';
          cell.style.visibility = 'visible';
          cell.style.opacity = '1';
          cell.style.color = cell.tagName === 'TH' ? '#ffffff' : '#0f172a';
        });
        if (printArea) {
          printArea.style.width = '100%';
          printArea.style.maxWidth = '100%';
          printArea.style.overflow = 'visible';
        }
      }

      var wm = clonedDoc.querySelector('.doc-print-watermark--overlay');
      if (wm) {
        wm.style.display = 'none';
      }
    }

    function buildStandaloneHtml(pdfOrientation) {
      var title = page.getAttribute('data-report-title') || 'تقرير مبيعات';
      var routeKey = page.getAttribute('data-report-route') || '';
      var logoUrl = routeKey === 'inventory_stocktake' ? '' : getCompanyLogoUrl();
      var bodyAttrs =
        window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
          ? window.DocumentHeader.bodyPrintAttrs(logoUrl, true)
          : '';
      var orient =
        pdfOrientation ||
        (isLandscapePdfRoute(routeKey) ? 'landscape' : 'portrait');
      var areaClass = 'report-sales-print-area';
      if (isHrEmployeesReport()) {
        areaClass += ' report-hr-employees-print';
      }
      return (
        '<!DOCTYPE html><html ' + (typeof appPrintHtmlLangAttrs === 'function' ? appPrintHtmlLangAttrs() : 'lang="ar" dir="rtl"') + '><head><meta charset="utf-8"><title>' +
        title +
        '</title><style>' +
        getPrintStyles(orient) +
        '</style></head><body' +
        bodyAttrs +
        '><div class="' +
        areaClass +
        '">' +
        getPrintAreaHtml() +
        '</div></body></html>'
      );
    }

    function getFileBase() {
      var cust =
        page.getAttribute('data-export-label') ||
        page.getAttribute('data-customer-label') ||
        page.getAttribute('data-rep-label') ||
        'report';
      var from = page.getAttribute('data-from-dmy') || '';
      var to = page.getAttribute('data-to-dmy') || '';
      var asOf = page.getAttribute('data-as-of-dmy') || '';
      var routeKey = page.getAttribute('data-report-route') || '';
      var prefix = 'sales-report';
      if (routeKey === 'report_sales_by_rep') {
        prefix = 'sales-report-rep';
      } else if (routeKey === 'report_sales_by_item') {
        prefix = 'sales-report-item';
      } else if (routeKey === 'report_customer_orders') {
        prefix = 'customer-orders-report';
      } else if (routeKey === 'report_purchases_by_item') {
        prefix = 'purchase-report-item';
      } else if (routeKey === 'report_purchase_returns') {
        prefix = 'purchase-report-returns';
      } else if (routeKey === 'report_sales_returns') {
        prefix = 'sales-report-returns';
      } else if (routeKey === 'report_sales_returns_totals') {
        prefix = 'sales-returns-totals';
      } else if (routeKey === 'report_sales_qty_extra') {
        prefix = 'sales-qty-extra';
      } else if (routeKey === 'report_purchases') {
        prefix = 'purchase-report';
      } else if (routeKey === 'report_customers') {
        prefix = 'customers-report';
      } else if (routeKey === 'report_invoice_tax') {
        prefix = 'invoice-tax';
      } else if (routeKey === 'item_stock_movements' || routeKey === 'report_inventory') {
        prefix = 'item-stock-ledger';
      } else if (routeKey === 'report_warehouse_items') {
        prefix = 'warehouse-items';
      } else if (routeKey === 'report_warehouse_zero_qty') {
        prefix = 'warehouse-zero-qty';
      } else if (routeKey === 'report_warehouse_negative_qty') {
        prefix = 'warehouse-negative-qty';
      } else if (routeKey === 'report_warehouse_financial') {
        prefix = 'warehouse-financial';
      } else if (routeKey === 'report_warehouse_moves') {
        prefix = 'warehouse-moves';
      } else if (routeKey === 'inventory_stocktake') {
        prefix = 'stocktake';
      } else if (
        routeKey === 'report_party_statement' ||
        routeKey === 'report_customer_statement' ||
        routeKey === 'report_supplier_statement'
      ) {
        prefix = 'party-statement';
      } else if (routeKey === 'report_trial_balance' || routeKey === 'report_trial_balance_detailed') {
        prefix = 'trial-balance';
      } else if (routeKey === 'report_chart_of_accounts') {
        prefix = 'chart-of-accounts';
      } else if (routeKey === 'report_income_statement' || routeKey === 'report_income_statement_comprehensive') {
        prefix = 'income-statement';
      } else if (routeKey === 'report_tax_declaration') {
        prefix = 'tax-declaration';
      } else if (routeKey === 'report_vat_net_payable') {
        prefix = 'vat-net-payable';
      } else if (routeKey === 'report_balance_sheet') {
        prefix = 'balance-sheet';
      } else if (routeKey === 'report_general_ledger' || routeKey === 'report_account_statement') {
        prefix = routeKey === 'report_account_statement' ? 'account-statement' : 'general-ledger';
      } else if (routeKey === 'report_incoming_checks') {
        prefix = 'incoming-checks-detail';
      } else if (routeKey === 'report_outgoing_checks') {
        prefix = 'outgoing-checks';
      } else if (routeKey === 'hr_payroll_ss_report' || routeKey === 'hr_payroll_income_tax_report' || routeKey === 'hr_payroll_bank_transfer_report') {
        prefix = routeKey === 'hr_payroll_income_tax_report'
            ? 'income-tax-report'
            : routeKey === 'hr_payroll_bank_transfer_report'
              ? 'bank-transfer-report'
              : 'ss-report';
      } else if (routeKey === 'report_tax_ar3') {
        prefix = 'tax-ar3';
      } else if (routeKey === 'report_receivables_aging') {
        prefix = 'receivables-aging';
      } else if (routeKey === 'report_receivables' || routeKey === 'report_supplier_payables') {
        prefix = 'receivables-report';
      }
      var safe =
        prefix +
        '-' +
        String(cust)
          .replace(/[^\w\u0600-\u06FF\-]+/g, '_')
          .slice(0, 40);
      if (from && to) {
        safe += '_' + from.replace(/\//g, '-') + '_' + to.replace(/\//g, '-');
      } else if (asOf) {
        safe += '_' + asOf.replace(/\//g, '-');
      }
      return safe;
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
      var summaryFit = isReceivablesSummaryMode();
      var salesFit = isSalesCustomerNameFitReport();
      var deliveryFit = isDeliveryReportRoute();
      var salesItemFit = isSalesItemNameFitReport();
      var purchasesFit = isPurchasesAllSuppliersReport();
      var itemStockFit = isItemStockLedgerReport();
      var incomingChecksFit = isVoucherChecksReport();
      var incomeTaxFit = isHrPayrollIncomeTaxReport();
      var needsFit = summaryFit || salesFit || deliveryFit || salesItemFit || purchasesFit || itemStockFit || incomingChecksFit || incomeTaxFit;
      var frameLayoutW = frame.style.width;
      var frameLayoutH = frame.style.height;
      if (needsFit) {
        frame.style.width = itemStockFit ? '297mm' : '210mm';
        frame.style.height = '1px';
      }
      win.document.open();
      win.document.write(fullHtml);
      win.document.close();
      runAfterPrintLayout(win, function () {
        try {
          if (summaryFit) {
            fitReceivablesSummaryPartyNames(win.document);
          }
          if (salesFit) {
            fitSalesReportCustomerNames(win.document);
            setTimeout(function () {
              fitSalesReportCustomerNames(win.document);
            }, 0);
          }
          if (deliveryFit) {
            fitDeliveryReportPrintCells(win.document);
            setTimeout(function () {
              fitDeliveryReportPrintCells(win.document);
            }, 0);
          }
          if (salesItemFit) {
            fitSalesReportItemNames(win.document);
            setTimeout(function () {
              fitSalesReportItemNames(win.document);
            }, 0);
          }
          if (purchasesFit) {
            fitPurchasesReportSupplierNames(win.document);
          }
          if (itemStockFit) {
            fitItemStockLedgerPartyNames(win.document);
          }
          if (incomingChecksFit) {
            fitIncomingChecksReportCells(win.document);
            setTimeout(function () {
              fitIncomingChecksReportCells(win.document);
            }, 0);
          }
          if (incomeTaxFit) {
            fitHrPayrollIncomeTaxReportNames(win.document);
            setTimeout(function () {
              fitHrPayrollIncomeTaxReportNames(win.document);
            }, 0);
          }
          win.focus();
          win.print();
        } catch (e) {}
        frame.style.width = frameLayoutW;
        frame.style.height = frameLayoutH;
      }, needsFit);
    }

    function applyPreviewFits(win) {
      if (!win || !win.document) return;
      try {
        if (isReceivablesSummaryMode()) {
          fitReceivablesSummaryPartyNames(win.document);
        }
        if (isSalesCustomerNameFitReport()) {
          fitSalesReportCustomerNames(win.document);
        }
        if (isDeliveryReportRoute()) {
          fitDeliveryReportPrintCells(win.document);
        }
        if (isSalesItemNameFitReport()) {
          fitSalesReportItemNames(win.document);
        }
        if (isPurchasesAllSuppliersReport()) {
          fitPurchasesReportSupplierNames(win.document);
        }
        if (isItemStockLedgerReport()) {
          fitItemStockLedgerPartyNames(win.document);
        }
        if (isVoucherChecksReport()) {
          fitIncomingChecksReportCells(win.document);
        }
        if (isHrPayrollIncomeTaxReport()) {
          fitHrPayrollIncomeTaxReportNames(win.document);
        }
      } catch (e) {}
    }

    var reportPrintOrientation = null;

    function getDefaultReportPrintOrientation() {
      var routeKey = page.getAttribute('data-report-route') || '';
      return isLandscapePdfRoute(routeKey) ? 'landscape' : 'portrait';
    }

    function getSelectedReportPrintOrientation() {
      if (reportPrintOrientation === 'landscape' || reportPrintOrientation === 'portrait') {
        return reportPrintOrientation;
      }
      if (window.PrintOrientation) {
        return PrintOrientation.get();
      }
      return getDefaultReportPrintOrientation();
    }

    function syncReportPrintOrientationControls(overlay) {
      if (!overlay) return;
      var orient = getSelectedReportPrintOrientation();
      overlay.classList.toggle('report-print-overlay--landscape', orient === 'landscape');
      overlay.classList.toggle('print-overlay--landscape', orient === 'landscape');
      if (window.PrintOrientation) {
        overlay.setAttribute('data-print-orient-current', orient);
        PrintOrientation.syncLayout(overlay);
        return;
      }
      var buttons = overlay.querySelectorAll('[data-print-orient]');
      Array.prototype.forEach.call(buttons, function (btn) {
        var active = btn.getAttribute('data-print-orient') === orient;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }

    function renderReportPrintPreviewFrame() {
      var overlay = document.getElementById('report-print-overlay');
      var frame = overlay && overlay.querySelector('#report-print-preview-frame');
      if (!overlay || !frame) return;
      syncReportPrintOrientationControls(overlay);
      var orient = getSelectedReportPrintOrientation();
      var html = buildStandaloneHtml(orient);
      var win = frame.contentWindow;
      win.document.open();
      win.document.write(html);
      win.document.close();
      runAfterPrintLayout(
        win,
        function () {
          applyPreviewFits(win);
          setTimeout(function () {
            applyPreviewFits(win);
          }, 0);
        },
        true
      );
    }

    function ensureReportPrintOverlay() {
      var overlay = document.getElementById('report-print-overlay');
      if (overlay) {
        return overlay;
      }
      overlay = document.createElement('div');
      overlay.id = 'report-print-overlay';
      overlay.className = 'sales-inv-print-overlay report-print-overlay no-print';
      overlay.setAttribute('hidden', '');
      overlay.innerHTML =
        '<div class="sales-inv-print-overlay-panel report-print-overlay-panel">' +
        '<div class="sales-inv-print-overlay-head">' +
        '<h3 class="sales-inv-print-overlay-title">معاينة الطباعة — شكل الورقة</h3>' +
        '<div class="sales-inv-print-overlay-actions">' +
        '<div class="report-print-orient" role="group" aria-label="اتجاه الطباعة">' +
        '<span class="report-print-orient__label">الاتجاه</span>' +
        '<button type="button" class="report-print-orient__btn" data-print-orient="portrait" aria-pressed="false">طولي</button>' +
        '<button type="button" class="report-print-orient__btn" data-print-orient="landscape" aria-pressed="false">عرضي</button>' +
        '</div>' +
        '<button type="button" class="btn btn-primary btn-sm" id="report-print-confirm">طباعة</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" id="report-print-close">إغلاق</button>' +
        '</div></div>' +
        '<div class="report-print-preview-stage">' +
        '<iframe id="report-print-preview-frame" class="report-print-preview-frame" title="معاينة الطباعة"></iframe>' +
        '</div></div>';
      document.body.appendChild(overlay);

      var closeBtn = overlay.querySelector('#report-print-close');
      var confirmBtn = overlay.querySelector('#report-print-confirm');
      if (closeBtn) {
        closeBtn.addEventListener('click', closeReportPrintPreview);
      }
      if (confirmBtn) {
        confirmBtn.addEventListener('click', confirmReportPrintFromPreview);
      }
      if (window.PrintOrientation) {
        PrintOrientation.enhance(overlay, {
          defaultOrient: getDefaultReportPrintOrientation(),
          onChange: function (orient) {
            reportPrintOrientation = orient;
            renderReportPrintPreviewFrame();
          },
        });
      } else {
        Array.prototype.forEach.call(overlay.querySelectorAll('[data-print-orient]'), function (btn) {
          btn.addEventListener('click', function () {
            var next = btn.getAttribute('data-print-orient');
            if (next !== 'portrait' && next !== 'landscape') return;
            if (getSelectedReportPrintOrientation() === next) return;
            reportPrintOrientation = next;
            renderReportPrintPreviewFrame();
          });
        });
      }
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
          closeReportPrintPreview();
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && !overlay.hidden) {
          closeReportPrintPreview();
        }
      });
      return overlay;
    }

    function closeReportPrintPreview() {
      var overlay = document.getElementById('report-print-overlay');
      if (!overlay) return;
      overlay.hidden = true;
      overlay.setAttribute('hidden', '');
      overlay.style.display = 'none';
    }

    function isReportPrintPreviewOpen() {
      var overlay = document.getElementById('report-print-overlay');
      return !!(overlay && !overlay.hidden);
    }

    function openReportPrintPreview() {
      var overlay = ensureReportPrintOverlay();
      var frame = overlay.querySelector('#report-print-preview-frame');
      if (!frame) {
        printHtmlInFrame(buildStandaloneHtml(getSelectedReportPrintOrientation()));
        return;
      }
      if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
      }

      reportPrintOrientation = getDefaultReportPrintOrientation();
      if (window.PrintOrientation) {
        PrintOrientation.set(reportPrintOrientation, overlay);
        PrintOrientation.markActive(overlay);
      }
      overlay.removeAttribute('hidden');
      overlay.hidden = false;
      overlay.style.display = 'flex';
      overlay.style.zIndex = '10050';
      renderReportPrintPreviewFrame();
    }

    function confirmReportPrintFromPreview() {
      var frame = document.querySelector('#report-print-preview-frame');
      var orient = getSelectedReportPrintOrientation();
      if (frame && frame.contentWindow) {
        try {
          applyPreviewFits(frame.contentWindow);
          frame.contentWindow.focus();
          frame.contentWindow.print();
          return;
        } catch (e) {}
      }
      printHtmlInFrame(buildStandaloneHtml(orient));
    }

    function runReportPrint() {
      if (!hasReportData()) {
        var routeKey = page.getAttribute('data-report-route') || '';
        if (routeKey === 'report_general_ledger' || routeKey === 'report_account_statement') {
          alertMsg('اختر حساباً وحدّد الفترة ثم اضغط «عرض».');
        } else if (routeKey === 'hr_payroll_ss_report' || routeKey === 'hr_payroll_income_tax_report' || routeKey === 'hr_payroll_bank_transfer_report') {
          alertMsg('اختر السنة' + (routeKey === 'hr_payroll_income_tax_report' ? ' ونوع الكشف' : '') + ' والشهر المرحّل ثم اضغط «عرض الكشف».');
        } else if (routeKey === 'report_tax_ar3') {
          alertMsg('اختر السنة الضريبية والموظف ثم اضغط «عرض الشهادة».');
        } else if (routeKey === 'report_purchases') {
          alertMsg('اعرض التقرير أولاً باختيار المورد والفترة ثم «عرض التقرير».');
        } else if (routeKey === 'report_warehouse_items' || routeKey === 'report_warehouse_zero_qty' || routeKey === 'report_warehouse_negative_qty') {
          alertMsg('اختر المستودع ثم اضغط «عرض التقرير» قبل الطباعة.');
        } else {
          alertMsg('اعرض التقرير أولاً (العميل/المندوب والفترة) ثم اضغط «عرض التقرير».');
        }
        return;
      }
      if (isReportPrintPreviewOpen()) {
        confirmReportPrintFromPreview();
        return;
      }
      openReportPrintPreview();
    }

    function getExportHost() {
      var host = document.getElementById('sales-inv-export-host');
      if (!host) {
        host = document.createElement('div');
        host.id = 'sales-inv-export-host';
        host.className = 'sales-inv-export-host';
        host.setAttribute('aria-hidden', 'true');
        document.body.appendChild(host);
      }
      return host;
    }

    function isLandscapePdfRoute(routeKey) {
      return (
        routeKey === 'report_customers' ||
        routeKey === 'report_oracle_customer_statement' ||
        routeKey === 'report_receivables_aging' ||
        isWarehouseItemsReportRoute(routeKey) ||
        routeKey === 'report_trial_balance' ||
        routeKey === 'report_trial_balance_detailed' ||
        routeKey === 'item_stock_movements' ||
        routeKey === 'report_inventory'
      );
    }

    function stagePdfExportHost(host, orientation) {
      return {
        width: host.style.width,
        maxWidth: host.style.maxWidth,
        padding: host.style.padding,
        position: host.style.position,
        left: host.style.left,
        top: host.style.top,
        opacity: host.style.opacity,
        visibility: host.style.visibility,
        zIndex: host.style.zIndex,
        pointerEvents: host.style.pointerEvents,
        _orientation: orientation,
      };
    }

    function applyPdfExportHostStage(host, orientation) {
      host.style.width = orientation === 'landscape' ? '277mm' : '190mm';
      host.style.maxWidth = 'none';
      host.style.padding = orientation === 'landscape' ? '4mm' : '10mm';
      host.style.position = 'fixed';
      host.style.left = '0';
      host.style.top = '0';
      host.style.opacity = '0.01';
      host.style.visibility = 'visible';
      host.style.zIndex = '-9999';
      host.style.pointerEvents = 'none';
    }

    function restorePdfExportHost(host, stash) {
      if (!stash) return;
      host.style.width = stash.width || '';
      host.style.maxWidth = stash.maxWidth || '';
      host.style.padding = stash.padding || '';
      host.style.position = stash.position || '';
      host.style.left = stash.left || '';
      host.style.top = stash.top || '';
      host.style.opacity = stash.opacity || '';
      host.style.visibility = stash.visibility || '';
      host.style.zIndex = stash.zIndex || '';
      host.style.pointerEvents = stash.pointerEvents || '';
    }

    function getPdfCanvasScale(routeKey) {
      if (isItemStockLedgerReport() || isTrialBalanceReportRoute(routeKey)) {
        return 1.35;
      }
      if (isVoucherChecksReport()) {
        return 1.5;
      }
      if (isVatNetPayableReport(routeKey)) {
        return isVatNetDetailView() ? 1.05 : 1.35;
      }
      return 2;
    }

    function getPdfMargins(routeKey, orientation) {
      if (routeKey === 'report_hr_employees') {
        return [10, 10, 12, 10];
      }
      if (routeKey === 'report_receivables_aging') {
        return orientation === 'landscape' ? [6, 5, 10, 5] : [6, 10, 10, 10];
      }
      if (isItemStockLedgerReport()) {
        return orientation === 'landscape' ? [6, 5, 10, 5] : [6, 10, 10, 10];
      }
      if (isVatNetPayableReport(routeKey)) {
        return orientation === 'landscape' ? [4, 4, 10, 4] : [8, 10, 14, 10];
      }
      return [6, 10, 10, 10];
    }

    function reportPdfNoDataMessage(routeKey) {
      if (routeKey === 'item_stock_movements' || routeKey === 'report_inventory') {
        return 'اختر المادة والمستودع ثم اضغط «بحث» لعرض الكشف قبل التصدير.';
      }
      if (isWarehouseItemsReportRoute(routeKey)) {
        return 'اختر المستودع ثم اضغط «عرض التقرير» قبل التصدير.';
      }
      if (routeKey === 'report_general_ledger' || routeKey === 'report_account_statement') {
        return 'اختر حساباً وحدّد الفترة ثم اضغط «عرض».';
      }
      if (routeKey === 'hr_payroll_ss_report' || routeKey === 'hr_payroll_income_tax_report' || routeKey === 'hr_payroll_bank_transfer_report') {
        return 'اختر السنة' + (routeKey === 'hr_payroll_income_tax_report' ? ' ونوع الكشف' : '') + ' والشهر المرحّل ثم اضغط «عرض الكشف».';
      }
      if (routeKey === 'report_tax_ar3') {
        return 'اختر السنة الضريبية والموظف ثم اضغط «عرض الشهادة».';
      }
      if (routeKey === 'report_receivables_aging') {
        return 'اعرض التقرير أولاً باختيار الفلاتر ثم «عرض التقرير».';
      }
      if (routeKey === 'report_purchases') {
        return 'اعرض التقرير أولاً باختيار المورد والفترة ثم «عرض التقرير».';
      }
      return 'اعرض التقرير أولاً باختيار العميل والفترة ثم «عرض التقرير».';
    }

    function getHtml2pdfLib() {
      return typeof html2pdf !== 'undefined' ? html2pdf : typeof window.html2pdf !== 'undefined' ? window.html2pdf : null;
    }

    function buildPdfHtml2pdfOptions(routeKey, pdfOrientation, targetEl) {
      var canvasScale = getPdfCanvasScale(routeKey);
      var capture = measurePdfCaptureElement(targetEl);
      var captureEl = capture.element || targetEl;
      var canvasOpts = {
        scale: canvasScale,
        logging: false,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        scrollX: 0,
        scrollY: 0,
      };
      if (captureEl) {
        var fixedW = capture.width;
        if (page && isVatNetPayableReport(page.getAttribute('data-report-route') || '') && isVatNetDetailView()) {
          fixedW = 1040;
        }
        canvasOpts.windowWidth = fixedW;
        canvasOpts.windowHeight = capture.height;
        canvasOpts.width = fixedW;
        canvasOpts.height = capture.height;
      }
      canvasOpts.onclone = function (clonedDoc) {
        applyPdfCloneDocumentFixes(clonedDoc, routeKey);
      };
      var pagebreak = { mode: ['css', 'legacy'] };
      if (isVatNetPayableReport(routeKey)) {
        pagebreak.avoid = ['.report-vat-net-detail-totals-wrap'];
      } else if (isPeriodInvoiceReportRouteKey(routeKey)) {
        pagebreak.avoid = ['.report-sales-grand-total-wrap', '.report-sales-grand-total-table'];
      }
      return {
        margin: getPdfMargins(routeKey, pdfOrientation),
        image: { type: 'jpeg', quality: 0.92 },
        html2canvas: canvasOpts,
        jsPDF: { unit: 'mm', format: 'a4', orientation: pdfOrientation },
        pagebreak: pagebreak,
      };
    }

    function addPdfPageNumbers(pdf) {
      if (!pdf || !pdf.internal) {
        return pdf;
      }
      var total = pdf.internal.getNumberOfPages();
      var pageWidth = pdf.internal.pageSize.getWidth();
      var pageHeight = pdf.internal.pageSize.getHeight();
      var i;
      for (i = 1; i <= total; i += 1) {
        pdf.setPage(i);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(10);
        pdf.setTextColor(15, 23, 42);
        pdf.text(String(i) + ' / ' + String(total), pageWidth / 2, pageHeight - 5, {
          align: 'center',
        });
      }
      return pdf;
    }

    function runHtml2pdfSave(targetEl, fname, routeKey, pdfOrientation, onDone) {
      var lib = getHtml2pdfLib();
      if (!lib) {
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error('تعذر تحميل مكتبة PDF. أعد تحميل الصفحة.');
        } else {
          alertMsg('تعذر تحميل مكتبة PDF.');
        }
        if (onDone) onDone();
        return;
      }
      var options = Object.assign({ filename: fname }, buildPdfHtml2pdfOptions(routeKey, pdfOrientation, targetEl));
      var captureEl = getPdfCaptureElement(targetEl) || targetEl;
      var worker = lib().set(options).from(captureEl);

      function handlePdfError() {
        if (onDone) onDone();
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error('تعذر إنشاء ملف PDF. جرّب «طباعة» ثم «حفظ كـ PDF» من المتصفح.');
        } else {
          alertMsg('تعذر إنشاء ملف PDF.');
        }
      }

      if (pdfNeedsPageNumbers(routeKey)) {
        worker
          .toContainer()
          .toCanvas()
          .toPdf()
          .get('pdf')
          .then(function (pdf) {
            addPdfPageNumbers(pdf);
            pdf.save(fname);
            if (onDone) onDone();
          })
          .catch(handlePdfError);
        return;
      }

      worker
        .save()
        .then(function () {
          if (onDone) onDone();
        })
        .catch(handlePdfError);
    }

    function waitForDocumentImages(doc, cb, timeoutMs) {
      doc = doc || document;
      var imgs = doc.querySelectorAll('img');
      if (!imgs.length) {
        cb();
        return;
      }
      var pending = imgs.length;
      var done = false;
      var finish = function () {
        if (!done) {
          done = true;
          cb();
        }
      };
      var safety = setTimeout(finish, timeoutMs || 5000);
      Array.prototype.forEach.call(imgs, function (img) {
        if (img.complete && img.naturalWidth > 0) {
          if (--pending <= 0) {
            clearTimeout(safety);
            finish();
          }
        } else {
          img.addEventListener('load', function () {
            if (--pending <= 0) {
              clearTimeout(safety);
              finish();
            }
          });
          img.addEventListener('error', function () {
            if (--pending <= 0) {
              clearTimeout(safety);
              finish();
            }
          });
        }
      });
    }

    function sizePdfCaptureFrame(frame, win, pdfOrientation) {
      var doc = win.document;
      var body = doc.body;
      var docEl = doc.documentElement;
      var frameW = pdfOrientation === 'landscape' ? 1123 : 794;
      frame.style.width = frameW + 'px';
      frame.style.overflow = 'visible';
      if (body) {
        body.style.overflow = 'visible';
        body.style.height = 'auto';
        body.style.minHeight = '0';
        body.style.width = frameW + 'px';
        body.style.maxWidth = frameW + 'px';
        body.style.boxSizing = 'border-box';
      }
      if (docEl) {
        docEl.style.overflow = 'visible';
        docEl.style.height = 'auto';
        docEl.style.minHeight = '0';
      }
      var contentH = Math.max(
        body ? body.scrollHeight : 0,
        body ? body.offsetHeight : 0,
        docEl ? docEl.scrollHeight : 0,
        docEl ? docEl.offsetHeight : 0,
        600
      );
      frame.style.height = contentH + 'px';
    }

    function downloadPdfViaPrintFrame(fname, routeKey, pdfOrientation) {
      var frame = getPrintFrame();
      var prevFrameStyle = {
        width: frame.style.width,
        height: frame.style.height,
        position: frame.style.position,
        left: frame.style.left,
        top: frame.style.top,
        opacity: frame.style.opacity,
        visibility: frame.style.visibility,
        zIndex: frame.style.zIndex,
        border: frame.style.border,
      };
      frame.style.width = pdfOrientation === 'landscape' ? '1123px' : '794px';
      frame.style.height = '600px';
      frame.style.position = 'fixed';
      frame.style.left = '0';
      frame.style.top = '0';
      frame.style.opacity = '0.01';
      frame.style.visibility = 'visible';
      frame.style.zIndex = '-9999';
      frame.style.border = '0';

      var win = frame.contentWindow;
      win.document.open();
      win.document.write(buildStandaloneHtml(pdfOrientation));
      win.document.close();

      function restoreFrame() {
        frame.style.width = prevFrameStyle.width || '';
        frame.style.height = prevFrameStyle.height || '';
        frame.style.position = prevFrameStyle.position || '';
        frame.style.left = prevFrameStyle.left || '';
        frame.style.top = prevFrameStyle.top || '';
        frame.style.opacity = prevFrameStyle.opacity || '';
        frame.style.visibility = prevFrameStyle.visibility || '';
        frame.style.zIndex = prevFrameStyle.zIndex || '';
        frame.style.border = prevFrameStyle.border || '';
      }

      runAfterPrintLayout(
        win,
        function () {
          sizePdfCaptureFrame(frame, win, pdfOrientation);
          if (isItemStockLedgerReport()) {
            fitItemStockLedgerPartyNames(win.document);
          }
          if (isReceivablesSummaryMode()) {
            fitReceivablesSummaryPartyNames(win.document);
          }
          if (isSalesCustomerNameFitReport()) {
            fitSalesReportCustomerNames(win.document);
          }
          if (isDeliveryReportRoute()) {
            fitDeliveryReportPrintCells(win.document);
          }
          if (isSalesItemNameFitReport()) {
            fitSalesReportItemNames(win.document);
          }
          if (isPurchasesAllSuppliersReport()) {
            fitPurchasesReportSupplierNames(win.document);
          }
          if (isVoucherChecksReport()) {
            fitIncomingChecksReportCells(win.document);
          }
          waitForDocumentImages(win.document, function () {
            sizePdfCaptureFrame(frame, win, pdfOrientation);
            var target = win.document.body;
            runHtml2pdfSave(target, fname, routeKey, pdfOrientation, restoreFrame);
          });
        },
        true
      );
    }

    function downloadPdf() {
      if (!hasReportData()) {
        alertMsg(reportPdfNoDataMessage(page.getAttribute('data-report-route') || ''));
        return;
      }
      if (!getHtml2pdfLib()) {
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error('تعذر تحميل مكتبة PDF. تحقق من الاتصال ثم أعد تحميل الصفحة.');
        } else {
          alertMsg('تعذر تحميل مكتبة PDF.');
        }
        return;
      }
      var fname = getFileBase() + '.pdf';
      var routeKey = page.getAttribute('data-report-route') || '';
      var pdfOrientation = isLandscapePdfRoute(routeKey) ? 'landscape' : 'portrait';
      if (isVatNetPayableReport(routeKey) && isVatNetDetailView()) {
        pdfOrientation = 'landscape';
      }

      if (
        isTrialBalanceReportRoute(routeKey) ||
        isVoucherChecksReport() ||
        isHrEmployeesReport()
      ) {
        downloadPdfViaPrintFrame(fname, routeKey, pdfOrientation);
        return;
      }

      var host = getExportHost();
      var printWrapStart = isVatNetPayableReport(routeKey)
        ? '<div class="report-vat-net-page report-vat-net-pdf-root"><div class="report-sales-print-area" style="padding:4px;">'
        : isHrEmployeesReport()
          ? '<div class="report-sales-print-area report-hr-employees-print" style="padding:4px;">'
          : '<div class="report-sales-print-area" style="padding:4px;">';
      var printWrapEnd = isVatNetPayableReport(routeKey) ? '</div></div>' : '</div>';
      host.innerHTML =
        '<style>' +
        getPrintStyles(pdfOrientation) +
        '</style>' +
        printWrapStart +
        getPrintAreaHtml() +
        printWrapEnd;

      var hostStash = stagePdfExportHost(host, pdfOrientation);
      applyPdfExportHostStage(host, pdfOrientation);
      if (isVatNetPayableReport(routeKey)) {
        var hostW = pdfOrientation === 'landscape' ? '277mm' : '190mm';
        host.style.width = hostW;
        host.style.maxWidth = hostW;
        host.style.height = 'auto';
        host.style.minHeight = '0';
        host.style.overflow = 'visible';
        host.style.boxSizing = 'border-box';
        var printArea = host.querySelector('.report-sales-print-area');
        if (printArea) {
          printArea.style.width = '100%';
          printArea.style.maxWidth = '100%';
          printArea.style.minWidth = '0';
          printArea.style.overflow = 'visible';
          printArea.style.boxSizing = 'border-box';
        }
      }

      function applyPdfFits(done) {
        if (isReceivablesSummaryMode()) {
          fitReceivablesSummaryPartyNames(host);
        }
        if (isSalesCustomerNameFitReport()) {
          fitSalesReportCustomerNames(host);
        }
        if (isDeliveryReportRoute()) {
          fitDeliveryReportPrintCells(host);
        }
        if (isSalesItemNameFitReport()) {
          fitSalesReportItemNames(host);
        }
        if (isPurchasesAllSuppliersReport()) {
          fitPurchasesReportSupplierNames(host);
        }
        if (isItemStockLedgerReport()) {
          fitItemStockLedgerPartyNames(host);
        }
        if (isVoucherChecksReport()) {
          fitIncomingChecksReportCells(host);
        }
        if (isHrPayrollIncomeTaxReport()) {
          fitHrPayrollIncomeTaxReportNames(host);
        }
        if (done) done();
      }

      function cleanupPdfHost() {
        restorePdfExportHost(host, hostStash);
        host.innerHTML = '';
      }

      runAfterPrintLayout(
        window,
        function () {
          applyPdfFits(function () {
            waitForDocumentImages(host, function () {
              var target = host.querySelector('.report-sales-print-area') || host;
              if (isVatNetPayableReport(routeKey)) {
                host.style.height = 'auto';
                host.style.overflow = 'visible';
                target.style.height = 'auto';
                target.style.overflow = 'visible';
              }
              runHtml2pdfSave(target, fname, routeKey, pdfOrientation, cleanupPdfHost);
            });
          });
        },
        true,
        isVatNetPayableReport(routeKey) && isVatNetDetailView() ? 650 : undefined
      );
    }

    function downloadExcel() {
      if (!hasReportData()) {
        var routeKey = page.getAttribute('data-report-route') || '';
        if (routeKey === 'report_general_ledger' || routeKey === 'report_account_statement') {
          alertMsg('اختر حساباً وحدّد الفترة ثم اضغط «عرض».');
        } else if (routeKey === 'hr_payroll_ss_report' || routeKey === 'hr_payroll_income_tax_report' || routeKey === 'hr_payroll_bank_transfer_report') {
          alertMsg('اختر السنة' + (routeKey === 'hr_payroll_income_tax_report' ? ' ونوع الكشف' : '') + ' والشهر المرحّل ثم اضغط «عرض الكشف».');
        } else if (routeKey === 'report_tax_ar3') {
          alertMsg('اختر السنة الضريبية والموظف ثم اضغط «عرض الشهادة».');
        } else if (routeKey === 'report_purchases') {
          alertMsg('اعرض التقرير أولاً باختيار المورد والفترة ثم «عرض التقرير».');
        } else {
          alertMsg('اعرض التقرير أولاً باختيار العميل والفترة ثم «عرض التقرير».');
        }
        return;
      }
      var html = buildStandaloneHtml();
      var blob = new Blob(['\uFEFF' + html], {
        type: 'application/vnd.ms-excel;charset=utf-8',
      });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = getFileBase() + '.xls';
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () {
        URL.revokeObjectURL(url);
        a.remove();
      }, 200);
    }

    document.addEventListener(
      'master-toolbar',
      function (e) {
        if (!e.detail || !isReportExportRoute()) return;
        var action = e.detail.action;
        if (action === 'print') {
          e.preventDefault();
          e.stopImmediatePropagation();
          runReportPrint();
        } else if (action === 'pdf') {
          e.preventDefault();
          e.stopImmediatePropagation();
          downloadPdf();
        } else if (action === 'excel') {
          e.preventDefault();
          e.stopImmediatePropagation();
          downloadExcel();
        }
      },
      true
    );

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReportSalesExport);
  } else {
    initReportSalesExport();
  }
})();
