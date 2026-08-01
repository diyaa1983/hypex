(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('sales-dlv-form');
  if (!form) return;

  var tbody = document.getElementById('sales-dlv-lines-body');
  var tpl = document.getElementById('sales-dlv-line-template');
  if (!tbody) {
    console.error('sales-delivery: missing table body');
    return;
  }
  if (!tpl) {
    console.warn('sales-delivery: line template missing');
  }

  var linesJson = document.getElementById('sales-dlv-lines-json');
  var apiItemsUrl = form.getAttribute('data-api-items') || '';
  var apiDeliveryUrl = form.getAttribute('data-api-delivery') || '';
  var deliveryPostUrl = form.getAttribute('data-delivery-post-url') || '';
  var deliveryUnpostUrl = form.getAttribute('data-delivery-unpost-url') || '';
  var deliveryUnlinkInvoiceUrl = form.getAttribute('data-delivery-unlink-invoice-url') || '';
  var deliveryDeleteUrl = form.getAttribute('data-delivery-delete-url') || '';
  var canUnpostDelivery = form.getAttribute('data-can-unpost') === '1';
  var warehouseRequired = form.getAttribute('data-warehouse-required') === '1';
  var defaultWarehouseId = parseInt(form.getAttribute('data-default-warehouse-id') || '0', 10);
  var newDeliveryUrl = form.getAttribute('data-new-url') || '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var initialDeliveryId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var defaultDate = form.getAttribute('data-default-date') || '';
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';

  var currentDeliveryId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'dlv_no',
    prevBtnId: 'dlv_no_prev',
    nextBtnId: 'dlv_no_next',
    defaultNoTitle: 'اكتب جزءاً من رقم السند واضغط Enter للبحث',
  };
  var deliveryIsPosted = false;
  var formDirty = false;
  var formSubmitting = false;
  var suppressDirtyMark = 0;
  var activePickRow = null;

  var LINE_NAV_SELECTORS = ['.js-barcode-inp', '.js-qty'];

  function fmtDate(value) {
    return global.AppFormat && AppFormat.formatDateDmY
      ? AppFormat.formatDateDmY(value)
      : String(value == null ? '' : value);
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function parseNum(v) {
    if (v === '' || v === null || v === undefined) return 0;
    var s = String(v).replace(/,/g, '');
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  function formatQtyValue(n, rawStr) {
    if (rawStr !== undefined && rawStr !== null && String(rawStr).trim() !== '') {
      var s = String(rawStr).trim().replace(/,/g, '');
      if (/[.,]/.test(s)) {
        var x = parseFloat(s);
        if (isFinite(x)) {
          s = String(s).replace(/,/g, '.');
          s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
          return s === '' ? '0' : s;
        }
      }
    }
    var q = Number(n);
    if (!isFinite(q)) return '1';
    if (Math.abs(q - Math.round(q)) < 1e-9) return String(Math.round(q));
    var out = String(q).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return out === '' ? '0' : out;
  }

  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'dlv_no';
  }

  function markFormDirty() {
    if (suppressDirtyMark > 0 || deliveryIsPosted) return;
    formDirty = true;
  }

  function markFormDirtyFromEvent(e) {
    if (e && e.target && isSearchOnlyField(e.target)) return;
    markFormDirty();
  }

  function clearFormDirty() {
    formDirty = false;
  }

  function runWithoutDirtyMark(fn) {
    suppressDirtyMark++;
    try {
      fn();
    } finally {
      suppressDirtyMark--;
    }
    clearFormDirty();
  }

  function newLineId() {
    return 'L' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
  }

  function buildRecipientSignatureBlock() {
    if (window.DocumentHeader && typeof window.DocumentHeader.buildRecipientSignature === 'function') {
      return window.DocumentHeader.buildRecipientSignature();
    }
    return (
      '<div class="doc-print-signature-block">' +
      '<span class="doc-print-signature-label">توقيع المستلم</span>' +
      '<span class="doc-print-signature-line" aria-hidden="true"></span>' +
      '</div>'
    );
  }

  function buildDocPrintHeader(title) {
    if (window.DocumentHeader && typeof window.DocumentHeader.build === 'function') {
      return window.DocumentHeader.build({
        companyName: companyName,
        logoUrl: companyLogoUrl,
        title: title || '',
      });
    }
    return (
      '<header class="doc-print-header"><div class="doc-print-header-top"><div class="doc-print-header-brand"><div class="doc-print-header-co">' +
      escapeHtml(companyName) +
      '</div><div class="doc-print-header-logo"></div></div></div><div class="doc-print-header-title">' +
      escapeHtml(title || '') +
      '</div></header>'
    );
  }

  function syncDeliveryIdField() {
    var rec = document.getElementById('dlv_record_id');
    if (!rec) return;
    rec.value = currentDeliveryId > 0 ? String(currentDeliveryId) : '';
  }

  function syncDeliveryNoDisplay(deliveryNo) {
    var dlvNo = document.getElementById('dlv_no');
    if (!dlvNo) return;
    if (currentDeliveryId > 0) {
      var no =
        deliveryNo !== undefined && deliveryNo !== null
          ? String(deliveryNo)
          : dlvNo.value;
      dlvNo.value = no.trim();
    } else {
      dlvNo.value = '';
    }
    updateDeliveryNoPostedStyle();
  }

  function refreshDeliveryEditState() {
    var locked = currentDeliveryId > 0 && !!deliveryIsPosted;
    form.classList.toggle('sales-inv-form-is-posted', locked);
    form.classList.toggle('sales-inv-form-is-locked', locked);
    var fields = form.querySelectorAll('#dlv_date, #dlv_customer, #dlv_wh, #dlv_notes');
    fields.forEach(function (el) {
      if (!el) return;
      if (locked) {
        el.setAttribute('readonly', 'readonly');
        if (el.tagName === 'SELECT') el.disabled = true;
      } else {
        el.removeAttribute('readonly');
        if (el.tagName === 'SELECT') el.disabled = false;
      }
    });
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var pick = tr.querySelector('.js-pick-open');
      var rm = tr.querySelector('.js-remove');
      tr.querySelectorAll('.js-qty, .js-barcode-inp').forEach(function (inp) {
        if (locked) {
          inp.setAttribute('readonly', 'readonly');
          if (inp.tagName === 'SELECT') inp.disabled = true;
        } else {
          inp.removeAttribute('readonly');
          if (inp.tagName === 'SELECT') inp.disabled = false;
        }
      });
      if (pick) {
        if (locked) {
          pick.disabled = true;
          pick.setAttribute('aria-disabled', 'true');
          pick.classList.add('is-readonly-display');
        } else {
          pick.disabled = false;
          pick.removeAttribute('aria-disabled');
          pick.classList.remove('is-readonly-display');
        }
      }
      if (rm) rm.style.display = locked ? 'none' : '';
      if (!locked) applyRowItemPickLock(tr);
    });
    if (!locked) ensureEntryRow();
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.syncToolbar();
    }
  }

  function deliveryArchiveState(id) {
    id = parseInt(String(id), 10) || 0;
    if (id < 1) {
      return { allowed: false, reason: 'not_saved' };
    }
    if (deliveryIsPosted) {
      return { allowed: true, readOnly: true, reason: '' };
    }
    return { allowed: true, readOnly: false, reason: '' };
  }

  function updateDeliveryNoPostedStyle() {
    var dlvNo = document.getElementById('dlv_no');
    if (!dlvNo) return;
    dlvNo.classList.remove('is-posted', 'is-unposted');
    if (currentDeliveryId < 1) return;
    if (deliveryIsPosted) {
      dlvNo.classList.add('is-posted');
    } else {
      dlvNo.classList.add('is-unposted');
    }
  }

  function syncInvoiceLinkHint(delivery) {
    var hint = document.getElementById('dlv_invoice_link_hint');
    var btn = document.getElementById('dlv_unlink_invoice_btn');
    var invId = delivery ? parseInt(delivery.linked_invoice_id, 10) || 0 : 0;
    var invNo = delivery && delivery.linked_invoice_no ? String(delivery.linked_invoice_no) : '';
    var invPosted = delivery && !!delivery.linked_invoice_is_posted;
    if (!hint && !btn) return;
    if (invId > 0 && invNo) {
      if (hint) {
        hint.hidden = false;
        hint.textContent =
          'مربوط في قاعدة البيانات بفاتورة «' +
          invNo +
          '»' +
          (invPosted ? ' (مرحّلة)' : ' (مسودة)') +
          '. إن كان الربط خاطئاً استخدم «فك الربط بالفاتورة» — لا حاجة لفك ترحيل الفاتورة ولا يتأثر إرسال الضريبة.';
      }
      if (btn) btn.hidden = false;
    } else {
      if (hint) {
        hint.hidden = true;
        hint.textContent = '';
      }
      if (btn) btn.hidden = true;
    }
  }

  function unlinkDeliveryInvoice() {
    if (!deliveryUnlinkInvoiceUrl || currentDeliveryId < 1) return;
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'فك ربط هذا السند عن فاتورة المبيعات في قاعدة البيانات؟\nلن يُلغى ترحيل الفاتورة ولا السند.',
      { title: 'فك الربط بالفاتورة' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('delivery_id', String(currentDeliveryId));
      fetch(deliveryUnlinkInvoiceUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error((data && data.error) || 'تعذر فك الربط.');
            return;
          }
          AppDialog.success(data.message || 'تم فك الربط.');
          loadDeliveryById(currentDeliveryId, true);
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function updatePostedBadge() {
    var el = document.getElementById('dlv_posted_badge');
    if (currentDeliveryId < 1) {
      if (el) el.hidden = true;
      updateDeliveryNoPostedStyle();
      return;
    }
    if (el) {
      el.hidden = false;
      if (deliveryIsPosted) {
        el.textContent = 'مرحّل';
        el.className = 'sales-inv-posted-badge badge badge-posted';
      } else {
        el.textContent = 'غير مرحّل';
        el.className = 'sales-inv-posted-badge badge badge-warn';
      }
    }
    updateDeliveryNoPostedStyle();
  }

  function applyBrowseNavFromPayload(payload) {
    if (window.DocumentNoNav && DocumentNoNav.applyBrowseNav) {
      DocumentNoNav.applyBrowseNav(docNoSearch, payload, setBrowseNav, DOC_NO_SEARCH_UI);
      return;
    }
    setBrowseNav(payload.prev_id || 0, payload.next_id || 0);
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('dlv_no_prev', 'dlv_no_next', prevId, nextId, {
        onEmpty: currentDeliveryId < 1,
        prevTitle: 'السند السابق',
        nextTitle: 'السند التالي',
        prevBeforeLatestTitle: 'السند قبل الأخير',
        latestTitle: 'آخر سند تسليم',
      });
      return;
    }
    var prevBtn = document.getElementById('dlv_no_prev');
    var nextBtn = document.getElementById('dlv_no_next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
  }

  function navigateEmptyDelivery(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchDeliveryResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchDeliveryResponse({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.delivery);
      },
      getPayload: function (data) {
        return data;
      },
      apply: applyDeliveryData,
      emptyMessage: 'لا توجد سندات محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر سند.',
      loadError: 'تعذر تحميل السند.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    return opts.fetchLatest().then(function (data) {
      if (opts.isOk(data)) opts.apply(opts.getPayload(data));
      else if (global.AppDialog) AppDialog.alert(opts.emptyMessage, { type: 'info' });
    });
  }

  /** تفعيل أسهم التنقل عند سند جديد فارغ */
  function refreshEmptyBrowseNav() {
    if (!apiDeliveryUrl) {
      setBrowseNav(0, 0);
      return;
    }
    fetchDeliveryResponse({ edge: 'first' }).then(function (data) {
      if (!data || !data.ok || !data.delivery) {
        setBrowseNav(0, 0);
        return;
      }
      var d = data.delivery;
      var firstId = parseInt(d.id, 10) || 0;
      setBrowseNav(d.prev_id || 0, firstId);
    });
  }

  function getRowItemId(tr) {
    if (!tr) return 0;
    var id = parseInt(tr.dataset.itemId, 10);
    if (id > 0) return id;
    id = parseInt(tr.getAttribute('data-item-id') || '', 10);
    return id > 0 ? id : 0;
  }

  function deliveryHasLines() {
    var hasLine = false;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (getRowItemId(tr) > 0) hasLine = true;
    });
    return hasLine;
  }

  function hasDraftContent() {
    if (deliveryHasLines()) return true;
    var notes = document.getElementById('dlv_notes');
    if (notes && String(notes.value).trim() !== '') return true;
    var cust = document.getElementById('dlv_customer');
    if (cust && cust.value !== '') return true;
    return false;
  }

  function syncJson() {
    var lines = [];
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var itemId = getRowItemId(tr);
      if (!itemId) return;
      var qtyEl = tr.querySelector('.js-qty');
      var desc = tr.dataset.lineDesc || tr.dataset.nameAr || '';
      lines.push({
        item_id: itemId,
        qty: parseNum(qtyEl ? qtyEl.value : 0),
        line_desc: desc,
      });
    });
    if (linesJson) linesJson.value = JSON.stringify(lines);
  }

  function validateBeforeSave() {
    var cust = document.getElementById('dlv_customer');
    if (!cust || !cust.value) {
      if (global.AppDialog) AppDialog.alert('اختر العميل قبل الحفظ.', { type: 'warning' });
      else alert('اختر العميل قبل الحفظ.');
      if (cust) cust.focus();
      return false;
    }
    var dlvDate = document.getElementById('dlv_date');
    if (dlvDate && !dlvDate.value.trim()) {
      if (global.AppDialog) AppDialog.alert('أدخل تاريخ السند.', { type: 'warning' });
      else alert('أدخل تاريخ السند.');
      if (dlvDate) dlvDate.focus();
      return false;
    }
    if (dlvDate && global.AppFormat && AppFormat.parseDateToIso) {
      if (!AppFormat.parseDateToIso(dlvDate.value)) {
        if (global.AppDialog) {
          AppDialog.alert('تاريخ السند غير صالح. استخدم يوم-شهر-سنة (مثل 16-05-2026).', {
            type: 'warning',
          });
        }
        if (dlvDate) dlvDate.focus();
        return false;
      }
    }
    if (!deliveryHasLines()) {
      if (global.AppDialog) AppDialog.alert('أضف سطرًا واحدًا على الأقل للمواد.', { type: 'warning' });
      else alert('أضف سطرًا واحدًا على الأقل للمواد.');
      return false;
    }
    var qtyCheck = validateLineQuantities();
    if (!qtyCheck.ok) {
      if (global.AppDialog) AppDialog.alert(qtyCheck.msg, { type: 'warning' });
      else alert(qtyCheck.msg);
      if (qtyCheck.tr) focusRowQtyField(qtyCheck.tr);
      return false;
    }
    if (warehouseRequired) {
      var wh = document.getElementById('dlv_wh');
      if (!wh || !wh.value) {
        if (global.AppDialog) AppDialog.alert('اختر المستودع قبل الحفظ.', { type: 'warning' });
        if (wh) wh.focus();
        return false;
      }
    }
    return true;
  }

  function setSaveBusy(busy, message) {
    if (global.AppBusy && AppBusy.setSaveBusy) {
      AppBusy.setSaveBusy(busy, message || 'جاري حفظ سند التسليم...');
      return;
    }
    var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (saveBtn) saveBtn.disabled = !!busy;
  }

  var pendingAfterSave = null;

  function finishSaveFromJson(data, onDone) {
    formSubmitting = false;
    setSaveBusy(false);
    clearFormDirty();
    var savedId = parseInt(data.delivery_id, 10) || 0;
    if (savedId > 0) {
      loadSavedDeliveryAfterSubmit(savedId, onDone);
      return;
    }
    window.location.reload();
  }

  function loadSavedDeliveryAfterSubmit(deliveryId, onDone) {
    fetchDeliveryResponse({ id: deliveryId }).then(function (data) {
      if (data && data.ok && data.delivery) {
        applyDeliveryData(data);
        updateHistory(deliveryId);
        if (onDone) {
          onDone();
        } else if (global.AppDialog) {
          AppDialog.success('تم حفظ سند التسليم بنجاح.');
        }
        return;
      }
      window.location.href =
        (newDeliveryUrl || window.location.pathname) +
        (newDeliveryUrl.indexOf('?') >= 0 ? '&' : '?') +
        'id=' +
        deliveryId;
    }).catch(function () {
      window.location.reload();
    });
  }

  function submitDeliveryForm() {
    formSubmitting = true;
    syncJson();
    syncDeliveryIdField();
    setSaveBusy(true);

    var actionUrl = form.getAttribute('action') || window.location.href;
    var fd = new FormData(form);
    fd.set('_action', 'save_delivery');
    if (linesJson) fd.set('lines_json', linesJson.value);
    var onDone = pendingAfterSave;
    pendingAfterSave = null;

    fetch(actionUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') >= 0) {
          return res.json().then(function (data) {
            if (!data || !data.ok) {
              formSubmitting = false;
              setSaveBusy(false);
              var msg = (data && data.message) || 'تعذر حفظ السند.';
              if (global.AppDialog) AppDialog.error(msg);
              else alert(msg);
              return;
            }
            finishSaveFromJson(data, onDone);
          });
        }
        if (onDone) {
          formSubmitting = false;
          setSaveBusy(false);
          onDone();
          return;
        }
        if (res.status >= 300 && res.status < 400) {
          var loc = res.headers.get('Location');
          if (loc) {
            window.location.href = new URL(loc, window.location.href).href;
            return;
          }
        }
        window.location.reload();
      })
      .catch(function (err) {
        console.error('sales-delivery save', err);
        formSubmitting = false;
        setSaveBusy(false);
        if (global.AppDialog) {
          AppDialog.error('تعذر حفظ السند. تحقق من الاتصال وحاول مرة أخرى.');
        } else {
          alert('تعذر حفظ السند.');
        }
      });
  }

  function trySave(onSuccess) {
    if (formSubmitting) return;
    if (deliveryIsPosted) {
      if (global.AppDialog) AppDialog.alert('لا يمكن تعديل سند مرحّل.', { type: 'warning' });
      return;
    }
    if (!validateBeforeSave()) return;
    pendingAfterSave = typeof onSuccess === 'function' ? onSuccess : null;
    try {
      submitDeliveryForm();
    } catch (err) {
      pendingAfterSave = null;
      formSubmitting = false;
      setSaveBusy(false);
      if (global.AppDialog) {
        AppDialog.error('تعذر حفظ السند: ' + (err.message || 'خطأ غير معروف'));
      }
    }
  }

  function applyQtyInputAttrs(tr) {
    var qty = tr.querySelector('.js-qty');
    if (qty) {
      qty.setAttribute('step', '1');
      qty.setAttribute('inputmode', 'decimal');
    }
  }

  function normalizeQtyInput(inp) {
    if (!inp) return;
    if (String(inp.value).trim() === '') {
      inp.value = '';
      return;
    }
    var n = parseNum(inp.value);
    if (n <= 0) {
      inp.value = '';
      return;
    }
    inp.value = formatQtyValue(n, inp.value);
  }


  function rowStockQty(tr) {
    var qty = parseNum(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0);
    return qty;
  }

  function rowNeedsQtyInput(tr) {
    return getRowItemId(tr) > 0 && rowStockQty(tr) <= 0;
  }

  function findFirstRowMissingQty() {
    var found = null;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (found) return;
      if (rowNeedsQtyInput(tr)) found = tr;
    });
    return found;
  }

  function alertQtyRequired(tr, actionLabel) {
    if (!tr || !global.AppDialog) return;
    var name = tr.dataset.nameAr || 'المادة';
    AppDialog.alert(
      'أدخل الكمية قبل ' + (actionLabel || 'المتابعة') + '.\n\nالمادة: ' + name,
      { type: 'warning', title: 'الكمية مطلوبة' }
    );
  }

  function blockItemPickUntilQty(tr, opts) {
    opts = opts || {};
    if (deliveryIsPosted) return false;

    if (tr && rowNeedsQtyInput(tr)) {
      if (opts.alert !== false) {
        alertQtyRequired(tr, opts.actionLabel || 'اختيار مادة أخرى');
      }
      focusRowQtyField(tr);
      return true;
    }

    var pending = findFirstRowMissingQty();
    if (pending && (!tr || pending !== tr)) {
      if (opts.alert !== false) {
        alertQtyRequired(pending, opts.actionLabel || 'اختيار مادة أخرى');
      }
      focusRowQtyField(pending);
      return true;
    }

    return false;
  }

  function blockLineNavIfQtyMissing(tr, current) {
    if (!current || !rowNeedsQtyInput(tr)) return false;
    if (
      current.classList.contains('js-qty') ||
      current.classList.contains('js-barcode-inp')
    ) {
      focusRowQtyField(tr);
      return true;
    }
    return false;
  }

  function applyRowQtyLock(tr) {
    if (!tr) return;
    if (deliveryIsPosted || getRowItemId(tr) < 1) {
      tr.querySelectorAll('.js-qty').forEach(function (el) {
        el.classList.remove('is-qty-required');
      });
      return;
    }
    var needsQty = rowStockQty(tr) <= 0;
    var pick = tr.querySelector('.js-pick-open');
    if (pick) {
      pick.classList.toggle('is-qty-required-locked', needsQty);
      pick.disabled = !!needsQty;
    }
    var itemCell = tr.querySelector('.sales-inv-item-cell');
    if (itemCell) {
      itemCell.classList.toggle('is-qty-required-locked', needsQty);
    }
    var barcodeInpLock = tr.querySelector('.js-barcode-inp');
    if (barcodeInpLock) {
      if (needsQty) {
        barcodeInpLock.setAttribute('readonly', 'readonly');
        barcodeInpLock.classList.add('is-qty-required-locked');
      } else if (barcodeInpLock.classList.contains('is-qty-required-locked')) {
        barcodeInpLock.removeAttribute('readonly');
        barcodeInpLock.classList.remove('is-qty-required-locked');
      }
    }
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) {
      qtyEl.classList.toggle('is-qty-required', needsQty);
      qtyEl.removeAttribute('readonly');
      qtyEl.removeAttribute('tabindex');
    }
  }

  function applyRowItemPickLock(tr) {
    if (!tr) return;
    if (deliveryIsPosted) return;
    var needsPick = getRowItemId(tr) < 1;
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) {
      if (needsPick) {
        qtyEl.setAttribute('readonly', 'readonly');
        qtyEl.setAttribute('tabindex', '-1');
        qtyEl.classList.add('is-item-pick-locked');
      } else {
        qtyEl.removeAttribute('readonly');
        qtyEl.removeAttribute('tabindex');
        qtyEl.classList.remove('is-item-pick-locked');
      }
    }
    var pick = tr.querySelector('.js-pick-open');
    if (pick && needsPick) {
      pick.tabIndex = 0;
    } else if (pick) {
      pick.removeAttribute('tabindex');
    }
    if (needsPick) {
      var barcodeInp = tr.querySelector('.js-barcode-inp');
      if (barcodeInp) {
        barcodeInp.removeAttribute('readonly');
        barcodeInp.removeAttribute('tabindex');
        barcodeInp.classList.remove('is-item-pick-locked');
      }
    }
    applyRowQtyLock(tr);
  }

  function focusRowQtyField(tr) {
    if (!tr) return;
    var run = function () {
      var qtyEl = tr.querySelector('.js-qty');
      if (!qtyEl || qtyEl.disabled || qtyEl.classList.contains('is-item-pick-locked')) return;
      qtyEl.focus();
      if (qtyEl.select) qtyEl.select();
    };
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(function () {
        window.setTimeout(run, 0);
      });
    } else {
      window.setTimeout(run, 0);
    }
  }

  function focusRowMaterialCodeField(tr) {
    if (!tr) return;
    if (blockItemPickUntilQty(tr, { alert: false, actionLabel: 'إدخال مادة' })) return;
    var bc = tr.querySelector('.js-barcode-inp');
    if (bc && !bc.disabled) {
      bc.focus();
      if (bc.select) bc.select();
      return;
    }
    openPickerForRow(tr);
  }

  function validateLineQuantities() {
    var firstBad = null;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!getRowItemId(tr)) return;
      if (rowStockQty(tr) <= 0 && !firstBad) firstBad = tr;
    });
    if (!firstBad) {
      return { ok: true };
    }
    var name = firstBad.dataset.nameAr || 'مادة بدون اسم';
    return {
      ok: false,
      msg: 'أدخل كمية لكل مادة في السند.\n\nالمادة: ' + name,
      tr: firstBad,
    };
  }

  function itemMaterialNumber(barcode, sku) {
    if (window.InvItemDisplay && typeof window.InvItemDisplay.materialNumber === 'function') {
      return window.InvItemDisplay.materialNumber(barcode, sku);
    }
    var bc = String(barcode == null ? '' : barcode).trim();
    if (bc) return bc;
    return String(sku == null ? '' : sku).trim();
  }

  function resolveLineItemCodes(source) {
    var o = source || {};
    var barcode = String(o.barcode == null ? '' : o.barcode).trim();
    var sku = String((o.sku == null ? '' : o.sku) || (o.code == null ? '' : o.code)).trim();
    var material = String(o.material_number == null ? '' : o.material_number).trim();
    var itemId = parseInt(o.item_id != null ? o.item_id : o.id, 10) || 0;

    if (!barcode && !sku && material) {
      barcode = material;
    }

    if (!barcode && !sku && itemId > 0 && global.ItemPickerModal && typeof ItemPickerModal.getCachedItem === 'function') {
      var cached = ItemPickerModal.getCachedItem(itemId);
      if (cached) {
        barcode = String(cached.barcode || '').trim();
        sku = String(cached.sku || '').trim();
      }
    }

    return { barcode: barcode, sku: sku, material_number: material, item_id: itemId };
  }

  function getEntryRow() {
    return tbody.querySelector('tr.is-entry-row');
  }

  function insertDataRowBeforeEntry(tr) {
    var entry = getEntryRow();
    if (entry) {
      tbody.insertBefore(tr, entry);
      return;
    }
    tbody.appendChild(tr);
  }

  function createRow(isEntry) {
    var tr;
    if (tpl && tpl.content && tpl.content.firstElementChild) {
      tr = tpl.content.firstElementChild.cloneNode(true);
      delete tr.dataset.rowBound;
    } else {
      var seed = getEntryRow();
      if (!seed) throw new Error('قالب سطر السند غير موجود');
      tr = seed.cloneNode(true);
      tr.classList.remove('is-picker-active');
      delete tr.dataset.rowBound;
    }
    tr.dataset.lineId = newLineId();
    tr.dataset.itemId = '';
    tr.dataset.nameAr = '';
    tr.dataset.lineDesc = '';
    setRowItemDisplay(tr, '', '', '');
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) qtyEl.value = '';
    if (isEntry) {
      tr.classList.add('is-entry-row');
    } else {
      tr.classList.remove('is-entry-row');
    }
    applyQtyInputAttrs(tr);
    bindRow(tr);
    return tr;
  }

  function ensureEntryRow() {
    var entry = getEntryRow();
    if (entry) {
      if (entry !== tbody.lastElementChild) tbody.appendChild(entry);
      return entry;
    }
    entry = createRow(true);
    tbody.appendChild(entry);
    return entry;
  }

  function finalizeEntryRow(tr) {
    if (!tr.classList.contains('is-entry-row')) return;
    tr.classList.remove('is-entry-row');
    var removeBtn = tr.querySelector('.js-remove');
    if (removeBtn) removeBtn.style.visibility = 'visible';
  }

  function setRowItemDisplay(tr, name, barcode, sku) {
    var nameEl = tr.querySelector('.js-name');
    if (nameEl) {
      nameEl.textContent = name || '';
      nameEl.classList.toggle('is-placeholder', !name);
    }
    var lovWrap = tr.querySelector('.sales-inv-item-lov');
    if (lovWrap) {
      lovWrap.classList.toggle('is-empty', !name);
    }
    var codes = resolveLineItemCodes({
      barcode: barcode,
      sku: sku,
      material_number: tr.dataset.materialNumber || '',
      item_id: tr.dataset.itemId || 0,
    });
    var materialNo = codes.material_number || itemMaterialNumber(codes.barcode, codes.sku);
    var skuEl = tr.querySelector('.js-sku');
    if (skuEl) {
      skuEl.textContent = materialNo;
    }
    if (materialNo) {
      tr.dataset.materialNumber = materialNo;
    } else {
      delete tr.dataset.materialNumber;
    }
    if (codes.barcode) tr.dataset.itemBarcode = codes.barcode;
    else delete tr.dataset.itemBarcode;
    if (codes.sku) tr.dataset.itemSku = codes.sku;
    else delete tr.dataset.itemSku;
    applyRowItemPickLock(tr);
    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.value = String(codes.barcode || '').trim();
    }
  }

  function renumberRows() {
    var n = 1;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var seq = tr.querySelector('.js-seq');
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (!seq) return;
      if (itemId > 0) {
        seq.textContent = String(n);
        n += 1;
      } else {
        seq.textContent = '';
      }
    });
  }

  function fillRowFromItem(tr, item) {
    var normalized = normalizePickerItem(item);
    if (!tr || !normalized) return false;
    var qtyEl = tr.querySelector('.js-qty');
    if (!qtyEl) return false;

    var name = normalized.name_ar || '';
    tr.dataset.itemId = String(normalized.id);
    tr.dataset.nameAr = name;
    tr.dataset.lineDesc = name;
    setRowItemDisplay(tr, name, normalized.barcode, normalized.sku);
    qtyEl.value = '';
    markFormDirty();
    return true;
  }

  function appendItemsToInvoice(items) {
    if (!items || !items.length) return null;
    if (blockItemPickUntilQty(null, { actionLabel: 'إضافة مادة' })) return null;
    var firstFocus = null;
    items.forEach(function (raw) {
      var row = appendLineFromPickerItem(raw);
      if (row && !firstFocus) firstFocus = row;
    });
    ensureEntryRow();
    renumberRows();
    syncJson();
    markFormDirty();
    return firstFocus;
  }

  function appendLineFromPickerItem(item) {
    var normalized = normalizePickerItem(item);
    if (!normalized) return null;
    ensureEntryRow();
    var row = createRow(false);
    insertDataRowBeforeEntry(row);
    if (!fillRowFromItem(row, normalized)) {
      row.remove();
      return null;
    }
    return row;
  }

  function addLineFromData(ln) {
    var tr = createRow(false);
    var name = ln.name_ar || ln.line_desc || '';
    tr.dataset.itemId = String(ln.item_id);
    tr.dataset.nameAr = name;
    tr.dataset.lineDesc = ln.line_desc || name;
    setRowItemDisplay(tr, name, ln.barcode || '', ln.sku || '');
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) qtyEl.value = formatQtyValue(ln.qty);
    applyRowItemPickLock(tr);
    insertDataRowBeforeEntry(tr);
    return tr;
  }

  function normalizePickerItem(item) {
    if (!item) return null;
    var id = parseInt(item.id != null ? item.id : item.item_id, 10);
    if (!id) return null;
    var barcode = String(item.barcode || item.material_number || '').trim();
    var sku = String(item.sku || item.code || '').trim();
    return {
      id: id,
      name_ar: item.name_ar || item.name || '',
      barcode: barcode,
      sku: sku,
      material_number: String(item.material_number || itemMaterialNumber(barcode, sku)).trim(),
    };
  }

  function addPickerItems(items) {
    if (!items || !items.length) return;
    if (blockItemPickUntilQty(activePickRow, { actionLabel: 'اختيار مادة' })) {
      closePicker();
      return;
    }
    var focusTr = null;
    var queue = items.slice();
    if (activePickRow && !parseInt(activePickRow.dataset.itemId, 10)) {
      var first = queue.shift();
      if (first && fillRowFromItem(activePickRow, first)) {
        focusTr = activePickRow;
        if (activePickRow.classList.contains('is-entry-row')) {
          finalizeEntryRow(activePickRow);
          ensureEntryRow();
        }
      }
    }
    if (queue.length) {
      var appended = appendItemsToInvoice(queue);
      if (!focusTr) focusTr = appended;
    }
    renumberRows();
    syncJson();
    markFormDirty();
    if (focusTr) {
      focusRowQtyField(focusTr);
    }
  }

  function normalizeItemCode(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function itemMatchesCode(item, code) {
    var c = normalizeItemCode(code);
    if (!c || !item) return false;
    var material = normalizeItemCode(
      item.material_number || itemMaterialNumber(item.barcode, item.sku)
    );
    return (
      normalizeItemCode(item.barcode) === c ||
      normalizeItemCode(item.sku) === c ||
      (material !== '' && material === c)
    );
  }

  function buildItemsApiUrl(q, listAll, opts) {
    opts = opts || {};
    var url = apiItemsUrl;
    var parts = [];
    if (opts.exactCode) {
      parts.push('code=' + encodeURIComponent(String(opts.exactCode).trim()));
    } else {
      if (listAll || q === '') parts.push('list=1');
      else if (q) parts.push('q=' + encodeURIComponent(q));
    }
    if (parts.length) url += (apiItemsUrl.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    return url;
  }

  function getBarcodeFromRow(tr) {
    var inp = tr.querySelector('.js-barcode-inp');
    if (inp) {
      return String(inp.value || '').trim();
    }
    var skuEl = tr.querySelector('.js-sku');
    return skuEl ? String(skuEl.textContent || '').trim() : '';
  }

  function applyBarcodeItemToRow(tr, item) {
    var normalized = normalizePickerItem(item);
    if (!normalized || !tr) return false;
    if (blockItemPickUntilQty(tr, { actionLabel: 'إدخال مادة' })) return false;

    var wasEntry = tr.classList.contains('is-entry-row');
    var emptyLine = !parseInt(tr.dataset.itemId, 10);
    var focusTr = null;

    if (wasEntry) {
      focusTr = appendItemsToInvoice([normalized]);
      var entry = getEntryRow();
      var bc = entry && entry.querySelector('.js-barcode-inp');
      if (bc) bc.value = '';
    } else if (emptyLine) {
      fillRowFromItem(tr, normalized);
      focusTr = tr;
    } else {
      focusTr = appendItemsToInvoice([normalized]);
    }

    renumberRows();
    syncJson();

    if (focusTr) {
      focusRowQtyField(focusTr);
    }
    return true;
  }

  function pickFromSearchResults(items, code, opts) {
    opts = opts || {};
    if (!items || !items.length) return null;
    if (code) {
      for (var i = 0; i < items.length; i++) {
        if (itemMatchesCode(items[i], code)) return items[i];
      }
    }
    if (opts.exactLookup && items.length > 0) return items[0];
    if (items.length === 1) return items[0];
    return null;
  }

  function resolveBarcodeOnRow(tr) {
    if (!tr || deliveryIsPosted) return;
    var code = getBarcodeFromRow(tr);
    if (!code) {
      openPickerForRow(tr);
      return;
    }
    fetch(buildItemsApiUrl('', false, { exactCode: code }), { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok && data.items && data.items.length) {
          var pick = pickFromSearchResults(data.items, code, { exactLookup: !!data.exact });
          if (pick && applyBarcodeItemToRow(tr, pick)) return;
        }
        return fetch(buildItemsApiUrl(code, false), { credentials: 'same-origin' })
          .then(function (r2) {
            return r2.json();
          })
          .then(function (data2) {
            if (!data2 || !data2.ok || !data2.items || !data2.items.length) {
              if (global.AppDialog) {
                AppDialog.alert('لم يُعثر على مادة بهذا الباركود أو الرمز.', { type: 'warning' });
              }
              return;
            }
            var pick2 = pickFromSearchResults(data2.items, code, { exactLookup: false });
            if (pick2 && applyBarcodeItemToRow(tr, pick2)) return;
            openPickerForRow(tr, { initialSearch: code });
          });
      })
      .catch(function () {
        if (global.AppDialog) AppDialog.error('تعذر البحث عن المادة.');
      });
  }

  function listNavRows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-line-id]')).filter(function (tr) {
      if (tr.classList.contains('is-entry-row')) return true;
      return parseInt(tr.dataset.itemId, 10) > 0;
    });
  }

  function getRowNavFields(tr) {
    if (getRowItemId(tr) < 1) {
      var bc = tr.querySelector('.js-barcode-inp');
      if (!bc || bc.disabled || bc.classList.contains('is-qty-required-locked')) return [];
      return [bc];
    }
    return LINE_NAV_SELECTORS.map(function (sel) {
      return tr.querySelector(sel);
    }).filter(function (el) {
      if (!el || el.disabled || el.readOnly || el.offsetParent === null) return false;
      if (el.classList.contains('is-item-pick-locked') || el.classList.contains('is-qty-required-locked')) {
        return false;
      }
      return true;
    });
  }

  function focusFieldEl(el) {
    if (!el) return;
    el.focus();
    if (el.select && el.tagName !== 'SELECT') el.select();
  }

  function handleTableArrowKey(e, tr, el) {
    var key = e.key;
    if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'ArrowLeft' && key !== 'ArrowRight') {
      return false;
    }
    e.preventDefault();

    var rows = listNavRows();
    var rowIdx = rows.indexOf(tr);
    if (rowIdx < 0) return true;

    var fields = getRowNavFields(tr);
    var colIdx = fields.indexOf(el);
    if (colIdx < 0) return true;

    var isRtl = document.documentElement.getAttribute('dir') === 'rtl';
    var dRow = 0;
    var dCol = 0;
    if (key === 'ArrowUp') dRow = -1;
    else if (key === 'ArrowDown') dRow = 1;
    else if (key === 'ArrowRight') dCol = isRtl ? -1 : 1;
    else if (key === 'ArrowLeft') dCol = isRtl ? 1 : -1;

    if (dCol !== 0) {
      var newCol = colIdx + dCol;
      if (newCol >= 0 && newCol < fields.length) {
        focusFieldEl(fields[newCol]);
      }
      return true;
    }

    if (dRow !== 0) {
      if (rowNeedsQtyInput(tr)) {
        focusRowQtyField(tr);
        return true;
      }
      var newRowIdx = rowIdx + dRow;
      while (newRowIdx >= 0 && newRowIdx < rows.length) {
        var targetRow = rows[newRowIdx];
        if (getRowItemId(targetRow) < 1) {
          if (blockItemPickUntilQty(targetRow, { alert: false, actionLabel: 'الانتقال لسطر جديد' })) {
            return true;
          }
          focusRowMaterialCodeField(targetRow);
          return true;
        }
        var targetFields = getRowNavFields(targetRow);
        if (targetFields.length) {
          var pickCol = Math.min(colIdx, targetFields.length - 1);
          focusFieldEl(targetFields[pickCol]);
          return true;
        }
        newRowIdx += dRow;
      }
    }
    return true;
  }

  function completeLineAndNext(tr) {
    var itemId = parseInt(tr.dataset.itemId, 10);
    if (!itemId) {
      openPickerForRow(tr);
      return;
    }
    if (rowNeedsQtyInput(tr)) {
      focusRowQtyField(tr);
      return;
    }
    syncJson();
    if (tr.classList.contains('is-entry-row')) finalizeEntryRow(tr);
    if (blockItemPickUntilQty(null, { alert: false, actionLabel: 'إضافة سطر جديد' })) {
      return;
    }
    var entry = ensureEntryRow();
    focusRowMaterialCodeField(entry);
  }

  function bindRow(tr) {
    if (tr.dataset.rowBound === '1') return;
    tr.dataset.rowBound = '1';

    tr.querySelectorAll('.js-pick-open').forEach(function (pickBtn) {
      pickBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openPickerForRow(tr);
      });
    });

    var itemCell = tr.querySelector('.sales-inv-item-cell');
    if (itemCell) {
      itemCell.addEventListener('click', function (e) {
        if (e.target.closest('.js-pick-open')) return;
        e.preventDefault();
        e.stopPropagation();
        openPickerForRow(tr);
      });
      itemCell.addEventListener('mousedown', function (e) {
        if (e.target.closest('.js-pick-open')) return;
        if (itemCell.classList.contains('is-qty-required-locked')) {
          e.preventDefault();
          alertQtyRequired(tr, 'اختيار مادة أخرى');
          focusRowQtyField(tr);
        }
      });
    }

    applyRowItemPickLock(tr);

    tr.querySelectorAll('.js-qty, .js-barcode-inp').forEach(function (lockEl) {
      lockEl.addEventListener('mousedown', function (e) {
        if (lockEl.classList.contains('is-qty-required-locked')) {
          e.preventDefault();
          focusRowQtyField(tr);
          return;
        }
        if (getRowItemId(tr) < 1 && !deliveryIsPosted && lockEl.classList.contains('js-qty')) {
          e.preventDefault();
          focusRowMaterialCodeField(tr);
        }
      });
      lockEl.addEventListener('focus', function () {
        if (lockEl.classList.contains('is-qty-required-locked')) {
          lockEl.blur();
          focusRowQtyField(tr);
          return;
        }
        if (getRowItemId(tr) < 1 && !deliveryIsPosted && lockEl.classList.contains('js-qty')) {
          lockEl.blur();
          focusRowMaterialCodeField(tr);
        }
      });
    });

    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, barcodeInp)) return;
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (getRowItemId(tr) > 0 && String(barcodeInp.value || '').trim() === '') {
          focusRowQtyField(tr);
          return;
        }
        resolveBarcodeOnRow(tr);
      });
      barcodeInp.addEventListener('blur', function () {
        var code = String(barcodeInp.value || '').trim();
        if (getRowItemId(tr) < 1 && code) {
          resolveBarcodeOnRow(tr);
        }
      });
    }

    var skuCell = tr.querySelector('.sales-inv-col-sku');
    if (skuCell) {
      skuCell.addEventListener('click', function (e) {
        if (e.target.closest('.js-barcode-inp')) return;
        if (getRowItemId(tr) < 1 && barcodeInp && !deliveryIsPosted) {
          e.preventDefault();
          focusRowMaterialCodeField(tr);
        }
      });
    }

    applyQtyInputAttrs(tr);

    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) {
      qtyEl.addEventListener('input', function () {
        syncJson();
        applyRowQtyLock(tr);
      });
      qtyEl.addEventListener('change', function () {
        normalizeQtyInput(qtyEl);
        syncJson();
        applyRowQtyLock(tr);
      });
      qtyEl.addEventListener('blur', function () {
        normalizeQtyInput(qtyEl);
        applyRowQtyLock(tr);
      });
      qtyEl.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, qtyEl)) return;
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (blockLineNavIfQtyMissing(tr, qtyEl)) return;
        completeLineAndNext(tr);
      });
    }

    var removeBtn = tr.querySelector('.js-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (deliveryIsPosted) return;
        var wasEntry = tr.classList.contains('is-entry-row');
        tr.remove();
        if (wasEntry || !getEntryRow()) ensureEntryRow();
        renumberRows();
        syncJson();
        markFormDirty();
      });
      if (tr.classList.contains('is-entry-row')) {
        removeBtn.style.visibility = 'hidden';
      }
    }
  }

  function isPickerOpen() {
    return global.ItemPickerModal && ItemPickerModal.isOpen();
  }

  function openPickerForRow(tr, opts) {
    opts = opts || {};
    if (!tr || deliveryIsPosted) return;
    if (blockItemPickUntilQty(tr, { actionLabel: 'اختيار مادة' })) return;
    if (global.CustomerPickerModal) {
      CustomerPickerModal.close();
    }
    if (!global.ItemPickerModal) {
      if (global.AppDialog) {
        AppDialog.alert('نافذة اختيار المواد غير متوفرة في الصفحة.', { type: 'warning' });
      }
      return;
    }
    if (!apiItemsUrl) {
      if (global.AppDialog) {
        AppDialog.alert('تعذر تحميل قائمة المواد: رابط البحث غير مضبوط.', { type: 'warning' });
      }
      return;
    }
    ItemPickerModal.open({
      buildItemsUrl: function (q, listAll) {
        return buildItemsApiUrl(q, listAll);
      },
      getWarehouseId: function () {
        return 0;
      },
      initialSearch: opts.initialSearch || '',
      emptyMessage: 'لا توجد مواد مطابقة',
      onOpen: function () {
        tbody.querySelectorAll('tr.is-picker-active').forEach(function (r) {
          r.classList.remove('is-picker-active');
        });
        tr.classList.add('is-picker-active');
        activePickRow = tr;
      },
      onClose: function () {
        if (activePickRow) {
          activePickRow.classList.remove('is-picker-active');
          activePickRow = null;
        }
      },
      onConfirm: function (items) {
        addPickerItems(items);
      },
    });
  }

  function closePicker() {
    if (global.ItemPickerModal) {
      ItemPickerModal.close();
    }
  }

  tbody.addEventListener(
    'keydown',
    function (e) {
      if (e.key !== 'Enter') return;
      var inp = e.target.closest('.js-barcode-inp');
      if (!inp || !tbody.contains(inp)) return;
      if (handleTableArrowKey(e, inp.closest('tr[data-line-id]'), inp)) return;
      var tr = inp.closest('tr[data-line-id]');
      if (!tr) return;
      e.preventDefault();
      e.stopPropagation();
      resolveBarcodeOnRow(tr);
    },
    true
  );

  form.addEventListener('input', markFormDirtyFromEvent);
  form.addEventListener('change', markFormDirtyFromEvent);
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (deliveryIsPosted) return;
    trySave();
  });

  form.addEventListener(
    'click',
    function (e) {
      var pickBtn = e.target.closest('.js-pick-open');
      if (pickBtn) {
        e.preventDefault();
        e.stopPropagation();
        var trPick = pickBtn.closest('tr[data-line-id]');
        if (trPick) openPickerForRow(trPick);
        return;
      }
      var itemCell = e.target.closest('.sales-inv-item-cell');
      if (itemCell) {
        var trCell = itemCell.closest('tr[data-line-id]');
        if (trCell && !e.target.closest('.js-pick-open')) {
          e.preventDefault();
          e.stopPropagation();
          openPickerForRow(trCell);
        }
      }
    },
    true
  );

  function fetchDeliveryResponse(query) {
    if (!apiDeliveryUrl) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiDeliveryUrl + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function applyDeliveryData(data) {
    var d = data.delivery;
    var lines = data.lines || [];
    if (!d) return;

    runWithoutDirtyMark(function () {
      currentDeliveryId = parseInt(d.id, 10) || 0;
      deliveryIsPosted = !!d.is_posted;
      syncDeliveryIdField();
      syncDeliveryNoDisplay(d.delivery_no || '');

      var dlvDate = document.getElementById('dlv_date');
      if (dlvDate) {
        dlvDate.value = fmtDate(d.delivery_date_dmy || d.delivery_date || '') || defaultDate;
      }

      if (dlvCustomerPickerApi) {
        dlvCustomerPickerApi.setById(d.customer_id || 0, true);
      } else {
        var custSel = document.getElementById('dlv_customer');
        if (custSel) custSel.value = String(d.customer_id || '');
      }

      var notes = document.getElementById('dlv_notes');
      if (notes) notes.value = d.notes || '';

      var wh = document.getElementById('dlv_wh');
      if (wh) {
        wh.value = d.warehouse_id ? String(d.warehouse_id) : '';
      }

      tbody.innerHTML = '';
      ensureEntryRow();
      lines.forEach(function (ln) {
        addLineFromData(ln);
      });

      renumberRows();
      syncJson();
      refreshDeliveryEditState();
      updatePostedBadge();
      syncInvoiceLinkHint(d);
      applyBrowseNavFromPayload(d);
      updateHistory(currentDeliveryId);
      closePicker();
    });
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newDeliveryUrl || window.location.pathname + '?r=sales_delivery';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    window.history.replaceState({ deliveryId: id }, '', url);
  }

  function loadDeliveryById(id, skipConfirm) {
    if (id < 1) return;
    function doLoad() {
      fetchDeliveryResponse({ id: id }).then(function (data) {
        if (!data || !data.ok || !data.delivery) {
          if (global.AppDialog) {
            AppDialog.error((data && data.message) || 'تعذر تحميل السند.');
          }
          return;
        }
        applyDeliveryData(data);
      });
    }
    if (skipConfirm) doLoad();
    else confirmUnsavedChanges(doLoad);
  }

  function loadDeliveryByNo(no) {
    no = String(no || '').trim();
    if (!no) return;
    confirmUnsavedChanges(function () {
      fetchDeliveryResponse({ no: no }).then(function (data) {
        if (!data || !data.ok || !data.delivery) {
          if (global.AppDialog) {
            AppDialog.error((data && data.message) || 'لم يتم العثور على سند يحتوي على هذا الرقم.');
          }
          return;
        }
        applyDeliveryData(data);
      });
    });
  }

  function navigateDelivery(dir) {
    confirmUnsavedChanges(function () {
      navigateDeliveryCore(dir);
    });
  }

  function navigateDeliveryCore(dir) {
    if (currentDeliveryId < 1) {
      navigateEmptyDelivery(dir);
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchDeliveryResponse({ id: id });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.delivery);
        },
        getPayload: function (data) {
          return data;
        },
        apply: applyDeliveryData,
        loadError: 'تعذر تحميل السند.',
      });
      return;
    }
    fetchDeliveryResponse({ id: currentDeliveryId, dir: dir }).then(function (data) {
      if (!data || !data.ok || !data.delivery) {
        if (global.AppDialog) {
          AppDialog.alert(
            (data && data.message) ||
              (dir === 'prev' ? 'لا يوجد سند أقدم.' : 'لا يوجد سند أحدث.'),
            { type: 'info' }
          );
        }
        return;
      }
      applyDeliveryData(data);
    });
  }

  function runToolbarDeliverySearch() {
    var dlvNoEl = document.getElementById('dlv_no');
    var no = dlvNoEl ? String(dlvNoEl.value || '').trim() : '';
    if (!no) {
      AppDialog.alert('أدخل رقم السند في الحقل أعلاه ثم اضغط بحث.', { type: 'warning' });
      if (dlvNoEl) dlvNoEl.focus();
      return;
    }
    loadDeliveryByNo(no);
  }

  function isOraUi() {
    return document.body && document.body.classList.contains('hr-ora-ui');
  }

  function confirmUnsavedChanges(onProceed, onCancel) {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.confirmSaveDiscardLeave === 'function') {
      global.ScreenExitGuard.confirmSaveDiscardLeave({
        when: function () {
          return formDirty && !deliveryIsPosted;
        },
        onSave: function (proceed) {
          trySave(proceed);
        },
        onDiscard: function (proceed) {
          clearFormDirty();
          if (proceed) proceed();
        },
        onProceed: onProceed,
        onCancel: onCancel,
      });
      return;
    }
    if (!formDirty || deliveryIsPosted) {
      if (onProceed) onProceed();
      return;
    }
    if (onProceed) onProceed();
  }

  function postCurrent() {
    if (!deliveryPostUrl) {
      AppDialog.alert('الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentDeliveryId < 1) {
      AppDialog.alert('احفظ السند أولًا قبل الترحيل.', { type: 'warning' });
      return;
    }
    if (deliveryIsPosted) {
      AppDialog.alert('هذا السند مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'ترحيل سند التسليم؟\nسيُخصم المخزون من المستودع بدون ذمة على العميل.',
      { title: 'ترحيل السند' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('delivery_id', String(currentDeliveryId));
      fetch(deliveryPostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            AppDialog.error(data.error || data.message || 'تعذر الترحيل.');
            return;
          }
          deliveryIsPosted = true;
          updatePostedBadge();
          refreshDeliveryEditState();
          AppDialog.success(
            data.message || 'تم الترحيل — صُرف المخزون بدون ذمة على العميل.'
          );
          loadDeliveryById(currentDeliveryId, true);
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function unpostCurrent() {
    if (!deliveryUnpostUrl || !canUnpostDelivery) {
      AppDialog.alert('فك الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentDeliveryId < 1 || !deliveryIsPosted) {
      AppDialog.alert('السند غير مرحّل.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'فك ترحيل سند التسليم؟\nسيُعاد المخزون إلى المستودع.',
      { title: 'فك الترحيل' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('delivery_id', String(currentDeliveryId));
      fetch(deliveryUnpostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            AppDialog.error(data.error || data.message || 'تعذر فك الترحيل.');
            return;
          }
          deliveryIsPosted = false;
          updatePostedBadge();
          refreshDeliveryEditState();
          AppDialog.success(data.message || 'تم فك الترحيل.');
          loadDeliveryById(currentDeliveryId, true);
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function resetDeliveryForm() {
    runWithoutDirtyMark(function () {
      if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
      currentDeliveryId = 0;
      deliveryIsPosted = false;
      syncDeliveryIdField();
      syncDeliveryNoDisplay('');
      var dlvDate = document.getElementById('dlv_date');
      if (dlvDate && defaultDate) dlvDate.value = defaultDate;
      var cust = document.getElementById('dlv_customer');
      if (cust) cust.value = '';
      var wh = document.getElementById('dlv_wh');
      if (wh && defaultWarehouseId > 0) wh.value = String(defaultWarehouseId);
      else if (wh) wh.value = '';
      var notes = document.getElementById('dlv_notes');
      if (notes) notes.value = '';
      tbody.innerHTML = '';
      closePicker();
      ensureEntryRow();
      renumberRows();
      syncJson();
      refreshDeliveryEditState();
      updatePostedBadge();
      syncInvoiceLinkHint(null);
      refreshEmptyBrowseNav();
      updateHistory(0);
    });
  }

  function initNewDelivery() {
    resetDeliveryForm();
    var entry = getEntryRow();
    if (entry) {
      setTimeout(function () {
        var bc = entry.querySelector('.js-barcode-inp');
        if (bc) bc.focus();
      }, 80);
    }
  }

  function buildDeliveryPrintInnerHtml() {
    var dateEl = document.getElementById('dlv_date');
    var date = fmtDate(dateEl ? dateEl.value : '');
    var cust = dlvCustomerPickerApi
      ? dlvCustomerPickerApi.getName()
      : window.CustomerPickerModal
        ? CustomerPickerModal.getLabel('dlv_customer')
        : '';
    var dlvNoEl = document.getElementById('dlv_no');
    var dlvNo = dlvNoEl && dlvNoEl.value ? dlvNoEl.value : '—';
    var notes = document.getElementById('dlv_notes');
    var notesVal = notes ? String(notes.value).trim() : '';
    var notesBlock = notesVal
      ? '<p style="margin:0.75rem 0 0;font-size:0.88rem;"><strong>ملاحظات:</strong> ' +
        escapeHtml(notesVal) +
        '</p>'
      : '';

    var rowHtml = '';
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (!itemId) return;
      var seqEl = tr.querySelector('.js-seq');
      var seq = seqEl ? seqEl.textContent : '';
      var barcode = getBarcodeFromRow(tr);
      var skuEl = tr.querySelector('.js-sku');
      var materialNo = skuEl ? String(skuEl.textContent || '').trim() : barcode;
      rowHtml +=
        '<tr>' +
        '<td style="padding:0.4rem;border:1px solid #cbd5e1;text-align:center;">' +
        escapeHtml(seq) +
        '</td>' +
        '<td style="padding:0.4rem;border:1px solid #cbd5e1;text-align:center;font-family:Arial,Helvetica,sans-serif;">' +
        escapeHtml(materialNo || barcode) +
        '</td>' +
        '<td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(tr.dataset.nameAr || '') +
        '</td>' +
        '<td style="padding:0.4rem;border:1px solid #cbd5e1;text-align:center;">' +
        escapeHtml(tr.querySelector('.js-qty').value) +
        '</td>' +
        '</tr>';
    });

    if (!rowHtml) {
      rowHtml =
        '<tr><td colspan="4" style="padding:1rem;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    var metaRows = [
      { label: 'رقم السند', value: dlvNo },
      { label: 'التاريخ', value: date },
      { label: 'العميل', value: cust },
    ];
    var metaTable =
      window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function'
        ? window.DocumentHeader.buildMetaTable(metaRows)
        : '<table><tr><td><strong>رقم السند:</strong> ' + escapeHtml(dlvNo) + '</td></tr></table>';

    var inner =
      buildDocPrintHeader('سند تسليم بضاعة') +
      metaTable +
      '<table><thead><tr>' +
      '<th>#</th><th>رقم المادة</th><th>المادة</th><th>الكمية</th>' +
      '</tr></thead><tbody>' +
      rowHtml +
      '</tbody></table>' +
      notesBlock +
      buildRecipientSignatureBlock();
    return window.DocumentHeader && window.DocumentHeader.wrapPrintContent
      ? window.DocumentHeader.wrapPrintContent(inner, companyLogoUrl)
      : inner;
  }

  function docPrintWatermarkStyles() {
    var dh = window.DocumentHeader;
    return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
      ? dh.buildPrintWatermarkStyles(companyLogoUrl)
      : '';
  }

  function getPrintFrameStyles() {
    var hdr = window.DocumentHeader && window.DocumentHeader.css ? window.DocumentHeader.css : '';
    var bold =
      window.DocumentHeader && window.DocumentHeader.printBoldCss
        ? window.DocumentHeader.printBoldCss
        : '';
    return (
      docPrintWatermarkStyles() +
      hdr +
      bold +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm 12mm;direction:rtl;}' +
      'table{border-collapse:collapse;width:100%;margin-top:0.5rem;}' +
      'th{background:#f1f5f9;padding:0.45rem;border:1px solid #94a3b8;font-size:12px;font-weight:700;}' +
      'td{padding:0.4rem;border:1px solid #cbd5e1;text-align:center;font-weight:700;}' +
      '.doc-print-meta{text-align:start;direction:rtl;}.doc-print-meta table{width:100%;border-collapse:collapse;}' +
      '.doc-print-meta td{border:none!important;padding:0.2rem 0!important;text-align:start!important;}' +
      '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}'
    );
  }

  function buildStandaloneDeliveryHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند تسليم بضاعة</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildDeliveryPrintInnerHtml() +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('sales-dlv-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'sales-dlv-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    return frame;
  }

  function printHtmlInFrame(fullHtml) {
    var frame = getPrintFrame();
    if (window.PrintOrientation) {
      fullHtml = PrintOrientation.prepareHtml(fullHtml);
      PrintOrientation.sizeFrame(frame);
    }
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 200);
  }

  function closePrintPreview() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    if (overlay) overlay.hidden = true;
  }

  function openPrintPreview(forPdf) {
    syncJson();
    var preview = document.getElementById('sales-inv-print-preview');
    var overlay = document.getElementById('sales-inv-print-overlay');
    var title = document.querySelector('.sales-inv-print-overlay-title');
    if (!preview || !overlay) {
      printHtmlInFrame(buildStandaloneDeliveryHtml());
      return;
    }
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    preview.innerHTML = '<div class="sales-inv-print-paper">' + buildDeliveryPrintInnerHtml() + '</div>';
    if (window.PrintOrientation) {
      var _po = document.getElementById('sales-inv-print-overlay');
      if (_po) PrintOrientation.markActive(_po);
    }
    if (title) {
      title.textContent = forPdf
        ? 'معاينة شكل الورقة — اختر «حفظ كـ PDF» من نافذة الطباعة'
        : 'معاينة شكل الورقة';
    }
    overlay.removeAttribute('hidden');
    overlay.hidden = false;
    overlay.style.display = 'flex';
    overlay.style.zIndex = '10050';
    overlay.style.opacity = '';
  }

  function runPrintFromPreview() {
    syncJson();
    printHtmlInFrame(buildStandaloneDeliveryHtml());
  }

  function handleToolbarPrint() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    var previewOpen = overlay && !overlay.hidden;
    if (previewOpen) {
      runPrintFromPreview();
      return;
    }
    openPrintPreview(false);
  }

  function getDeliveryFileBase() {
    var dlvNoEl = document.getElementById('dlv_no');
    var no = dlvNoEl && dlvNoEl.value ? String(dlvNoEl.value).trim() : '';
    if (!no) no = 'draft';
    return 'delivery-' + no.replace(/[^\w\u0600-\u06FF\-]+/g, '_');
  }

  function downloadDeliveryExcel() {
    syncJson();
    var html = buildStandaloneDeliveryHtml();
    var blob = new Blob(['\uFEFF' + html], {
      type: 'application/vnd.ms-excel;charset=utf-8',
    });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = getDeliveryFileBase() + '.xls';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(url);
      a.remove();
    }, 200);
  }

  function downloadDeliveryPdf() {
    syncJson();
    if (typeof html2pdf === 'undefined') {
      AppDialog.error('تعذر تحميل مكتبة PDF. تحقق من الاتصال بالإنترنت ثم أعد تحميل الصفحة.');
      return;
    }
    var fname = getDeliveryFileBase() + '.pdf';
    var overlay = document.getElementById('sales-inv-print-overlay');
    var preview = document.getElementById('sales-inv-print-preview');
    if (!overlay || !preview) {
      AppDialog.error('عنصر المعاينة غير متاح. أعد تحميل الصفحة.');
      return;
    }
    var wasHidden = overlay.hidden;
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    preview.innerHTML = buildDeliveryPrintInnerHtml();
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
    var waitForImages = function (cb) {
      var imgs = preview.querySelectorAll('img');
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
    };
    requestAnimationFrame(function () {
      waitForImages(function () {
        try {
          html2pdf()
            .set({
              margin: [10, 10, 10, 10],
              filename: fname,
              image: { type: 'jpeg', quality: 0.95 },
              html2canvas: {
                scale: 2,
                logging: false,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff',
              },
              jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
              pagebreak: { mode: ['css', 'legacy'] },
            })
            .from(preview)
            .save()
            .then(cleanup)
            .catch(function (err) {
              try {
                console.error('[pdf] export failed', err);
              } catch (_e) {}
              cleanup();
              AppDialog.error('تعذر إنشاء ملف PDF.');
            });
        } catch (err) {
          try {
            console.error('[pdf] sync error', err);
          } catch (_e) {}
          cleanup();
          AppDialog.error('تعذر إنشاء ملف PDF.');
        }
      });
    });
  }

  function bootstrapExistingRows() {
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!tr.dataset.lineId) tr.dataset.lineId = newLineId();
      if (!tr.dataset.itemId) tr.dataset.itemId = '';
      applyQtyInputAttrs(tr);
      bindRow(tr);
    });
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!form || !e.detail) return;
    var action = e.detail.action;

    if (action === 'search') {
      e.preventDefault();
      e.stopImmediatePropagation();
      runToolbarDeliverySearch();
      return;
    }
    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      trySave();
      return;
    }
    if (action === 'post') {
      e.preventDefault();
      postCurrent();
      return;
    }
    if (action === 'unpost') {
      e.preventDefault();
      unpostCurrent();
      return;
    }
    if (action === 'print') {
      e.preventDefault();
      e.stopImmediatePropagation();
      handleToolbarPrint();
      return;
    }
    if (action === 'excel') {
      e.preventDefault();
      e.stopImmediatePropagation();
      downloadDeliveryExcel();
      return;
    }
    if (action === 'pdf') {
      e.preventDefault();
      e.stopImmediatePropagation();
      downloadDeliveryPdf();
      return;
    }
    if (action === 'delete') {
      e.preventDefault();
      if (currentDeliveryId > 0) {
        if (deliveryIsPosted) {
          AppDialog.alert('لا يمكن حذف سند مرحّل.', { type: 'warning' });
          return;
        }
        var dlvId = currentDeliveryId;
        var dlvNoEl = document.getElementById('dlv_no');
        var dlvLabel = dlvNoEl && dlvNoEl.value ? dlvNoEl.value : String(dlvId);
        if (!deliveryDeleteUrl) {
          AppDialog.error('حذف السند غير متاح.');
          return;
        }
        AppDialog.confirm(
          'حذف السند «' + dlvLabel + '» نهائياً؟\nلا يمكن التراجع عن هذا الإجراء.',
          { title: 'حذف السند', danger: true, okText: 'حذف' }
        ).then(function (ok) {
          if (!ok) return;
          var fd = new FormData();
          var csrfInp = form.querySelector('input[name="_csrf"]');
          fd.append('_csrf', csrfInp ? csrfInp.value : '');
          fd.append('delivery_id', String(dlvId));
          fetch(deliveryDeleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (!data.ok) {
                AppDialog.error(data.error || data.message || 'تعذر حذف السند.');
                return;
              }
              AppDialog.success(data.message || 'تم حذف السند.').then(function () {
                if (newDeliveryUrl) {
                  window.location.href = newDeliveryUrl;
                } else {
                  initNewDelivery();
                }
              });
            })
            .catch(function () {
              AppDialog.error('تعذر الاتصال بالخادم.');
            });
        });
        return;
      }
      if (!hasDraftContent()) {
        resetDeliveryForm();
        return;
      }
      AppDialog.confirm('مسح بيانات السند الحالي (البنود والحقول)؟', {
        title: 'مسح السند',
        danger: true,
        okText: 'مسح',
      }).then(function (ok) {
        if (ok) resetDeliveryForm();
      });
      return;
    }
    if (action === 'exit') {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (exitUrl) {
          window.location.href = exitUrl;
        } else {
          var bar = document.getElementById('master-toolbar');
          var url = bar ? bar.getAttribute('data-exit-url') : '';
          if (url) window.location.href = url;
          else window.history.back();
        }
      });
    }
  });

  var newDlvBtn = document.querySelector('.sales-inv-btn-new');
  if (newDlvBtn) {
    newDlvBtn.addEventListener('click', function (e) {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (newDeliveryUrl) {
          window.location.href = newDeliveryUrl;
        } else {
          initNewDelivery();
        }
      });
    });
  }

  var oraCloseBtn = document.querySelector('.sales-dlv-wrap .ora12-title-bar__close');
  if (oraCloseBtn) {
    oraCloseBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var closeUrl = (document.getElementById('master-toolbar') || {}).getAttribute?.('data-close-url') || exitUrl;
      var href = oraCloseBtn.getAttribute('href') || closeUrl;
      confirmUnsavedChanges(function () {
        if (window.ScreenExitGuard && typeof window.ScreenExitGuard.navigateExit === 'function') {
          window.ScreenExitGuard.navigateExit(href || '');
          return;
        }
        if (href) {
          window.location.href = href;
        } else {
          window.history.back();
        }
      });
    }, true);
  }

  var prevBtn = document.getElementById('dlv_no_prev');
  var nextBtn = document.getElementById('dlv_no_next');
  var dlvNoInput = document.getElementById('dlv_no');
  if (prevBtn) {
    prevBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      navigateDelivery('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      navigateDelivery('next');
    });
  }
  if (dlvNoInput) {
    dlvNoInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runToolbarDeliverySearch();
    });
    dlvNoInput.addEventListener('blur', function () {
      var no = dlvNoInput.value.trim();
      if (window.DocumentNoNav && DocumentNoNav.shouldSkipBlurSearch(docNoSearch, currentDeliveryId, no)) {
        return;
      }
      loadDeliveryByNo(no);
    });
  }

  var printCloseBtn = document.getElementById('sales-inv-print-close');
  var printRunBtn = document.getElementById('sales-inv-print-run');
  var printOverlay = document.getElementById('sales-inv-print-overlay');
  if (printRunBtn) {
    printRunBtn.addEventListener('click', function () {
      runPrintFromPreview();
    });
  }
  if (printCloseBtn) {
    printCloseBtn.addEventListener('click', closePrintPreview);
  }
  if (printOverlay) {
    printOverlay.addEventListener('click', function (e) {
      if (e.target === printOverlay) closePrintPreview();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var overlay = document.getElementById('sales-inv-print-overlay');
    if (overlay && !overlay.hidden) closePrintPreview();
  });

  var dlvCustomerPickerApi = null;

  var unlinkInvoiceBtn = document.getElementById('dlv_unlink_invoice_btn');
  if (unlinkInvoiceBtn) {
    unlinkInvoiceBtn.addEventListener('click', function (e) {
      e.preventDefault();
      unlinkDeliveryInvoice();
    });
  }

  function bootDeliveryPage() {
    if (global.FinVoucherArchive && form) {
      global.FinVoucherArchive.init({
        apiUrl: form.getAttribute('data-archive-api') || '',
        csrf: (form.querySelector('input[name="_csrf"]') || {}).value || '',
        kind: form.getAttribute('data-archive-kind') || 'sales_delivery',
        title: 'سند تسليم',
        canArchive: form.getAttribute('data-can-archive') === '1',
        getVoucherId: function () {
          return currentDeliveryId;
        },
        getVoucherLabel: function () {
          return {
            no: (document.getElementById('dlv_no') || {}).value || '',
            date: (document.getElementById('dlv_date') || {}).value || '',
          };
        },
        companyName: form.getAttribute('data-company-name') || '',
        isArchiveAllowed: deliveryArchiveState,
      });
    }
    if (window.CustomerPickerModal) {
      dlvCustomerPickerApi = CustomerPickerModal.bind({
        hidden: 'dlv_customer',
        open: 'dlv_customer_open',
        display: 'dlv_customer_display',
        jsonId: 'sales-dlv-customers-json',
        getDisabled: function () {
          return deliveryIsPosted;
        },
        onSelect: function () {
          markFormDirty();
        },
      });
    }
    bootstrapExistingRows();
    if (initialDeliveryId > 0) {
      fetchDeliveryResponse({ id: initialDeliveryId }).then(function (data) {
        if (data && data.ok && data.delivery) {
          applyDeliveryData(data);
        }
      });
      ensureEntryRow();
      return;
    }
    runWithoutDirtyMark(function () {
      if (!getEntryRow()) tbody.innerHTML = '';
      bootstrapExistingRows();
      ensureEntryRow();
      renumberRows();
      syncJson();
      refreshDeliveryEditState();
      refreshEmptyBrowseNav();
      var firstEntry = getEntryRow();
      if (firstEntry) {
        setTimeout(function () {
          var bc = firstEntry.querySelector('.js-barcode-inp');
          if (bc) bc.focus();
        }, 80);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDeliveryPage);
  } else {
    bootDeliveryPage();
  }

  window.addEventListener('beforeunload', function (e) {
    if (window.__managerAllowUnload) return;
    if (formSubmitting || !formDirty || deliveryIsPosted) return;
    e.preventDefault();
    e.returnValue = '';
  });

  if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExitDeferred === 'function') {
    global.ScreenExitGuard.registerScreenExitDeferred({
      hasUnsaved: function () {
        return formDirty && !deliveryIsPosted;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExit === 'function') {
    global.ScreenExitGuard.registerScreenExit({
      hasUnsaved: function () {
        return formDirty && !deliveryIsPosted;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else {
    global.ManagerScreenExit = {
      hasUnsaved: function () {
        return formDirty && !deliveryIsPosted;
      },
      confirmLeave: confirmUnsavedChanges,
    };
  }
})();
