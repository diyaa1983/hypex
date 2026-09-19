(function () {
  'use strict';

  var cfg = window.MRepTransferConfig || {};
  var form = document.getElementById('m-rep-form');
  if (!form || !cfg.saveApi) return;

  var TB = window.MobileToolbar || {};
  var photoArchive = null;
  var cartArchiveBtn = null;

  var statusEl = document.getElementById('m-rep-status');
  var itemGrid = document.getElementById('m-rep-item-grid');
  var itemEmpty = document.getElementById('m-rep-item-empty');
  var itemSearch = document.getElementById('m-rep-item-search');
  var moveIdEl = document.getElementById('m-rep-move-id');
  var moveNoEl = document.getElementById('m-rep-move-no');
  var bagFab = document.getElementById('m-rep-bag-fab');
  var bagCount = document.getElementById('m-rep-bag-count');

  var qtyMini = document.getElementById('m-rep-qty-mini');
  var qtyMiniBackdrop = document.getElementById('m-rep-qty-mini-backdrop');
  var qtyMiniName = document.getElementById('m-rep-qty-mini-name');
  var qtyMiniMeta = document.getElementById('m-rep-qty-mini-meta');
  var qtyMiniInput = document.getElementById('m-rep-qty-mini-input');
  var qtyMiniCancel = document.getElementById('m-rep-qty-mini-cancel');
  var qtyMiniAdd = document.getElementById('m-rep-qty-mini-add');

  var cartEl = document.getElementById('m-rep-cart');
  var cartBackdrop = document.getElementById('m-rep-cart-backdrop');
  var cartClose = document.getElementById('m-rep-cart-close');
  var cartBody = document.getElementById('m-rep-cart-body');
  var cartSummary = document.getElementById('m-rep-cart-summary');
  var cartClear = document.getElementById('m-rep-cart-clear');
  var saveBtn = document.getElementById('m-rep-btn-save');
  var postBtn = document.getElementById('m-rep-btn-post');
  var deleteBtn = document.getElementById('m-rep-btn-delete');
  var cartPostedMsg = document.getElementById('m-rep-cart-posted-msg');
  var cartFooterNormal = document.getElementById('m-rep-cart-footer-normal');
  var pdfBtn = document.getElementById('m-rep-btn-pdf');
  var cartDoneBtn = document.getElementById('m-rep-cart-done');
  var topbarPdf = document.getElementById('m-rep-topbar-pdf');
  var pdfBanner = document.getElementById('m-rep-pdf-banner');
  var pdfBannerMsg = document.getElementById('m-rep-pdf-banner-msg');
  var bannerPdfBtn = document.getElementById('m-rep-banner-pdf');
  var bannerDoneBtn = document.getElementById('m-rep-pdf-banner-done');
  var pdfView = document.getElementById('m-rep-pdf-view');
  var pdfFrame = document.getElementById('m-rep-pdf-view-frame');
  var pdfViewClose = document.getElementById('m-rep-pdf-view-close');
  var pdfViewDl = document.getElementById('m-rep-pdf-view-dl');
  var pdfViewDone = document.getElementById('m-rep-pdf-view-done');

  var allItems = [];
  var cart = [];
  var moveId = 0;
  var lastPostedMoveId = 0;
  var pendingNextMoveNo = '';
  var lastPdfBlobUrl = '';
  var searchTimer = null;
  var busy = false;
  var pendingItemId = 0;

  function showStatus(msg, type) {
    if (!statusEl) return;
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.className = 'm-alert m-alert--' + (type === 'error' ? 'error' : 'success');
  }

  function hideStatus() {
    if (!statusEl) return;
    statusEl.hidden = true;
    statusEl.textContent = '';
  }

  function fmtQty(n) {
    var dp = cfg.decimalPlaces != null ? cfg.decimalPlaces : 2;
    return Number(n).toLocaleString('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: dp,
    });
  }

  function fmtMoveNo(n) {
    var v = parseInt(String(n || ''), 10);
    return isNaN(v) ? String(n || '') : String(v);
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function itemLetter(name) {
    var n = String(name || '').trim();
    return n ? n.charAt(0) : 'م';
  }

  function cartIndex(itemId) {
    for (var i = 0; i < cart.length; i++) {
      if (cart[i].item_id === itemId) return i;
    }
    return -1;
  }

  function cartLine(itemId) {
    var idx = cartIndex(itemId);
    return idx >= 0 ? cart[idx] : null;
  }

  function updateBagBadge() {
    if (!bagCount || !bagFab) return;
    var n = cart.length;
    if (n > 0) {
      bagCount.hidden = false;
      bagCount.textContent = String(n);
      bagFab.classList.add('has-items');
    } else {
      bagCount.hidden = true;
      bagCount.textContent = '0';
      bagFab.classList.remove('has-items');
    }
  }

  function renderItemGrid(filterQ) {
    if (!itemGrid) return;
    var q = String(filterQ || '').trim().toLowerCase();
    itemGrid.innerHTML = '';
    var shown = 0;
    allItems.forEach(function (it) {
      var hay = (String(it.name_ar || '') + ' ' + String(it.sku || '')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      shown++;
      var ln = cartLine(it.id);
      var inCart = !!ln;
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-rep-item-tile' + (inCart ? ' is-in-cart' : '');
      tile.setAttribute('role', 'listitem');
      tile.setAttribute('data-pick', String(it.id));
      tile.innerHTML =
        (inCart ? '<span class="m-rep-item-badge">' + escapeHtml(fmtQty(ln.qty)) + '</span>' : '') +
        '<span class="m-rep-item-tile-avatar" aria-hidden="true">' + escapeHtml(itemLetter(it.name_ar)) + '</span>' +
        '<span class="m-rep-item-tile-name">' + escapeHtml(it.name_ar || '') + '</span>' +
        '<span class="m-rep-item-tile-meta">' + escapeHtml(it.sku || '') +
        (it.stock_qty != null ? ' · ' + fmtQty(it.stock_qty) : '') + '</span>';
      itemGrid.appendChild(tile);
    });
    if (itemEmpty) itemEmpty.hidden = shown > 0;
    updateBagBadge();
    updatePdfButtonState();
  }

  function renderCart() {
    if (!cartBody) return;
    if (lastPostedMoveId > 0) {
      if (cartSummary && cartPostedMsg && cartPostedMsg.textContent) {
        cartSummary.textContent = cartPostedMsg.textContent;
      }
      return;
    }
    cartBody.innerHTML = '';
    if (cartSummary) {
      cartSummary.textContent = cart.length
        ? cart.length + ' مادة في السلة'
        : 'لا توجد مواد في السلة.';
    }
    if (!cart.length) {
      var emptyRow = document.createElement('tr');
      emptyRow.innerHTML = '<td colspan="3" class="m-rep-cart-empty">السلة فارغة — اختر مواد من القائمة.</td>';
      cartBody.appendChild(emptyRow);
      updatePdfButtonState();
      return;
    }
    cart.forEach(function (ln, idx) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td class="m-rep-cart-td-name">' +
        '<strong>' + escapeHtml(ln.name_ar || '') + '</strong>' +
        '<span class="muted">' + escapeHtml(ln.sku || '') + '</span></td>' +
        '<td class="m-rep-cart-td-qty" dir="ltr">' + escapeHtml(fmtQty(ln.qty)) + '</td>' +
        '<td class="m-rep-cart-td-act">' +
        '<button type="button" class="m-rep-cart-edit" data-cart-edit="' + ln.item_id + '" title="تعديل">✎</button>' +
        '<button type="button" class="m-rep-cart-del" data-cart-del="' + idx + '" title="حذف">×</button>' +
        '</td>';
      cartBody.appendChild(tr);
    });
    updatePdfButtonState();
  }

  function openQtyMini(itemId) {
    var it = allItems.find(function (x) { return x.id === itemId; });
    if (!it || !qtyMini || !qtyMiniInput) return;

    pendingItemId = itemId;
    var existing = cartLine(itemId);

    if (qtyMiniName) qtyMiniName.textContent = it.name_ar || '—';
    if (qtyMiniMeta) {
      var meta = it.sku || '';
      if (it.stock_qty != null) meta += (meta ? ' · ' : '') + 'رصيد ' + fmtQty(it.stock_qty);
      qtyMiniMeta.textContent = meta;
    }
    qtyMiniInput.value = existing ? String(existing.qty || '') : '1';
    if (qtyMiniAdd) qtyMiniAdd.textContent = existing ? 'تحديث السلة' : 'إضافة للسلة';

    qtyMini.hidden = false;
    qtyMini.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-rep-overlay-open');

    setTimeout(function () {
      qtyMiniInput.focus();
      qtyMiniInput.select();
    }, 40);
  }

  function closeQtyMini() {
    if (!qtyMini) return;
    qtyMini.hidden = true;
    qtyMini.setAttribute('aria-hidden', 'true');
    if (!cartEl || cartEl.hidden) {
      document.body.classList.remove('m-rep-overlay-open');
    }
    pendingItemId = 0;
  }

  function addToCartFromMini() {
    if (!pendingItemId) return;
    var it = allItems.find(function (x) { return x.id === pendingItemId; });
    if (!it) return;

    var qty = parseFloat(String(qtyMiniInput ? qtyMiniInput.value : '').replace(',', '.'));
    if (!(qty > 0)) {
      if (qtyMiniInput) qtyMiniInput.focus();
      showStatus('أدخل كمية صحيحة.', 'error');
      return;
    }

    var idx = cartIndex(pendingItemId);
    if (idx >= 0) {
      cart[idx].qty = String(qty);
    } else {
      cart.push({
        item_id: it.id,
        name_ar: it.name_ar,
        sku: it.sku,
        stock_qty: it.stock_qty,
        qty: String(qty),
      });
    }

    closeQtyMini();
    hideStatus();
    renderItemGrid(itemSearch ? itemSearch.value : '');
    renderCart();
    if (bagFab) {
      bagFab.classList.add('m-rep-bag-fab--pulse');
      setTimeout(function () { bagFab.classList.remove('m-rep-bag-fab--pulse'); }, 450);
    }
  }

  function openCart() {
    if (!cartEl) return;
    renderCart();
    cartEl.hidden = false;
    cartEl.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-rep-overlay-open', 'm-rep-cart-open');
  }

  function closeCartForce() {
    if (!cartEl) return;
    cartEl.hidden = true;
    cartEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('m-rep-cart-open');
    if (!qtyMini || qtyMini.hidden) {
      document.body.classList.remove('m-rep-overlay-open');
    }
  }

  function closeCart() {
    closeCartForce();
  }

  function buildPdfUrl(id) {
    if (!cfg.pdfApi || id < 1) return '';
    return cfg.pdfApi
      + (cfg.pdfApi.indexOf('?') >= 0 ? '&' : '?')
      + 'id=' + encodeURIComponent(String(id))
      + '&direction=' + encodeURIComponent(cfg.direction || 'load');
  }

  function updatePdfButtonState() {
    var canPdf = lastPostedMoveId > 0 || cart.length > 0;
    if (pdfBtn) {
      pdfBtn.disabled = !canPdf || busy;
      pdfBtn.classList.toggle('is-ready', canPdf && !busy);
      if (lastPostedMoveId > 0) {
        pdfBtn.textContent = 'PDF';
        pdfBtn.title = 'تحميل PDF';
      } else if (cart.length > 0) {
        pdfBtn.textContent = 'PDF';
        pdfBtn.title = 'ترحيل ثم تحميل PDF';
      } else {
        pdfBtn.textContent = 'PDF';
        pdfBtn.title = 'أضف مواد للسلة';
      }
    }
  }

  function buildPdfFilename(moveNo) {
    var label = cfg.direction === 'return' ? 'إرجاع عهدة' : 'تحميل عهدة';
    var no = String(moveNo || '').trim();
    if (!no && moveNoEl) no = String(moveNoEl.value || '').trim();
    return no ? label + ' - ' + no + '.pdf' : label + '.pdf';
  }

  function closePdfView() {
    if (pdfView) {
      pdfView.hidden = true;
      pdfView.setAttribute('aria-hidden', 'true');
    }
    if (pdfFrame) pdfFrame.removeAttribute('src');
    if (lastPdfBlobUrl) {
      URL.revokeObjectURL(lastPdfBlobUrl);
      lastPdfBlobUrl = '';
    }
    document.body.classList.remove('m-rep-pdf-open');
  }

  function showPdfBlob(blob, filename) {
    closePdfView();
    if (!blob || blob.size < 32) {
      showStatus('ملف PDF فارغ أو تالف.', 'error');
      return;
    }
    var url = URL.createObjectURL(blob);
    lastPdfBlobUrl = url;
    if (pdfFrame) pdfFrame.src = url;
    if (pdfViewDl) {
      pdfViewDl.href = url;
      pdfViewDl.download = filename || 'عهدة.pdf';
    }
    if (pdfView) {
      pdfView.hidden = false;
      pdfView.setAttribute('aria-hidden', 'false');
      document.body.classList.add('m-rep-pdf-open');
    }
  }

  function buildPdfFormData() {
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf || '');
    fd.append('direction', cfg.direction || 'load');
    fd.append('move_date', (document.getElementById('m-rep-date') || {}).value || '');
    fd.append('lines_json', JSON.stringify(collectPayload()));
    fd.append('move_id', String(lastPostedMoveId > 0 ? lastPostedMoveId : (moveId || 0)));
    return fd;
  }

  function applyPdfResponseHeaders(r) {
    var mid = r.headers.get('X-Rep-Move-Id');
    if (!mid) return null;
    var id = parseInt(mid, 10) || 0;
    if (id < 1) return null;

    var mno = r.headers.get('X-Rep-Move-No');
    var next = r.headers.get('X-Rep-Next-Move-No');
    var moveNo = mno ? decodeURIComponent(mno) : '';
    var nextNo = next ? decodeURIComponent(next) : (cfg.previewMoveNo || '1');

    var msg = (cfg.direction === 'return' ? 'تم ترحيل إرجاع العهدة.' : 'تم ترحيل تحميل العهدة.');
    if (moveNo) msg += ' رقم السند: ' + moveNo;

    return { id: id, moveNo: moveNo, nextNo: nextNo, message: msg };
  }

  function requestCustodyPdf() {
    if (busy) return;
    if (!lastPostedMoveId && !cart.length) {
      showStatus('أضف مواد للسلة أولاً.', 'error');
      return;
    }
    if (!cfg.pdfApi) {
      showStatus('رابط PDF غير مهيأ.', 'error');
      return;
    }

    setBusy(true);
    showStatus('جاري ترحيل العهدة وتجهيز PDF...', 'success');
    closeCartForce();

    fetch(cfg.pdfApi, {
      method: 'POST',
      body: buildPdfFormData(),
      credentials: 'same-origin',
    })
      .then(function (r) {
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        var meta = applyPdfResponseHeaders(r);
        if (!r.ok) {
          if (ct.indexOf('json') >= 0) {
            return r.json().then(function (j) {
              throw new Error(j.message || j.error || ('خطأ ' + r.status));
            });
          }
          return r.text().then(function (t) {
            throw new Error((t || '').trim() || ('خطأ ' + r.status));
          });
        }
        if (ct.indexOf('json') >= 0) {
          return r.json().then(function (j) {
            throw new Error(j.message || j.error || 'تعذر PDF');
          });
        }
        return r.blob().then(function (blob) {
          return { blob: blob, meta: meta };
        });
      })
      .then(function (res) {
        setBusy(false);
        if (!res || !res.blob) return;
        if (res.meta && !lastPostedMoveId) {
          showPostSuccess(res.meta.message, res.meta.id, res.meta.nextNo, false);
        } else if (res.meta && lastPostedMoveId) {
          if (moveNoEl && res.meta.moveNo) moveNoEl.value = fmtMoveNo(res.meta.moveNo);
        }
        showPdfBlob(res.blob, buildPdfFilename(res.meta ? res.meta.moveNo : ''));
        showStatus('تم فتح PDF — يمكنك تحميله أو مشاركته.', 'success');
      })
      .catch(function (err) {
        setBusy(false);
        var msg = err && err.message ? String(err.message) : 'تعذر تجهيز PDF.';
        showStatus(msg, 'error');
      });
  }

  function handlePdfClick() {
    requestCustodyPdf();
  }

  function setPdfLinks(url) {
    [topbarPdf, bannerPdfBtn].forEach(function (el) {
      if (!el) return;
      if (url) {
        el.href = url;
        el.hidden = false;
        el.removeAttribute('aria-hidden');
      } else {
        el.removeAttribute('href');
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
      }
    });
    updatePdfButtonState();
  }

  function setCartPostedMode(on, message, postedMoveId) {
    lastPostedMoveId = on ? (postedMoveId || 0) : 0;
    var pdfUrl = on && lastPostedMoveId > 0 ? buildPdfUrl(lastPostedMoveId) : '';

    if (saveBtn) saveBtn.disabled = !!on || busy;
    if (postBtn) postBtn.disabled = !!on || busy;
    if (cartClear) cartClear.disabled = !!on || busy;
    if (cartDoneBtn) cartDoneBtn.hidden = !on;

    if (cartPostedMsg) {
      cartPostedMsg.hidden = !on;
      cartPostedMsg.textContent = on ? (message || 'تم الترحيل — اضغط PDF لتحميل قائمة المواد.') : '';
    }

    setPdfLinks(pdfUrl);

    if (pdfBanner) pdfBanner.hidden = !on;
    if (pdfBannerMsg) {
      pdfBannerMsg.textContent = on ? (message || 'تم الترحيل — حمّل PDF لإرسال قائمة المواد.') : '';
    }
    if (photoArchive) photoArchive.refreshMeta();
  }

  function loadItems() {
    var url = cfg.itemsApi + (cfg.itemsApi.indexOf('?') >= 0 ? '&' : '?') +
      'list=1' + (cfg.positiveStockOnly ? '&positive=1' : '');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          allItems = [];
          renderItemGrid('');
          if (itemEmpty) {
            itemEmpty.hidden = false;
            itemEmpty.textContent = data.message || 'تعذر تحميل المواد.';
          }
          return;
        }
        allItems = data.items || [];
        renderItemGrid('');
      })
      .catch(function () {
        if (itemEmpty) {
          itemEmpty.hidden = false;
          itemEmpty.textContent = 'تعذر الاتصال بالخادم.';
        }
      });
  }

  function loadMoveForEdit() {
    var editId = parseInt(cfg.editMoveId || '0', 10);
    if (editId < 1 || !cfg.viewApi) {
      return Promise.resolve();
    }
    showStatus('جاري تحميل العهدة...', 'success');
    var url =
      cfg.viewApi +
      '?id=' +
      encodeURIComponent(String(editId)) +
      '&direction=' +
      encodeURIComponent(cfg.direction || 'load');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok || !data.move) {
          showStatus(data.message || 'تعذر تحميل العهدة للتعديل.', 'error');
          return;
        }
        var mv = data.move;
        moveId = parseInt(mv.id, 10) || 0;
        if (moveIdEl) moveIdEl.value = String(moveId);
        if (moveNoEl) moveNoEl.value = fmtMoveNo(mv.move_no);
        var dateEl = document.getElementById('m-rep-date');
        if (dateEl && mv.move_date_dmy) dateEl.value = mv.move_date_dmy;
        cart = (mv.lines || []).map(function (ln) {
          return {
            item_id: parseInt(ln.item_id, 10) || 0,
            sku: ln.sku || '',
            name_ar: ln.name_ar || '',
            qty: ln.qty,
          };
        });
        renderCart();
        renderItemGrid(itemSearch ? itemSearch.value : '');
        hideStatus();
        if (photoArchive) photoArchive.refreshMeta();
        updateDeleteButtonState();
        openCart();
      })
      .catch(function () {
        showStatus('تعذر الاتصال بالخادم.', 'error');
      });
  }

  function collectPayload() {
    var payloadLines = [];
    cart.forEach(function (ln) {
      var qty = parseFloat(String(ln.qty || '').replace(',', '.'));
      if (ln.item_id > 0 && qty > 0) {
        payloadLines.push({ item_id: ln.item_id, qty: qty });
      }
    });
    return payloadLines;
  }

  function updateDeleteButtonState() {
    if (!deleteBtn) return;
    var show = !!(cfg.canDelete && cfg.deleteApi && moveId > 0 && lastPostedMoveId < 1);
    deleteBtn.hidden = !show;
    deleteBtn.disabled = busy || lastPostedMoveId > 0;
  }

  function setBusy(on) {
    busy = on;
    if (saveBtn) saveBtn.disabled = on || lastPostedMoveId > 0;
    if (postBtn) postBtn.disabled = on || lastPostedMoveId > 0;
    updatePdfButtonState();
    updateDeleteButtonState();
  }

  function confirmDelete(msg) {
    if (window.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(msg, {
        title: 'تأكيد الحذف',
        okText: 'نعم، احذف',
        cancelText: 'إلغاء',
        danger: true,
      }).then(function (ok) {
        return !!ok;
      });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function runDelete() {
    if (!cfg.canDelete || !cfg.deleteApi || moveId < 1 || busy || lastPostedMoveId > 0) return;
    confirmDelete('حذف العهدة؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      setBusy(true);
      showStatus('جاري الحذف...', 'success');
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('move_id', String(moveId));
      fd.append('direction', cfg.direction || 'load');
      fetch(cfg.deleteApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          setBusy(false);
          if (!data || !data.ok) {
            showStatus((data && data.message) || 'تعذر حذف العهدة.', 'error');
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف العهدة.');
          }
          var listUrl = cfg.listUrl || '';
          if (listUrl) {
            window.location.href = listUrl;
            return;
          }
          resetAfterPost(cfg.previewMoveNo || '1');
          hideStatus();
        })
        .catch(function () {
          setBusy(false);
          showStatus('تعذر الاتصال بالخادم.', 'error');
        });
    });
  }

  function buildFormData(action) {
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf || '');
    fd.append('_action', action);
    fd.append('direction', cfg.direction || 'load');
    fd.append('move_id', String(moveId || 0));
    fd.append('move_date', (document.getElementById('m-rep-date') || {}).value || '');
    fd.append('lines_json', JSON.stringify(collectPayload()));
    if (photoArchive && photoArchive.takePendingFile) {
      var pendingPhoto = photoArchive.takePendingFile();
      if (pendingPhoto && pendingPhoto.file) {
        fd.append('archive_photo', pendingPhoto.file, pendingPhoto.name);
      }
    }
    return fd;
  }

  function handleArchiveAfterSave(data) {
    if (!data || !data.ok) return Promise.resolve();
    var invId = parseInt(data.move_id, 10) || 0;
    if (photoArchive) photoArchive.refreshMeta();
    if (data.archive_error && window.AppDialog && AppDialog.error) {
      AppDialog.error(data.archive_error);
      return Promise.resolve();
    }
    if (data.archive_uploaded && photoArchive && photoArchive.showBriefSuccess) {
      return photoArchive.showBriefSuccess('تم حفظ المرفق في الأرشيف', 1000);
    }
    if (invId > 0 && photoArchive && photoArchive.hasPending && photoArchive.hasPending()) {
      return photoArchive.flushPending(invId, { silent: true }).then(function () {
        if (photoArchive) photoArchive.refreshMeta();
      });
    }
    return Promise.resolve();
  }

  function resetAfterPost(nextNo) {
    moveId = 0;
    if (moveIdEl) moveIdEl.value = '0';
    cart = [];
    renderItemGrid(itemSearch ? itemSearch.value : '');
    renderCart();
    closeCartForce();
    if (moveNoEl) moveNoEl.value = fmtMoveNo(nextNo || cfg.previewMoveNo || '1');
    var dateEl = document.getElementById('m-rep-date');
    if (dateEl && cfg.todayDate) dateEl.value = cfg.todayDate;
    updateDeleteButtonState();
  }

  function finishPostedCart(nextNo) {
    var next = nextNo || pendingNextMoveNo || cfg.previewMoveNo || '1';
    pendingNextMoveNo = '';
    closePdfView();
    setCartPostedMode(false);
    resetAfterPost(next);
    hideStatus();
  }

  function showPostSuccess(message, postedMoveId, nextNo) {
    pendingNextMoveNo = nextNo || cfg.previewMoveNo || '1';
    setCartPostedMode(true, message, postedMoveId);
    showStatus(message || 'تم الترحيل.', 'success');
  }

  function submitAction(action, options) {
    options = options || {};
    if (busy) return;
    if (!options.keepStatus) hideStatus();
    if (!cart.length) {
      showStatus('السلة فارغة. أضف مواد أولاً.', 'error');
      return;
    }
    var payloadLines = collectPayload();
    if (!payloadLines.length) {
      showStatus('أدخل كميات صحيحة للمواد.', 'error');
      return;
    }

    setBusy(true);
    showStatus(action === 'save' ? 'جاري الحفظ...' : 'جاري الترحيل...', 'success');

    fetch(cfg.saveApi, { method: 'POST', body: buildFormData(action), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          showStatus(data.message || data.error || 'تعذر التنفيذ.', 'error');
          if (data.move_id) {
            moveId = parseInt(data.move_id, 10) || 0;
            if (moveIdEl) moveIdEl.value = String(moveId);
          }
          if (data.move_no && moveNoEl) moveNoEl.value = fmtMoveNo(data.move_no);
          updateDeleteButtonState();
          return;
        }
        if (data.move_id) {
          moveId = parseInt(data.move_id, 10) || 0;
          if (moveIdEl) moveIdEl.value = String(moveId);
        }
        if (data.move_no && moveNoEl) moveNoEl.value = fmtMoveNo(data.move_no);
        updateDeleteButtonState();
        if (isPostedResponse(data, action)) {
          var postedId = parseInt(data.move_id, 10) || 0;
          handleArchiveAfterSave(data).then(function () {
            showPostSuccess(data.message || 'تم الترحيل بنجاح.', postedId, data.next_move_no);
            closeCartForce();
          });
        } else if (data.action === 'saved') {
          handleArchiveAfterSave(data).then(function () {
            showStatus(data.message || 'تم الحفظ.', 'success');
          });
        } else {
          handleArchiveAfterSave(data).then(function () {
            showStatus(data.message || 'تم بنجاح.', 'success');
          });
        }
      })
      .catch(function () {
        setBusy(false);
        showStatus('تعذر الاتصال بالخادم.', 'error');
      });
  }

  function isPostedResponse(data, sentAction) {
    if (!data || !data.ok) return false;
    if (data.action === 'posted') return true;
    return sentAction === 'post' && (parseInt(data.move_id, 10) || 0) > 0;
  }

  if (itemGrid) {
    itemGrid.addEventListener('click', function (e) {
      var pick = e.target.closest('[data-pick]');
      if (!pick) return;
      openQtyMini(parseInt(pick.getAttribute('data-pick'), 10));
    });
  }

  if (cartBody) {
    cartBody.addEventListener('click', function (e) {
      if (lastPostedMoveId > 0) return;
      var editBtn = e.target.closest('[data-cart-edit]');
      if (editBtn) {
        openQtyMini(parseInt(editBtn.getAttribute('data-cart-edit'), 10));
        return;
      }
      var delBtn = e.target.closest('[data-cart-del]');
      if (delBtn) {
        cart.splice(parseInt(delBtn.getAttribute('data-cart-del'), 10), 1);
        renderCart();
        renderItemGrid(itemSearch ? itemSearch.value : '');
      }
    });
  }

  if (bagFab) bagFab.addEventListener('click', openCart);
  if (cartBackdrop) cartBackdrop.addEventListener('click', closeCart);
  if (cartClose) cartClose.addEventListener('click', closeCart);
  if (qtyMiniBackdrop) qtyMiniBackdrop.addEventListener('click', closeQtyMini);
  if (qtyMiniCancel) qtyMiniCancel.addEventListener('click', closeQtyMini);
  if (qtyMiniAdd) qtyMiniAdd.addEventListener('click', addToCartFromMini);
  if (cartClear) {
    cartClear.addEventListener('click', function () {
      cart = [];
      renderCart();
      renderItemGrid(itemSearch ? itemSearch.value : '');
    });
  }

  if (qtyMiniInput) {
    qtyMiniInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        addToCartFromMini();
      }
    });
  }

  if (itemSearch) {
    itemSearch.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { renderItemGrid(itemSearch.value); }, 200);
    });
  }

  if (saveBtn) saveBtn.addEventListener('click', function () { submitAction('save'); });
  if (postBtn) postBtn.addEventListener('click', function () { submitAction('post'); });
  if (deleteBtn) deleteBtn.addEventListener('click', runDelete);
  if (pdfBtn) pdfBtn.addEventListener('click', handlePdfClick);
  if (pdfViewClose) pdfViewClose.addEventListener('click', closePdfView);
  if (pdfViewDone) {
    pdfViewDone.addEventListener('click', function () {
      closePdfView();
      finishPostedCart(pendingNextMoveNo);
    });
  }
  if (cartDoneBtn) {
    cartDoneBtn.addEventListener('click', function () {
      finishPostedCart(pendingNextMoveNo);
    });
  }
  if (bannerDoneBtn) {
    bannerDoneBtn.addEventListener('click', function () {
      finishPostedCart(pendingNextMoveNo);
    });
  }
  function guardPdfClick(e) {
    e.preventDefault();
    requestCustodyPdf();
  }
  [topbarPdf, bannerPdfBtn].forEach(function (el) {
    if (el) el.addEventListener('click', guardPdfClick);
  });

  cartArchiveBtn = document.getElementById('m-rep-cart-archive');
  if (cfg.canArchive && window.MobileInvoicePhotoArchive) {
    photoArchive = MobileInvoicePhotoArchive.create({
      apiUrl: cfg.archiveApi,
      csrf: cfg.csrf,
      kind: 'warehouse_move',
      getInvoiceId: function () {
        if (lastPostedMoveId > 0) return lastPostedMoveId;
        return parseInt(moveIdEl && moveIdEl.value ? moveIdEl.value : '0', 10) || 0;
      },
      getInvoiceLabel: function () {
        return moveNoEl && moveNoEl.value ? String(moveNoEl.value) : '';
      },
      isLocked: function () {
        return lastPostedMoveId > 0;
      },
    });
    if (cartArchiveBtn) {
      cartArchiveBtn.addEventListener('click', function (e) {
        if (e && e.preventDefault) e.preventDefault();
        photoArchive.openViewer();
      });
      photoArchive.refreshMeta();
    }
  }

  if (moveNoEl) moveNoEl.value = fmtMoveNo(moveNoEl.value);
  document.body.classList.add('m-page-rep-custody');
  loadItems().then(function () {
    return loadMoveForEdit();
  }).then(function () {
    renderCart();
    updatePdfButtonState();
    updateDeleteButtonState();
  });
})();
