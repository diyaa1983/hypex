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
    // لا تضاعف /hypex إذا أعاد rewriteJs كتابة المسار مسبقاً
    if (b && (path === b || path.indexOf(b + '/') === 0)) return path;
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

  function focusLineQty(idx, tbodySel) {
    var tb = document.querySelector(tbodySel || '#si-lines-body, #df-lines-body, #co-lines-body');
    if (!tb || idx == null || idx < 0) return;
    setTimeout(function () {
      var tr = tb.querySelector('tr[data-idx="' + idx + '"]');
      var q = tr && tr.querySelector('.js-qty');
      if (!q || q.disabled || q.readOnly) return;
      try {
        q.focus({ preventScroll: true });
        if (q.select) q.select();
      } catch (e) {
        try {
          q.focus();
        } catch (e2) {
          /* ignore */
        }
      }
    }, 40);
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
      '<select id="hx-lk-cat" class="hx-lk__input hx-lk__cat" hidden title="فئة Oracle">' +
      '<option value="">— كل الفئات —</option>' +
      '</select>' +
      '<input type="search" id="hx-lk-q" class="hx-lk__input" placeholder="…ابحث" autocomplete="off">' +
      '</div>' +
      '<div class="hx-lk__list" id="hx-lk-list"></div>' +
      '<footer class="hx-lk__foot">' +
      '<span>Esc · حفظ F10 · أطراف F7 · حذف بند F4 · مواد F3 · سطر F2</span>' +
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

  function closeFloatingSuggests() {
    document.querySelectorAll('#pa-global-suggest, .js-item-suggest, .si-suggest--float').forEach(function (el) {
      try {
        el.hidden = true;
        el.setAttribute('hidden', '');
        el.innerHTML = '';
        el.classList.remove(
          'si-suggest--float',
          'si-suggest--barcode',
          'si-suggest--name',
          'si-suggest--sku',
          'si-suggest--pa'
        );
        // لا تستخدم display:none — تبقى بعد إغلاق F3 وتمنع ظهور قائمة الحقل لاحقاً
        el.style.display = '';
        el.style.position = '';
        el.style.left = '';
        el.style.right = '';
        el.style.top = '';
        el.style.width = '';
        el.style.minWidth = '';
        el.style.maxWidth = '';
        el.style.zIndex = '';
        el.style.inset = '';
        if (el._hxHome && el._hxHome.parentNode && el.parentNode === document.body) {
          el._hxHome.appendChild(el);
        }
      } catch (e) {
        /* ignore */
      }
    });
  }

  function openModal(kind) {
    ensureModal();
    closeFloatingSuggests();
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
    var catEl = modal.querySelector('#hx-lk-cat');
    if (catEl) {
      if (kind === 'items') {
        catEl.hidden = false;
        catEl.removeAttribute('hidden');
        ensureItemCategories(catEl).then(function () {
          loadList(qEl.value || '');
        });
      } else {
        catEl.hidden = true;
        catEl.setAttribute('hidden', '');
        catEl.value = '';
      }
    }
    modal.hidden = false;
    setTimeout(function () {
      qEl.focus();
    }, 30);
    if (kind !== 'items') loadList('');
  }

  function ensureItemCategories(catEl) {
    if (!catEl) return Promise.resolve();
    if (catEl.dataset.loaded === '1') {
      var coCat = document.getElementById('co-oracle-cat-filter');
      if (coCat && coCat.value) catEl.value = coCat.value;
      return Promise.resolve();
    }
    return fetch(baseUrl('/api/lookup/item-categories'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        var cur = catEl.value || '';
        catEl.innerHTML = '<option value="">— كل الفئات —</option>';
        (data.rows || []).forEach(function (row) {
          var o = document.createElement('option');
          o.value = String(row.cat || '');
          var code = String(row.cat || '');
          var name = String(row.name || '');
          o.textContent = code && name && name.indexOf(code) < 0 ? code + ' — ' + name : name || code;
          catEl.appendChild(o);
        });
        catEl.value = cur;
        catEl.dataset.loaded = '1';
        if (!catEl._hxCatBound) {
          catEl._hxCatBound = true;
          catEl.addEventListener('change', function () {
            var q = (modal.querySelector('#hx-lk-q') || {}).value || '';
            loadList(q);
          });
        }
      })
      .catch(function () {
        /* ignore */
      });
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
      var catEl = modal && modal.querySelector('#hx-lk-cat');
      var cat = catEl ? String(catEl.value || '').trim() : '';
      if (cat) url += '&cat=' + encodeURIComponent(cat);
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
            var bc = String(row.barcode != null ? row.barcode : '').trim();
            var sku = String(row.sku != null ? row.sku : '').trim();
            var nm = String(row.name_ar != null ? row.name_ar : '').trim();
            if (!bc && row.code && String(row.code).trim() !== sku) {
              bc = String(row.code).trim();
            }
            btn.className = 'hx-lk__row hx-lk__row--item';
            btn.innerHTML =
              '<span class="hx-lk__bc" dir="ltr">' +
              esc(bc || '—') +
              '</span>' +
              '<strong class="hx-lk__nm">' +
              esc(nm || '—') +
              '</strong>' +
              '<span class="hx-lk__sku" dir="ltr">' +
              esc(sku || '—') +
              '</span>';
            btn.title = [bc, nm, sku].filter(Boolean).join(' · ');
            btn.addEventListener('click', function () {
              applyItem(row);
            });
          } else {
            var pCode = String(row.code != null ? row.code : '').trim();
            var pName = String(row.name_ar != null ? row.name_ar : '').trim();
            var pPhone = String(row.phone != null ? row.phone : '').trim();
            btn.className = 'hx-lk__row hx-lk__row--party';
            btn.innerHTML =
              '<span class="hx-lk__code" dir="ltr">' +
              esc(pCode || '—') +
              '</span>' +
              '<strong class="hx-lk__nm">' +
              esc(pName || '—') +
              '</strong>' +
              (pPhone
                ? '<span class="hx-lk__phone" dir="ltr">' + esc(pPhone) + '</span>'
                : '');
            btn.title = [pCode, pName, pPhone].filter(Boolean).join(' · ');
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

  function partyFieldLabel(row, withCode) {
    var name = String(row.name_ar || row.name || '').trim();
    var code = String(row.code || '').trim();
    if (withCode) {
      return code ? code + (name ? ' — ' + name : '') : name;
    }
    return name || code;
  }

  function fillNamedPair(nameId, idId, row, withCode) {
    var nameEl = document.getElementById(nameId);
    var idEl = document.getElementById(idId);
    if (!nameEl || nameEl.readOnly || nameEl.disabled) return false;
    nameEl.value = partyFieldLabel(row, !!withCode);
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
    var isSupplier = mode === 'suppliers' || !!document.getElementById('df_party');
    if (isSupplier) {
      filled = fillNamedPair('df_party', 'df_party_id', c, true);
    }
    if (!filled) {
      var pairs = [
        ['inv_customer', 'inv_customer_id'],
        ['ret_customer', 'ret_customer_id'],
        ['dl_customer', 'dl_customer_id'],
        ['co_customer', 'co_customer_id'],
      ];
      for (var i = 0; i < pairs.length; i++) {
        if (fillNamedPair(pairs[i][0], pairs[i][1], c, false)) {
          filled = true;
          break;
        }
      }
    }
    if (!filled) {
      var generic = document.querySelector('[data-hx-customer-input], [data-hx-party-input]');
      if (generic) {
        generic.value = partyFieldLabel(c, isSupplier);
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
      toast('تم الاختيار: ' + partyFieldLabel(c, false));
    } else {
      toast(partyFieldLabel(c, isSupplier));
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
    // openModal يغلق أي قائمة منسدلة تحت الحقل — لا تُفتح قائمتان معاً
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

  function hasVisible(selList) {
    for (var i = 0; i < selList.length; i++) {
      var nodes = document.querySelectorAll(selList[i]);
      for (var j = 0; j < nodes.length; j++) {
        if (isVisible(nodes[j])) return true;
      }
    }
    return false;
  }

  /** F3 — شاشات عليها حقل مادة / أسطر بنود فقط */
  function hasItemContext() {
    if (document.getElementById('pa-root')) return true;
    if (document.getElementById('co-doc-bar') || document.getElementById('co-lines-body')) return true;
    if (document.getElementById('si-doc-bar') || document.getElementById('si-lines-body')) return true;
    if (document.getElementById('dl-lines-body') || document.getElementById('df_party')) {
      // طلب شراء/مرتجع/تسليم: إذا يوجد جدول بنود
      if (hasVisible(['input.js-item-code', 'input.js-item', 'input[data-hx-item-input]'])) return true;
    }
    return hasVisible([
      'input.js-item-code:not([readonly])',
      'input.js-item-name:not([readonly])',
      'input.js-item:not([readonly])',
      'input[data-hx-item-input]:not([readonly])',
    ]);
  }

  /** F7 — شاشات عليها عميل أو مورد فقط */
  function hasPartyContext() {
    if (document.getElementById('co_customer')) return true;
    if (document.getElementById('inv_customer')) return true;
    if (document.getElementById('ret_customer')) return true;
    if (document.getElementById('dl_customer')) return true;
    if (document.getElementById('df_party')) return true;
    return hasVisible(['[data-hx-customer-input]', '[data-hx-party-input]', '#df_party', '#co_customer']);
  }

  /** F2/F4 — شاشات بنود فقط */
  function hasLineContext() {
    if (document.getElementById('pa-root')) return true;
    if (document.getElementById('co-doc-bar')) return true;
    if (document.getElementById('si-doc-bar')) return true;
    if (document.getElementById('pa-add-line')) return true;
    return hasVisible([
      '#pa-add-line',
      '#co-add-line',
      '#si-add-line',
      '#dl-add-line',
      '[data-hx-add-line]',
      'tbody#pa-tbody',
      'tbody#co-lines-body',
      'tbody#si-lines-body',
    ]);
  }

  function hasSaveContext() {
    return hasVisible([
      '[data-hx-save]',
      'button.si-tb--save',
      '#co-save',
      '#si-save',
      '#dl-save',
      '#pa-form button[type="submit"]',
      'button[name="action"][value="save"]',
      'button[form][data-hx-save]',
    ]);
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

    // لا تُفعَّل الاختصارات إلا في السياق الصحيح — لا تمنع السلوك الافتراضي للصفحة
    if (k === 'F3' && !hasItemContext()) return;
    if (k === 'F7' && !hasPartyContext()) return;
    if (k === 'F2' && !hasLineContext()) return;
    if (k === 'F4' && !hasLineContext()) return;
    if (k === 'F10' && !hasSaveContext()) return;

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
      if (
        t &&
        t.matches &&
        t.matches('input.js-item, input[data-hx-item-input], input.js-item-sku, input.js-item-code, input.js-item-name')
      ) {
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
    focusLineQty: focusLineQty,
    lovIconHtml: lovBtnSvg,
    refreshLovButtons: installLovButtons,
  };

  function lovBtnSvg() {
    return (
      '<svg class="hx-lov-btn__ico" viewBox="0 0 16 16" width="13" height="13" aria-hidden="true" focusable="false">' +
      '<path fill="currentColor" d="M1.5 2.5h9v1.4h-9V2.5zm0 4.3h9v1.4h-9V6.8zm0 4.3h6.5v1.4H1.5v-1.4z"/>' +
      '<path fill="currentColor" d="M11.2 10.2l2.2 2.2 2.2-2.2.85.85L13.4 14.1 10.35 11.05l.85-.85z"/>' +
      '</svg>'
    );
  }

  function ensureLovWrap(el) {
    if (!el || !el.parentNode) return null;
    var parent = el.parentNode;
    if (parent.classList && parent.classList.contains('hx-lov-wrap')) return parent;
    if (parent.classList && parent.classList.contains('si-item-sku-wrap')) return parent;
    if (parent.querySelector && parent.querySelector(':scope > .hx-lov-btn')) return parent;
    var wrap = document.createElement('div');
    wrap.className = 'hx-lov-wrap';
    parent.insertBefore(wrap, el);
    wrap.appendChild(el);
    return wrap;
  }

  function openNativeSelect(sel) {
    if (!sel || sel.disabled) return;
    try {
      sel.focus();
    } catch (e) {
      /* ignore */
    }
    try {
      if (typeof sel.showPicker === 'function') {
        sel.showPicker();
        return;
      }
    } catch (e2) {
      /* fallback below */
    }
    var prevSize = sel.getAttribute('size');
    var prevH = sel.style.height;
    var n = sel.options ? sel.options.length : 0;
    sel.size = Math.min(Math.max(n, 2), 10);
    sel.style.height = 'auto';
    sel.style.position = 'relative';
    sel.style.zIndex = '40';
    var done = function () {
      if (prevSize == null) sel.removeAttribute('size');
      else sel.setAttribute('size', prevSize);
      sel.size = prevSize ? Number(prevSize) : 1;
      sel.style.height = prevH || '';
      sel.style.position = '';
      sel.style.zIndex = '';
      sel.removeEventListener('blur', done);
      sel.removeEventListener('change', done);
    };
    sel.addEventListener('change', done);
    sel.addEventListener('blur', done);
  }

  function attachLovButton(el, kind, title) {
    if (!el || el.getAttribute('data-hx-lov') === '1') return;
    if (el.readOnly || el.disabled) return;
    el.setAttribute('data-hx-lov', '1');

    // رقم المادة: زر موجود مسبقاً — نوحّد الأيقونة فقط
    if (kind === 'items') {
      var existing =
        (el.parentNode && el.parentNode.querySelector && el.parentNode.querySelector('.js-item-pick, .si-item-pick')) ||
        null;
      if (existing) {
        existing.classList.add('hx-lov-btn', 'si-item-pick');
        existing.innerHTML = lovBtnSvg();
        existing.title = title;
        existing.setAttribute('aria-label', title);
        return;
      }
    }

    var host = ensureLovWrap(el);
    if (!host) return;
    if (host.querySelector('.hx-lov-btn')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hx-lov-btn' + (kind === 'items' ? ' si-item-pick js-item-pick' : '');
    btn.title = title;
    btn.setAttribute('aria-label', title);
    btn.tabIndex = -1;
    btn.innerHTML = lovBtnSvg();
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      try {
        el.focus();
      } catch (err) {
        /* ignore */
      }
      if (kind === 'items') openItems();
      else if (kind === 'select') openNativeSelect(el);
      else openPartyList();
    });
    host.appendChild(btn);
  }

  function installLovButtons() {
    var partyInputs = document.querySelectorAll(
      '#co_customer, #inv_customer, #ret_customer, #dl_customer, #df_party, [data-hx-customer-input], [data-hx-party-input]'
    );
    partyInputs.forEach(function (el) {
      var isSup = el.id === 'df_party' || (el.matches && el.matches('[data-hx-party-input]'));
      attachLovButton(el, isSup ? 'suppliers' : 'customers', isSup ? 'قائمة الموردين (F7)' : 'قائمة العملاء (F7)');
    });

    var headerSelects = document.querySelectorAll(
      '#co_pay, #co_rep, #co_wh, #inv_pay, #inv_rep, #inv_wh, #ret_pay, #ret_rep, #ret_wh, #dl_pay, #dl_rep, #dl_wh'
    );
    headerSelects.forEach(function (el) {
      var titles = {
        co_pay: 'النوع (ذمم / نقدي)',
        inv_pay: 'النوع (ذمم / نقدي)',
        ret_pay: 'النوع (ذمم / نقدي)',
        dl_pay: 'النوع (ذمم / نقدي)',
        co_rep: 'المندوب',
        inv_rep: 'المندوب',
        ret_rep: 'المندوب',
        dl_rep: 'المندوب',
        co_wh: 'المستودع',
        inv_wh: 'المستودع',
        ret_wh: 'المستودع',
        dl_wh: 'المستودع',
      };
      attachLovButton(el, 'select', titles[el.id] || 'اختيار');
    });

    document.querySelectorAll('select.js-unit').forEach(function (el) {
      attachLovButton(el, 'select', 'الوحدة');
    });
    document.querySelectorAll('select.js-tax').forEach(function (el) {
      attachLovButton(el, 'select', 'الضريبة');
    });
    document.querySelectorAll('input.js-item-sku').forEach(function (el) {
      attachLovButton(el, 'items', 'قائمة المواد (F3)');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installLovButtons);
  } else {
    installLovButtons();
  }
  document.addEventListener('hx:lines-rendered', installLovButtons);
  setTimeout(installLovButtons, 400);
  setTimeout(installLovButtons, 1200);
})();
