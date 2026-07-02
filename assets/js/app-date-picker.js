(function () {
  'use strict';

  /** d-m-Y → Y-m-d */
  function formatDmYFromParts(d, mo, y) {
    return (
      String(d).padStart(2, '0') +
      '-' +
      String(mo).padStart(2, '0') +
      '-' +
      String(y)
    );
  }

  /** يوم شهر سنة مفصولة بمسافات أو فواصل → d-m-Y أو فارغ */
  function tryFormatLooseDmY(raw) {
    var text = String(raw || '').trim();
    var m = text.match(/^(\d{1,2})[\s./-]+(\d{1,2})[\s./-]+(\d{4})$/);
    if (!m) {
      return '';
    }
    var d = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    if (d < 1 || d > 31 || mo < 1 || mo > 12 || y < 1900 || y > 2100) {
      return '';
    }
    return formatDmYFromParts(d, mo, y);
  }

  function parseDmYToIso(str) {
    var raw = String(str || '').trim();
    var loose = tryFormatLooseDmY(raw);
    if (loose) {
      raw = loose;
    }
    var digitsOnly = raw.replace(/\D/g, '');
    if (digitsOnly.length === 8) {
      raw = formatDmYFromDigits(digitsOnly);
    }

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
    return (
      String(parseInt(m[3], 10)).padStart(2, '0') +
      '-' +
      String(parseInt(m[2], 10)).padStart(2, '0') +
      '-' +
      m[1]
    );
  }

  function clampDateDigits(digits) {
    digits = String(digits || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length >= 2) {
      var day = parseInt(digits.slice(0, 2), 10);
      if (!isNaN(day) && day > 31) {
        digits = '31' + digits.slice(2);
      }
    }
    if (digits.length >= 4) {
      var month = parseInt(digits.slice(2, 4), 10);
      if (!isNaN(month) && month > 12) {
        digits = digits.slice(0, 2) + '12' + digits.slice(4);
      }
    }
    return digits;
  }

  /** أرقام فقط (حتى 8) → d-m-Y مع فواصل أثناء الكتابة */
  function formatDmYFromDigits(digits) {
    digits = clampDateDigits(digits);
    if (digits.length <= 2) {
      return digits;
    }
    if (digits.length <= 4) {
      return digits.slice(0, 2) + '-' + digits.slice(2);
    }
    return digits.slice(0, 2) + '-' + digits.slice(2, 4) + '-' + digits.slice(4);
  }

  function rememberValidDmY(textInput, value) {
    var iso = parseDmYToIso(value);
    if (iso) {
      textInput.dataset.dateLastValid = formatIsoToDmY(iso);
    }
  }

  function restoreValidDmY(textInput, nativeInput) {
    var last = textInput.dataset.dateLastValid || '';
    if (!last && nativeInput && nativeInput.value) {
      last = formatIsoToDmY(nativeInput.value);
    }
    if (last && parseDmYToIso(last)) {
      textInput.value = last;
      nativeInput.value = parseDmYToIso(last);
      return true;
    }
    return false;
  }

  function countDigitsBefore(str, position) {
    var count = 0;
    var limit = Math.max(0, Math.min(position, str.length));
    for (var i = 0; i < limit; i++) {
      if (/\d/.test(str.charAt(i))) {
        count++;
      }
    }
    return count;
  }

  function caretAfterDigits(formatted, digitCount) {
    if (digitCount <= 0) {
      return 0;
    }
    var seen = 0;
    for (var i = 0; i < formatted.length; i++) {
      if (/\d/.test(formatted.charAt(i))) {
        seen++;
        if (seen >= digitCount) {
          return i + 1;
        }
      }
    }
    return formatted.length;
  }

  function autoFormatDmYInput(textInput) {
    var raw = String(textInput.value || '');
    var loose = tryFormatLooseDmY(raw);
    if (loose) {
      var digitsBefore = countDigitsBefore(raw, textInput.selectionStart || 0);
      if (loose !== raw) {
        textInput.value = loose;
        var newPos = caretAfterDigits(loose, digitsBefore);
        try {
          textInput.setSelectionRange(newPos, newPos);
        } catch (e) {
          /* ignored */
        }
      }
      return;
    }
    if (/\s/.test(raw)) {
      return;
    }
    var digitsBefore = countDigitsBefore(raw, textInput.selectionStart || 0);
    var formatted = formatDmYFromDigits(raw);
    if (formatted === raw) {
      return;
    }
    textInput.value = formatted;
    var newPos = caretAfterDigits(formatted, digitsBefore);
    try {
      textInput.setSelectionRange(newPos, newPos);
    } catch (e) {
      /* ignored */
    }
  }

  function finalizeDmYInput(textInput, nativeInput) {
    var loose = tryFormatLooseDmY(textInput.value);
    if (loose) {
      var isoLoose = parseDmYToIso(loose);
      if (isoLoose) {
        textInput.value = loose;
        nativeInput.value = isoLoose;
        rememberValidDmY(textInput, loose);
        return;
      }
    }

    var digits = String(textInput.value || '').replace(/\D/g, '');
    if (digits.length === 8) {
      var formatted = formatDmYFromDigits(digits);
      var iso = parseDmYToIso(formatted);
      if (iso) {
        textInput.value = formatted;
        nativeInput.value = iso;
        rememberValidDmY(textInput, formatted);
        return;
      }
    }

    if (digits.length > 0) {
      restoreValidDmY(textInput, nativeInput);
      return;
    }

    textInput.value = '';
    nativeInput.value = '';
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
      rememberValidDmY(textInput, dmy);
      textInput.dispatchEvent(new Event('change', { bubbles: true }));
      textInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function openNativePicker(nativeInput, anchorEl) {
    if (!nativeInput) {
      return;
    }

    var anchor = anchorEl || nativeInput.parentElement;
    var prevInline = nativeInput.getAttribute('style') || '';

    function resetNativeAnchor() {
      nativeInput.setAttribute('style', prevInline);
      nativeInput.classList.remove('date-dmy-native--anchored');
    }

    if (anchor && anchor.getBoundingClientRect) {
      var rect = anchor.getBoundingClientRect();
      nativeInput.classList.add('date-dmy-native--anchored');
      nativeInput.style.position = 'fixed';
      nativeInput.style.left = rect.left + 'px';
      nativeInput.style.top = rect.bottom + 'px';
      nativeInput.style.width = Math.max(rect.width, 8) + 'px';
      nativeInput.style.height = '1px';
      nativeInput.style.opacity = '0';
      nativeInput.style.pointerEvents = 'none';
      nativeInput.style.clip = 'auto';
      nativeInput.style.overflow = 'visible';
      nativeInput.style.border = '0';
      nativeInput.style.padding = '0';
      nativeInput.style.margin = '0';
      nativeInput.style.zIndex = '10000';
    }

    function onPickerDismiss() {
      resetNativeAnchor();
      document.removeEventListener('pointerdown', onPointerDown, true);
      window.removeEventListener('blur', onPickerDismiss);
    }

    function onPointerDown(e) {
      if (e.target === nativeInput) {
        return;
      }
      window.setTimeout(onPickerDismiss, 0);
    }

    if (typeof nativeInput.showPicker === 'function') {
      try {
        nativeInput.showPicker();
        window.setTimeout(function () {
          document.addEventListener('pointerdown', onPointerDown, true);
          window.addEventListener('blur', onPickerDismiss);
        }, 0);
        nativeInput.addEventListener(
          'change',
          function () {
            onPickerDismiss();
          },
          { once: true }
        );
        return;
      } catch (e) {
        resetNativeAnchor();
      }
    }

    nativeInput.focus({ preventScroll: true });
    nativeInput.click();
    window.setTimeout(resetNativeAnchor, 300);
  }

  var AR_MONTHS = [
    'يناير',
    'فبراير',
    'مارس',
    'أبريل',
    'مايو',
    'يونيو',
    'يوليو',
    'أغسطس',
    'سبتمبر',
    'أكتوبر',
    'نوفمبر',
    'ديسمبر',
  ];

  var AR_WEEK_SAT = ['سبت', 'أحد', 'إثن', 'ثل', 'أرب', 'خم', 'جم'];

  var activeCustomPopup = null;
  var activeCustomPopupAnchor = null;

  function clearCustomPopupReflowListeners() {
    window.removeEventListener('scroll', onCustomPopupReflow, true);
    window.removeEventListener('resize', onCustomPopupReflow);
  }

  function positionCustomPopup(popup, anchorEl) {
    if (!popup || !anchorEl || !anchorEl.getBoundingClientRect) {
      return;
    }

    var rect = anchorEl.getBoundingClientRect();
    var popupWidth = popup.offsetWidth || Math.min(280, window.innerWidth * 0.92);
    var popupHeight = popup.offsetHeight || 280;
    var left = rect.left + rect.width / 2;
    var top = rect.bottom + 4;
    var halfW = popupWidth / 2;

    if (top + popupHeight > window.innerHeight - 8) {
      top = Math.max(8, rect.top - popupHeight - 4);
    }

    left = Math.max(halfW + 8, Math.min(window.innerWidth - halfW - 8, left));

    popup.classList.add('date-dmy-popup--anchored');
    popup.style.left = left + 'px';
    popup.style.top = top + 'px';
  }

  function onCustomPopupReflow() {
    if (activeCustomPopup && activeCustomPopupAnchor) {
      positionCustomPopup(activeCustomPopup, activeCustomPopupAnchor);
    }
  }

  function closeCustomPopup() {
    if (!activeCustomPopup) {
      return;
    }
    activeCustomPopup.remove();
    activeCustomPopup = null;
    activeCustomPopupAnchor = null;
    clearCustomPopupReflowListeners();
    document.removeEventListener('pointerdown', onCustomPopupOutside, true);
    document.removeEventListener('keydown', onCustomPopupKeydown, true);
  }

  function onCustomPopupOutside(e) {
    if (!activeCustomPopup) {
      return;
    }
    if (activeCustomPopup.contains(e.target)) {
      return;
    }
    if (activeCustomPopupAnchor && activeCustomPopupAnchor.contains(e.target)) {
      return;
    }
    closeCustomPopup();
  }

  function onCustomPopupKeydown(e) {
    if (e.key === 'Escape') {
      closeCustomPopup();
    }
  }

  function satWeekCol(jsDay) {
    return (jsDay + 1) % 7;
  }

  function isoFromParts(y, m, d) {
    return (
      String(y) +
      '-' +
      String(m).padStart(2, '0') +
      '-' +
      String(d).padStart(2, '0')
    );
  }

  function parseIsoToDate(iso) {
    var m = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) {
      return null;
    }
    return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
  }

  function openCustomSatCalendar(wrap, textInput, native) {
    closeCustomPopup();
    syncNativeFromText(textInput, native);

    var viewDate = parseIsoToDate(native.value) || new Date();
    var viewYear = viewDate.getFullYear();
    var viewMonth = viewDate.getMonth();

    var popup = document.createElement('div');
    popup.className = 'date-dmy-popup';
    popup.setAttribute('dir', 'rtl');
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'اختيار التاريخ');
    document.body.appendChild(popup);
    activeCustomPopup = popup;
    activeCustomPopupAnchor = wrap;

    function render() {
      var first = new Date(viewYear, viewMonth, 1);
      var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
      var startPad = satWeekCol(first.getDay());
      var selectedIso = native.value || '';
      var todayIso = isoFromParts(
        new Date().getFullYear(),
        new Date().getMonth() + 1,
        new Date().getDate()
      );

      var html = '';
      html += '<div class="date-dmy-popup__head">';
      html +=
        '<button type="button" class="date-dmy-popup__nav" data-nav="-1" aria-label="الشهر السابق">‹</button>';
      html +=
        '<span class="date-dmy-popup__title">' +
        AR_MONTHS[viewMonth] +
        ' ' +
        viewYear +
        '</span>';
      html +=
        '<button type="button" class="date-dmy-popup__nav" data-nav="1" aria-label="الشهر التالي">›</button>';
      html += '</div>';

      html += '<div class="date-dmy-popup__week">';
      for (var w = 0; w < 7; w++) {
        html += '<span class="date-dmy-popup__dow">' + AR_WEEK_SAT[w] + '</span>';
      }
      html += '</div>';

      html += '<div class="date-dmy-popup__grid">';
      var cell = 0;
      for (var pad = 0; pad < startPad; pad++) {
        html += '<span class="date-dmy-popup__day is-empty" aria-hidden="true"></span>';
        cell++;
      }
      for (var day = 1; day <= daysInMonth; day++) {
        var iso = isoFromParts(viewYear, viewMonth + 1, day);
        var cls = 'date-dmy-popup__day';
        if (iso === selectedIso) {
          cls += ' is-selected';
        }
        if (iso === todayIso) {
          cls += ' is-today';
        }
        html +=
          '<button type="button" class="' +
          cls +
          '" data-iso="' +
          iso +
          '">' +
          day +
          '</button>';
        cell++;
      }
      while (cell % 7 !== 0) {
        html += '<span class="date-dmy-popup__day is-empty" aria-hidden="true"></span>';
        cell++;
      }
      html += '</div>';

      html += '<div class="date-dmy-popup__foot">';
      html += '<button type="button" class="date-dmy-popup__foot-btn" data-action="today">اليوم</button>';
      html += '<button type="button" class="date-dmy-popup__foot-btn" data-action="clear">مسح</button>';
      html += '</div>';

      popup.innerHTML = html;
      positionCustomPopup(popup, wrap);
    }

    popup.addEventListener('click', function (e) {
      var navBtn = e.target.closest('[data-nav]');
      if (navBtn) {
        viewMonth += parseInt(navBtn.getAttribute('data-nav'), 10);
        if (viewMonth < 0) {
          viewMonth = 11;
          viewYear--;
        } else if (viewMonth > 11) {
          viewMonth = 0;
          viewYear++;
        }
        render();
        return;
      }

      var dayBtn = e.target.closest('[data-iso]');
      if (dayBtn) {
        native.value = dayBtn.getAttribute('data-iso');
        syncTextFromNative(textInput, native);
        closeCustomPopup();
        return;
      }

      var footBtn = e.target.closest('[data-action]');
      if (!footBtn) {
        return;
      }
      var action = footBtn.getAttribute('data-action');
      if (action === 'today') {
        var now = new Date();
        native.value = isoFromParts(now.getFullYear(), now.getMonth() + 1, now.getDate());
        syncTextFromNative(textInput, native);
        closeCustomPopup();
      } else if (action === 'clear') {
        native.value = '';
        textInput.value = '';
        textInput.dispatchEvent(new Event('change', { bubbles: true }));
        textInput.dispatchEvent(new Event('input', { bubbles: true }));
        closeCustomPopup();
      }
    });

    render();
    window.setTimeout(function () {
      positionCustomPopup(popup, wrap);
      document.addEventListener('pointerdown', onCustomPopupOutside, true);
      document.addEventListener('keydown', onCustomPopupKeydown, true);
      window.addEventListener('scroll', onCustomPopupReflow, true);
      window.addEventListener('resize', onCustomPopupReflow);
    }, 0);
  }

  function prefersNativeCalendar(textInput) {
    return textInput.getAttribute('data-date-calendar') === 'native';
  }

  function openDatePicker(wrap, textInput, native, btn) {
    syncNativeFromText(textInput, native);
    if (prefersNativeCalendar(textInput)) {
      openNativePicker(native, wrap || btn);
      return;
    }
    openCustomSatCalendar(wrap, textInput, native);
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
    textInput.setAttribute('inputmode', 'numeric');
    textInput.setAttribute('maxlength', '14');
    textInput.setAttribute('placeholder', 'يوم-شهر-سنة');
    if (!textInput.getAttribute('dir')) {
      textInput.setAttribute('dir', 'ltr');
    }

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
    if (textInput.value) {
      var initialIso = parseDmYToIso(textInput.value);
      if (!initialIso) {
        var initDigits = String(textInput.value).replace(/\D/g, '');
        if (initDigits.length === 8) {
          var initFormatted = formatDmYFromDigits(initDigits);
          initialIso = parseDmYToIso(initFormatted);
          if (initialIso) {
            textInput.value = initFormatted;
          }
        }
      }
      if (initialIso) {
        textInput.value = formatIsoToDmY(initialIso);
        native.value = initialIso;
        rememberValidDmY(textInput, textInput.value);
      } else {
        textInput.value = '';
        native.value = '';
      }
    }

    textInput.addEventListener('focus', function () {
      rememberValidDmY(textInput, textInput.value);
    });

    btn.addEventListener('click', function () {
      openDatePicker(wrap, textInput, native, btn);
    });

    textInput.addEventListener('dblclick', function () {
      openDatePicker(wrap, textInput, native, btn);
    });

    native.addEventListener('change', function () {
      syncTextFromNative(textInput, native);
    });

    textInput.addEventListener('input', function () {
      autoFormatDmYInput(textInput);
      syncNativeFromText(textInput, native);
    });

    textInput.addEventListener('blur', function () {
      finalizeDmYInput(textInput, native);
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
    formatDmYFromDigits: formatDmYFromDigits,
  };
})();
