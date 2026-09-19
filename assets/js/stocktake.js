(function () {
  'use strict';

  var form = document.getElementById('stocktake-form');
  if (!form) return;
  var tbody = document.getElementById('stocktake-lines-body');
  var printBody = document.getElementById('stocktake-print-body');
  var tpl = document.getElementById('stocktake-line-tpl');
  var linesJsonInp = document.getElementById('stocktake-lines-json');
  var actionInp = document.getElementById('stocktake-action');
  var docIdInp = document.getElementById('stocktake-doc-id');
  var noInp = document.getElementById('stocktake-no');
  var dateInp = document.getElementById('stocktake-date');
  var whSel = document.getElementById('stocktake-warehouse');
  var notesInp = document.getElementById('stocktake-notes');
  var pickBtn = document.getElementById('stocktake-pick-items');
  var isPosted = form.classList.contains('is-posted');
  var apiItems = form.getAttribute('data-api-items') || '';
  var apiDoc = form.getAttribute('data-api-doc') || '';
  var apiStock = form.getAttribute('data-api-stock') || '';
  var currentDocId = parseInt(form.getAttribute('data-initial-id') || '0', 10) || 0;
  var prevId = 0;
  var nextId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'stocktake-no',
    prevBtnId: 'stocktake-no-prev',
    nextBtnId: 'stocktake-no-next',
    defaultNoTitle: 'اكتب جزءاً من رقم السند واضغط Enter للبحث',
  };

  function alertMsg(msg) {
    if (window.AppDialog && AppDialog.alert) AppDialog.alert(msg, { type: 'warning' });
    else window.alert(msg);
  }
  function confirmMsg(msg, onOk) {
    if (window.AppDialog && AppDialog.confirm) {
      AppDialog.confirm(msg).then(function (ok) { if (ok && onOk) onOk(); });
    } else if (window.confirm(msg) && onOk) onOk();
  }

  function setStatusColor() {
    if (!noInp) return;
    noInp.classList.remove('is-posted', 'is-unposted');
    if (currentDocId < 1) return;
    noInp.classList.add(isPosted ? 'is-posted' : 'is-unposted');
    syncToolbarDelete();
  }

  function syncToolbarDelete() {
    var delBtn = document.querySelector('#master-toolbar [data-master-action="delete"]');
    if (!delBtn) return;
    var enabled = currentDocId > 0 && !isPosted;
    delBtn.disabled = !enabled;
    delBtn.classList.toggle('is-inactive', !enabled);
    delBtn.title = enabled
      ? 'حذف سند الجرد'
      : isPosted
        ? 'لا يمكن حذف سند مرحّل'
        : 'احفظ السند أولاً ثم احذف';
  }

  function syncToolbarUnpost() {
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    if (!unpostBtn) return;
    var enabled = currentDocId > 0 && isPosted;
    unpostBtn.disabled = !enabled;
    unpostBtn.classList.toggle('is-inactive', !enabled);
    unpostBtn.title = enabled
      ? 'فك ترحيل سند الجرد'
      : currentDocId < 1
        ? 'احفظ السند أولاً'
        : 'السند غير مرحّل';
  }

  function syncPrintMeta() {
    var pn = document.getElementById('stocktake-print-no');
    var pd = document.getElementById('stocktake-print-date');
    var pw = document.getElementById('stocktake-print-wh');
    if (pn) pn.textContent = noInp ? String(noInp.value || '') : '';
    if (pd) pd.textContent = dateInp ? String(dateInp.value || '') : '';
    if (pw && whSel) pw.textContent = whSel.options[whSel.selectedIndex] ? whSel.options[whSel.selectedIndex].text : '';
  }

  function renumber() {
    var i = 0;
    tbody.querySelectorAll('tr.stocktake-line').forEach(function (tr) {
      i += 1;
      var seq = tr.querySelector('.js-seq');
      if (seq) seq.textContent = String(i);
    });
  }

  function buildItemsUrl(q, listAll) {
    var url = apiItems;
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(String(q).trim()));
    var whId = parseInt(whSel && whSel.value ? whSel.value : '0', 10) || 0;
    if (whId > 0) parts.push('warehouse_id=' + whId);
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
  }

  function fetchStock(itemId, cb) {
    var whId = parseInt(whSel && whSel.value ? whSel.value : '0', 10) || 0;
    if (whId < 1 || itemId < 1 || !apiStock) return cb(0);
    fetch(apiStock + '&warehouse_id=' + whId + '&item_id=' + itemId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { cb(d && d.ok ? parseFloat(d.stock_qty || 0) : 0); })
      .catch(function () { cb(0); });
  }

  function toNumber(value) {
    var n = parseFloat(String(value == null ? '0' : value).replace(',', '.'));
    return isFinite(n) ? n : 0;
  }

  function fmt(value) {
    var n = Math.round(toNumber(value) * 1000000) / 1000000;
    return String(n).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  }

  function recalcRow(tr) {
    if (!tr) return;
    var book = toNumber(tr.querySelector('.js-book') ? tr.querySelector('.js-book').textContent : '0');
    var counted = toNumber(tr.querySelector('.js-counted') ? tr.querySelector('.js-counted').value : '0');
    var unitCost = toNumber(tr.getAttribute('data-unit-cost') || '0');
    var diff = counted - book;
    var diffCost = diff * unitCost;
    var diffEl = tr.querySelector('.js-diff');
    var diffCostEl = tr.querySelector('.js-diff-cost');
    var unitCostEl = tr.querySelector('.js-unit-cost');
    if (unitCostEl) unitCostEl.textContent = fmt(unitCost);
    if (diffEl) diffEl.textContent = fmt(diff);
    if (diffCostEl) diffCostEl.textContent = fmt(diffCost);
  }

  function addLine(item, bookQty, countedQty, unitCost, options) {
    options = options || {};
    var skipSync = !!options.skipSync;
    var existingIds = options.existingIds || null;
    if (!tpl || !tbody) return;
    var itemId = parseInt(item.id, 10) || 0;
    if (itemId < 1) return;
    var dup = false;
    if (existingIds) {
      dup = !!existingIds[itemId];
    } else {
      tbody.querySelectorAll('tr.stocktake-line').forEach(function (r) {
        if (parseInt(r.getAttribute('data-item-id') || '0', 10) === itemId) dup = true;
      });
    }
    if (dup) return;
    var tr = tpl.content.firstElementChild.cloneNode(true);
    tr.setAttribute('data-item-id', String(itemId));
    var sku = tr.querySelector('.js-sku');
    var name = tr.querySelector('.js-name');
    var book = tr.querySelector('.js-book');
    var counted = tr.querySelector('.js-counted');
    var normalizedUnitCost = toNumber(unitCost != null ? unitCost : item.default_cost);
    tr.setAttribute('data-unit-cost', String(normalizedUnitCost));
    if (sku) {
      var n = window.InvItemDisplay && InvItemDisplay.materialNumberDigitsOnly
        ? InvItemDisplay.materialNumberDigitsOnly(item.barcode, item.sku)
        : String(item.sku || '');
      sku.textContent = n;
    }
    if (name) name.textContent = item.name_ar || '';
    if (book) book.textContent = String((bookQty != null ? bookQty : 0));
    if (counted) counted.value = countedQty != null ? String(countedQty) : String((bookQty != null ? bookQty : 0));
    if (counted && isPosted) counted.readOnly = true;
    var rm = tr.querySelector('.js-remove');
    if (rm) rm.addEventListener('click', function () { tr.remove(); renumber(); syncPrintTable(); });
    if (counted) counted.addEventListener('input', function () { recalcRow(tr); syncPrintTable(); });
    recalcRow(tr);
    tbody.appendChild(tr);
    if (existingIds) existingIds[itemId] = true;
    if (!skipSync) {
      renumber();
      syncPrintTable();
    }
  }

  function pickItems() {
    var whId = parseInt(whSel && whSel.value ? whSel.value : '0', 10) || 0;
    if (whId < 1) {
      alertMsg('اختر المستودع أولاً.');
      return;
    }
    if (!window.ItemPickerModal) {
      alertMsg('نافذة اختيار المواد غير متوفرة.');
      return;
    }
    ItemPickerModal.open({
      singleSelect: false,
      screenCenter: true,
      buildItemsUrl: buildItemsUrl,
      getWarehouseId: function () { return whId; },
      onConfirm: function (items) {
        var picked = items || [];
        if (!picked.length) return;
        var existingIds = {};
        tbody.querySelectorAll('tr.stocktake-line').forEach(function (r) {
          var id = parseInt(r.getAttribute('data-item-id') || '0', 10);
          if (id > 0) existingIds[id] = true;
        });
        var pending = 0;
        picked.forEach(function (it) {
          var stock = toNumber(it && it.stock_qty != null ? it.stock_qty : 0);
          if (Math.abs(stock) > 0.000001 || (it && it.stock_qty != null)) {
            addLine(it, stock, stock, it.default_cost, { skipSync: true, existingIds: existingIds });
            return;
          }
          pending += 1;
          fetchStock(parseInt(it.id, 10) || 0, function (liveStock) {
            addLine(it, liveStock, liveStock, it.default_cost, { skipSync: true, existingIds: existingIds });
            pending -= 1;
            if (pending < 1) {
              renumber();
              syncPrintTable();
            }
          });
        });
        if (pending < 1) {
          renumber();
          syncPrintTable();
        }
      },
    });
  }

  function collectLines() {
    var out = [];
    tbody.querySelectorAll('tr.stocktake-line').forEach(function (tr) {
      var itemId = parseInt(tr.getAttribute('data-item-id') || '0', 10);
      var book = parseFloat(tr.querySelector('.js-book') ? tr.querySelector('.js-book').textContent : '0');
      var counted = parseFloat(tr.querySelector('.js-counted') ? tr.querySelector('.js-counted').value : '0');
      if (itemId > 0) out.push({ item_id: itemId, book_qty: isFinite(book) ? book : 0, counted_qty: isFinite(counted) ? counted : 0 });
    });
    return out;
  }

  function syncPrintTable() {
    syncPrintMeta();
    if (!printBody) return;
    var html = '';
    var i = 0;
    tbody.querySelectorAll('tr.stocktake-line').forEach(function (tr) {
      i += 1;
      var sku = tr.querySelector('.js-sku') ? tr.querySelector('.js-sku').textContent : '';
      var name = tr.querySelector('.js-name') ? tr.querySelector('.js-name').textContent : '';
      name = String(name || '').replace(/\s+/g, ' ').trim();
      var book = tr.querySelector('.js-book') ? tr.querySelector('.js-book').textContent : '0';
      var counted = tr.querySelector('.js-counted') ? tr.querySelector('.js-counted').value : '0';
      var unitCost = tr.querySelector('.js-unit-cost') ? tr.querySelector('.js-unit-cost').textContent : '0';
      var diff = tr.querySelector('.js-diff') ? tr.querySelector('.js-diff').textContent : '0';
      var diffCost = tr.querySelector('.js-diff-cost') ? tr.querySelector('.js-diff-cost').textContent : '0';
      html += '<tr><td>' + i + '</td><td><code>' + sku + '</code></td><td class="col-item-name">' + name + '</td><td>' + book + '</td><td>' + counted + '</td><td>' + unitCost + '</td><td>' + diff + '</td><td>' + diffCost + '</td></tr>';
    });
    if (!html) html = '<tr><td colspan="8" class="muted">لا توجد بنود.</td></tr>';
    printBody.innerHTML = html;
  }

  function submitForm(action) {
    if (isPosted) return alertMsg('السند مرحّل ولا يمكن تعديله.');
    var whId = parseInt(whSel && whSel.value ? whSel.value : '0', 10) || 0;
    if (whId < 1) return alertMsg('اختر المستودع.');
    var lines = collectLines();
    if (lines.length < 1) return alertMsg('اختر مادة واحدة على الأقل.');
    linesJsonInp.value = JSON.stringify(lines);
    actionInp.value = action;
    form.requestSubmit ? form.requestSubmit() : form.submit();
  }

  function setBrowseNavFromSearch(prev, next) {
    prevId = prev > 0 ? prev : 0;
    nextId = next > 0 ? next : 0;
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('stocktake-no-prev', 'stocktake-no-next', prevId, nextId, {
        onEmpty: false,
        prevTitle: 'سند الجرد السابق',
        nextTitle: 'سند الجرد التالي',
      });
    }
  }

  function applyBrowseNavFromDoc(doc) {
    if (window.DocumentNoNav && DocumentNoNav.applyBrowseNav) {
      DocumentNoNav.applyBrowseNav(docNoSearch, doc, setBrowseNavFromSearch, DOC_NO_SEARCH_UI);
      return;
    }
    setBrowseNavFromSearch(doc.prev_id || 0, doc.next_id || 0);
  }

  function setBrowseNav(prev, next) {
    browseNavPrevId = prev > 0 ? prev : 0;
    browseNavNextId = next > 0 ? next : 0;
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('stocktake-no-prev', 'stocktake-no-next', browseNavPrevId, browseNavNextId, {
        onEmpty: currentDocId < 1,
        prevTitle: 'سند الجرد السابق',
        nextTitle: 'سند الجرد التالي',
        prevBeforeLatestTitle: 'السند قبل الأخير',
        latestTitle: 'آخر سند جرد',
      });
      return;
    }
  }

  function refreshEmptyBrowseNav() {
    if (!apiDoc) {
      setBrowseNav(0, 0);
      return;
    }
    fetchDoc({ edge: 'first' }, function (data) {
      if (!data || !data.ok || !data.doc) {
        setBrowseNav(0, 0);
        return;
      }
      var newestId = parseInt(data.doc.id, 10) || 0;
      setBrowseNav(data.doc.prev_id || 0, newestId);
    });
  }

  function fetchDocPromise(query) {
    return new Promise(function (resolve) {
      fetchDoc(query, resolve);
    });
  }

  function navigateEmptyStocktake(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchDocPromise({ id: id });
      },
      fetchLatest: function () {
        return fetchDocPromise({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.doc);
      },
      getPayload: function (data) {
        return data.doc;
      },
      apply: applyDoc,
      emptyMessage: 'لا توجد سندات جرد محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر سند.',
      loadError: 'تعذر تحميل السند.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    fetchDoc({ edge: 'first' }, function (d) {
      if (d && d.ok && d.doc) applyDoc(d.doc);
    });
  }

  function fetchDoc(query, cb) {
    var qs = Object.keys(query).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&');
    fetch(apiDoc + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { cb(d); })
      .catch(function () { cb(null); });
  }

  function applyDoc(doc) {
    currentDocId = parseInt(doc.id, 10) || 0;
    isPosted = !!doc.is_posted;
    form.classList.toggle('is-posted', isPosted);
    docIdInp.value = currentDocId > 0 ? String(currentDocId) : '';
    if (noInp) noInp.value = doc.take_no || '';
    if (dateInp) dateInp.value = doc.take_date_display || '';
    if (notesInp) notesInp.value = doc.notes || '';
    if (whSel) whSel.value = doc.warehouse_id ? String(doc.warehouse_id) : '';
    if (whSel) whSel.disabled = isPosted;
    if (dateInp) dateInp.readOnly = isPosted;
    if (notesInp) notesInp.readOnly = isPosted;
    if (pickBtn) pickBtn.style.display = isPosted ? 'none' : '';
    applyBrowseNavFromDoc(doc);
    tbody.innerHTML = '';
    (doc.lines || []).forEach(function (ln) {
      addLine({
        id: ln.item_id,
        sku: ln.item_sku,
        barcode: ln.item_sku,
        name_ar: ln.item_name
      }, ln.book_qty, ln.counted_qty, ln.unit_cost);
    });
    setStatusColor();
    syncToolbarUnpost();
    syncPrintTable();
  }

  function searchByNo() {
    var no = noInp ? String(noInp.value || '').trim() : '';
    if (!no) return alertMsg('أدخل رقم السند أولاً.');
    fetchDoc({ no: no }, function (d) {
      if (!d || !d.ok || !d.doc) return alertMsg((d && d.message) || 'لم يتم العثور على سند يحتوي على هذا الرقم.');
      applyDoc(d.doc);
    });
  }

  function navDoc(dir) {
    if (currentDocId < 1) {
      navigateEmptyStocktake(dir);
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchDocPromise({ id: id });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.doc);
        },
        getPayload: function (data) {
          return data.doc;
        },
        apply: applyDoc,
        loadError: 'تعذر تحميل السند.',
      });
      return;
    }
    var target = dir === 'prev' ? prevId : nextId;
    if (target > 0) {
      fetchDoc({ id: target }, function (d) {
        if (d && d.ok && d.doc) applyDoc(d.doc);
      });
      return;
    }
    fetchDoc({ id: currentDocId, dir: dir }, function (d) {
      if (!d || !d.ok || !d.doc) return alertMsg((d && d.message) || 'لا يوجد.');
      applyDoc(d.doc);
    });
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var bar = document.getElementById('master-toolbar');
    var route = bar ? bar.getAttribute('data-active-route') || '' : '';
    if (route !== 'inventory_stocktake') return;
    var a = e.detail.action;
    if (a === 'save') { e.preventDefault(); e.stopImmediatePropagation(); submitForm('save'); }
    else if (a === 'post') { e.preventDefault(); e.stopImmediatePropagation(); confirmMsg('ترحيل سند الجرد وتحديث الأرصدة؟', function () { submitForm('post'); }); }
    else if (a === 'delete') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (isPosted) return alertMsg('لا يمكن حذف سند مرحّل.');
      if (currentDocId < 1) return alertMsg('احفظ السند أولاً.');
      confirmMsg('حذف سند الجرد نهائياً؟', function () {
        actionInp.value = 'delete';
        linesJsonInp.value = '[]';
        form.requestSubmit ? form.requestSubmit() : form.submit();
      });
    }
    else if (a === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (currentDocId < 1) return alertMsg('احفظ السند أولاً.');
      if (!isPosted) return alertMsg('السند غير مرحّل.');
      confirmMsg('فك ترحيل سند الجرد؟ سيتم التراجع عن حركات المخزون الناتجة عن هذا السند.', function () {
        actionInp.value = 'unpost';
        linesJsonInp.value = '[]';
        form.requestSubmit ? form.requestSubmit() : form.submit();
      });
    }
    else if (a === 'search') { e.preventDefault(); e.stopImmediatePropagation(); searchByNo(); }
    else if (a === 'new') { e.preventDefault(); e.stopImmediatePropagation(); window.location.href = form.getAttribute('data-new-url') || window.location.href; }
  }, true);

  if (pickBtn) pickBtn.addEventListener('click', pickItems);
  var prevBtn = document.getElementById('stocktake-no-prev');
  var nextBtn = document.getElementById('stocktake-no-next');
  if (prevBtn) prevBtn.addEventListener('click', function () { navDoc('prev'); });
  if (nextBtn) nextBtn.addEventListener('click', function () { navDoc('next'); });
  if (noInp) noInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); searchByNo(); } });

  var initLines = window.__STOCKTAKE_INITIAL_LINES__;
  if (Array.isArray(initLines) && initLines.length) {
    initLines.forEach(function (ln) {
      addLine({
        id: ln.item_id,
        sku: ln.item_sku,
        barcode: ln.item_sku,
        name_ar: ln.item_name
      }, ln.book_qty, ln.counted_qty, ln.unit_cost);
    });
  }
  syncPrintTable();
  setStatusColor();
  syncToolbarDelete();
  syncToolbarUnpost();
  if (currentDocId < 1) {
    refreshEmptyBrowseNav();
  }
})();
