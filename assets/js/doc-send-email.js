/* doc-send-email.js — مساعد مشترك لإرسال المستند (فاتورة/مرتجع) كـ PDF عبر البريد.
 *
 * الاستخدام:
 *   window.DocSendEmail.send({
 *     url:           '...api/document_send_email.php', // مطلوب
 *     docType:       'sales_invoice' | 'purchase_invoice' | 'sales_return' | 'purchase_return',
 *     docNo:         'INV-1001',          // اختياري — لإسم الملف والموضوع
 *     fileBase:      'invoice-INV-1001',  // اختياري — اسم الملف بدون امتداد
 *     buildHtml:     function() {...},     // تُرجِع HTML للطباعة (تشمل CSS أو يفترض أن المعاينة جاهزة)
 *     csrfToken:     '...',                // مطلوب — قيمة _csrf
 *     overlayId:     'sales-inv-print-overlay',
 *     previewId:     'sales-inv-print-preview',
 *     defaultEmail:  ''                    // اختياري — لتعبئة الحقل مسبقاً
 *   });
 */
(function (global) {
  'use strict';

  function escapeName(s) {
    return String(s || '').replace(/[^\w\u0600-\u06FF\-]+/g, '_');
  }

  function waitForImages(root, cb) {
    var imgs = root.querySelectorAll('img');
    if (!imgs.length) { cb(); return; }
    var pending = imgs.length;
    var done = false;
    var finish = function () { if (!done) { done = true; cb(); } };
    var safety = setTimeout(finish, 5000);
    Array.prototype.forEach.call(imgs, function (img) {
      if (img.complete && img.naturalWidth > 0) {
        if (--pending <= 0) { clearTimeout(safety); finish(); }
      } else {
        img.addEventListener('load', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
        img.addEventListener('error', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
      }
    });
  }

  function generatePdfBlob(opts, cb) {
    if (typeof html2pdf === 'undefined') {
      cb(new Error('مكتبة PDF غير محمّلة. تحقق من الاتصال بالإنترنت.'));
      return;
    }
    var overlay = document.getElementById(opts.overlayId || 'sales-inv-print-overlay');
    var preview = document.getElementById(opts.previewId || 'sales-inv-print-preview');
    if (!overlay || !preview) {
      cb(new Error('عنصر المعاينة غير متاح.'));
      return;
    }
    var wasHidden = overlay.hidden;
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    preview.innerHTML = opts.buildHtml();
    overlay.removeAttribute('hidden');
    overlay.hidden = false;
    overlay.style.display = 'flex';
    overlay.style.zIndex = '99999';
    overlay.style.opacity = '0.001';

    var cleanup = function () {
      setTimeout(function () {
        try {
          overlay.style.opacity = '';
          overlay.style.zIndex = '';
          overlay.style.display = '';
          if (wasHidden) {
            overlay.hidden = true;
            overlay.setAttribute('hidden', '');
          }
        } catch (_e) {}
      }, 100);
    };

    requestAnimationFrame(function () {
      waitForImages(preview, function () {
        try {
          html2pdf()
            .set({
              margin: [10, 10, 10, 10],
              filename: (opts.fileBase || 'document') + '.pdf',
              image: { type: 'jpeg', quality: 0.95 },
              html2canvas: { scale: 2, logging: false, useCORS: true, allowTaint: true, backgroundColor: '#ffffff' },
              jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
              pagebreak: { mode: ['css', 'legacy'] },
            })
            .from(preview)
            .outputPdf('blob')
            .then(function (blob) {
              cleanup();
              cb(null, blob);
            })
            .catch(function (err) {
              cleanup();
              cb(err || new Error('تعذر إنشاء PDF.'));
            });
        } catch (err) {
          cleanup();
          cb(err);
        }
      });
    });
  }

  function send(opts) {
    opts = opts || {};
    if (!opts.url) {
      try { AppDialog.error('عنوان خدمة الإرسال غير مهيأ.'); } catch (_e) {}
      return;
    }
    if (!opts.csrfToken) {
      try { AppDialog.error('انتهت صلاحية الجلسة، أعد تحميل الصفحة.'); } catch (_e) {}
      return;
    }
    if (typeof opts.buildHtml !== 'function') {
      try { AppDialog.error('تعذر تجميع المستند للإرسال.'); } catch (_e) {}
      return;
    }

    AppDialog.prompt('أدخل البريد الإلكتروني للمستلم:', {
      title: 'إرسال بالبريد',
      value: opts.defaultEmail || '',
      okText: 'متابعة',
      cancelText: 'إلغاء',
      multiline: false,
      placeholder: 'name@example.com',
    }).then(function (val) {
      if (val === null) return;
      var to = String(val || '').trim();
      if (!to || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
        AppDialog.error('البريد الإلكتروني غير صالح.');
        return;
      }
      AppDialog.confirm('إرسال PDF إلى «' + to + '»؟', {
        title: 'تأكيد الإرسال',
        okText: 'إرسال',
        cancelText: 'إلغاء',
      }).then(function (ok) {
        if (!ok) return;
        var loadingClosed = false;
        var loadingDlg = null;
        try {
          if (AppDialog.loading) {
            loadingDlg = AppDialog.loading('جارٍ توليد PDF وإرساله...');
          }
        } catch (_e) {}

        generatePdfBlob(opts, function (err, blob) {
          if (err) {
            if (loadingDlg && !loadingClosed) { try { loadingDlg.close(); } catch (_e) {} loadingClosed = true; }
            AppDialog.error(err.message || 'تعذر إنشاء PDF.');
            return;
          }
          var fd = new FormData();
          fd.append('_csrf', opts.csrfToken);
          fd.append('to_email', to);
          fd.append('doc_type', opts.docType || '');
          fd.append('doc_no', opts.docNo || '');
          var fname = (opts.fileBase || 'document') + '.pdf';
          fd.append('pdf', blob, fname);
          fetch(opts.url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
          })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'استجابة غير صالحة من الخادم.' }; }); })
            .then(function (data) {
              if (loadingDlg && !loadingClosed) { try { loadingDlg.close(); } catch (_e) {} loadingClosed = true; }
              if (data && data.ok) {
                AppDialog.success(data.message || 'تم الإرسال بنجاح.');
              } else {
                AppDialog.error((data && data.error) || 'تعذر إرسال البريد.');
              }
            })
            .catch(function () {
              if (loadingDlg && !loadingClosed) { try { loadingDlg.close(); } catch (_e) {} loadingClosed = true; }
              AppDialog.error('تعذر الاتصال بالخادم.');
            });
        });
      });
    });
  }

  function downloadBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      try { URL.revokeObjectURL(url); } catch (_e) {}
      try { a.remove(); } catch (_e) {}
    }, 1500);
  }

  global.DocSendEmail = { send: send };
})(window);
