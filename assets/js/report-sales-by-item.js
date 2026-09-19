(function () {
  'use strict';

  function buildItemsApiUrl(apiItems, q, listAll, warehouseId) {
    var url = apiItems || '';
    if (!url) return '';
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(String(q).trim()));
    if (warehouseId > 0) {
      parts.push('warehouse_id=' + encodeURIComponent(String(warehouseId)));
    }
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
  }

  function getWarehouseIdFromSlot(slot) {
    var fromSlot = parseInt(slot.getAttribute('data-warehouse-id') || '0', 10);
    if (fromSlot > 0) return fromSlot;
    var page = slot.closest('.report-sales-page');
    if (page) {
      var fromPage = parseInt(page.getAttribute('data-default-warehouse-id') || '0', 10);
      if (fromPage > 0) return fromPage;
    }
    return 0;
  }

  function formatItemLabel(item) {
    if (!item) return '';
    var name = String(item.name_ar || item.name || '').trim();
    var code = '';
    if (window.InvItemDisplay && typeof InvItemDisplay.materialNumber === 'function') {
      code = InvItemDisplay.materialNumber(item.barcode || '', item.sku || '');
    } else {
      code = String(item.barcode || item.sku || '').trim();
    }
    if (name === '') return code;
    return code !== '' ? name + ' — ' + code : name;
  }

  function findSlotEl(slot, idAttr, fallbackSelector) {
    var id = slot.getAttribute(idAttr) || '';
    if (id) {
      var byId = slot.querySelector('#' + id.replace(/\\/g, '\\\\').replace(/"/g, '\\"'));
      if (byId) return byId;
      var global = document.getElementById(id);
      if (global && slot.contains(global)) return global;
    }
    return fallbackSelector ? slot.querySelector(fallbackSelector) : null;
  }

  function bindSlot(slot) {
    if (!slot || slot.dataset.reportItemPickerBound === '1') return;

    var placeholder = slot.getAttribute('data-placeholder') || 'اضغط لاختيار المادة';
    var allLabel = slot.getAttribute('data-all-label') || 'جميع المواد';
    var apiItems = slot.getAttribute('data-api-items') || '';
    var allowAll = slot.getAttribute('data-allow-all') === '1';
    var displayText = slot.getAttribute('data-display-text') || '';
    var warehouseId = getWarehouseIdFromSlot(slot);

    var hidden = findSlotEl(slot, 'data-hidden-id', 'input[type="hidden"]');
    var openBtn = findSlotEl(slot, 'data-open-id', 'button.sales-inv-cust-open');
    var display = findSlotEl(slot, 'data-display-id', '.sales-inv-cust-open-label');
    var allBtn = findSlotEl(slot, 'data-all-btn-id', '.report-item-all-btn');
    if (!hidden || !openBtn || !display) return;

    slot.dataset.reportItemPickerBound = '1';

    function setSelection(id, name) {
      if (id === '' || id == null) {
        hidden.value = '';
      } else if (allowAll && (id === 0 || id === '0' || id === 'all')) {
        hidden.value = '0';
      } else {
        hidden.value = String(id);
      }
      if (name && String(name).trim() !== '') {
        display.textContent = String(name).trim();
        display.classList.remove('is-placeholder');
      } else {
        display.textContent = placeholder;
        display.classList.add('is-placeholder');
      }
      try {
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (e) {}
    }

    if (displayText !== '') {
      display.textContent = displayText;
      display.classList.remove('is-placeholder');
    } else if (allowAll && hidden.value === '0') {
      setSelection('0', allLabel);
    }

    function openPicker() {
      if (!window.ItemPickerModal) {
        if (window.AppDialog) {
          AppDialog.alert('نافذة اختيار المواد غير متوفرة.', { type: 'warning' });
        }
        return;
      }
      if (!apiItems) {
        if (window.AppDialog) {
          AppDialog.alert('تعذر تحميل المواد: رابط البحث غير مضبوط.', { type: 'warning' });
        }
        return;
      }
      var initialSearch = '';
      ItemPickerModal.open({
        singleSelect: true,
        screenCenter: true,
        anchorEl: openBtn,
        allowAll: allowAll,
        allLabel: allLabel,
        getWarehouseId: function () {
          return warehouseId;
        },
        buildItemsUrl: function (q, listAll) {
          return buildItemsApiUrl(apiItems, q, listAll, warehouseId);
        },
        initialSearch: initialSearch,
        emptyMessage:
          warehouseId > 0 ? 'لا توجد مواد في هذا المستودع' : 'لا توجد مواد مطابقة',
        onSelect: function (item) {
          if (!item) return;
          setSelection(item.id, formatItemLabel(item));
        },
      });
    }

    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openPicker();
    });

    function selectAllItems(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      setSelection('0', allLabel);
    }

    slot.__reportItemSelectAll = selectAllItems;

    if (allowAll && allBtn) {
      allBtn.addEventListener('click', selectAllItems);
    }

    var form = slot.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (allowAll && display.textContent.trim() === allLabel.trim()) {
          hidden.value = '0';
        }
        if (hidden.value === '') {
          e.preventDefault();
          var msg = allowAll
            ? 'اختر المادة أو اضغط «جميع المواد».'
            : 'اختر المادة.';
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(msg, { type: 'warning' });
          } else {
            window.alert(msg);
          }
        }
      });
    }
  }

  function init() {
    document.querySelectorAll('[data-report-item-single-picker]').forEach(bindSlot);
  }

  document.addEventListener('click', function (e) {
    var allBtn = e.target.closest('.report-item-all-btn');
    if (!allBtn) return;
    var slot = allBtn.closest('[data-report-item-single-picker]');
    if (!slot || slot.dataset.reportItemPickerBound === '1') return;
    bindSlot(slot);
    if (typeof slot.__reportItemSelectAll === 'function') {
      slot.__reportItemSelectAll(e);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

