(function () {
  'use strict';

  /** d-m-Y → Y-m-d */
  function parseDmYToIso(str) {
    var raw = String(str || '').trim();
    var m = raw.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (!m) {
      return '';
    }
    var d = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    if (d < 1 || d > 31 || mo < 1 || mo > 12 || y < 1900 || y > 2100) {
      return '';
    }
    return (
      String(y) +
      '-' +
      String(mo).padStart(2, '0') +
      '-' +
      String(d).padStart(2, '0')
    );
  }

  /** Y-m-d → d-m-Y (مطابق لـ format_date_dmY في PHP) */
  function formatIsoToDmY(iso) {
    var m = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) {
      return '';
    }
    return parseInt(m[3], 10) + '-' + parseInt(m[2], 10) + '-' + m[1];
  }

  function syncNativeFromText(textInput, nativeInput) {
    var iso = parseDmYToIso(textInput.value);
    nativeInput.value = iso;
  }

  function syncTextFromNative(textInput, nativeInput) {
    if (!nativeInput.value) {
      return;
    }
    var dmy = formatIsoToDmY(nativeInput.value);
    if (dmy) {
      textInput.value = dmy;
      textInput.dispatchEvent(new Event('change', { bubbles: true }));
      textInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function openNativePicker(nativeInput) {
    if (!nativeInput) {
      return;
    }
    if (typeof nativeInput.showPicker === 'function') {
      try {
        nativeInput.showPicker();
        return;
      } catch (e) {
        /* fall through */
      }
    }
    nativeInput.focus({ preventScroll: true });
    nativeInput.click();
  }

  function bindDateField(textInput) {
    if (!textInput || textInput.dataset.datePickerBound === '1') {
      return;
    }
    if (textInput.readOnly || textInput.disabled) {
      return;
    }
    if (textInput.type !== 'text' && textInput.type !== '') {
      return;
    }

    textInput.dataset.datePickerBound = '1';

    var wrap = document.createElement('div');
    wrap.className = 'date-dmy-field';
    if (textInput.classList.contains('input-compact')) {
      wrap.classList.add('date-dmy-field--compact');
    }

    var parent = textInput.parentNode;
    parent.insertBefore(wrap, textInput);
    wrap.appendChild(textInput);

    var native = document.createElement('input');
    native.type = 'date';
    native.className = 'date-dmy-native';
    native.tabIndex = -1;
    native.setAttribute('aria-hidden', 'true');
    wrap.appendChild(native);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'date-dmy-picker-btn';
    btn.title = 'اختيار التاريخ من التقويم';
    btn.setAttribute('aria-label', 'اختيار التاريخ من التقويم');
    btn.innerHTML =
      '<span class="date-dmy-picker-ico" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" width="18" height="18" focusable="false">' +
      '<path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 16H5V9h14v11ZM7 11h2v2H7v-2Zm4 0h2v2h-2v-2Zm4 0h2v2h-2v-2Zm-8 4h2v2H7v-2Zm4 0h2v2h-2v-2Zm4 0h2v2h-2v-2Z"/>' +
      '</svg></span>';
    wrap.appendChild(btn);

    syncNativeFromText(textInput, native);

    btn.addEventListener('click', function () {
      syncNativeFromText(textInput, native);
      openNativePicker(native);
    });

    textInput.addEventListener('dblclick', function () {
      syncNativeFromText(textInput, native);
      openNativePicker(native);
    });

    native.addEventListener('change', function () {
      syncTextFromNative(textInput, native);
    });

    textInput.addEventListener('blur', function () {
      syncNativeFromText(textInput, native);
    });
  }

  function initDatePickers(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('.js-date-dmy').forEach(bindDateField);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initDatePickers(document);
    });
  } else {
    initDatePickers(document);
  }

  window.AppDatePicker = {
    init: initDatePickers,
    parseDmYToIso: parseDmYToIso,
    formatIsoToDmY: formatIsoToDmY,
  };
})();
