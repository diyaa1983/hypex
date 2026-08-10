/**
 * اختصارات النظام (كل الشاشات)
 * F2  — إضافة سطر مادة
 * F3  — قائمة المواد
 * F4  — حذف بند المادة الحالي (ليس حذف المستند)
 * F7  — قائمة العملاء (أو الموردين في المشتريات)
 * F10 — حفظ
 */
(function () {
  'use strict';

  if (window.__HYPEX_SC__) return;
  window.__HYPEX_SC__ = true;

  var modal = null;
  var mode = ''; // customers | suppliers | items
  var qTimer = null;
  var lastFocusItem = null;

  function baseUrl(path) {
    if (typeof window.__hypexUrl === 'function') return window.__hypexUrl(path);
    var b =
      typeof window.__HYPEX_BASE__ === 'string' && window.__HYPEX_BASE__
        ? window.__HYPEX_BASE__
        : '';
    if (b && b.charAt(b.length - 1) === '/') b = b.slice(0, -1);
    if (!path || path.charAt(0) !== '/') path = '/' + (path || '');
    return b + path;
  }

  function isVisible(el) {
    if (!el || el.disabled) return false;
    if (el.getAttribute('aria-disabled') === 'true') return false;
    if (el.hasAttribute('hidden') || el.hidden) return false;
    var st = window.getComputedStyle(el);
    if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) {
      return false;
    }
    // offsetParent null عندما position:fixed — لا تمنع النقر
    var r = el.getBoundingClientRect();
    if (r.width <= 0 && r.height <= 0) return false;
    return true;
  }

  function clickEl(el) {
    if (!el || !isVisible(el)) return false;
    try {
      el.focus({ preventScroll: true });
    } catch (e) {
      /* ignore */
    }
    el.click();
    return true;
  }

  function clickFirst(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var list = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < list.length; j++) {
        if (clickEl(list[j])) return list[j];
      }
    }
    return null;
  }

  function findBtnByText(re) {
    var nodes = document.querySelectorAll(
      'button, a.si-btn, a.btn, a.si-tb, input[type="submit"], [role="button"]'
    );
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (!isVisible(el)) continue;
      var label = (el.textContent || el.value || el.getAttribute('aria-label') || el.title || '')
        .replace(/\s+/g, ' ')
        .trim();
      if (re.test(label)) return el;
    }
    return null;
  }

  function toast(msg) {
    var t = document.getElementById('hx-sc-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'hx-sc-toast';
      t.className = 'hx-sc-toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('is-on');
    clearTimeout(t._tm);
    t._tm = setTimeout(function () {
      t.classList.remove('is-on');
    }, 2200);
  }

  function doSave() {
    var ev = new CustomEvent('hx:save', { bubbles: true, cancelable: true });
    document.dispatchEvent(ev);
    if (ev.defaultPrevented) return true;

    if (
      clickFirst([
        '[data-hx-save]:not([disabled])',
        '#si-save:not([disabled])',
        '#sr-save:not([disabled])',
        '#dl-save:not([disabled])',
        '#co-save:not([disabled])',
        '#df-save:not([disabled])',
        '#jv-save:not([disabled])',
        'button.si-tb--save:not([disabled])',
        'button.si-btn--primary[type="submit"]:not([disabled])',
        'form[data-hx-save] button[type="submit"]:not([disabled])',
        'button[form][type="submit"].si-btn--primary:not([disabled])',
      ])
    ) {
      return true;
    }
    var byText = findBtnByText(/^(حفظ|حفظ\s|حفظ وترحيل)/);
    if (byText) {
      byText.click();
      return true;
    }
    var form = document.querySelector(
      'form[data-hx-save], form#jv-form, form.si-meta, form.si-doc-form, form#item-form'
    );
    if (form) {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
      return true;
    }
    toast('لا يوجد زر حفظ في هذه الشاشة');
    return false;
  }

  function doDeleteLine() {
    // السماح للنماذج باعتراض الحدث (حذف بند فقط — لا حذف الفاتورة/المستند)
    var ev = new CustomEvent('hx:delete-line', { bubbles: true, cancelable: true });
    document.dispatchEvent(ev);
    if (ev.defaultPrevented) return true;

    function lineFrom(el) {
      if (!el || !el.closest) return null;
      return el.closest(
        '#si-lines-body tr, #co-lines-body tr, #df-lines-body tr, #dl-lines-body tr, table.si-lines tbody tr, tr[data-idx]'
      );
    }

    var tr = lineFrom(document.activeElement);
    if (!tr) tr = lineFrom(lastFocusItem);

    if (!tr) {
      var bodies = [
        '#si-lines-body tr',
        '#co-lines-body tr',
        '#df-lines-body tr',
        '#dl-lines-body tr',
        'table.si-lines tbody tr',
      ];
      for (var i = 0; i < bodies.length; i++) {
        var rows = document.querySelectorAll(bodies[i]);
        if (rows.length) {
          tr = rows[rows.length - 1];
          break;
        }
      }
    }

    if (!tr) {
      toast('لا يوجد بند مادة للحذف');
      return false;
    }

    var del = tr.querySelector('.js-del, button.si-del, [data-hx-del-line]');
    if (del && !del.disabled) {
      del.click();
      return true;
    }

    // صف بدون زر حذف (مقفل) أو بنية مختلفة
    toast('لا يمكن حذف هذا البند حالياً');
    return false;
  }

  function focusLastItemInput() {
    var sels = [
      '#si-lines-body tr:last-child input.js-item',
      '#df-lines-body tr:last-child input.js-item',
      '#co-lines-body tr:last-child input.js-item',
      '#dl-lines-body tr:last-child input.js-item',
      'tbody tr:last-child input.js-item',
      'input.js-item',
      'input[data-hx-item-input]',
      'input.js-item-code',
    ];
    for (var i = 0; i < sels.length; i++) {
      var nodes = document.querySelectorAll(sels[i]);
      for (var j = nodes.length - 1; j >= 0; j--) {
        var el = nodes[j];
        if (el && !el.readOnly && !el.disabled && isVisible(el)) {
          try {
            el.focus();
            if (el.select) el.select();
          } catch (e) {
            /* ignore */
          }
          lastFocusItem = el;
          return el;
        }
      }
    }
    return null;
  }

  function doAddLine() {
    var ev = new CustomEvent('hx:add-line', { bubbles: true, cancelable: true });
    document.dispatchEvent(ev);
    if (ev.defaultPrevented) {
      setTimeout(focusLastItemInput, 40);
      return true;
    }

    var el = clickFirst([
      '[data-hx-add-line]:not([disabled])',
      '#si-add-line:not([disabled])',
      '#sr-add-line:not([disabled])',
      '#dl-add-line:not([disabled])',
      '#co-add-line:not([disabled])',
      '#df-add-line:not([disabled])',
      '#jv-add-line:not([disabled])',
      'button.si-tb--accent:not([disabled])',
    ]);
    if (!el) {
      var byText = findBtnByText(/\+\s*سطر|＋\s*سطر|إضافة\s*سطر|سطر\s*جديد|مادة\s*جديدة|\+\s*line/i);
      if (byText) {
        byText.click();
        el = byText;
      }
    }
    if (el) {
      setTimeout(focusLastItemInput, 50);
      return true;
    }
    // إن وُجد حقل مادة بالفعل — ركّز عليه
    if (focusLastItemInput()) {
      toast('سطر المادة جاهز');
      return true;
    }
    toast('هذه الشاشة لا تدعم إضافة سطر مادة');
    return false;
  }

  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'hx-lk';
    modal.className = 'hx-lk';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="hx-lk__backdrop" data-hx-lk-close="1"></div>' +
      '<div class="hx-lk__panel" role="dialog" aria-modal="true" aria-labelledby="hx-lk-title">' +
      '<header class="hx-lk__head">' +
      '<div><p class="hx-lk__kicker" id="hx-lk-kicker">اختصار النظام</p>' +
      '<h2 id="hx-lk-title">قائمة</h2></div>' +
      '<button type="button" class="hx-lk__x" data-hx-lk-close="1" aria-label="إغلاق">×</button>' +
      '</header>' +
      '<div class="hx-lk__search">' +
      '<input type="search" id="hx-lk-q" class="hx-lk__input" placeholder="ابحث…" autocomplete="off">' +
      '</div>' +
      '<div class="hx-lk__list" id="hx-lk-list"></div>' +
      '<footer class="hx-lk__foot">' +
      '<span dir="ltr">F2 سطر · F3 مواد · F4 حذف بند · F7 أطراف · F10 حفظ · Esc</span>' +
      '<a class="hx-lk__link" id="hx-lk-open-full" href="#">فتح القائمة الكاملة</a>' +
      '</footer></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) {
      var t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-hx-lk-close')) closeModal();
    });
    var qEl = modal.querySelector('#hx-lk-q');
    qEl.addEventListener('input', function () {
      clearTimeout(qTimer);
      qTimer = setTimeout(function () {
        loadList(qEl.value || '');
      }, 160);
    });
    qEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        var first = modal.querySelector('.hx-lk__row');
        if (first) first.focus();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        var row = modal.querySelector('.hx-lk__row');
        if (row) row.click();
      }
    });
    return modal;
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    mode = '';
  }

  function partyMode() {
    // شاشات المشتريات: موردين بدل عملاء
    if (document.getElementById('df_party') || document.getElementById('df_party_id')) {
      return 'suppliers';
    }
    return 'customers';
  }

  function openModal(kind) {
    ensureModal();
    mode = kind;
    var title =
      kind === 'customers'
        ? 'قائمة العملاء'
        : kind === 'suppliers'
          ? 'قائمة الموردين'
          : 'قائمة المواد';
    var kicker = kind === 'items' ? 'F3' : 'F7';
    modal.querySelector('#hx-lk-title').textContent = title;
    modal.querySelector('#hx-lk-kicker').textContent = 'اختصار ' + kicker;
    var full = modal.querySelector('#hx-lk-open-full');
    if (kind === 'customers') {
      full.href = baseUrl('/customers/list');
      full.textContent = 'قائمة العملاء الكاملة';
    } else if (kind === 'suppliers') {
      full.href = baseUrl('/suppliers/list');
      full.textContent = 'قائمة الموردين الكاملة';
    } else {
      full.href = baseUrl('/inventory/items');
      full.textContent = 'قائمة المواد الكاملة';
    }
    var qEl = modal.querySelector('#hx-lk-q');
    qEl.value = '';
    modal.hidden = false;
    setTimeout(function () {
      qEl.focus();
    }, 30);
    loadList('');
  }

  function loadList(q) {
    var list = document.getElementById('hx-lk-list');
    if (!list) return;
    list.innerHTML = '<p class="hx-lk__empty">جاري التحميل…</p>';
    var url;
    if (mode === 'customers') {
      url = baseUrl('/api/lookup/customers?q=') + encodeURIComponent(q || '');
    } else if (mode === 'suppliers') {
      url = baseUrl('/api/purchases/suppliers?q=') + encodeURIComponent(q || '');
    } else {
      url = baseUrl('/api/lookup/items?q=') + encodeURIComponent(q || '');
    }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, status: r.status, body: j };
        });
      })
      .then(function (res) {
        if (!res.ok || !(res.body && res.body.ok)) {
          list.innerHTML =
            '<p class="hx-lk__empty hx-lk__empty--err">' +
            ((res.body && res.body.error) || 'تعذر تحميل القائمة') +
            '</p>';
          return;
        }
        var rows = res.body.rows || [];
        if (!rows.length) {
          list.innerHTML =
            '<p class="hx-lk__empty">' +
            (mode === 'items'
              ? 'لا توجد مواد — أضف أصنافاً من المخزون'
              : mode === 'suppliers'
                ? 'لا يوجد موردون'
                : 'لا يوجد عملاء') +
            '</p>';
          return;
        }
        list.innerHTML = '';
        rows.slice(0, 80).forEach(function (row) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'hx-lk__row';
          if (mode === 'items') {
            btn.innerHTML =
              '<strong>' +
              esc(row.name_ar || '') +
              '</strong><span dir="ltr">' +
              esc(row.code || row.sku || '') +
              (row.sale_price != null ? ' · ' + esc(String(row.sale_price)) : '') +
              '</span>';
            btn.addEventListener('click', function () {
              applyItem(row);
            });
          } else {
            btn.innerHTML =
              '<strong>' +
              esc(row.name_ar || '') +
              '</strong><span dir="ltr">' +
              esc(row.code || '') +
              (row.phone ? ' · ' + esc(row.phone) : '') +
              '</span>';
            btn.addEventListener('click', function () {
              applyParty(row);
            });
          }
          btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              var n = btn.nextElementSibling;
              if (n) n.focus();
            } else if (e.key === 'ArrowUp') {
              e.preventDefault();
              var p = btn.previousElementSibling;
              if (p) p.focus();
              else document.getElementById('hx-lk-q').focus();
            } else if (e.key === 'Enter') {
              e.preventDefault();
              btn.click();
            } else if (e.key === 'Escape') {
              e.preventDefault();
              closeModal();
            }
          });
          list.appendChild(btn);
        });
      })
      .catch(function () {
        list.innerHTML = '<p class="hx-lk__empty hx-lk__empty--err">تعذر الاتصال</p>';
      });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fillNamedPair(nameId, idId, row) {
    var nameEl = document.getElementById(nameId);
    var idEl = document.getElementById(idId);
    if (!nameEl || nameEl.readOnly || nameEl.disabled) return false;
    nameEl.value = (row.code || '') + ' — ' + (row.name_ar || '');
    if (idEl) idEl.value = row.id;
    try {
      nameEl.dispatchEvent(new Event('change', { bubbles: true }));
      nameEl.dispatchEvent(new Event('input', { bubbles: true }));
    } catch (e) {
      /* ignore */
    }
    return true;
  }

  function applyParty(c) {
    var filled = false;
    if (mode === 'suppliers' || document.getElementById('df_party')) {
      filled = fillNamedPair('df_party', 'df_party_id', c);
    }
    if (!filled) {
      var pairs = [
        ['inv_customer', 'inv_customer_id'],
        ['ret_customer', 'ret_customer_id'],
        ['dl_customer', 'dl_customer_id'],
        ['co_customer', 'co_customer_id'],
      ];
      for (var i = 0; i < pairs.length; i++) {
        if (fillNamedPair(pairs[i][0], pairs[i][1], c)) {
          filled = true;
          break;
        }
      }
    }
    if (!filled) {
      var generic = document.querySelector('[data-hx-customer-input], [data-hx-party-input]');
      if (generic) {
        generic.value = (c.code || '') + ' — ' + (c.name_ar || '');
        var hid = document.querySelector('[data-hx-customer-id], [data-hx-party-id]');
        if (hid) hid.value = c.id;
        filled = true;
      }
    }
    var ev = new CustomEvent('hx:customer-picked', {
      detail: c,
      bubbles: true,
      cancelable: true,
    });
    document.dispatchEvent(ev);
    closeModal();
    if (filled || ev.defaultPrevented) {
      toast('تم الاختيار: ' + (c.name_ar || c.code || ''));
    } else {
      toast((c.code || '') + ' — ' + (c.name_ar || ''));
    }
  }

  function applyItem(it) {
    closeModal();
    var ev = new CustomEvent('hx:item-picked', {
      detail: it,
      bubbles: true,
      cancelable: true,
    });
    document.dispatchEvent(ev);
    if (ev.defaultPrevented) {
      toast('مادة: ' + (it.name_ar || it.code || it.sku || ''));
      setTimeout(focusLastItemInput, 40);
      return;
    }

    // تعبئة مباشرة إن لم تلتقط الشاشة الحدث
    var target = null;
    if (lastFocusItem && document.contains(lastFocusItem) && !lastFocusItem.disabled) {
      target = lastFocusItem;
    }
    if (!target) {
      var active = document.activeElement;
      if (active && active.matches && active.matches('input.js-item, input[data-hx-item-input]')) {
        target = active;
      }
    }
    if (!target) {
      var boxes = document.querySelectorAll(
        'input.js-item, input[data-hx-item-input], input.js-item-code'
      );
      for (var i = boxes.length - 1; i >= 0; i--) {
        if (!boxes[i].readOnly && !boxes[i].disabled && isVisible(boxes[i])) {
          // فضّل سطراً بلا مادة
          var tr = boxes[i].closest('tr');
          var hid = tr && tr.querySelector('.js-item-id');
          if (hid && Number(hid.value) > 0 && i > 0) continue;
          target = boxes[i];
          break;
        }
      }
    }
    if (target) {
      target.focus();
      target.value = (it.code || it.sku || '') + ' — ' + (it.name_ar || '');
      var row = target.closest('tr');
      var idEl = row && row.querySelector('.js-item-id');
      if (idEl) idEl.value = it.id;
      try {
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (e) {
        /* ignore */
      }
      setTimeout(function () {
        var suggest =
          (row && row.querySelector('.js-item-suggest button, .si-suggest button')) ||
          (target.parentElement &&
            target.parentElement.querySelector('.js-item-suggest button, .si-suggest button'));
        if (suggest) suggest.click();
      }, 250);
    }
    toast('مادة: ' + (it.name_ar || it.code || it.sku || ''));
  }

  function openPartyList() {
    var kind = partyMode();
    if (kind === 'customers') {
      var cust =
        document.getElementById('inv_customer') ||
        document.getElementById('ret_customer') ||
        document.getElementById('dl_customer') ||
        document.getElementById('co_customer') ||
        document.querySelector('[data-hx-customer-input]');
      if (cust && !cust.readOnly && !cust.disabled) {
        try {
          cust.focus();
        } catch (e) {
          /* ignore */
        }
      }
    } else {
      var party = document.getElementById('df_party');
      if (party && !party.readOnly && !party.disabled) {
        try {
          party.focus();
        } catch (e) {
          /* ignore */
        }
      }
    }
    openModal(kind);
  }

  function openItems() {
    focusLastItemInput();
    openModal('items');
  }

  function resolveKey(e) {
    var k = e.key || '';
    var c = e.code || '';
    var n = e.keyCode || e.which || 0;
    if (k === 'F2' || c === 'F2' || n === 113) return 'F2';
    if (k === 'F3' || c === 'F3' || n === 114) return 'F3';
    if (k === 'F4' || c === 'F4' || n === 115) return 'F4';
    if (k === 'F7' || c === 'F7' || n === 118) return 'F7';
    if (k === 'F10' || c === 'F10' || n === 121) return 'F10';
    if (k === 'Escape' || c === 'Escape' || n === 27) return 'Escape';
    return '';
  }

  function onKey(e) {
    var k = resolveKey(e);
    if (!k) return;

    // لا تتعارض مع Ctrl/Alt/Meta
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    if (k === 'Escape' && modal && !modal.hidden) {
      e.preventDefault();
      e.stopPropagation();
      closeModal();
      return;
    }

    if (k === 'F2' || k === 'F3' || k === 'F4' || k === 'F7' || k === 'F10') {
      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
    }

    if (k === 'F10') {
      doSave();
      return;
    }
    if (k === 'F2') {
      doAddLine();
      return;
    }
    if (k === 'F4') {
      doDeleteLine();
      return;
    }
    if (k === 'F7') {
      openPartyList();
      return;
    }
    if (k === 'F3') {
      openItems();
    }
  }

  // capture — قبل مستمعات الحقول واحتجاز F10
  document.addEventListener('keydown', onKey, true);

  document.addEventListener(
    'focusin',
    function (e) {
      var t = e.target;
      if (t && t.matches && t.matches('input.js-item, input[data-hx-item-input], input.js-item-code')) {
        lastFocusItem = t;
      }
    },
    true
  );

  window.HypexShortcuts = {
    save: doSave,
    addLine: doAddLine,
    deleteLine: doDeleteLine,
    /** @deprecated استخدم deleteLine — F4 لا يحذف المستند */
    deleteDoc: doDeleteLine,
    customers: openPartyList,
    items: openItems,
    close: closeModal,
  };
})();
