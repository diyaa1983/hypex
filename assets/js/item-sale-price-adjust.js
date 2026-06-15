(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;
  var form = document.getElementById('item-price-adj-form');
  if (!form) return;

  var apiItems = form.getAttribute('data-api-items') || '';
  var apiDoc = form.getAttribute('data-api-doc') || '';
  var unitPriceStep = form.getAttribute('data-unit-price-step') || '0.01';
  var defaultTaxLabel = form.getAttribute('data-default-tax') || '';
  var canPost = form.getAttribute('data-can-post') === '1';
  var newUrl = form.getAttribute('data-new-url') || '';
  var initialDocId = parseInt(form.getAttribute('data-initial-id') || '0', 10);

  var currentDocId = initialDocId > 0 ? initialDocId : 0;
  var docIsPosted = form.classList.contains('item-price-adj-form-is-posted');
  var browseNavPrevId = 0;
  var browseNavNextId = 0;

  var adjNoInp = document.getElementById('item-price-adj-no');
  var adjDateInp = document.getElementById('item-price-adj-date');
  var notesInp = document.getElementById('item-price-adj-notes');
  var tbody = document.getElementById('item-price-adj-lines-body');
  var tpl = document.getElementById('item-price-adj-line-tpl');
  var linesJsonInp = document.getElementById('item-price-adj-lines-json');
  var actionInp = document.getElementById('item-price-adj-action');
  var docIdHidden = document.getElementById('item-price-adj-doc-id');

  function alertMsg(msg, opts) {
    if (global.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, opts || { type: 'warning' });
    } else {
      window.alert(msg);
    }
  }

  function confirmMsg(msg, onOk) {
    if (global.AppDialog && AppDialog.confirm) {
      AppDialog.confirm(msg, { type: 'warning' }).then(function (ok) {
        if (ok && onOk) onOk();
      });
    } else if (window.confirm(msg) && onOk) {
      onOk();
    }
  }

  function fmtDate(value) {
    if (global.AppFormat && AppFormat.formatDateDisplay) {
      return AppFormat.formatDateDisplay(value);
    }
    return value == null ? '' : String(value);
  }

  function formatTax(rate) {
    var r = parseFloat(rate);
    if (!isFinite(r) || Math.abs(r) < 0.000001) return 'معفى';
    return r.toFixed(3) + '%';
  }

  function resolveItemSku(item) {
    if (!item) return '';
    var barcode = item.barcode != null ? String(item.barcode).trim() : '';
    var sku = item.sku != null ? String(item.sku).trim() : '';
    if (global.InvItemDisplay && typeof InvItemDisplay.materialNumber === 'function') {
      return InvItemDisplay.materialNumber(barcode, sku);
    }
    return barcode || sku || '';
  }

  function resolveItemTax(item) {
    if (item && item.tax_rate_percent != null && item.tax_rate_percent !== '') {
      var t = parseFloat(item.tax_rate_percent);
      if (isFinite(t)) return t;
    }
    return parseFloat(defaultTaxLabel);
  }

  function getCurrentDocId() {
    if (currentDocId > 0) return currentDocId;
    return parseInt(docIdHidden && docIdHidden.value ? docIdHidden.value : '0', 10) || 0;
  }

  function updateAdjNoPostedStyle() {
    if (!adjNoInp) return;
    adjNoInp.classList.remove('is-posted', 'is-unposted');
    if (currentDocId < 1) return;
    if (docIsPosted) {
      adjNoInp.classList.add('is-posted');
    } else {
      adjNoInp.classList.add('is-unposted');
    }
  }

  function syncAdjNoDisplay(adjNo) {
    if (!adjNoInp) return;
    if (currentDocId > 0) {
      adjNoInp.value = adjNo != null && adjNo !== undefined ? String(adjNo).trim() : adjNoInp.value;
    } else {
      adjNoInp.value = '';
    }
    updateAdjNoPostedStyle();
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('item-price-adj-no-prev', 'item-price-adj-no-next', browseNavPrevId, browseNavNextId, {
        onEmpty: getCurrentDocId() < 1,
        prevTitle: 'الحركة السابقة',
        nextTitle: 'الحركة التالية',
        prevBeforeLatestTitle: 'الحركة قبل الأخيرة',
        latestTitle: 'آخر حركة تعديل أسعار',
      });
      return;
    }
    var prevBtn = document.getElementById('item-price-adj-no-prev');
    var nextBtn = document.getElementById('item-price-adj-no-next');
    if (prevBtn) prevBtn.disabled = !(browseNavPrevId > 0);
    if (nextBtn) nextBtn.disabled = !(browseNavNextId > 0);
  }

  function navigateEmptyDoc(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchDocResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchDocResponse({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.doc);
      },
      getPayload: function (data) {
        return data.doc;
      },
      apply: applyDocData,
      emptyMessage: 'لا توجد حركات تعديل أسعار محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر حركة.',
      loadError: 'تعذر تحميل الحركة.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    return opts.fetchLatest().then(function (data) {
      if (opts.isOk(data)) opts.apply(opts.getPayload(data));
      else alertMsg(opts.emptyMessage);
    });
  }

  function refreshEmptyBrowseNav() {
    if (!apiDoc || form.getAttribute('data-schema-ready') === '0') {
      setBrowseNav(0, 0);
      return;
    }
    fetchDocResponse({ edge: 'first' }).then(function (data) {
      if (!data || !data.ok || !data.doc) {
        setBrowseNav(0, 0);
        return;
      }
      var newestId = parseInt(data.doc.id, 10) || 0;
      setBrowseNav(data.doc.prev_id || 0, newestId);
    });
  }

  function syncToolbarPost() {
    var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
    if (!postBtn) return;
    var enabled = canPost && getCurrentDocId() > 0 && !docIsPosted;
    postBtn.disabled = !enabled;
    postBtn.classList.toggle('is-inactive', !enabled);
    postBtn.title = enabled
      ? 'ترحيل الأسعار وتحديث بطاقات المواد'
      : 'احفظ الحركة أولاً ثم رحّل';
  }

  function refreshDocEditState() {
    var locked = docIsPosted;
    form.classList.toggle('item-price-adj-form-is-posted', locked);
    form.classList.toggle('item-price-adj-form-is-saved', getCurrentDocId() > 0);
    if (adjDateInp) {
      if (locked) adjDateInp.setAttribute('readonly', 'readonly');
      else adjDateInp.removeAttribute('readonly');
    }
    if (notesInp) {
      if (locked) notesInp.setAttribute('readonly', 'readonly');
      else notesInp.removeAttribute('readonly');
    }
    if (tbody) {
      tbody.querySelectorAll('tr.item-price-adj-line').forEach(function (tr) {
        var pick = tr.querySelector('.js-pick-open');
        var priceInp = tr.querySelector('.js-new-price');
        var removeBtn = tr.querySelector('.js-remove');
        if (priceInp) {
          if (locked) priceInp.setAttribute('readonly', 'readonly');
          else priceInp.removeAttribute('readonly');
        }
        if (pick) pick.disabled = locked;
        if (removeBtn) removeBtn.style.display = locked ? 'none' : '';
      });
    }
    var delCol = document.querySelector('.item-price-adj-table .col-del');
    if (delCol) delCol.style.display = locked ? 'none' : '';
    if (!locked) ensureEntryRow();
    else {
      var entry = tbody ? tbody.querySelector('tr.is-entry-row') : null;
      if (entry) entry.remove();
    }
    syncToolbarPost();
    updateAdjNoPostedStyle();
  }

  function renumberLines() {
    if (!tbody) return;
    var n = 0;
    tbody.querySelectorAll('tr.item-price-adj-line:not(.is-entry-row)').forEach(function (tr) {
      n += 1;
      var seq = tr.querySelector('.js-seq');
      if (seq) seq.textContent = String(n);
    });
  }

  function fillLineRow(tr, ln) {
    var itemId = parseInt(ln.item_id, 10) || 0;
    tr.setAttribute('data-item-id', itemId > 0 ? String(itemId) : '');
    tr.classList.remove('is-entry-row');
    var skuEl = tr.querySelector('.js-sku');
    var nameEl = tr.querySelector('.js-name');
    var oldEl = tr.querySelector('.js-old-price');
    var taxEl = tr.querySelector('.js-tax');
    var priceInp = tr.querySelector('.js-new-price');
    if (skuEl) {
      var skuVal = ln.item_sku;
      if (skuVal != null && typeof skuVal === 'object') skuVal = '';
      skuEl.textContent = String(skuVal != null ? skuVal : '').trim();
    }
    if (nameEl) {
      nameEl.textContent = ln.item_name || '';
      nameEl.classList.remove('is-placeholder');
    }
    if (oldEl) {
      oldEl.textContent = ln.old_sale_price_display != null ? ln.old_sale_price_display : ln.old_sale_price;
    }
    if (taxEl) {
      taxEl.textContent = ln.tax_display || formatTax(ln.tax_rate_percent);
    }
    if (priceInp) {
      var np = parseFloat(ln.new_sale_price);
      priceInp.value = isFinite(np) && np > 0 ? String(np) : '';
    }
    var delCell = tr.querySelector('.col-del');
    if (delCell && docIsPosted) delCell.style.display = 'none';
  }

  function createLineRow(ln) {
    if (!tpl || !tbody) return null;
    var tr = tpl.content.firstElementChild.cloneNode(true);
    if (ln) fillLineRow(tr, ln);
    tbody.appendChild(tr);
    bindLineRow(tr);
    return tr;
  }

  function ensureEntryRow(focusPick) {
    if (!tpl || !tbody || docIsPosted) return null;
    var existing = tbody.querySelector('tr.is-entry-row');
    if (existing) {
      if (focusPick) {
        var pickExisting = existing.querySelector('.js-pick-open');
        if (pickExisting) pickExisting.focus();
      }
      return existing;
    }
    var tr = tpl.content.firstElementChild.cloneNode(true);
    tbody.appendChild(tr);
    bindLineRow(tr);
    if (focusPick) {
      var pickNew = tr.querySelector('.js-pick-open');
      if (pickNew) pickNew.focus();
    }
    return tr;
  }

  function focusEntryRowPicker() {
    var entry = ensureEntryRow(true);
    if (entry) {
      entry.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  function collectLinesFromTable() {
    var lines = [];
    if (!tbody) return lines;
    tbody.querySelectorAll('tr.item-price-adj-line:not(.is-entry-row)').forEach(function (tr) {
      var itemId = parseInt(tr.getAttribute('data-item-id') || '0', 10);
      var priceInp = tr.querySelector('.js-new-price');
      if (itemId < 1 || !priceInp) return;
      var np = parseFloat(priceInp.value);
      if (!isFinite(np)) return;
      lines.push({ item_id: itemId, new_sale_price: np });
    });
    return lines;
  }

  function syncLinesJson() {
    if (linesJsonInp) {
      linesJsonInp.value = JSON.stringify(collectLinesFromTable());
    }
  }

  function bindLineRow(tr) {
    var pick = tr.querySelector('.js-pick-open');
    var removeBtn = tr.querySelector('.js-remove');
    var priceInp = tr.querySelector('.js-new-price');
    if (pick) {
      pick.addEventListener('click', function (e) {
        e.preventDefault();
        if (docIsPosted) return;
        openItemPickerForRow(tr);
      });
    }
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (docIsPosted) return;
        tr.remove();
        renumberLines();
        ensureEntryRow();
      });
    }
    if (priceInp) {
      priceInp.addEventListener('input', syncLinesJson);
    }
  }

  function openItemPickerForRow(tr) {
    if (!global.ItemPickerModal || !apiItems) {
      alertMsg('نافذة اختيار المواد غير متوفرة.');
      return;
    }
    ItemPickerModal.open({
      singleSelect: true,
      screenCenter: true,
      anchorEl: tr.querySelector('.js-pick-open'),
      buildItemsUrl: function (q, listAll) {
        var url = apiItems;
        var parts = [];
        if (listAll || !q) parts.push('list=1');
        else parts.push('q=' + encodeURIComponent(String(q).trim()));
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
      },
      emptyMessage: 'لا توجد مواد',
      onSelect: function (item) {
        if (!item || parseInt(item.id, 10) < 1) return;
        var itemId = parseInt(item.id, 10);
        var dup = false;
        tbody.querySelectorAll('tr.item-price-adj-line:not(.is-entry-row)').forEach(function (r) {
          if (r !== tr && parseInt(r.getAttribute('data-item-id') || '0', 10) === itemId) dup = true;
        });
        if (dup) {
          alertMsg('هذه المادة مضافة مسبقاً في الجدول.');
          return;
        }
        var sale = parseFloat(item.default_sale);
        var taxRate = resolveItemTax(item);
        var ln = {
          item_id: itemId,
          item_sku: resolveItemSku(item),
          item_name: item.name_ar || '',
          old_sale_price: isFinite(sale) ? sale : 0,
          old_sale_price_display: isFinite(sale) ? sale : 0,
          new_sale_price: '',
          tax_rate_percent: taxRate,
          tax_display: formatTax(taxRate),
        };
        fillLineRow(tr, ln);
        if (tr.classList.contains('is-entry-row')) {
          tr.classList.remove('is-entry-row');
          ensureEntryRow();
        }
        renumberLines();
        syncLinesJson();
        var priceInp = tr.querySelector('.js-new-price');
        if (priceInp) priceInp.focus();
      },
    });
  }

  function applyDocData(doc) {
    currentDocId = parseInt(doc.id, 10) || 0;
    docIsPosted = !!doc.is_posted;
    if (docIdHidden) {
      docIdHidden.value = currentDocId > 0 ? String(currentDocId) : '';
    }
    syncAdjNoDisplay(doc.adj_no || '');
    if (adjDateInp) {
      adjDateInp.value = doc.adj_date_display || fmtDate(doc.adj_date || '') || adjDateInp.value;
    }
    if (notesInp) notesInp.value = doc.notes || '';
    if (tbody) {
      tbody.innerHTML = '';
      (doc.lines || []).forEach(function (ln) {
        createLineRow(ln);
      });
      renumberLines();
    }
    refreshDocEditState();
    setBrowseNav(doc.prev_id || 0, doc.next_id || 0);
    updateHistory(currentDocId);
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newUrl || window.location.pathname + '?r=item_sale_price_adjust';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    window.history.replaceState({ docId: id }, '', url);
  }

  function fetchDocResponse(query) {
    if (!apiDoc) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiDoc + '?' + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function loadDocById(id) {
    if (id < 1) return;
    fetchDocResponse({ id: id }).then(function (data) {
      if (!data || !data.ok || !data.doc) {
        alertMsg((data && data.message) || 'تعذر تحميل الحركة.');
        return;
      }
      applyDocData(data.doc);
    });
  }

  function loadDocByNo(no) {
    no = String(no || '').trim();
    if (!no) return;
    fetchDocResponse({ no: no }).then(function (data) {
      if (!data || !data.ok || !data.doc) {
        alertMsg((data && data.message) || 'لم يتم العثور على حركة بهذا الرقم.');
        return;
      }
      applyDocData(data.doc);
    });
  }

  function navigateDoc(dir) {
    if (currentDocId < 1) {
      navigateEmptyDoc(dir);
      return;
    }
    fetchDocResponse({ id: currentDocId, dir: dir }).then(function (data) {
      if (!data || !data.ok || !data.doc) {
        alertMsg(
          (data && data.message) || (dir === 'prev' ? 'لا توجد حركة أقدم.' : 'لا توجد حركة أحدث.')
        );
        return;
      }
      applyDocData(data.doc);
    });
  }

  function submitForm(action) {
    if (form.getAttribute('data-schema-ready') === '0') {
      alertMsg('جداول تعديل الأسعار غير جاهزة. راجع رسالة التنبيه أعلى الصفحة أو نفّذ ترحيل قاعدة البيانات.');
      return;
    }
    syncLinesJson();
    var lines = collectLinesFromTable();
    if (action === 'save' && lines.length < 1) {
      alertMsg('أضف مادة واحدة على الأقل بسعر معدّل.');
      return;
    }
    if (actionInp) actionInp.value = action;
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var act = e.detail.action;
    if (act === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      submitForm('save');
      return;
    }
    if (act === 'search') {
      e.preventDefault();
      e.stopImmediatePropagation();
      var no = adjNoInp ? String(adjNoInp.value || '').trim() : '';
      if (!no) {
        alertMsg('أدخل رقم الحركة ثم اضغط بحث.');
        if (adjNoInp) adjNoInp.focus();
        return;
      }
      loadDocByNo(no);
      return;
    }
    if (act === 'post') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (getCurrentDocId() < 1) {
        alertMsg('احفظ الحركة أولاً.');
        return;
      }
      confirmMsg('ترحيل الأسعار وتحديث بطاقات المواد؟', function () {
        submitForm('post');
      });
    }
  });

  var prevBtn = document.getElementById('item-price-adj-no-prev');
  var nextBtn = document.getElementById('item-price-adj-no-next');
  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      navigateDoc('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      navigateDoc('next');
    });
  }

  var addLineBtn = document.getElementById('item-price-adj-add-line');
  if (addLineBtn) {
    addLineBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (docIsPosted) return;
      focusEntryRowPicker();
    });
  }
  if (adjNoInp) {
    adjNoInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadDocByNo(adjNoInp.value);
      }
    });
  }

  if (form.getAttribute('data-schema-ready') === '0') {
    return;
  }

  if (initialDocId > 0 && form.getAttribute('data-schema-ready') !== '0') {
    loadDocById(initialDocId);
  } else if (window.__ITEM_PRICE_ADJ_INITIAL_LINES__ && window.__ITEM_PRICE_ADJ_INITIAL_LINES__.length) {
    window.__ITEM_PRICE_ADJ_INITIAL_LINES__.forEach(function (ln) {
      createLineRow(ln);
    });
    renumberLines();
    ensureEntryRow();
    refreshDocEditState();
    refreshEmptyBrowseNav();
  } else {
    ensureEntryRow();
    refreshDocEditState();
    refreshEmptyBrowseNav();
  }
})();
