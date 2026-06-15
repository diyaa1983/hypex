(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('item-stock-ledger-form');
  if (!form) return;

  var apiItems = form.getAttribute('data-api-items') || '';
  var pickBtn = document.getElementById('item-stock-pick-btn');
  var hidden = document.getElementById('item-stock-item-id');
  var display = document.getElementById('item-stock-item-display');
  var whSelect = document.getElementById('item-stock-wh');

  function getWarehouseId() {
    if (!whSelect) return 0;
    return parseInt(whSelect.value, 10) || 0;
  }

  function buildItemsApiUrl(q, listAll) {
    var url = apiItems;
    if (!url) return '';
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(String(q).trim()));
    var whId = getWarehouseId();
    if (whId > 0) parts.push('warehouse_id=' + encodeURIComponent(String(whId)));
    if (parts.length) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    }
    return url;
  }

  function setSelectedItem(item) {
    if (!hidden || !display) return;
    if (item && parseInt(item.id, 10) > 0) {
      hidden.value = String(item.id);
      display.textContent = String(item.name_ar || '');
      display.classList.remove('is-placeholder');
    } else {
      hidden.value = '';
      display.textContent = 'اضغط لاختيار المادة';
      display.classList.add('is-placeholder');
    }
  }

  function openPicker() {
    if (!global.ItemPickerModal) {
      if (global.AppDialog) {
        AppDialog.alert('نافذة اختيار المواد غير متوفرة.', { type: 'warning' });
      }
      return;
    }
    if (!apiItems) {
      if (global.AppDialog) {
        AppDialog.alert('تعذر تحميل المواد: رابط البحث غير مضبوط.', { type: 'warning' });
      }
      return;
    }
    var whId = getWarehouseId();
    var initialSearch = '';
    if (display && !display.classList.contains('is-placeholder')) {
      initialSearch = display.textContent.trim();
    }

    ItemPickerModal.open({
      singleSelect: true,
      screenCenter: true,
      anchorEl: pickBtn,
      buildItemsUrl: buildItemsApiUrl,
      getWarehouseId: getWarehouseId,
      initialSearch: initialSearch,
      emptyMessage: whId > 0 ? 'لا توجد مواد في هذا المستودع' : 'لا توجد مواد مطابقة',
      onSelect: function (item) {
        setSelectedItem(item);
      },
    });
  }

  if (pickBtn) {
    pickBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openPicker();
    });
  }

  if (whSelect && global.ItemPickerModal) {
    whSelect.addEventListener('change', function () {
      ItemPickerModal.invalidateCache();
      if (ItemPickerModal.isOpen()) {
        ItemPickerModal.reload();
      }
    });
  }
})();
