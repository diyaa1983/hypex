(function () {
  'use strict';

  var cfg = window.MPartyStatement || {};
  var TB = window.MobileToolbar || {};
  var printDoc = null;
  var typeRadios = document.querySelectorAll('input[name="m_ps_type"]');
  var pickCust = document.getElementById('m-ps-pick-customer');
  var pickSupp = document.getElementById('m-ps-pick-supplier');
  var custId = document.getElementById('m-ps-customer-id');
  var suppId = document.getElementById('m-ps-supplier-id');
  var fromEl = document.getElementById('m-ps-from');
  var toEl = document.getElementById('m-ps-to');
  var loadingEl = document.getElementById('m-ps-loading');
  var resultEl = document.getElementById('m-ps-result');
  var linesBody = document.getElementById('m-ps-lines-body');
  var linesFoot = document.getElementById('m-ps-lines-foot');
  var titleEl = document.getElementById('m-ps-result-title');
  var btnPrint = TB.btn ? TB.btn('print') : null;
  var btnPdf = TB.btn ? TB.btn('pdf') : null;

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function fmtDateIso(iso) {
    if (!iso || iso.indexOf('-') < 0) return iso;
    var p = iso.split('-');
    if (p.length === 3) return p[2] + '-' + p[1] + '-' + p[0];
    return iso;
  }

  function fmtMoney(n) {
    var v = parseFloat(n) || 0;
    var s = v.toFixed(3);
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts[1] === '000' ? parts[0] : parts[0] + '.' + parts[1].replace(/0+$/, '');
  }

  function getPartyType() {
    var r = document.querySelector('input[name="m_ps_type"]:checked');
    return r && r.value === 'supplier' ? 'supplier' : 'customer';
  }

  function clearCustomerPick() {
    if (custId) custId.value = '';
    var lbl = document.getElementById('m-ps-customer-label');
    var chosen = document.getElementById('m-ps-customer-chosen');
    var openBtn = document.getElementById('m-ps-open-customer');
    if (lbl) lbl.textContent = '';
    if (chosen) chosen.hidden = true;
    if (openBtn) openBtn.hidden = false;
  }

  function clearSupplierPick() {
    if (suppId) suppId.value = '';
    var lbl = document.getElementById('m-ps-supplier-label');
    var chosen = document.getElementById('m-ps-supplier-chosen');
    var openBtn = document.getElementById('m-ps-open-supplier');
    if (lbl) lbl.textContent = '';
    if (chosen) chosen.hidden = true;
    if (openBtn) openBtn.hidden = false;
  }

  function syncTypeUi() {
    var t = getPartyType();
    var isCustomer = t === 'customer';
    if (pickCust) {
      pickCust.classList.toggle('m-ps-pick-block--off', !isCustomer);
      pickCust.setAttribute('aria-hidden', isCustomer ? 'false' : 'true');
    }
    if (pickSupp) {
      pickSupp.classList.toggle('m-ps-pick-block--off', isCustomer);
      pickSupp.setAttribute('aria-hidden', isCustomer ? 'true' : 'false');
    }
    if (isCustomer) {
      clearSupplierPick();
    } else {
      clearCustomerPick();
    }
    if (resultEl) resultEl.hidden = true;
    var summaryReset = document.getElementById('m-ps-summary');
    if (summaryReset) summaryReset.hidden = true;
    setExportButtons(false);
    printDoc = null;
  }

  function setExportButtons(enabled) {
    if (!enabled) {
      if (TB.show) TB.show({ run: true }, { cols: 1 });
      return;
    }
    if (TB.show) TB.show({ run: true, print: true, pdf: true }, { cols: 3 });
    if (btnPrint) btnPrint.disabled = false;
    if (btnPdf) btnPdf.disabled = false;
  }

  typeRadios.forEach(function (radio) {
    radio.addEventListener('change', syncTypeUi);
  });
  syncTypeUi();

  var clearCustBtn = document.getElementById('m-ps-clear-customer');
  var clearSuppBtn = document.getElementById('m-ps-clear-supplier');
  if (clearCustBtn) clearCustBtn.addEventListener('click', clearCustomerPick);
  if (clearSuppBtn) clearSuppBtn.addEventListener('click', clearSupplierPick);

  function buildPicker(gridId, emptyId, list, onPick) {
    var grid = document.getElementById(gridId);
    var empty = document.getElementById(emptyId);
    var searchEl = gridId === 'm-ps-customer-grid' ? document.getElementById('m-ps-customer-search') : document.getElementById('m-ps-supplier-search');
    var colors = ['#4f46e5', '#0d9488', '#d97706', '#db2777', '#2563eb', '#7c3aed'];

    function render(filter) {
      if (!grid) return;
      var q = String(filter || '').trim().toLowerCase();
      var items = (list || []).filter(function (c) {
        return !q || String(c.name_ar || '').toLowerCase().indexOf(q) >= 0;
      });
      if (!items.length) {
        grid.innerHTML = '';
        if (empty) empty.hidden = false;
        return;
      }
      if (empty) empty.hidden = true;
      grid.innerHTML = items
        .map(function (c, i) {
          var letter = String(c.name_ar || '?').trim().charAt(0) || '?';
          var bg = colors[i % colors.length];
          return (
            '<button type="button" class="m-customer-grid-item" data-id="' +
            escapeHtml(String(c.id)) +
            '" data-name="' +
            escapeHtml(String(c.name_ar || '')) +
            '">' +
            '<span class="m-customer-avatar" style="background:' +
            bg +
            '">' +
            escapeHtml(letter) +
            '</span>' +
            '<span class="m-customer-grid-name">' +
            escapeHtml(String(c.name_ar || '')) +
            '</span></button>'
          );
        })
        .join('');
      grid.querySelectorAll('.m-customer-grid-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
          onPick(parseInt(btn.getAttribute('data-id'), 10) || 0, btn.getAttribute('data-name') || '');
        });
      });
    }

    if (searchEl) {
      searchEl.addEventListener('input', function () {
        render(searchEl.value);
      });
    }
    render('');
    return render;
  }

  var custPicker = document.getElementById('m-ps-customer-picker');
  var suppPicker = document.getElementById('m-ps-supplier-picker');

  buildPicker('m-ps-customer-grid', 'm-ps-customer-empty', cfg.customers || [], function (id, name) {
    if (getPartyType() !== 'customer') return;
    clearSupplierPick();
    if (custId) custId.value = String(id);
    var lbl = document.getElementById('m-ps-customer-label');
    var chosen = document.getElementById('m-ps-customer-chosen');
    var openBtn = document.getElementById('m-ps-open-customer');
    if (lbl) lbl.textContent = name;
    if (chosen) chosen.hidden = false;
    if (openBtn) openBtn.hidden = true;
    if (custPicker) {
      custPicker.hidden = true;
      custPicker.setAttribute('aria-hidden', 'true');
    }
  });

  buildPicker('m-ps-supplier-grid', 'm-ps-supplier-empty', cfg.suppliers || [], function (id, name) {
    if (getPartyType() !== 'supplier') return;
    clearCustomerPick();
    if (suppId) suppId.value = String(id);
    var lbl = document.getElementById('m-ps-supplier-label');
    var chosen = document.getElementById('m-ps-supplier-chosen');
    var openBtn = document.getElementById('m-ps-open-supplier');
    if (lbl) lbl.textContent = name;
    if (chosen) chosen.hidden = false;
    if (openBtn) openBtn.hidden = true;
    if (suppPicker) {
      suppPicker.hidden = true;
      suppPicker.setAttribute('aria-hidden', 'true');
    }
  });

  function openPicker(el) {
    if (!el) return;
    el.hidden = false;
    el.removeAttribute('hidden');
    el.setAttribute('aria-hidden', 'false');
  }

  var btnCust = document.getElementById('m-ps-open-customer');
  var btnSupp = document.getElementById('m-ps-open-supplier');
  if (btnCust) btnCust.addEventListener('click', function () { openPicker(custPicker); });
  if (btnSupp) btnSupp.addEventListener('click', function () { openPicker(suppPicker); });
  var closeCust = document.getElementById('m-ps-customer-close');
  var closeSupp = document.getElementById('m-ps-supplier-close');
  if (closeCust)
    closeCust.addEventListener('click', function () {
      if (custPicker) {
        custPicker.hidden = true;
        custPicker.setAttribute('aria-hidden', 'true');
      }
    });
  if (closeSupp)
    closeSupp.addEventListener('click', function () {
      if (suppPicker) {
        suppPicker.hidden = true;
        suppPicker.setAttribute('aria-hidden', 'true');
      }
    });

  function moneyCell(n) {
    var v = parseFloat(n) || 0;
    if (Math.abs(v) < 0.000001) return '—';
    return escapeHtml(fmtMoney(v));
  }

  function tableRow(cells, rowClass) {
    return (
      '<tr' +
      (rowClass ? ' class="' + rowClass + '"' : '') +
      '>' +
      cells.join('') +
      '</tr>'
    );
  }

  function tdDate(dmy) {
    return '<td class="m-ps-td m-ps-td--date" dir="ltr">' + escapeHtml(dmy) + '</td>';
  }

  function tdDesc(text, emphasis) {
    return (
      '<td class="m-ps-td m-ps-td--desc' +
      (emphasis ? ' m-ps-td--emph' : '') +
      '">' +
      escapeHtml(text) +
      '</td>'
    );
  }

  function tdDoc(refNo, hintText) {
    var ref = String(refNo || '').trim();
    var hint = String(hintText || '').trim();
    var inner = '—';
    if (ref !== '') {
      inner = '<span class="m-ps-doc-no">' + escapeHtml(ref) + '</span>';
      if (hint !== '') {
        inner += '<span class="m-ps-doc-hint">' + escapeHtml(hint) + '</span>';
      }
    }
    return '<td class="m-ps-td m-ps-td--doc">' + inner + '</td>';
  }

  function tdMoney(n, strong) {
    return (
      '<td class="m-ps-td m-ps-td--money' +
      (strong ? ' m-ps-td--strong' : '') +
      '" dir="ltr">' +
      moneyCell(n) +
      '</td>'
    );
  }

  function renderLines(data) {
    if (!linesBody) return;
    var rows = data.rows || [];
    var summaryEl = document.getElementById('m-ps-summary');
    if (summaryEl) {
      summaryEl.hidden = false;
      summaryEl.removeAttribute('hidden');
      var typeLbl = data.party_type === 'supplier' ? 'مورد' : 'عميل';
      var repName = String(data.sales_rep_name || data.sales_rep_names || '').trim();
      summaryEl.innerHTML =
        '<p class="m-ps-summary-type">' +
        escapeHtml(typeLbl) +
        '</p>' +
        '<p class="m-ps-summary-name">' +
        escapeHtml(data.party_name || '') +
        '</p>' +
        (repName
          ? '<p class="m-ps-summary-rep">المندوب: ' + escapeHtml(repName) + '</p>'
          : '') +
        '<p class="m-ps-summary-period muted">' +
        'من ' +
        escapeHtml(data.from_dmy || '') +
        ' إلى ' +
        escapeHtml(data.to_dmy || '') +
        '</p>';
    }

    var bodyHtml = '';
    if (!rows.length) {
      bodyHtml +=
        '<tr class="m-ps-tr m-ps-tr--empty"><td colspan="6" class="m-ps-td m-ps-td--empty">لا توجد حركات في هذه الفترة.</td></tr>';
    }
    rows.forEach(function (r) {
      bodyHtml += tableRow(
        [
          tdDate(fmtDateIso(String(r.date || ''))),
          tdDesc(String(r.description || '—'), false),
          tdDoc(r.ref_no, r.doc_hint),
          tdMoney(r.debit, false),
          tdMoney(r.credit, false),
          tdMoney(r.balance, true),
        ],
        'm-ps-tr'
      );
    });
    linesBody.innerHTML = bodyHtml;

    var od = parseFloat(data.opening_debit) || 0;
    var oc = parseFloat(data.opening_credit) || 0;
    var footerDebit = od + (parseFloat(data.total_debit) || 0);
    var footerCredit = oc + (parseFloat(data.total_credit) || 0);
    if (linesFoot) {
      linesFoot.hidden = false;
      linesFoot.removeAttribute('hidden');
      linesFoot.innerHTML = tableRow(
        [
          '<td colspan="3" class="m-ps-td m-ps-td--foot-label"><strong>المجموع</strong></td>',
          tdMoney(footerDebit, true),
          tdMoney(footerCredit, true),
          tdMoney(data.closing_balance, true),
        ],
        'm-ps-tr m-ps-tr--foot'
      );
    }
  }

  function parseJsonResponse(r) {
    return r.json().catch(function () {
      return { ok: false, message: 'تعذر قراءة رد الخادم.' };
    });
  }

  function apiErrorMessage(err, fallback) {
    if (err && err.message && String(err.message).trim() !== '') {
      return String(err.message);
    }
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return 'لا يوجد اتصال بالإنترنت.';
    }
    return fallback || 'تعذر الاتصال بالخادم.';
  }

  function buildApiUrl(extraParams) {
    var pt = getPartyType();
    var pid = pt === 'supplier' ? parseInt(suppId && suppId.value, 10) : parseInt(custId && custId.value, 10);
    var q =
      '?party_type=' +
      encodeURIComponent(pt) +
      '&party_id=' +
      pid +
      '&from=' +
      encodeURIComponent(fromEl ? fromEl.value : '') +
      '&to=' +
      encodeURIComponent(toEl ? toEl.value : '');
    if (extraParams) {
      Object.keys(extraParams).forEach(function (k) {
        q += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(extraParams[k]));
      });
    }
    return (cfg.apiUrl || '') + q;
  }

  function fetchReport() {
    var pt = getPartyType();
    var pid = pt === 'supplier' ? parseInt(suppId && suppId.value, 10) : parseInt(custId && custId.value, 10);
    if (!pid) {
      if (window.AppDialog && AppDialog.error) AppDialog.error(pt === 'supplier' ? 'اختر المورد.' : 'اختر العميل.');
      return Promise.reject();
    }
    if (!cfg.apiUrl) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('عنوان واجهة الكشف غير مضبوط.');
      return Promise.reject();
    }
    if (loadingEl) loadingEl.hidden = false;
    if (resultEl) resultEl.hidden = true;
    setExportButtons(false);
    return fetch(buildApiUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return parseJsonResponse(r).then(function (data) {
          if (!r.ok || !data || !data.ok) {
            throw new Error(
              (data && data.message) ||
                (r.status === 403 ? 'لا توجد صلاحية لعرض كشف الحساب.' : 'تعذر تحميل الكشف.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        printDoc = data;
        if (titleEl) {
          titleEl.textContent = 'نتيجة الكشف';
        }
        renderLines(data);
        if (resultEl) {
          resultEl.hidden = false;
          resultEl.removeAttribute('hidden');
        }
        setExportButtons(true);
        return data;
      })
      .catch(function (err) {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(apiErrorMessage(err, 'تعذر تحميل الكشف.'));
        }
        throw err;
      });
  }

  function fetchPrintDocument() {
    if (printDoc && printDoc.html) {
      return Promise.resolve(printDoc);
    }
    if (!cfg.apiUrl) {
      return Promise.reject(new Error('عنوان واجهة الكشف غير مضبوط.'));
    }
    return fetch(buildApiUrl({ print: '1' }), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return parseJsonResponse(r).then(function (data) {
          if (!r.ok || !data || !data.ok) {
            throw new Error((data && data.message) || 'تعذر تجهيز الطباعة.');
          }
          return data;
        });
      })
      .then(function (data) {
        printDoc = printDoc ? Object.assign(printDoc, data) : data;
        return printDoc;
      });
  }

  var runBtn = TB.btn ? TB.btn('run') : document.getElementById('m-ps-run');
  if (runBtn) runBtn.addEventListener('click', function () { fetchReport(); });

  function printHtml(html) {
    if (window.MobileDocList && MobileDocList.printHtml) {
      MobileDocList.printHtml(html, 'm-ps-print-frame');
      return;
    }
    var frame = document.getElementById('m-ps-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'm-ps-print-frame';
      frame.className = 'm-print-frame';
      document.body.appendChild(frame);
    }
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (_e) {}
    }, 450);
  }

  function injectPdfStyles(css) {
    var el = document.getElementById('m-ps-pdf-style');
    if (!el) {
      el = document.createElement('style');
      el.id = 'm-ps-pdf-style';
      document.head.appendChild(el);
    }
    el.textContent = css || '';
  }

  function getPdfOverlay() {
    var overlay = document.getElementById('m-ps-pdf-overlay');
    var preview = document.getElementById('m-ps-pdf-preview');
    if (!overlay || !preview) return null;
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    return { overlay: overlay, preview: preview };
  }

  function downloadPdf() {
    if (!printDoc) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('اعرض الكشف أولاً ثم حاول التصدير.');
      return;
    }
    var fname =
      window.MobilePdfFilename && MobilePdfFilename.partyStatement
        ? MobilePdfFilename.partyStatement(
            printDoc.party_name || printDoc.party_code,
            printDoc.party_type,
            printDoc.from_dmy,
            printDoc.to_dmy
          )
        : 'كشف حساب - ' + (printDoc.party_name || 'report') + '.pdf';

    if (btnPdf) btnPdf.disabled = true;

    function doneOk() {
      if (btnPdf) btnPdf.disabled = false;
    }

    function runPdfExport() {
      if (window.MobilePdfExport && MobilePdfExport.exportMobilePdf) {
        return MobilePdfExport.exportMobilePdf(printDoc, { filename: fname, delayMs: 550 });
      }
      if (typeof window.html2pdf === 'undefined') {
        return Promise.reject(new Error('no_html2pdf'));
      }
      var fullDoc = printDoc.html || printDoc.html_pdf || '';
      if (!fullDoc) {
        return Promise.reject(new Error('no_html'));
      }
      if (window.MobilePdfExport && MobilePdfExport.runIframe) {
        return MobilePdfExport.runIframe({
          fullDocument: fullDoc,
          filename: fname,
          margin: [6, 10, 10, 10],
          delayMs: 600,
        });
      }
      return Promise.reject(new Error('no_export'));
    }

    var prep = printDoc.html || printDoc.html_pdf ? Promise.resolve(printDoc) : fetchPrintDocument();
    prep
      .then(runPdfExport)
      .then(doneOk)
      .catch(function (err) {
        doneOk();
        if (err && err.message === 'no_html2pdf') {
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error('مكتبة PDF غير محمّلة. أعد تحميل الصفحة.');
          }
          return;
        }
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(apiErrorMessage(err, 'تعذر تنزيل PDF.'));
        }
      });
  }

  if (btnPrint) {
    btnPrint.addEventListener('click', function () {
      if (!printDoc) {
        if (window.AppDialog && AppDialog.error) AppDialog.error('اعرض الكشف أولاً.');
        return;
      }
      if (btnPrint) btnPrint.disabled = true;
      fetchPrintDocument()
        .then(function (doc) {
          if (btnPrint) btnPrint.disabled = false;
          if (doc && doc.html) {
            printHtml(doc.html);
            return;
          }
          throw new Error('تعذر تجهيز الطباعة.');
        })
        .catch(function (err) {
          if (btnPrint) btnPrint.disabled = false;
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error(apiErrorMessage(err, 'تعذر الاتصال بالخادم.'));
          }
        });
    });
  }
  if (btnPdf) {
    btnPdf.addEventListener('click', downloadPdf);
  }
})();
