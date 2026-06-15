(function () {
  'use strict';

  var form = document.getElementById('fin-cn-form');
  if (!form) return;

  var apiNote = form.getAttribute('data-api-note') || '';
  var apiDelete = form.getAttribute('data-api-delete') || '';
  var apiItems = form.getAttribute('data-api-items') || '';
  var newUrl = form.getAttribute('data-new-url') || '';
  var defaultDate = form.getAttribute('data-default-date') || '';
  var dp = parseInt(form.getAttribute('data-decimal-places') || '2', 10);
  if (isNaN(dp) || dp < 0) dp = 2;

  var recordIdInp = document.getElementById('cn_record_id');
  var partyTypeInp = document.getElementById('cn_party_type');
  var partyIdInp = document.getElementById('cn_party_id');
  var linesJsonInp = document.getElementById('cn_lines_json');
  var tbody = document.getElementById('cn_lines_body');
  var tpl = document.getElementById('fin-cn-line-template');
  var grandTotalEl = document.getElementById('cn_grand_total');
  var noInp = document.getElementById('cn_no');
  var dateInp = document.getElementById('cn_date');
  var reasonInp = document.getElementById('cn_reason');
  var custWrap = document.getElementById('cn_party_customer_wrap');
  var suppWrap = document.getElementById('cn_party_supplier_wrap');
  var partyRadios = form.querySelectorAll('input[name="party_type_ui"]');

  var currentId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var formSubmitting = false;
  var activePickRow = null;
  var customerPickerApi = null;
  var supplierPickerApi = null;

  function fmt(n) {
    return Number(n || 0).toFixed(dp);
  }

  function buildItemsUrl(q, listAll) {
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(q));
    return apiItems + (apiItems.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
  }

  function getPartyType() {
    var r = form.querySelector('input[name="party_type_ui"]:checked');
    return r ? r.value : 'customer';
  }

  function syncPartyTypeUi() {
    var pt = getPartyType();
    if (partyTypeInp) partyTypeInp.value = pt;
    if (custWrap) custWrap.hidden = pt !== 'customer';
    if (suppWrap) suppWrap.hidden = pt !== 'supplier';
  }

  function getPartyId() {
    var pt = getPartyType();
    var h = document.getElementById(pt === 'supplier' ? 'cn_supplier' : 'cn_customer');
    return h ? parseInt(h.value, 10) || 0 : 0;
  }

  function setPartyId(id) {
    if (partyIdInp) partyIdInp.value = id > 0 ? String(id) : '';
  }

  function renumberLines() {
    if (!tbody) return;
    var i = 0;
    tbody.querySelectorAll('tr.fin-cn-line').forEach(function (tr) {
      i++;
      var seq = tr.querySelector('.js-seq');
      if (seq) seq.textContent = String(i);
    });
  }

  function calcLine(tr) {
    var qty = parseFloat((tr.querySelector('.js-qty') || {}).value) || 0;
    var price = parseFloat((tr.querySelector('.js-price') || {}).value) || 0;
    var total = qty * price;
    var cell = tr.querySelector('.js-line-total');
    if (cell) cell.textContent = fmt(total);
    return total;
  }

  function calcGrandTotal() {
    var sum = 0;
    if (tbody) {
      tbody.querySelectorAll('tr.fin-cn-line').forEach(function (tr) {
        sum += calcLine(tr);
      });
    }
    if (grandTotalEl) grandTotalEl.textContent = fmt(sum);
    return sum;
  }

  function collectLines() {
    var lines = [];
    if (!tbody) return lines;
    tbody.querySelectorAll('tr.fin-cn-line').forEach(function (tr) {
      var qty = parseFloat((tr.querySelector('.js-qty') || {}).value) || 0;
      var price = parseFloat((tr.querySelector('.js-price') || {}).value) || 0;
      var lineTotal = qty * price;
      if (lineTotal <= 0) return;
      lines.push({
        item_id: parseInt(tr.getAttribute('data-item-id') || '0', 10) || 0,
        description_ar: String((tr.querySelector('.js-desc') || {}).value || '').trim(),
        qty: qty,
        unit_price: price,
        line_total: lineTotal,
      });
    });
    return lines;
  }

  function addLine(data) {
    if (!tpl || !tbody) return null;
    var node = tpl.content.cloneNode(true);
    var tr = node.querySelector('tr');
    if (!tr) return null;
    data = data || {};
    if (data.item_id) tr.setAttribute('data-item-id', String(data.item_id));
    var nameEl = tr.querySelector('.js-name');
    if (nameEl) {
      if (data.item_name) {
        nameEl.textContent = data.item_name;
        nameEl.classList.remove('is-placeholder');
      }
    }
    var desc = tr.querySelector('.js-desc');
    if (desc && data.description_ar) desc.value = data.description_ar;
    var qty = tr.querySelector('.js-qty');
    if (qty) qty.value = data.qty != null ? String(data.qty) : '1';
    var price = tr.querySelector('.js-price');
    if (price) price.value = data.unit_price != null ? String(data.unit_price) : '0';
    bindLine(tr);
    tbody.appendChild(tr);
    renumberLines();
    calcGrandTotal();
    return tr;
  }

  function bindLine(tr) {
    tr.querySelectorAll('.js-qty, .js-price').forEach(function (inp) {
      inp.addEventListener('input', function () {
        calcLine(tr);
        calcGrandTotal();
      });
    });
    var rm = tr.querySelector('.js-remove');
    if (rm) {
      rm.addEventListener('click', function () {
        tr.remove();
        renumberLines();
        calcGrandTotal();
        if (!tbody.querySelector('tr.fin-cn-line')) addLine();
      });
    }
    var pick = tr.querySelector('.js-pick-open');
    if (pick) {
      pick.addEventListener('click', function () {
        openItemPicker(tr);
      });
    }
  }

  function openItemPicker(tr) {
    if (!window.ItemPickerModal || !apiItems) {
      if (window.AppDialog) AppDialog.alert('قائمة المواد غير متاحة.', { type: 'warning' });
      return;
    }
    ItemPickerModal.open({
      buildItemsUrl: buildItemsUrl,
      getWarehouseId: function () {
        return 0;
      },
      emptyMessage: 'لا توجد مواد',
      onOpen: function () {
        if (activePickRow) activePickRow.classList.remove('is-picker-active');
        tr.classList.add('is-picker-active');
        activePickRow = tr;
      },
      onClose: function () {
        if (activePickRow) {
          activePickRow.classList.remove('is-picker-active');
          activePickRow = null;
        }
      },
      onSelect: function (item) {
        if (!item) return;
        tr.setAttribute('data-item-id', String(item.id || 0));
        var nameEl = tr.querySelector('.js-name');
        if (nameEl) {
          nameEl.textContent = (item.name_ar || item.code || '').trim() || '—';
          nameEl.classList.remove('is-placeholder');
        }
        var price = tr.querySelector('.js-price');
        if (price && item.sale_price != null && parseFloat(item.sale_price) > 0) {
          price.value = String(item.sale_price);
        }
        calcLine(tr);
        calcGrandTotal();
      },
    });
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('cn_no_prev', 'cn_no_next', browseNavPrevId, browseNavNextId, {
        onEmpty: currentId < 1,
        prevTitle: 'الإشعار السابق',
        nextTitle: 'الإشعار التالي',
        prevBeforeLatestTitle: 'الإشعار قبل الأخير',
        latestTitle: 'آخر إشعار دائن',
      });
    }
  }

  function refreshEmptyBrowseNav() {
    if (!apiNote) {
      setBrowseNav(0, 0);
      return;
    }
    fetchNoteQuery({ edge: 'first' }).then(function (data) {
      if (!data || !data.ok || !data.note) {
        setBrowseNav(0, 0);
        return;
      }
      var newestId = parseInt(data.note.id, 10) || 0;
      setBrowseNav(data.note.prev_id || 0, newestId);
    });
  }

  function fetchNoteQuery(query) {
    if (!apiNote) return Promise.resolve(null);
    var url = apiNote + (apiNote.indexOf('?') >= 0 ? '&' : '?');
    var parts = [];
    Object.keys(query).forEach(function (k) {
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(query[k]));
    });
    url += parts.join('&');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function navigateNote(dir) {
    if (currentId < 1) {
      if (window.DocumentNoNav) {
        DocumentNoNav.navigateEmpty(dir, {
          browseNavPrevId: browseNavPrevId,
          browseNavNextId: browseNavNextId,
          fetchById: function (id) {
            return fetchNoteQuery({ id: id });
          },
          fetchLatest: function () {
            return fetchNoteQuery({ edge: 'first' });
          },
          isOk: function (data) {
            return !!(data && data.ok && data.note);
          },
          getPayload: function (data) {
            return data.note;
          },
          apply: applyNote,
          emptyMessage: 'لا توجد إشعارات دائنة.',
          loadLatestError: 'تعذر تحميل آخر إشعار.',
          loadError: 'تعذر تحميل الإشعار.',
        });
        return;
      }
      fetchNoteQuery({ edge: 'first' }).then(function (data) {
        if (data && data.ok && data.note) applyNote(data.note);
      });
      return;
    }
    loadNote(currentId, dir);
  }

  function clearForm() {
    currentId = 0;
    if (recordIdInp) recordIdInp.value = '';
    if (noInp) noInp.value = '';
    if (dateInp) dateInp.value = defaultDate;
    if (reasonInp) reasonInp.value = '';
    if (tbody) tbody.innerHTML = '';
    addLine();
    calcGrandTotal();
    if (customerPickerApi) customerPickerApi.setById(0, true);
    if (supplierPickerApi) supplierPickerApi.setById(0, true);
    setPartyId(0);
    refreshEmptyBrowseNav();
  }

  function applyNote(note) {
    if (!note) return;
    currentId = note.id || 0;
    if (recordIdInp) recordIdInp.value = currentId > 0 ? String(currentId) : '';
    if (noInp) noInp.value = note.note_no || '';
    if (dateInp) dateInp.value = note.note_date_dmy || defaultDate;
    if (reasonInp) reasonInp.value = note.reason || '';
    var pt = note.party_type === 'supplier' ? 'supplier' : 'customer';
    partyRadios.forEach(function (r) {
      r.checked = r.value === pt;
    });
    syncPartyTypeUi();
    if (pt === 'customer' && customerPickerApi) {
      customerPickerApi.setById(note.party_id, true);
      var disp = document.getElementById('cn_customer_display');
      if (disp && note.party_name) {
        disp.textContent = note.party_name;
        disp.classList.remove('is-placeholder');
      }
    } else if (supplierPickerApi) {
      supplierPickerApi.setById(note.party_id, true);
      var sdisp = document.getElementById('cn_supplier_display');
      if (sdisp && note.party_name) {
        sdisp.textContent = note.party_name;
        sdisp.classList.remove('is-placeholder');
      }
    }
    setPartyId(note.party_id || 0);
    if (tbody) tbody.innerHTML = '';
    (note.lines || []).forEach(function (ln) {
      var label = ln.item_name || ln.description_ar || '';
      if (ln.item_code) label = ln.item_code + ' — ' + label;
      addLine({
        item_id: ln.item_id,
        item_name: label,
        description_ar: ln.description_ar,
        qty: ln.qty,
        unit_price: ln.unit_price,
      });
    });
    if (!tbody || !tbody.querySelector('tr.fin-cn-line')) addLine();
    calcGrandTotal();
    setBrowseNav(note.prev_id || 0, note.next_id || 0);
  }

  function loadNote(id, dir) {
    if (!apiNote || id < 1) return;
    var url = apiNote + (apiNote.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id;
    if (dir) url += '&dir=' + encodeURIComponent(dir);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok || !data.note) {
          if (window.AppDialog) {
            AppDialog.alert(data.message || 'الإشعار غير موجود.', { type: 'warning' });
          }
          return;
        }
        applyNote(data.note);
        if (window.history && window.history.replaceState) {
          var u = new URL(window.location.href);
          u.searchParams.set('r', 'credit_notes');
          u.searchParams.set('id', String(data.note.id));
          window.history.replaceState({}, '', u.pathname + u.search);
        }
      })
      .catch(function () {
        if (window.AppDialog) AppDialog.alert('تعذر تحميل الإشعار.', { type: 'error' });
      });
  }

  function trySave() {
    if (formSubmitting) return;
    syncPartyTypeUi();
    var pid = getPartyId();
    setPartyId(pid);
    if (pid < 1) {
      var msg = getPartyType() === 'customer' ? 'اختر العميل.' : 'اختر المورد.';
      if (window.AppDialog) AppDialog.alert(msg, { type: 'warning' });
      return;
    }
    var lines = collectLines();
    if (!lines.length) {
      if (window.AppDialog) AppDialog.alert('أضف سطرًا واحدًا على الأقل بمبلغ أكبر من صفر.', { type: 'warning' });
      return;
    }
    if (linesJsonInp) linesJsonInp.value = JSON.stringify(lines);
    formSubmitting = true;
    var fd = new FormData(form);
    fd.set('party_id', String(pid));
    fd.set('party_type', getPartyType());
    fd.set('lines_json', JSON.stringify(lines));
    fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        formSubmitting = false;
        if (data.ok) {
          if (data.id) loadNote(data.id);
          if (window.AppDialog) AppDialog.alert(data.message || 'تم الحفظ.', { type: 'success' });
        } else if (window.AppDialog) {
          AppDialog.alert(data.message || 'تعذر الحفظ.', { type: 'error' });
        }
      })
      .catch(function () {
        formSubmitting = false;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
  }

  function deleteNote() {
    if (currentId < 1) {
      clearForm();
      return;
    }
    if (!apiDelete) return;
    if (!window.confirm('حذف إشعار الدائنة الحالي؟')) return;
    var fd = new FormData();
    var csrf = form.querySelector('input[name="_csrf"]');
    if (csrf) fd.append('_csrf', csrf.value);
    fd.append('note_id', String(currentId));
    fetch(apiDelete, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data.ok) {
          window.location.href = newUrl;
        } else if (window.AppDialog) {
          AppDialog.alert(data.message || 'تعذر الحذف.', { type: 'error' });
        }
      });
  }

  partyRadios.forEach(function (r) {
    r.addEventListener('change', syncPartyTypeUi);
  });

  var addBtn = document.getElementById('cn_add_line');
  if (addBtn) addBtn.addEventListener('click', function () {
    addLine();
  });

  var prevBtn = document.getElementById('cn_no_prev');
  var nextBtn = document.getElementById('cn_no_next');
  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      navigateNote('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      navigateNote('next');
    });
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var action = e.detail.action;
    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      trySave();
    } else if (action === 'new') {
      e.preventDefault();
      e.stopImmediatePropagation();
      window.location.href = newUrl;
    } else if (action === 'delete') {
      e.preventDefault();
      e.stopImmediatePropagation();
      deleteNote();
    }
  });

  if (window.CustomerPickerModal) {
    customerPickerApi = CustomerPickerModal.bind({
      hidden: 'cn_customer',
      open: 'cn_customer_open',
      display: 'cn_customer_display',
      jsonId: 'fin-cn-customers-json',
      onSelect: function () {
        if (getPartyType() === 'customer') setPartyId(getPartyId());
      },
    });
  }
  if (window.SupplierPickerModal) {
    supplierPickerApi = SupplierPickerModal.bind({
      hidden: 'cn_supplier',
      open: 'cn_supplier_open',
      display: 'cn_supplier_display',
      jsonId: 'fin-cn-suppliers-json',
      onSelect: function () {
        if (getPartyType() === 'supplier') setPartyId(getPartyId());
      },
    });
  }

  syncPartyTypeUi();
  var initialId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  if (initialId > 0) {
    loadNote(initialId);
  } else {
    clearForm();
  }
})();
