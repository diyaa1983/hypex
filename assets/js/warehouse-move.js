(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;
  var form = document.getElementById('warehouse-move-form');
  if (!form) return;

  var page = document.querySelector('.warehouse-move-page');
  var scriptEl = document.querySelector('script[src*="warehouse-move.js"]');
  var canPost = scriptEl && scriptEl.getAttribute('data-can-post') === '1';
  var apiItems = form.getAttribute('data-api-items') || '';
  var apiStock = form.getAttribute('data-api-stock') || '';
  var apiMove = form.getAttribute('data-api-move') || '';
  var apiPrint = page ? page.getAttribute('data-api-print') || '' : '';
  var apiUnpost = page ? page.getAttribute('data-move-unpost-url') || '' : '';
  var apiDelete = page ? page.getAttribute('data-move-delete-url') || '' : '';
  var docPrintCss = page ? page.getAttribute('data-doc-print-css') || '' : '';
  var reportPrintCss = page ? page.getAttribute('data-report-print-css') || '' : '';
  var companyLogoUrl = page ? page.getAttribute('data-company-logo-url') || '' : '';
  if (!companyLogoUrl && document.body) {
    companyLogoUrl = document.body.getAttribute('data-company-logo-url') || '';
  }
  var canUnpost = page && page.getAttribute('data-can-unpost') === '1';
  var canDelete = page && page.getAttribute('data-can-delete') === '1';
  var newMoveUrl = form.getAttribute('data-new-url') || '';
  var initialMoveId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var currentMoveId = initialMoveId > 0 ? initialMoveId : 0;
  var moveIsPosted = form.classList.contains('wh-move-form-is-posted');
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var qtyDp = parseInt(form.getAttribute('data-qty-dp') || '2', 10);
  var moveNoInp = document.getElementById('wh-move-no');
  var moveDateInp = document.getElementById('wh-move-date');
  var notesInp = document.getElementById('wh-move-notes');

  var tbody = document.getElementById('wh-move-lines-body');
  var tpl = document.getElementById('wh-move-line-tpl');
  var linesPanel = document.getElementById('wh-move-lines-panel');
  var linesJsonInp = document.getElementById('wh-move-lines-json');
  var actionInp = document.getElementById('wh-move-action');
  var moveIdHidden = document.getElementById('wh-move-id');
  var typeSel = document.getElementById('wh-move-type');
  var whSel = document.getElementById('wh-move-warehouse');
  var whLabel = document.getElementById('wh-move-wh-label');
  var whToWrap = document.getElementById('wh-move-to-wrap');
  var whToSel = document.getElementById('wh-move-warehouse-to');
  var onHandColLabel = document.getElementById('wh-move-onhand-col-label');
  var qtyColLabel = document.getElementById('wh-move-qty-col-label');
  var hintEl = document.getElementById('wh-move-hint');
  var warehouseNotice = document.getElementById('wh-move-warehouse-notice');

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

  function getWarehouseId() {
    return parseInt(whSel && whSel.value ? whSel.value : '0', 10) || 0;
  }

  function getCurrentMoveId() {
    if (currentMoveId > 0) {
      return currentMoveId;
    }
    return parseInt(moveIdHidden && moveIdHidden.value ? moveIdHidden.value : '0', 10) || 0;
  }

  function fmtDate(value) {
    if (global.AppFormat && AppFormat.formatDateDisplay) {
      return AppFormat.formatDateDisplay(value);
    }
    return value == null ? '' : String(value);
  }

  function updateMoveNoPostedStyle() {
    if (!moveNoInp) return;
    moveNoInp.classList.remove('is-posted', 'is-unposted');
    if (currentMoveId < 1) return;
    if (moveIsPosted) {
      moveNoInp.classList.add('is-posted');
    } else {
      moveNoInp.classList.add('is-unposted');
    }
  }

  function syncMoveNoDisplay(moveNo) {
    if (!moveNoInp) return;
    if (currentMoveId > 0) {
      moveNoInp.value = moveNo != null && moveNo !== undefined ? String(moveNo).trim() : moveNoInp.value;
    } else {
      moveNoInp.value = '';
    }
    moveNoInp.placeholder = '';
    updateMoveNoPostedStyle();
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('wh-move-no-prev', 'wh-move-no-next', prevId, nextId, {
        onEmpty: currentMoveId < 1,
        prevTitle: 'الحركة السابقة',
        nextTitle: 'الحركة التالية',
        prevBeforeLatestTitle: 'الحركة قبل الأخيرة',
        latestTitle: 'آخر حركة مستودع',
      });
      return;
    }
    var prevBtn = document.getElementById('wh-move-no-prev');
    var nextBtn = document.getElementById('wh-move-no-next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
  }

  function navigateEmptyMove(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchMoveResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchMoveResponse({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.move);
      },
      getPayload: function (data) {
        return data.move;
      },
      apply: applyMoveData,
      emptyMessage: 'لا توجد حركات مستودع محفوظة بعد.',
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
    if (!apiMove) {
      setBrowseNav(0, 0);
      return;
    }
    fetchMoveResponse({ edge: 'first' }).then(function (data) {
      if (!data || !data.ok || !data.move) {
        setBrowseNav(0, 0);
        return;
      }
      var newestId = parseInt(data.move.id, 10) || 0;
      setBrowseNav(data.move.prev_id || 0, newestId);
    });
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newMoveUrl || window.location.pathname + '?r=warehouse_moves';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    window.history.replaceState({ moveId: id }, '', url);
  }

  function refreshMoveEditState() {
    var locked = moveIsPosted;
    form.classList.toggle('wh-move-form-is-posted', locked);
    form.classList.toggle('wh-move-form-is-saved', currentMoveId > 0);
    if (moveDateInp) {
      if (locked) moveDateInp.setAttribute('readonly', 'readonly');
      else moveDateInp.removeAttribute('readonly');
    }
    if (typeSel) typeSel.disabled = locked;
    if (whSel) whSel.disabled = locked;
    if (whToSel) whToSel.disabled = locked;
    if (notesInp) {
      if (locked) notesInp.setAttribute('readonly', 'readonly');
      else notesInp.removeAttribute('readonly');
    }
    if (tbody) {
      tbody.querySelectorAll('tr.wh-move-line').forEach(function (tr) {
        var pick = tr.querySelector('.js-pick-open');
        var qtyInp = tr.querySelector('.js-qty');
        var removeBtn = tr.querySelector('.js-remove');
        if (qtyInp) {
          if (locked) qtyInp.setAttribute('readonly', 'readonly');
          else qtyInp.removeAttribute('readonly');
        }
        if (pick) pick.disabled = locked;
        if (removeBtn) removeBtn.style.display = locked ? 'none' : '';
      });
    }
    if (!locked) ensureEntryRow();
    else if (tbody) {
      var entry = tbody.querySelector('tr.is-entry-row');
      if (entry) entry.remove();
    }
    syncToolbarPost();
    syncToolbarUnpost();
    syncToolbarDelete();
    updateMoveNoPostedStyle();
    var postedBadge = document.getElementById('wh-move-posted-badge');
    if (postedBadge) {
      if (moveIsPosted && currentMoveId > 0) {
        postedBadge.hidden = false;
        postedBadge.textContent = 'مرحّل';
      } else {
        postedBadge.hidden = true;
        postedBadge.textContent = '';
      }
    }
  }

  function applyMoveData(move) {
    currentMoveId = parseInt(move.id, 10) || 0;
    moveIsPosted = !!move.is_posted;
    if (moveIdHidden) {
      moveIdHidden.value = currentMoveId > 0 ? String(currentMoveId) : '';
    }
    if (moveNoInp) {
      moveNoInp.dataset.loadedNo = move.move_no || '';
    }
    syncMoveNoDisplay(move.move_no || '');

    if (moveDateInp) {
      moveDateInp.value = move.move_date_display || fmtDate(move.move_date || '') || moveDateInp.value;
    }
    if (typeSel && move.movement_type_code) {
      typeSel.value = String(move.movement_type_code);
      ensureMovementTypeSelected();
    }
    if (whSel) {
      whSel.value = move.warehouse_id ? String(move.warehouse_id) : '';
    }
    if (whToSel) {
      whToSel.value = move.warehouse_to_id ? String(move.warehouse_to_id) : '';
    }
    if (notesInp) {
      notesInp.value = move.notes || '';
    }

    if (tbody) {
      tbody.innerHTML = '';
      (move.lines || []).forEach(function (ln) {
        createLineRow(ln);
      });
      renumberLines();
    }

    syncMovementTypeUi();
    syncWarehouseNotice();
    refreshMoveEditState();
    setBrowseNav(move.prev_id || 0, move.next_id || 0);
    updateHistory(currentMoveId);
  }

  function fetchMoveResponse(query) {
    if (!apiMove) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiMove + '?' + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function loadMoveById(id) {
    if (id < 1) return;
    fetchMoveResponse({ id: id }).then(function (data) {
      if (!data || !data.ok || !data.move) {
        alertMsg((data && data.message) || 'تعذر تحميل الحركة.');
        return;
      }
      applyMoveData(data.move);
    });
  }

  function loadMoveByNo(no) {
    no = String(no || '').trim();
    if (!no) return;
    fetchMoveResponse({ no: no }).then(function (data) {
      if (!data || !data.ok || !data.move) {
        alertMsg((data && data.message) || 'لم يتم العثور على حركة بهذا الرقم.');
        return;
      }
      applyMoveData(data.move);
    });
  }

  function runToolbarMoveSearch() {
    var no = moveNoInp ? String(moveNoInp.value || '').trim() : '';
    if (!no) {
      alertMsg('أدخل رقم الحركة في الحقل أعلاه ثم اضغط بحث.');
      if (moveNoInp) moveNoInp.focus();
      return;
    }
    loadMoveByNo(no);
  }

  function navigateMove(dir) {
    if (currentMoveId < 1) {
      navigateEmptyMove(dir);
      return;
    }
    fetchMoveResponse({ id: currentMoveId, dir: dir }).then(function (data) {
      if (!data || !data.ok || !data.move) {
        alertMsg(
          (data && data.message) || (dir === 'prev' ? 'لا توجد حركة أقدم.' : 'لا توجد حركة أحدث.')
        );
        return;
      }
      applyMoveData(data.move);
    });
  }

  function getMovementType() {
    return typeSel ? String(typeSel.value || '') : '';
  }

  function ensureMovementTypeSelected() {
    if (!typeSel || typeSel.options.length < 1) {
      return;
    }
    var current = getMovementType();
    if (current !== '') {
      for (var i = 0; i < typeSel.options.length; i++) {
        if (typeSel.options[i].value === current) {
          return;
        }
      }
    }
    var initial = form.getAttribute('data-initial-type') || 'adjust_in';
    var picked = false;
    for (var j = 0; j < typeSel.options.length; j++) {
      if (typeSel.options[j].value === initial) {
        typeSel.selectedIndex = j;
        picked = true;
        break;
      }
    }
    if (!picked) {
      for (var k = 0; k < typeSel.options.length; k++) {
        if (typeSel.options[k].value) {
          typeSel.selectedIndex = k;
          break;
        }
      }
    }
  }

  function movementTypeHint(code) {
    var types = global.__WH_MOVE_TYPES__;
    if (Array.isArray(types)) {
      for (var i = 0; i < types.length; i++) {
        if (types[i] && String(types[i].code || '') === code) {
          var hint = String(types[i].hint_ar || '').trim();
          if (hint !== '') {
            return hint;
          }
        }
      }
    }
    return '';
  }

  function isTransferType() {
    return getMovementType() === 'transfer';
  }

  function isAdjustInType() {
    return getMovementType() === 'adjust_in';
  }

  function isAdjustOutType() {
    return getMovementType() === 'adjust_out';
  }

  function isDisposalType() {
    return getMovementType() === 'disposal';
  }

  function syncMovementTypeUi() {
    var code = getMovementType();
    var transfer = code === 'transfer';
    var adjustIn = isAdjustInType();
    var adjustOut = isAdjustOutType();
    var disposal = isDisposalType();

    if (whToWrap) {
      whToWrap.hidden = !transfer;
    }
    if (whToSel) {
      whToSel.required = transfer && !moveIsPosted;
    }
    if (whLabel) {
      whLabel.textContent = transfer ? 'من مستودع *' : 'المستودع *';
    }
    if (onHandColLabel) {
      onHandColLabel.textContent = 'الكمية الحالية';
    }
    if (qtyColLabel) {
      if (adjustIn || adjustOut || disposal) {
        qtyColLabel.textContent = 'الكمية المعدلة';
      } else if (transfer) {
        qtyColLabel.textContent = 'الكمية المراد نقلها';
      } else {
        qtyColLabel.textContent = 'الكمية';
      }
    }

    tbody.querySelectorAll('.js-qty').forEach(function (inp) {
      if (adjustIn) {
        inp.placeholder = 'كمية الزيادة';
        inp.title = 'تُضاف إلى الكمية الحالية عند الترحيل';
      } else if (adjustOut) {
        inp.placeholder = 'كمية النقصان';
        inp.title = 'تُخصم من الكمية الحالية عند الترحيل';
      } else if (disposal) {
        inp.placeholder = 'كمية الإتلاف';
        inp.title = 'تُخرج من المستودع (إتلاف) عند الترحيل';
      } else if (transfer) {
        inp.placeholder = '';
        inp.title = 'كمية النقل بين المستودعين';
      } else {
        inp.placeholder = '';
        inp.title = '';
      }
    });

    if (hintEl) {
      var typeHint = movementTypeHint(code);
      if (typeHint !== '') {
        hintEl.textContent = typeHint;
      } else if (adjustIn) {
        hintEl.textContent =
          'اختر المستودع ثم أضف المواد من القائمة. الكمية المعدلة تُزاد على الرصيد الحالي عند الترحيل.';
      } else if (adjustOut) {
        hintEl.textContent =
          'اختر المستودع ثم أضف المواد من القائمة. الكمية المعدلة تُنقص من الرصيد الحالي عند الترحيل.';
      } else if (transfer) {
        hintEl.textContent = 'اختر المستودعين ثم حدد المواد وكميات النقل.';
      } else if (disposal) {
        hintEl.textContent =
          'اختر المستودع ثم أضف المواد من القائمة. الكمية المعدلة تُخصم من الرصيد (إتلاف) عند الترحيل.';
      } else {
        hintEl.textContent = 'اختر المستودع ثم أضف المواد.';
      }
    }

    syncWarehouseNotice();
  }

  function syncWarehouseNotice() {
    if (moveIsPosted) {
      if (warehouseNotice) warehouseNotice.hidden = true;
      return;
    }
    var hasWh = getWarehouseId() > 0;
    if (warehouseNotice) {
      warehouseNotice.hidden = hasWh;
    }
    if (linesPanel) {
      linesPanel.classList.toggle('wh-move-lines--no-warehouse', !hasWh);
    }
  }

  function formatQty(n) {
    var x = parseFloat(n);
    if (!isFinite(x)) return '0';
    return x.toFixed(qtyDp);
  }

  function escapeHtml(s) {
    var d = document.createElement('p');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function fetchItemStock(itemId, cb) {
    var whId = getWarehouseId();
    if (itemId < 1 || whId < 1) {
      cb(0);
      return;
    }
    if (!apiStock) {
      cb(0);
      return;
    }
    var url =
      apiStock +
      (apiStock.indexOf('?') >= 0 ? '&' : '?') +
      'warehouse_id=' +
      whId +
      '&item_id=' +
      itemId;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        cb(data && data.ok ? parseFloat(data.stock_qty || 0) : 0);
      })
      .catch(function () {
        cb(0);
      });
  }

  function renumberLines() {
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr.wh-move-line:not(.is-entry-row)');
    rows.forEach(function (tr, i) {
      var seq = tr.querySelector('.js-seq');
      if (seq) seq.textContent = String(i + 1);
    });
  }

  function bindRow(tr) {
    if (!tr || tr.dataset.bound === '1') return;
    tr.dataset.bound = '1';

    var pickBtn = tr.querySelector('.js-pick-open');
    var qtyInp = tr.querySelector('.js-qty');
    var removeBtn = tr.querySelector('.js-remove');

    if (pickBtn) {
      pickBtn.addEventListener('click', function () {
        if (moveIsPosted) return;
        if (getWarehouseId() < 1) {
          alertMsg('اختر المستودع أولاً.');
          return;
        }
        openItemPicker(tr);
      });
    }

    if (qtyInp) {
      qtyInp.addEventListener('input', function () {
        tr.classList.remove('is-entry-row');
        ensureEntryRow();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (moveIsPosted) return;
        if (tr.classList.contains('is-entry-row')) return;
        tr.remove();
        renumberLines();
        ensureEntryRow();
      });
    }
  }

  function setRowItem(tr, item) {
    var itemId = parseInt(item.id, 10) || 0;
    tr.dataset.itemId = String(itemId);
    var skuEl = tr.querySelector('.js-sku');
    var nameEl = tr.querySelector('.js-name');
    var onHandEl = tr.querySelector('.js-on-hand');
    if (skuEl) {
      var itemNo =
        global.InvItemDisplay && InvItemDisplay.materialNumberDigitsOnly
          ? InvItemDisplay.materialNumberDigitsOnly(item.barcode, item.sku)
          : String(item.sku || '').replace(/\D/g, '');
      skuEl.textContent = itemNo;
    }
    if (nameEl) {
      nameEl.textContent = String(item.name_ar || '');
      nameEl.classList.remove('is-placeholder');
    }
    var stockFromPicker = parseFloat(item.stock_qty);
    if (isFinite(stockFromPicker) && getWarehouseId() > 0) {
      if (onHandEl) onHandEl.textContent = formatQty(stockFromPicker);
      tr.dataset.onHand = String(stockFromPicker);
    } else {
      fetchItemStock(itemId, function (stock) {
        if (onHandEl) onHandEl.textContent = formatQty(stock);
        tr.dataset.onHand = String(stock);
      });
    }
    tr.classList.remove('is-entry-row');
    renumberLines();
    ensureEntryRow();
  }

  function buildItemsApiUrl(q, listAll) {
    if (!apiItems) return '';
    var url = apiItems;
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(String(q).trim()));
    var whId = getWarehouseId();
    if (whId > 0) parts.push('warehouse_id=' + whId);
    if (parts.length) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    }
    return url;
  }

  function openItemPicker(tr) {
    if (!global.ItemPickerModal) {
      alertMsg('نافذة اختيار المواد غير متوفرة.');
      return;
    }
    var pickBtn = tr.querySelector('.js-pick-open');
    global.ItemPickerModal.open({
      singleSelect: true,
      screenCenter: true,
      anchorEl: pickBtn,
      buildItemsUrl: buildItemsApiUrl,
      getWarehouseId: getWarehouseId,
      emptyMessage: 'لا توجد مواد',
      onSelect: function (item) {
        if (item && parseInt(item.id, 10) > 0) {
          setRowItem(tr, item);
        }
      },
    });
  }

  function createLineRow(data) {
    if (!tpl || !tbody) return null;
    var frag = tpl.content.cloneNode(true);
    var tr = frag.querySelector('tr');
    if (!tr) return null;
    if (moveIsPosted) {
      var del = tr.querySelector('.wh-col-del');
      if (del) del.remove();
      var qtyInp = tr.querySelector('.js-qty');
      if (qtyInp) {
        qtyInp.readOnly = true;
      }
      var pick = tr.querySelector('.js-pick-open');
      if (pick) pick.disabled = true;
    }
    tbody.appendChild(tr);
    bindRow(tr);
    if (data && parseInt(data.item_id, 10) > 0) {
      setRowItem(tr, {
        id: data.item_id,
        sku: data.sku || '',
        name_ar: data.name_ar || '',
      });
      var qtyInp2 = tr.querySelector('.js-qty');
      if (qtyInp2 && data.qty) qtyInp2.value = String(data.qty);
      if (data.on_hand != null) {
        var onHandEl = tr.querySelector('.js-on-hand');
        if (onHandEl) onHandEl.textContent = formatQty(data.on_hand);
      }
    }
    return tr;
  }

  function ensureEntryRow() {
    if (moveIsPosted || !tbody) return null;
    var entry = tbody.querySelector('tr.is-entry-row');
    if (!entry) {
      entry = createLineRow(null);
      if (entry) entry.classList.add('is-entry-row');
    }
    return entry;
  }

  function collectLines() {
    var lines = [];
    if (!tbody) return lines;
    tbody.querySelectorAll('tr.wh-move-line').forEach(function (tr) {
      if (tr.classList.contains('is-entry-row')) return;
      var itemId = parseInt(tr.dataset.itemId || '0', 10);
      var qtyInp = tr.querySelector('.js-qty');
      var qty = qtyInp ? parseFloat(String(qtyInp.value).replace(',', '.')) : 0;
      if (itemId > 0 && qty > 0) {
        lines.push({ item_id: itemId, qty: qty });
      }
    });
    return lines;
  }

  function validateBeforeSubmit() {
    if (!form.reportValidity || form.reportValidity()) {
      /* ok */
    } else {
      return false;
    }
    if (getWarehouseId() < 1) {
      alertMsg('اختر المستودع.');
      return false;
    }
    if (isTransferType()) {
      var toId = parseInt(whToSel && whToSel.value ? whToSel.value : '0', 10);
      if (toId < 1) {
        alertMsg('اختر المستودع المستهدف للنقل.');
        return false;
      }
      if (toId === getWarehouseId()) {
        alertMsg('لا يمكن النقل إلى نفس المستودع.');
        return false;
      }
    }
    var lines = collectLines();
    if (lines.length < 1) {
      alertMsg('أضف مادة واحدة على الأقل بكمية أكبر من صفر.');
      return false;
    }
    return true;
  }

  function submitForm(action) {
    if (moveIsPosted && action !== 'print') {
      alertMsg('لا يمكن تعديل حركة مرحّلة.');
      return;
    }
    if (!validateBeforeSubmit()) return;
    if (linesJsonInp) {
      linesJsonInp.value = JSON.stringify(collectLines());
    }
    if (actionInp) actionInp.value = action;
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  function syncToolbarPost() {
    var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
    if (!postBtn) return;
    var moveId = getCurrentMoveId();
    var enabled = canPost && !moveIsPosted && moveId > 0;
    postBtn.disabled = !enabled;
    postBtn.classList.toggle('is-inactive', !enabled);
    postBtn.title = enabled
      ? 'ترحيل الحركة إلى المخزون'
      : moveIsPosted
        ? 'الحركة مرحّلة'
        : 'احفظ الحركة أولاً ثم رحّل';
  }

  function syncToolbarDelete() {
    var delBtn = document.querySelector('#master-toolbar [data-master-action="delete"]');
    if (!delBtn) return;
    var moveId = getCurrentMoveId();
    var enabled = canDelete && !moveIsPosted && moveId > 0;
    delBtn.disabled = !enabled;
    delBtn.classList.toggle('is-inactive', !enabled);
    delBtn.title = enabled
      ? 'حذف الحركة من النظام'
      : moveIsPosted
        ? 'فكّ الترحيل أولاً ثم احذف'
        : moveId < 1
          ? 'احفظ الحركة أولاً أو امسح البنود'
          : 'غير متاح';
  }

  function syncToolbarUnpost() {
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    if (!unpostBtn) return;
    var moveId = getCurrentMoveId();
    var enabled = canUnpost && moveIsPosted && moveId > 0;
    unpostBtn.disabled = !enabled;
    unpostBtn.classList.toggle('is-inactive', !enabled);
    unpostBtn.title = enabled
      ? 'فك ترحيل الحركة (إلغاء المخزون والقيد المحاسبي)'
      : !moveIsPosted
        ? 'الحركة غير مرحّلة'
        : 'احفظ وارحّل الحركة أولاً';
  }

  function docPrintWatermarkStyles() {
    var dh = global.DocumentHeader;
    return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
      ? dh.buildPrintWatermarkStyles(companyLogoUrl)
      : '';
  }

  function getPrintFrameStyles() {
    var dh = global.DocumentHeader && global.DocumentHeader.css ? global.DocumentHeader.css : '';
    var bold =
      global.DocumentHeader && global.DocumentHeader.printBoldCss
        ? global.DocumentHeader.printBoldCss
        : '';
    return (
      docPrintWatermarkStyles() +
      dh +
      bold +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm 12mm;direction:rtl;background:#fff;}' +
      '.report-sales-table-wrap{width:100%;overflow:visible;}' +
      '.report-sales-table,.wh-move-print-table{width:100%;border-collapse:collapse;margin-top:0.35rem;}' +
      '.report-sales-table th,.report-sales-table td,.wh-move-print-table th,.wh-move-print-table td{border:1px solid #94a3b8;padding:0.35rem 0.45rem;text-align:start;}' +
      '.report-sales-table th,.wh-move-print-table th{background:#f1f5f9;font-weight:800;}' +
      '.col-money{text-align:end;white-space:nowrap;}' +
      '.wh-move-print-item-name{font-weight:700;}'
    );
  }

  function buildStandalonePrintHtml(innerHtml) {
    var bodyAttrs =
      global.DocumentHeader && global.DocumentHeader.bodyPrintAttrs
        ? global.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    var wrapped =
      global.DocumentHeader && global.DocumentHeader.wrapPrintContent
        ? global.DocumentHeader.wrapPrintContent(innerHtml, companyLogoUrl)
        : innerHtml;
    var links = '';
    if (docPrintCss) {
      links += '<link rel="stylesheet" href="' + docPrintCss + '">';
    }
    if (reportPrintCss) {
      links += '<link rel="stylesheet" href="' + reportPrintCss + '">';
    }
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>حركة مستودع</title>' +
      links +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      wrapped +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('wh-move-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'wh-move-print-frame';
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
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 250);
  }

  function fetchPrintHtml(moveId) {
    if (!apiPrint || moveId < 1) {
      return Promise.resolve(null);
    }
    var url =
      apiPrint +
      (apiPrint.indexOf('?') >= 0 ? '&' : '?') +
      'id=' +
      moveId +
      '&embed=1';
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function closePrintPreview() {
    var overlay = document.getElementById('wh-move-print-overlay');
    if (overlay) {
      overlay.hidden = true;
    }
  }

  function showPrintPreviewHtml(html) {
    var preview = document.getElementById('wh-move-print-preview');
    var overlay = document.getElementById('wh-move-print-overlay');
    if (!preview || !overlay) {
      printHtmlInFrame(buildStandalonePrintHtml(html));
      return;
    }
    var inner = html;
    if (global.DocumentHeader && DocumentHeader.wrapPrintContent) {
      inner = DocumentHeader.wrapPrintContent(inner, companyLogoUrl);
    }
    preview.innerHTML = inner;
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    overlay.removeAttribute('hidden');
    overlay.hidden = false;
    overlay.style.display = 'flex';
    overlay.style.zIndex = '10050';
  }

  function openPrintPreview() {
    var moveId = getCurrentMoveId();
    if (moveId < 1) {
      alertMsg('احفظ الحركة أولاً لطباعة التقرير بالترويسة والجدول الموحّد.');
      return;
    }
    fetchPrintHtml(moveId).then(function (data) {
      if (!data || !data.ok || !data.html) {
        alertMsg((data && data.message) || 'تعذر تحميل نموذج الطباعة.');
        return;
      }
      showPrintPreviewHtml(data.html);
    });
  }

  function runPrintFromPreview() {
    var moveId = getCurrentMoveId();
    if (moveId < 1) {
      alertMsg('احفظ الحركة أولاً.');
      return;
    }
    fetchPrintHtml(moveId).then(function (data) {
      if (!data || !data.ok || !data.html) {
        alertMsg((data && data.message) || 'تعذر تحميل نموذج الطباعة.');
        return;
      }
      printHtmlInFrame(buildStandalonePrintHtml(data.html));
    });
  }

  function handleToolbarPrint() {
    var overlay = document.getElementById('wh-move-print-overlay');
    var previewOpen = overlay && !overlay.hidden;
    if (previewOpen) {
      runPrintFromPreview();
      return;
    }
    openPrintPreview();
  }

  function deleteCurrentMove() {
    if (!canDelete) {
      alertMsg('ليس لديك صلاحية الحذف.');
      return;
    }
    var moveId = getCurrentMoveId();
    if (moveId < 1) {
      if (tbody && tbody.querySelectorAll('tr.wh-move-line:not(.is-entry-row)').length > 0) {
        confirmMsg('مسح جميع بنود الحركة من الجدول؟', function () {
          tbody.innerHTML = '';
          ensureEntryRow();
        });
        return;
      }
      alertMsg('لا توجد حركة محفوظة.');
      return;
    }
    if (moveIsPosted) {
      alertMsg('لا يمكن حذف حركة مرحّلة. فكّ الترحيل أولاً.');
      return;
    }
    var moveNo = moveNoInp ? String(moveNoInp.value || '').trim() : '';
    var label = moveNo !== '' ? 'حركة #' + moveNo : 'هذه الحركة';
    confirmMsg('حذف ' + label + ' نهائياً من النظام؟', function () {
      if (!apiDelete) {
        if (actionInp) actionInp.value = 'delete';
        form.submit();
        return;
      }
      var csrfInput = form.querySelector('[name="_csrf"]');
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('move_id', String(moveId));
      fetch(apiDelete, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            alertMsg((data && (data.message || data.error)) || 'تعذر الحذف.');
            return;
          }
          if (global.AppDialog && AppDialog.success) {
            AppDialog.success(data.message || 'تم حذف الحركة.');
          }
          window.location.href = newMoveUrl || window.location.pathname + '?r=warehouse_moves';
        })
        .catch(function () {
          alertMsg('تعذر الاتصال بالخادم.');
        });
    });
  }

  function unpostCurrentMove() {
    if (!canUnpost) {
      alertMsg('ليس لديك صلاحية فك الترحيل.');
      return;
    }
    var moveId = getCurrentMoveId();
    if (moveId < 1) {
      alertMsg('احفظ الحركة أولاً.');
      return;
    }
    if (!moveIsPosted) {
      alertMsg('الحركة غير مرحّلة.');
      return;
    }
    var msg =
      'سيتم فك ترحيل الحركة:\n' +
      '• إلغاء حركات المخزون (إعادة الأرصدة كما قبل الترحيل).\n' +
      '• إلغاء القيد المحاسبي إن وُجد (زيادة/نقصان/إتلاف).\n' +
      '• إعادة الحركة إلى مسودة للتعديل.\n\nمتابعة؟';
    confirmMsg(msg, function () {
      if (apiUnpost) {
        var csrfInput = form.querySelector('[name="_csrf"]');
        var fd = new FormData();
        fd.append('_csrf', csrfInput ? csrfInput.value : '');
        fd.append('move_id', String(moveId));
        fetch(apiUnpost, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data || !data.ok) {
              alertMsg((data && (data.message || data.error)) || 'تعذر فك الترحيل.');
              return;
            }
            if (global.AppDialog && AppDialog.success) {
              AppDialog.success(data.message || 'تم فك ترحيل الحركة.');
            }
            loadMoveById(moveId);
          })
          .catch(function () {
            alertMsg('تعذر الاتصال بالخادم.');
          });
        return;
      }
      if (actionInp) actionInp.value = 'unpost';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }

  function doPdf() {
    var moveId = getCurrentMoveId();
    if (moveId < 1) {
      alertMsg('احفظ الحركة أولاً لتحميل PDF.');
      return;
    }
    if (apiPrint) {
      window.open(apiPrint + '?id=' + moveId + '&format=pdf', '_blank', 'noopener');
    }
  }

  function doExcel() {
    var rows = [['#', 'رقم المادة', 'المادة', 'المتوفرة', 'الكمية']];
    tbody.querySelectorAll('tr.wh-move-line:not(.is-entry-row)').forEach(function (tr, i) {
      rows.push([
        String(i + 1),
        tr.querySelector('.js-sku') ? tr.querySelector('.js-sku').textContent : '',
        tr.querySelector('.js-name') ? tr.querySelector('.js-name').textContent : '',
        tr.querySelector('.js-on-hand') ? tr.querySelector('.js-on-hand').textContent : '',
        tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : '',
      ]);
    });
    if (rows.length < 2) {
      alertMsg('لا توجد بنود للتصدير.');
      return;
    }
    var csv = rows
      .map(function (row) {
        return row
          .map(function (c) {
            return '"' + String(c).replace(/"/g, '""') + '"';
          })
          .join(',');
      })
      .join('\r\n');
    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'warehouse-move.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var action = e.detail.action;
    if (action === 'search') {
      e.preventDefault();
      e.stopImmediatePropagation();
      runToolbarMoveSearch();
      return;
    }
    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      confirmMsg('حفظ حركة المستودع؟', function () {
        submitForm('save');
      });
      return;
    }
    if (action === 'delete') {
      e.preventDefault();
      e.stopImmediatePropagation();
      deleteCurrentMove();
      return;
    }
    if (action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      unpostCurrentMove();
      return;
    }
    if (action === 'post') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (!canPost) {
        alertMsg('ليس لديك صلاحية الترحيل.');
        return;
      }
      var moveId = getCurrentMoveId();
      if (moveId < 1) {
        var savePostMsg = 'لم تُحفظ الحركة بعد. حفظ وترحيل معاً؟';
        if (isAdjustOutType()) {
          savePostMsg = 'حفظ وترحيل تعديل النقصان؟ ستُخصم الكميات من المخزون.';
        } else if (isDisposalType()) {
          savePostMsg = 'حفظ وترحيل الإتلاف؟ ستُخصم الكميات من المخزون.';
        } else if (isAdjustInType()) {
          savePostMsg = 'حفظ وترحيل تعديل الزيادة؟ ستُزاد الكميات في المخزون.';
        }
        confirmMsg(savePostMsg, function () {
          submitForm('save_post');
        });
        return;
      }
      var postMsg = 'ترحيل الحركة إلى المخزون؟ لا يمكن التعديل بعد الترحيل.';
      if (isAdjustInType()) {
        postMsg = 'ترحيل تعديل الزيادة؟ ستُزاد الكميات المعدلة على رصيد المستودع.';
      } else if (isAdjustOutType()) {
        postMsg = 'ترحيل تعديل النقصان؟ ستُخصم الكميات المعدلة من رصيد المستودع.';
      } else if (isDisposalType()) {
        postMsg = 'ترحيل الإتلاف؟ ستُخصم الكميات المعدلة من رصيد المستودع.';
      }
      confirmMsg(postMsg, function () {
        submitForm('post');
      });
      return;
    }
    if (action === 'print') {
      e.preventDefault();
      e.stopImmediatePropagation();
      handleToolbarPrint();
      return;
    }
    if (action === 'pdf') {
      e.preventDefault();
      e.stopImmediatePropagation();
      doPdf();
      return;
    }
    if (action === 'excel') {
      e.preventDefault();
      e.stopImmediatePropagation();
      doExcel();
    }
  });

  if (typeSel) {
    typeSel.addEventListener('change', syncMovementTypeUi);
  }
  if (whSel) {
    whSel.addEventListener('change', function () {
      syncWarehouseNotice();
      if (global.ItemPickerModal && ItemPickerModal.invalidateCache) {
        ItemPickerModal.invalidateCache();
      }
      tbody.querySelectorAll('tr.wh-move-line').forEach(function (tr) {
        var itemId = parseInt(tr.dataset.itemId || '0', 10);
        if (itemId > 0) {
          fetchItemStock(itemId, function (stock) {
            var onHandEl = tr.querySelector('.js-on-hand');
            if (onHandEl) onHandEl.textContent = formatQty(stock);
            tr.dataset.onHand = String(stock);
          });
        }
      });
    });
  }
  var prevBtn = document.getElementById('wh-move-no-prev');
  var nextBtn = document.getElementById('wh-move-no-next');
  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      navigateMove('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      navigateMove('next');
    });
  }
  if (moveNoInp) {
    moveNoInp.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runToolbarMoveSearch();
    });
    moveNoInp.addEventListener('blur', function () {
      var no = String(moveNoInp.value || '').trim();
      if (!no || currentMoveId < 1) return;
      if (no === String(moveNoInp.dataset.loadedNo || '')) return;
      loadMoveByNo(no);
    });
  }

  function bootMovePage() {
    form.classList.toggle('wh-move-form-is-saved', currentMoveId > 0);
    updateMoveNoPostedStyle();
    if (currentMoveId > 0) {
      if (moveNoInp) moveNoInp.dataset.loadedNo = moveNoInp.value;
      fetchMoveResponse({ id: currentMoveId }).then(function (data) {
        if (data && data.ok && data.move) {
          setBrowseNav(data.move.prev_id || 0, data.move.next_id || 0);
        }
      });
    } else {
      refreshEmptyBrowseNav();
    }
    refreshMoveEditState();
  }

  ensureMovementTypeSelected();
  syncMovementTypeUi();

  var initial = global.__WH_MOVE_INITIAL_LINES__;
  if (Array.isArray(initial) && initial.length) {
    initial.forEach(function (ln) {
      createLineRow(ln);
    });
    renumberLines();
    initial.forEach(function (ln, idx) {
      if (ln.item_id > 0 && (!ln.on_hand || ln.on_hand === 0)) {
        fetchItemStock(ln.item_id, function (stock) {
          var rows = tbody.querySelectorAll('tr.wh-move-line:not(.is-entry-row)');
          if (rows[idx]) {
            var el = rows[idx].querySelector('.js-on-hand');
            if (el) el.textContent = formatQty(stock);
          }
        });
      }
    });
  }
  if (!moveIsPosted) {
    ensureEntryRow();
  }
  syncWarehouseNotice();
  bootMovePage();

  var printCloseBtn = document.getElementById('wh-move-print-close');
  if (printCloseBtn) {
    printCloseBtn.addEventListener('click', closePrintPreview);
  }
})();
