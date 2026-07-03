(function () {
  'use strict';

  var cfg = window.MInvoiceView || {};
  var dp = cfg.decimalPlaces != null ? cfg.decimalPlaces : 2;
  var loadingEl = document.getElementById('m-inv-view-loading');
  var rootEl = document.getElementById('m-inv-view-root');
  var invoiceData = null;
  var printDoc = null;
  var printLoading = false;
  var TB = window.MobileToolbar || {};

  function roundN(n) {
    var p = Math.pow(10, dp);
    return Math.round(n * p) / p;
  }

  function fmt(n) {
    return roundN(n).toFixed(dp);
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  /** تأكيد يعمل على الهاتف (يتطلب ui-dialog.css في التخطيط) */
  function mobileConfirm(message, options) {
    options = options || {};
    var title = options.title || 'تأكيد';
    var okText = options.okText || 'نعم';
    var cancelText = options.cancelText || 'إلغاء';
    if (window.AppDialog && typeof AppDialog.confirm === 'function') {
      return AppDialog.confirm(String(message), {
        title: title,
        okText: okText,
        cancelText: cancelText,
        danger: !!options.danger,
        type: options.danger ? 'warning' : 'confirm',
      }).then(function (ok) {
        return !!ok;
      });
    }
    return Promise.resolve(window.confirm(String(message)));
  }

  function parseJsonResponse(r) {
    return r
      .json()
      .catch(function () {
        return { ok: false, error: 'تعذر قراءة رد الخادم.' };
      });
  }

  function fetchPrintDocument(force) {
    if (!force && printDoc) {
      return Promise.resolve(printDoc);
    }
    if (!cfg.printApi || !cfg.invoiceId) {
      return Promise.reject(new Error('no_print_api'));
    }
    if (printLoading) {
      return new Promise(function (resolve, reject) {
        var tries = 0;
        var t = setInterval(function () {
          tries++;
          if (printDoc) {
            clearInterval(t);
            resolve(printDoc);
          } else if (!printLoading || tries > 80) {
            clearInterval(t);
            reject(new Error('timeout'));
          }
        }, 100);
      });
    }
    printLoading = true;
    var url = cfg.printApi + '?id=' + encodeURIComponent(String(cfg.invoiceId));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        printLoading = false;
        if (!data || !data.ok || !data.html) {
          throw new Error('bad_response');
        }
        printDoc = {
          html: data.html,
          html_pdf: data.html_pdf || data.html,
          inner_pdf: data.inner_pdf || data.inner || '',
          innerPdf: data.inner_pdf || data.inner || '',
          styles: data.styles || '',
          stylesPdf: data.styles_pdf || data.styles || '',
          styles_pdf: data.styles_pdf || data.styles || '',
          pdf_download_url: data.pdf_download_url || '',
          mobile_pdf: !!data.mobile_pdf,
        };
        return printDoc;
      })
      .catch(function (err) {
        printLoading = false;
        throw err;
      });
  }

  function printHtml(html) {
    var frame = document.getElementById('m-inv-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'm-inv-print-frame';
      frame.style.cssText = 'position:fixed;left:-9999px;width:0;height:0;border:0;';
      document.body.appendChild(frame);
    }
    var doc = frame.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
    setTimeout(function () {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    }, 500);
  }

  function waitForImages(root, cb) {
    if (!root) {
      cb();
      return;
    }
    var imgs = root.querySelectorAll('img');
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
    var safety = setTimeout(finish, 5000);
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

  function stripPdfWatermarks(html) {
    var box = document.createElement('div');
    box.innerHTML = html;
    box.querySelectorAll('.doc-print-watermark, .doc-print-watermark--overlay').forEach(function (el) {
      el.parentNode.removeChild(el);
    });
    var root = box.querySelector('.doc-print-watermark-root');
    if (root && root.parentNode) {
      while (root.firstChild) {
        root.parentNode.insertBefore(root.firstChild, root);
      }
      root.parentNode.removeChild(root);
    }
    return box.innerHTML;
  }

  function injectPdfStyles(css) {
    var el = document.getElementById('m-inv-pdf-style');
    if (!el) {
      el = document.createElement('style');
      el.id = 'm-inv-pdf-style';
      document.head.appendChild(el);
    }
    el.textContent = css || '';
  }

  function clearPdfStyles() {
    var el = document.getElementById('m-inv-pdf-style');
    if (el) el.textContent = '';
  }

  function getPdfOverlay() {
    var overlay = document.getElementById('m-inv-pdf-overlay');
    var preview = document.getElementById('m-inv-pdf-preview');
    if (!overlay || !preview) return null;
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    return { overlay: overlay, preview: preview };
  }

  function downloadPdf(doc) {
    doc = doc || printDoc;
    if (!doc) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تجهيز PDF.');
      return;
    }
    var invNo = invoiceData && invoiceData.invoice_no ? invoiceData.invoice_no : cfg.invoiceId;
    var custName = invoiceData && invoiceData.customer_name ? invoiceData.customer_name : '';
    var fname =
      window.MobilePdfFilename && MobilePdfFilename.invoice
        ? MobilePdfFilename.invoice(invNo, custName)
        : 'فاتورة - ' + (invNo || 'doc') + '.pdf';

    if (window.MobilePdfExport && MobilePdfExport.exportMobilePdf) {
      return MobilePdfExport.exportMobilePdf(doc, { filename: fname, delayMs: 550 }).catch(function () {
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تنزيل PDF.');
      });
    }

    if (typeof window.html2pdf === 'undefined') {
      if (window.AppDialog && AppDialog.error) AppDialog.error('مكتبة PDF غير محمّلة. أعد تحميل الصفحة.');
      return;
    }
    var ui = getPdfOverlay();
    if (!ui) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('عنصر التصدير غير متاح.');
      return;
    }
    var inner = stripPdfWatermarks(doc.innerPdf || '');
    if (!inner) {
      if (window.AppDialog && AppDialog.error) {
        AppDialog.error('تعذر تجهيز محتوى PDF. أعد تحميل الصفحة ثم حاول مرة أخرى.');
      }
      return;
    }

    var overlay = ui.overlay;
    var preview = ui.preview;
    var wasHidden = overlay.hidden;

    injectPdfStyles(doc.stylesPdf || doc.styles || '');
    preview.innerHTML = inner;
    preview.style.width = '210mm';
    preview.style.minWidth = '210mm';
    preview.style.maxWidth = '210mm';

    overlay.removeAttribute('hidden');
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    overlay.style.display = 'block';
    overlay.style.opacity = '0.01';

    var cleanup = function () {
      clearPdfStyles();
      preview.innerHTML = '';
      preview.style.width = '';
      preview.style.minWidth = '';
      preview.style.maxWidth = '';
      overlay.style.opacity = '';
      overlay.style.display = '';
      if (wasHidden) {
        overlay.hidden = true;
        overlay.setAttribute('hidden', '');
        overlay.setAttribute('aria-hidden', 'true');
      }
    };

    var runExport = function () {
      try {
        html2pdf()
          .set({
            margin: [10, 10, 10, 10],
            filename: fname,
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: {
              scale: 2,
              useCORS: true,
              allowTaint: true,
              logging: false,
              backgroundColor: '#ffffff',
            },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'] },
          })
          .from(preview)
          .save()
          .then(cleanup)
          .catch(function () {
            cleanup();
            if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر إنشاء ملف PDF.');
          });
      } catch (_e) {
        cleanup();
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر إنشاء ملف PDF.');
      }
    };

    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        waitForImages(preview, function () {
          setTimeout(runExport, 300);
        });
      });
    });
  }

  function runPrintAction(kind) {
    if (!invoiceData) return;
    var btn = kind === 'pdf' ? (TB.btn ? TB.btn('pdf') : null) : TB.btn ? TB.btn('print') : null;
    if (btn) btn.disabled = true;
    fetchPrintDocument(false)
      .then(function (pdoc) {
        if (btn) btn.disabled = false;
        if (kind === 'pdf') downloadPdf(pdoc);
        else printHtml(pdoc.html);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error('تعذر تجهيز نسخة الطباعة.');
        }
      });
  }

  function setFactWrap(wrapId, ddId, text, show) {
    var wrap = document.getElementById(wrapId);
    var dd = document.getElementById(ddId);
    if (!wrap || !dd) return;
    if (show && String(text || '').trim() !== '') {
      dd.textContent = text;
      wrap.hidden = false;
    } else {
      dd.textContent = '';
      wrap.hidden = true;
    }
  }

  function renderInvoice(inv) {
    invoiceData = inv;
    setText('m-inv-view-no', inv.invoice_no || '—');
    setText('m-inv-view-date', inv.invoice_date || '—');
    var cust = inv.customer_name || '—';
    if (inv.customer_code) cust += ' (' + inv.customer_code + ')';
    setText('m-inv-view-customer', cust);
    setText(
      'm-inv-view-payment',
      inv.payment_label || (inv.payment_type === 'cash' ? 'نقدي' : 'ذمة')
    );
    setFactWrap('m-inv-view-wh-wrap', 'm-inv-view-warehouse', inv.warehouse_name || '', !!inv.warehouse_name);
    setFactWrap('m-inv-view-rep-wrap', 'm-inv-view-rep', inv.sales_rep_name || '', !!inv.sales_rep_name);
    setFactWrap('m-inv-view-notes-wrap', 'm-inv-view-notes', inv.notes || '', !!inv.notes);

    var tagsEl = document.getElementById('m-inv-view-tags');
    if (tagsEl) {
      var tags = inv.is_posted
        ? '<span class="m-tag m-tag--ok">مرحّلة</span>'
        : '<span class="m-tag m-tag--draft">غير مرحّلة</span>';
      if (inv.einv_sent) tags += ' <span class="m-tag m-tag--einv">فوترة</span>';
      tagsEl.innerHTML = tags;
    }
    setText('m-inv-view-sub', fmt(inv.subtotal));
    setText('m-inv-view-tax', fmt(inv.tax_amount));
    setText('m-inv-view-grand', fmt(inv.total));

    var lineRows = inv.lines || [];
    setText('m-inv-view-lines-count', lineRows.length ? lineRows.length + ' بند' : '');

    var linesEl = document.getElementById('m-inv-view-lines');
    if (linesEl) {
      linesEl.innerHTML = '';
      lineRows.forEach(function (ln, idx) {
        var code = (ln.sku || ln.barcode || '').trim();
        var disc = String(ln.line_discount_input || '').trim() || '—';
        var taxPct = ln.tax_rate_percent != null ? fmt(ln.tax_rate_percent) : '—';
        var qExtra = parseFloat(ln.qty_extra) || 0;
        var sub = fmt(ln.line_subtotal != null ? ln.line_subtotal : ln.line_total);
        var card = document.createElement('article');
        card.className = 'm-inv-view-line-card';
        card.setAttribute('role', 'listitem');
        card.innerHTML =
          '<header class="m-inv-view-line-card-head">' +
          '<span class="m-inv-view-line-card-seq">' + (idx + 1) + '</span>' +
          '<div class="m-inv-view-line-card-title">' +
          '<div class="m-inv-view-line-card-name">' + escapeHtml(ln.name_ar || ln.line_desc || '—') + '</div>' +
          (code ? '<div class="m-inv-view-line-card-code muted">' + escapeHtml(code) + '</div>' : '') +
          '</div>' +
          '<div class="m-inv-view-line-card-gross">' + fmt(ln.line_gross) + '</div>' +
          '</header>' +
          '<dl class="m-inv-view-line-card-grid">' +
          '<div><dt>كمية</dt><dd>' + fmt(ln.qty) + '</dd></div>' +
          '<div><dt>إضافية</dt><dd>' + (qExtra > 0 ? fmt(qExtra) : '—') + '</dd></div>' +
          '<div><dt>السعر</dt><dd>' + fmt(ln.unit_price) + '</dd></div>' +
          '<div><dt>خصم</dt><dd>' + escapeHtml(disc) + '</dd></div>' +
          '<div><dt>قبل الضريبة</dt><dd>' + sub + '</dd></div>' +
          '<div><dt>%ض</dt><dd>' + taxPct + '</dd></div>' +
          '<div><dt>الضريبة</dt><dd>' + fmt(ln.tax_amount) + '</dd></div>' +
          '</dl>';
        linesEl.appendChild(card);
      });
    }
    var canChange = !inv.is_posted && !inv.einv_sent;
    var vis = { print: true, pdf: true };
    if (cfg.canEdit && canChange) vis.edit = true;
    if (cfg.canDelete && canChange) vis.delete = true;
    if (cfg.canPost && !inv.is_posted) vis.post = true;
    if (cfg.canSendEinvoice && inv.is_posted && !inv.einv_sent) vis.einvoice = true;
    var gpsPanel = document.getElementById('m-inv-gps-place-panel');
    if (gpsPanel) {
      gpsPanel.hidden = !!inv.is_posted || !cfg.gpsEnabled || !vis.post;
    }
    if (TB.show) TB.show(vis);
    if (loadingEl) loadingEl.hidden = true;
    if (rootEl) rootEl.hidden = false;
    printDoc = null;
    fetchPrintDocument(false).catch(function () {});
  }

  function loadInvoice() {
    var url = cfg.invoiceApi + '?id=' + encodeURIComponent(String(cfg.invoiceId));
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error((data && data.message) || 'الفاتورة غير موجودة.');
          }
          window.location.href = cfg.listUrl || mobileListFallback();
          return;
        }
        renderInvoice(data.invoice);
      })
      .catch(function () {
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تحميل الفاتورة.');
      });
  }

  function mobileListFallback() {
    if (window.AppMobile && AppMobile.mobileUrl) {
      return AppMobile.mobileUrl + '?r=m_sales_invoice_list';
    }
    return '?r=m_sales_invoice_list';
  }

  cfg.listUrl = cfg.listUrl || mobileListFallback();

  function editInvoiceHref() {
    var base = cfg.editUrl || '';
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 'id=' + encodeURIComponent(String(cfg.invoiceId));
  }

  function runDeleteInvoice() {
    if (!cfg.deleteApi || !cfg.invoiceId) return;
    var doDelete = function () {
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('invoice_id', String(cfg.invoiceId));
      var btnDel = TB.btn ? TB.btn('delete') : null;
      if (btnDel) btnDel.disabled = true;
      fetch(cfg.deleteApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(parseJsonResponse)
        .then(function (data) {
          if (btnDel) btnDel.disabled = false;
          if (!data || !data.ok) {
            var msg = (data && (data.message || data.error)) || 'تعذر حذف الفاتورة.';
            if (window.AppDialog && AppDialog.error) AppDialog.error(msg);
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف الفاتورة.');
          }
          window.location.href = cfg.listUrl || mobileListFallback();
        })
        .catch(function () {
          if (btnDel) btnDel.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    };
    mobileConfirm('هل تريد حذف هذه الفاتورة نهائياً؟\nلا يمكن التراجع عن الحذف.', {
      title: 'تأكيد الحذف',
      okText: 'نعم، احذف',
      cancelText: 'إلغاء',
      danger: true,
    }).then(function (ok) {
      if (ok) doDelete();
    });
  }

  var btnPrint = TB.btn ? TB.btn('print') : null;
  var btnPdf = TB.btn ? TB.btn('pdf') : null;
  var btnEinv = TB.btn ? TB.btn('einvoice') : null;
  var postFlowBusy = false;
  var postStatusTimer = null;

  function showPostStatus(message, type) {
    var el = document.getElementById('m-inv-post-status');
    if (!el) {
      return;
    }
    if (postStatusTimer) {
      clearTimeout(postStatusTimer);
      postStatusTimer = null;
    }
    el.className = 'm-alert m-alert--' + (type === 'error' ? 'error' : 'success');
    el.textContent = String(message || '').trim();
    el.hidden = !el.textContent;
    if (!el.textContent) {
      return;
    }
    postStatusTimer = setTimeout(function () {
      el.hidden = true;
      el.textContent = '';
    }, 9000);
  }

  function runPostInvoice() {
    if (postFlowBusy || !cfg.postApi || !cfg.invoiceId) return;
    postFlowBusy = true;

    function submitPost(gps) {
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('invoice_id', String(cfg.invoiceId));
      if (window.AppGeo && AppGeo.appendToFormData && gps) {
        AppGeo.appendToFormData(fd, gps, 'mobile');
      }
      var placeInput = document.getElementById('m-inv-gps-place');
      if (placeInput && String(placeInput.value || '').trim() !== '') {
        fd.append('gps_place', String(placeInput.value).trim());
      }
      var btnPost = TB.btn ? TB.btn('post') : null;
      if (btnPost) btnPost.disabled = true;
      fetch(cfg.postApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(parseJsonResponse)
        .then(function (data) {
          if (btnPost) btnPost.disabled = false;
          if (!data || !data.ok) {
            postFlowBusy = false;
            var msg = (data && (data.message || data.error)) || 'تعذر ترحيل الفاتورة.';
            showPostStatus(msg, 'error');
            return;
          }
          var okMsg =
            window.AppDialog && AppDialog.formatActionMessage
              ? AppDialog.formatActionMessage(data, {
                  fallback: 'تم ترحيل الفاتورة.',
                })
              : (data && data.message) || 'تم ترحيل الفاتورة.';
          if (data && data.gps_saved === 0 && (cfg.gpsEnabled || window.APP_GPS_ENABLED)) {
            var gpsWarn =
              (data.warnings && data.warnings[0]) ||
              (data.warning) ||
              'تم الترحيل بدون حفظ موقع GPS — استخدم الخريطة عند الترحيل القادم.';
            showPostStatus(gpsWarn, 'error');
          } else {
            showPostStatus(okMsg, 'success');
          }
          postFlowBusy = false;
          loadInvoice();
        })
        .catch(function () {
          postFlowBusy = false;
          if (btnPost) btnPost.disabled = false;
          showPostStatus('تعذر الاتصال بالخادم.', 'error');
        });
    }

    mobileConfirm(
      'هل تريد ترحيل هذه الفاتورة؟\n\nسيتم صرف المخزون وتسجيل حساب العميل.\n' +
        (cfg.gpsEnabled || window.APP_GPS_ENABLED
          ? 'سيُطلب موقعك الحالي (GPS) أو تحديده على الخريطة — لا يُستخدم موقع قديم.\n'
          : '') +
        'يُسمح بالصرف حتى لو أصبح الرصيد سالبًا.',
      {
        title: 'تأكيد الترحيل',
        okText: 'نعم، رحّل',
        cancelText: 'إلغاء',
      }
    ).then(function (ok) {
      if (!ok) {
        postFlowBusy = false;
        return;
      }
      if (cfg.gpsEnabled || (window.APP_GPS_ENABLED && window.AppGeo && AppGeo.withGpsForPost)) {
        showPostStatus('جاري تحديد موقعك بدقة (GPS)...', 'success');
        AppGeo.withGpsForPost('mobile', function (gps) {
          if (gps === undefined) {
            postFlowBusy = false;
            showPostStatus('تم إلغاء الترحيل — يجب تحديد موقعك على الخريطة.', 'error');
            return;
          }
          if (
            !gps ||
            (window.AppGeo &&
              AppGeo.isAcceptablePostGps &&
              !AppGeo.isAcceptablePostGps(gps))
          ) {
            postFlowBusy = false;
            showPostStatus(
              'لم يُحدَّد موقع صالح. اضغط «موقعي الآن» على الخريطة أو انقر على موقعك.',
              'error'
            );
            return;
          }
          showPostStatus('جاري الترحيل...', 'success');
          submitPost(gps);
        });
        return;
      }
      submitPost(null);
    });
  }

  var btnEdit = TB.btn ? TB.btn('edit') : null;
  var btnDelete = TB.btn ? TB.btn('delete') : null;
  var btnPost = TB.btn ? TB.btn('post') : null;

  if (btnPrint) {
    btnPrint.addEventListener('click', function () {
      runPrintAction('print');
    });
  }
  if (btnPdf) {
    btnPdf.addEventListener('click', function () {
      runPrintAction('pdf');
    });
  }
  if (btnEdit) {
    btnEdit.addEventListener('click', function () {
      window.location.href = editInvoiceHref();
    });
  }
  if (btnDelete) {
    btnDelete.addEventListener('click', runDeleteInvoice);
  }
  if (btnPost) {
    btnPost.addEventListener(
      'click',
      function (e) {
        if (e && typeof e.preventDefault === 'function') {
          e.preventDefault();
        }
        if (e && typeof e.stopPropagation === 'function') {
          e.stopPropagation();
        }
        runPostInvoice();
      },
      { passive: false }
    );
  }
  if (btnEinv) {
    btnEinv.addEventListener('click', function () {
      if (!invoiceData || !cfg.einvoiceApi) return;
      if (!invoiceData.is_posted) {
        if (window.AppDialog && AppDialog.alert) {
          AppDialog.alert('يجب ترحيل الفاتورة قبل إرسالها للفوترة.', { type: 'warning' });
        }
        return;
      }
      if (window.AppDialog && AppDialog.confirm) {
        AppDialog.confirm('إرسال الفاتورة للفوترة الإلكترونية؟', { title: 'فوترة' }).then(function (ok) {
          if (!ok) return;
          sendEinvoice();
        });
      } else {
        sendEinvoice();
      }
    });
  }

  function sendEinvoice() {
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf || '');
    fd.append('invoice_id', String(cfg.invoiceId));
    if (btnEinv) btnEinv.disabled = true;
    fetch(cfg.einvoiceApi, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (btnEinv) btnEinv.disabled = false;
        if (!data || !data.ok) {
          if (window.AppDialog && AppDialog.error) AppDialog.error((data && data.error) || 'تعذر الإرسال.');
          return;
        }
        if (window.AppDialog && AppDialog.success) AppDialog.success('تم إرسال الفاتورة للفوترة.');
        printDoc = null;
        loadInvoice();
      })
      .catch(function () {
        if (btnEinv) btnEinv.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  loadInvoice();
})();
