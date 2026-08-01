(function () {
  'use strict';

  var MODAL_ID = 'app-item-picker-modal';
  var BACKDROP_ID = 'app-shared-picker-backdrop';
  var LEGACY_BACKDROP_IDS = ['app-customer-picker-backdrop', 'app-item-picker-backdrop'];
  var activeBinding = null;
  var installed = false;
  var activeIndex = -1;
  var warehouseCache = { id: null, list: [], byId: {} };
  var repositionHandler = null;
  var pendingRowClickTimer = null;
  var prefetchInFlight = null;
  var searchDebounceTimer = null;

  function appendPickerParam(url) {
    if (!url) return url;
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'picker=1';
  }

  function escapeHtml(s) {
    var d = document.createElement('p');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function norm(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function getModal() {
    return document.getElementById(MODAL_ID);
  }

  function getSearch() {
    return document.getElementById('app-item-picker-search');
  }

  function getResults() {
    return document.getElementById('app-item-picker-results');
  }

  function getAddBtn() {
    return document.getElementById('app-item-picker-add');
  }

  function hideLegacyBackdrops() {
    LEGACY_BACKDROP_IDS.forEach(function (id) {
      var legacy = document.getElementById(id);
      if (!legacy) return;
      legacy.classList.remove('is-open');
      legacy.hidden = true;
      legacy.setAttribute('aria-hidden', 'true');
    });
  }

  function isCustomerPickerOpen() {
    return window.CustomerPickerModal && CustomerPickerModal.isOpen
      ? CustomerPickerModal.isOpen()
      : false;
  }

  function ensureBackdrop() {
    hideLegacyBackdrops();
    var el = document.getElementById(BACKDROP_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = BACKDROP_ID;
      el.className = 'sales-inv-pick-backdrop';
      el.hidden = true;
      el.setAttribute('aria-hidden', 'true');
      document.body.appendChild(el);
      el.addEventListener('click', function () {
        if (window.CustomerPickerModal && CustomerPickerModal.isOpen && CustomerPickerModal.isOpen()) {
          CustomerPickerModal.close();
        }
        close();
      });
    }
    return el;
  }

  function clearModalPosition(modal) {
    modal.classList.remove('is-screen-center', 'is-flip-up', 'is-anchored');
    modal.style.top = '';
    modal.style.left = '';
    modal.style.right = '';
    modal.style.bottom = '';
    modal.style.transform = '';
    modal.style.margin = '';
    modal.style.width = '';
    modal.style.maxHeight = '';
  }

  function positionNearAnchor(modal, anchorEl) {
    clearModalPosition(modal);
    if (!anchorEl || typeof anchorEl.getBoundingClientRect !== 'function') {
      modal.classList.add('is-screen-center');
      modal.style.width = 'min(520px, calc(100vw - 32px))';
      modal.style.maxHeight = 'min(88vh, calc(100vh - 24px))';
      return;
    }

    modal.classList.add('is-anchored');
    modal.style.width = 'min(520px, calc(100vw - 32px))';

    var rect = anchorEl.getBoundingClientRect();
    var gap = 8;
    var vpad = 12;
    var mw = modal.offsetWidth || Math.min(520, window.innerWidth - 32);
    var mh = modal.offsetHeight || 360;
    var spaceAbove = rect.top - vpad;
    var spaceBelow = window.innerHeight - rect.bottom - vpad;
    var placeAbove = spaceAbove >= Math.min(mh, 220) || spaceAbove >= spaceBelow;

    var top;
    if (placeAbove) {
      top = Math.max(vpad, rect.top - mh - gap);
      modal.classList.add('is-flip-up');
      modal.style.maxHeight = Math.max(160, Math.min(mh, spaceAbove - gap)) + 'px';
    } else {
      top = Math.min(window.innerHeight - vpad - 120, rect.bottom + gap);
      modal.style.maxHeight = Math.max(160, Math.min(mh, spaceBelow - gap)) + 'px';
    }

    var left = rect.left + (rect.width - mw) / 2;
    left = Math.max(vpad, Math.min(left, window.innerWidth - mw - vpad));

    modal.style.position = 'fixed';
    modal.style.zIndex = '100602';
    modal.style.top = top + 'px';
    modal.style.left = left + 'px';
    modal.style.right = 'auto';
    modal.style.bottom = 'auto';
    modal.style.margin = '0';
    modal.style.transform = 'none';
  }

  function shouldUseScreenCenter(binding) {
    if (!binding) return true;
    if (binding.screenCenter) return true;
    return !binding.anchorEl;
  }

  function scheduleReposition() {
    if (!activeBinding || !isOpen()) return;
    var modal = getModal();
    if (!modal) return;
    requestAnimationFrame(function () {
      if (!activeBinding || !isOpen()) return;
      var anchor = shouldUseScreenCenter(activeBinding) ? null : activeBinding.anchorEl;
      positionNearAnchor(modal, anchor);
    });
  }

  function bindRepositionListeners() {
    if (repositionHandler) return;
    repositionHandler = function () {
      scheduleReposition();
    };
    window.addEventListener('resize', repositionHandler);
    window.addEventListener('scroll', repositionHandler, true);
  }

  function unbindRepositionListeners() {
    if (!repositionHandler) return;
    window.removeEventListener('resize', repositionHandler);
    window.removeEventListener('scroll', repositionHandler, true);
    repositionHandler = null;
  }

  function setVisible(visible) {
    var modal = getModal();
    if (!modal) return;
    var backdrop = ensureBackdrop();
    if (visible) {
      document.body.appendChild(modal);
      modal.removeAttribute('hidden');
      modal.classList.add('is-open');
      modal.style.display = 'flex';
      modal.style.flexDirection = 'column';
      modal.style.position = 'fixed';
      modal.style.zIndex = '100602';
      modal.style.overflow = 'hidden';
      clearModalPosition(modal);
      backdrop.removeAttribute('hidden');
      backdrop.classList.add('is-open');
      backdrop.setAttribute('aria-hidden', 'false');
      bindRepositionListeners();
      scheduleReposition();
    } else {
      activeIndex = -1;
      unbindRepositionListeners();
      modal.classList.remove('is-open', 'is-screen-center', 'is-flip-up', 'is-anchored');
      modal.setAttribute('hidden', '');
      modal.style.display = '';
      modal.style.flexDirection = '';
      modal.style.position = '';
      modal.style.zIndex = '';
      modal.style.overflow = '';
      clearModalPosition(modal);
      if (!isCustomerPickerOpen()) {
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('hidden', '');
        backdrop.setAttribute('aria-hidden', 'true');
      }
      if (activeBinding && typeof activeBinding.onClose === 'function') {
        activeBinding.onClose();
      }
      activeBinding = null;
    }
  }

  function close() {
    clearPendingRowClick();
    activeIndex = -1;
    setVisible(false);
  }

  function getPickableRows() {
    var results = getResults();
    if (!results) return [];
    return Array.prototype.slice.call(
      results.querySelectorAll('.sales-inv-pick-item[data-item-id]')
    );
  }

  function highlightActive() {
    var rows = getPickableRows();
    rows.forEach(function (row, i) {
      row.classList.toggle('is-active', i === activeIndex);
    });
    if (activeIndex >= 0 && rows[activeIndex]) {
      rows[activeIndex].scrollIntoView({ block: 'nearest' });
    }
  }

  function clearPendingRowClick() {
    if (pendingRowClickTimer) {
      clearTimeout(pendingRowClickTimer);
      pendingRowClickTimer = null;
    }
  }

  function confirmSingleItemRow(rowEl) {
    if (!activeBinding || !rowEl) return;
    var id = rowEl.getAttribute('data-item-id');
    if (id === '0' && activeBinding.allowAll) {
      selectSingleItem(rowEl);
      return;
    }
    var it = activeBinding.byId[id];
    if (!it) return;
    var onConfirm = activeBinding.onConfirm;
    var onSelect = activeBinding.onSelect;
    close();
    if (typeof onConfirm === 'function') {
      onConfirm([it]);
    } else if (typeof onSelect === 'function') {
      onSelect(it);
    }
  }

  function selectSingleItem(rowEl) {
    if (!activeBinding || !rowEl) return;
    var id = rowEl.getAttribute('data-item-id');
    if (id === '0' && activeBinding.allowAll) {
      var onSelectAll = activeBinding.onSelect;
      close();
      if (typeof onSelectAll === 'function') {
        onSelectAll({
          id: 0,
          name_ar: activeBinding.allLabel || 'جميع المواد',
        });
      }
      return;
    }
    var it = activeBinding.byId[id];
    if (!it) return;
    var onSelect = activeBinding.onSelect;
    close();
    if (typeof onSelect === 'function') {
      onSelect(it);
    }
  }

  function confirmHighlightedRow() {
    if (!activeBinding) return;
    var rows = getPickableRows();
    if (!rows.length) return;
    var idx = activeIndex >= 0 ? activeIndex : 0;
    if (activeBinding.singleSelect) {
      selectSingleItem(rows[idx]);
      return;
    }
    confirmSingleItemRow(rows[idx]);
  }

  function applyPickerChrome(binding) {
    var modal = getModal();
    if (!modal) return;
    var foot = modal.querySelector('.item-picker-foot');
    var title = modal.querySelector('.sales-inv-pick-title');
    var hint = document.getElementById('app-item-picker-hint');
    if (binding.singleSelect) {
      modal.classList.add('is-single-select');
      if (foot) foot.hidden = true;
      if (title) title.textContent = 'اختيار مادة';
      if (hint) hint.textContent = 'انقر المادة أو Enter للاختيار';
    } else {
      modal.classList.remove('is-single-select');
      if (foot) foot.hidden = false;
      if (title) title.textContent = 'اختيار مواد';
      if (hint) hint.textContent = 'انقر مرتين لإضافة مادة — أو حدّد عدة مواد ثم اضغط إضافة';
    }
  }

  function isOpen() {
    var modal = getModal();
    return !!(modal && modal.classList.contains('is-open'));
  }

  function itemMatchesNeedle(item, needle) {
    if (!needle) return true;
    return (
      norm(item.name_ar).indexOf(needle) >= 0 ||
      norm(item.sku).indexOf(needle) >= 0 ||
      norm(item.barcode).indexOf(needle) >= 0
    );
  }

  function countSelected() {
    if (!activeBinding || !activeBinding.selectedIds) return 0;
    var n = 0;
    Object.keys(activeBinding.selectedIds).forEach(function (k) {
      if (activeBinding.selectedIds[k]) n += 1;
    });
    return n;
  }

  function updateAddBtn() {
    var btn = getAddBtn();
    if (!btn) return;
    var n = countSelected();
    btn.disabled = n < 1;
    btn.classList.toggle('is-inactive', n < 1);
    btn.textContent = n > 0 ? 'إضافة المحدد (' + n + ')' : 'إضافة المحدد';
  }

  function setItemSelected(itemId, checked) {
    if (!activeBinding) return;
    var id = String(itemId);
    if (checked) {
      activeBinding.selectedIds[id] = true;
      if (activeBinding.byId[id]) {
        activeBinding.selectedItems[id] = activeBinding.byId[id];
      }
    } else {
      delete activeBinding.selectedIds[id];
      delete activeBinding.selectedItems[id];
    }
    updateAddBtn();
  }

  function toggleRowSelection(rowEl) {
    if (!rowEl || !activeBinding) return;
    var id = rowEl.getAttribute('data-item-id');
    if (!id) return;
    var cb = rowEl.querySelector('.sales-inv-pick-check');
    var next = cb ? !cb.checked : !activeBinding.selectedIds[id];
    if (cb) cb.checked = next;
    rowEl.classList.toggle('is-selected', next);
    setItemSelected(id, next);
  }

  function renderResults(binding, q) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var sourceList = needle && binding.remoteList && binding.remoteNeedle === needle
      ? binding.remoteList
      : binding.list;
    var matches = sourceList.filter(function (it) {
      return itemMatchesNeedle(it, needle);
    });
    var limit = binding.maxResults > 0 ? binding.maxResults : 500;
    results.innerHTML = '';

    if (binding.singleSelect && binding.allowAll && (!needle || norm(binding.allLabel).indexOf(needle) >= 0)) {
      var allRow = document.createElement('button');
      allRow.type = 'button';
      allRow.className = 'sales-inv-pick-item sales-inv-pick-item--all';
      allRow.setAttribute('data-item-id', '0');
      allRow.innerHTML =
        '<div class="sales-inv-pick-item-body"><span class="sales-inv-pick-item-name">' +
        escapeHtml(binding.allLabel || 'جميع المواد') +
        '</span></div>';
      allRow.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        selectSingleItem(allRow);
      });
      results.appendChild(allRow);
    }

    if (!matches.length) {
      if (!results.childNodes.length) {
        results.innerHTML =
          '<div class="sales-inv-pick-empty">' +
          escapeHtml(needle ? 'لا توجد مادة مطابقة' : binding.emptyMessage || 'لا توجد مواد') +
          '</div>';
      }
      updateAddBtn();
      return;
    }

    var frag = document.createDocumentFragment();

    matches.slice(0, limit).forEach(function (it) {
      var itemId = String(it.id);
      var row = document.createElement('div');
      row.className = 'sales-inv-pick-item';
      row.setAttribute('role', 'option');
      row.setAttribute('data-item-id', itemId);
      row.setAttribute('tabindex', '0');

      if (!binding.singleSelect) {
        var checked = !!binding.selectedIds[itemId];
        if (checked) row.classList.add('is-selected');

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'sales-inv-pick-check';
        cb.checked = checked;
        cb.setAttribute('aria-label', 'تحديد ' + (it.name_ar || ''));
        row.appendChild(cb);
      }

      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      var code = String(it.barcode || it.sku || '').trim();
      var nameSpan = document.createElement('span');
      nameSpan.className = 'sales-inv-pick-item-name';
      nameSpan.textContent = it.name_ar || '';
      body.appendChild(nameSpan);
      if (code) {
        var codeSpan = document.createElement('span');
        codeSpan.className = 'sales-inv-pick-item-barcode';
        codeSpan.textContent = code;
        body.appendChild(codeSpan);
      }

      row.appendChild(body);
      frag.appendChild(row);
    });

    results.appendChild(frag);

    if (matches.length > limit) {
      var more = document.createElement('div');
      more.className = 'sales-inv-pick-empty';
      more.textContent = 'يُعرض ' + limit + ' من ' + matches.length + ' — ضيّق البحث لرؤية الباقي';
      results.appendChild(more);
    }

    updateAddBtn();
    highlightActive();
    scheduleReposition();
  }

  function getSelectedItems() {
    if (!activeBinding) return [];
    var items = [];
    var seen = {};
    Object.keys(activeBinding.selectedIds).forEach(function (idKey) {
      if (!activeBinding.selectedIds[idKey]) return;
      var it = activeBinding.selectedItems[idKey] || activeBinding.byId[idKey];
      if (!it) return;
      var id = String(it.id);
      if (seen[id]) return;
      seen[id] = true;
      items.push(it);
    });
    return items;
  }

  function applySelection() {
    if (!activeBinding) return;
    var items = getSelectedItems();
    if (!items.length) {
      if (window.AppDialog) {
        AppDialog.alert('حدد مادة واحدة على الأقل.', { type: 'warning' });
      }
      return;
    }
    var onConfirm = activeBinding.onConfirm;
    var onSelect = activeBinding.onSelect;
    close();
    if (typeof onConfirm === 'function') {
      onConfirm(items);
    } else if (typeof onSelect === 'function' && items.length === 1) {
      onSelect(items[0]);
    } else if (typeof onSelect === 'function') {
      items.forEach(function (it) {
        onSelect(it);
      });
    }
  }

  function normalizeItems(raw) {
    var list = [];
    var byId = {};
    (raw || []).forEach(function (it) {
      var id = parseInt(it.id != null ? it.id : it.item_id, 10);
      if (!id) return;
      var units = Array.isArray(it.units) ? it.units : [];
      var row = {
        id: id,
        name_ar: String(it.name_ar || it.name || ''),
        sku: String(it.sku || ''),
        barcode: String(it.barcode || it.sku || ''),
        default_sale: it.default_sale != null ? it.default_sale : 0,
        default_cost: it.default_cost,
        unit_id: it.unit_id != null ? it.unit_id : 0,
        unit_name: it.unit_name != null ? String(it.unit_name) : '',
        units: units,
      };
      list.push(row);
      byId[String(id)] = row;
    });
    return { list: list, byId: byId };
  }

  function applyItemsToBinding(binding, packed, cacheKey) {
    binding.list = packed.list;
    binding.byId = packed.byId;
    binding.cacheWarehouseId = cacheKey;
    warehouseCache.id = cacheKey;
    warehouseCache.list = packed.list;
    warehouseCache.byId = packed.byId;
  }

  function fetchPickerItems(binding, q, listAll) {
    if (!binding.buildItemsUrl) {
      return Promise.resolve(null);
    }
    var url = appendPickerParam(binding.buildItemsUrl(q || '', !!listAll));
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          return { ok: false, error: (data && (data.message || data.error)) || 'تعذر تحميل المواد' };
        }
        return { ok: true, packed: normalizeItems(data.items || []) };
      })
      .catch(function () {
        return { ok: false, error: 'تعذر الاتصال بالخادم' };
      });
  }

  function loadItems(binding, opts) {
    opts = opts || {};
    var results = getResults();
    var cacheKey = 0;
    var initialQ = String(opts.initialSearch || '').trim();
    if (!initialQ && warehouseCache.id === cacheKey && warehouseCache.list.length) {
      binding.list = warehouseCache.list;
      binding.byId = warehouseCache.byId;
      binding.cacheWarehouseId = cacheKey;
      renderResults(binding, getSearch() ? getSearch().value : '');
      return Promise.resolve();
    }
    if (results) {
      results.innerHTML = '<div class="sales-inv-pick-empty">جاري التحميل…</div>';
    }
    if (!binding.buildItemsUrl) {
      if (results) {
        results.innerHTML =
          '<div class="sales-inv-pick-empty sales-inv-pick-err">رابط البحث غير متاح</div>';
      }
      return Promise.resolve();
    }
    var fetchQ = initialQ;
    var fetchListAll = !fetchQ;
    return fetchPickerItems(binding, fetchQ, fetchListAll).then(function (res) {
      if (!res || !res.ok) {
        binding.list = [];
        binding.byId = {};
        if (results) {
          results.innerHTML =
            '<div class="sales-inv-pick-empty sales-inv-pick-err">' +
            escapeHtml((res && res.error) || 'تعذر تحميل المواد') +
            '</div>';
        }
        return;
      }
      if (fetchQ) {
        binding.remoteList = res.packed.list;
        binding.remoteNeedle = norm(fetchQ);
        binding.list = warehouseCache.list.length ? warehouseCache.list : res.packed.list;
        binding.byId = warehouseCache.byId && Object.keys(warehouseCache.byId).length
          ? Object.assign({}, warehouseCache.byId, res.packed.byId)
          : res.packed.byId;
      } else {
        applyItemsToBinding(binding, res.packed, cacheKey);
      }
      renderResults(binding, getSearch() ? getSearch().value : initialQ || '');
    });
  }

  function open(opts) {
    if (!opts) return;
    install();
    if (window.CustomerPickerModal) {
      CustomerPickerModal.close();
    }
    hideLegacyBackdrops();
    activeBinding = {
      list: [],
      byId: {},
      selectedIds: {},
      selectedItems: {},
      remoteList: null,
      remoteNeedle: '',
      singleSelect: !!opts.singleSelect,
      anchorEl: opts.anchorEl || null,
      screenCenter: !!opts.screenCenter,
      buildItemsUrl: opts.buildItemsUrl,
      getWarehouseId: opts.getWarehouseId || function () {
        return 0;
      },
      onSelect: opts.onSelect || null,
      onConfirm: opts.onConfirm || null,
      onClose: opts.onClose || null,
      allowAll: !!opts.allowAll,
      allLabel: opts.allLabel || 'جميع المواد',
      maxResults: parseInt(opts.maxResults, 10) || 500,
      emptyMessage: opts.emptyMessage || '',
      cacheWarehouseId: null,
    };
    applyPickerChrome(activeBinding);
    if (typeof opts.onOpen === 'function') {
      opts.onOpen();
    }
    var search = getSearch();
    var initialQ = opts.initialSearch || '';
    setVisible(true);
    if (search) {
      search.value = initialQ;
    }
    updateAddBtn();
    loadItems(activeBinding, { initialSearch: initialQ }).then(function () {
      if (search) {
        scheduleReposition();
        setTimeout(function () {
          search.focus();
          search.select();
          scheduleReposition();
        }, 0);
      }
    });
  }

  function scheduleRemoteSearch(binding, needle) {
    if (searchDebounceTimer) {
      clearTimeout(searchDebounceTimer);
      searchDebounceTimer = null;
    }
    var n = norm(needle);
    if (!n || n.length < 2) {
      binding.remoteList = null;
      binding.remoteNeedle = '';
      return;
    }
    searchDebounceTimer = setTimeout(function () {
      searchDebounceTimer = null;
      if (!activeBinding || activeBinding !== binding || !isOpen()) return;
      var search = getSearch();
      if (!search || norm(search.value) !== n) return;
      fetchPickerItems(binding, needle, false).then(function (res) {
        if (!activeBinding || activeBinding !== binding || !isOpen()) return;
        if (!search || norm(search.value) !== n) return;
        if (!res || !res.ok) return;
        binding.remoteList = res.packed.list;
        binding.remoteNeedle = n;
        res.packed.list.forEach(function (it) {
          binding.byId[String(it.id)] = it;
        });
        renderResults(binding, search.value);
      });
    }, 280);
  }

  function prefetch(opts) {
    if (!opts || typeof opts.buildItemsUrl !== 'function') {
      return Promise.resolve(false);
    }
    if (warehouseCache.id === 0 && warehouseCache.list.length) {
      return Promise.resolve(true);
    }
    if (prefetchInFlight) {
      return prefetchInFlight;
    }
    prefetchInFlight = fetchPickerItems({ buildItemsUrl: opts.buildItemsUrl }, '', true).then(function (res) {
      prefetchInFlight = null;
      if (!res || !res.ok) return false;
      warehouseCache.id = 0;
      warehouseCache.list = res.packed.list;
      warehouseCache.byId = res.packed.byId;
      if (activeBinding) {
        activeBinding.list = res.packed.list;
        activeBinding.byId = res.packed.byId;
        activeBinding.cacheWarehouseId = 0;
      }
      return true;
    });
    return prefetchInFlight;
  }

  function invalidateCache() {
    warehouseCache.id = null;
    warehouseCache.list = [];
    warehouseCache.byId = {};
    if (activeBinding) {
      activeBinding.cacheWarehouseId = null;
      activeBinding.list = [];
      activeBinding.byId = {};
    }
  }

  function reload() {
    if (!activeBinding || !isOpen()) return;
    warehouseCache.id = null;
    var search = getSearch();
    loadItems(activeBinding, { initialSearch: search ? search.value : '' });
  }

  function install() {
    if (installed) return;
    installed = true;

    var closeBtn = document.getElementById('app-item-picker-close');
    if (closeBtn) closeBtn.addEventListener('click', close);

    var addBtn = getAddBtn();
    if (addBtn) addBtn.addEventListener('click', applySelection);

    var search = getSearch();
    if (search) {
      search.addEventListener('input', function () {
        if (activeBinding) {
          scheduleRemoteSearch(activeBinding, search.value);
          renderResults(activeBinding, search.value);
        }
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
          return;
        }
        if (!isOpen() || !activeBinding) return;

        var rows = getPickableRows();
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (!rows.length) return;
          activeIndex = activeIndex < rows.length - 1 ? activeIndex + 1 : 0;
          highlightActive();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (!rows.length) return;
          activeIndex = activeIndex > 0 ? activeIndex - 1 : rows.length - 1;
          highlightActive();
          return;
        }
        if (e.key === 'Enter') {
          e.preventDefault();
          if (!rows.length) return;
          confirmHighlightedRow();
        }
      });
    }

    var results = getResults();
    if (results) {
      results.addEventListener('click', function (e) {
        if (!activeBinding) return;
        var row = e.target.closest('.sales-inv-pick-item');
        if (!row) return;
        if (activeBinding.singleSelect) {
          selectSingleItem(row);
          return;
        }
        if (e.target.classList.contains('sales-inv-pick-check')) {
          clearPendingRowClick();
          var id = row.getAttribute('data-item-id');
          setItemSelected(id, e.target.checked);
          row.classList.toggle('is-selected', e.target.checked);
          return;
        }
        clearPendingRowClick();
        var rowEl = row;
        pendingRowClickTimer = setTimeout(function () {
          pendingRowClickTimer = null;
          toggleRowSelection(rowEl);
        }, 220);
      });
      results.addEventListener('dblclick', function (e) {
        if (!activeBinding || activeBinding.singleSelect) return;
        var row = e.target.closest('.sales-inv-pick-item');
        if (!row) return;
        e.preventDefault();
        clearPendingRowClick();
        confirmSingleItemRow(row);
      });
      results.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          var row = e.target.closest('.sales-inv-pick-item');
          if (!row) return;
          e.preventDefault();
          if (activeBinding && activeBinding.singleSelect) {
            selectSingleItem(row);
          } else {
            toggleRowSelection(row);
          }
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var modal = getModal();
      if (modal && modal.classList.contains('is-open')) close();
    });
  }

  window.ItemPickerModal = {
    install: install,
    open: open,
    close: close,
    isOpen: isOpen,
    invalidateCache: invalidateCache,
    reload: reload,
    prefetch: prefetch,
    getCachedItem: function (id) {
      var key = String(id);
      if (warehouseCache.byId[key]) return warehouseCache.byId[key];
      if (activeBinding && activeBinding.byId && activeBinding.byId[key]) {
        return activeBinding.byId[key];
      }
      return null;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})();
