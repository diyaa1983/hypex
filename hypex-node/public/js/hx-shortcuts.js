/**
 * اختصارات النظام (كل الشاشات والتقارير)
 * F2  — إضافة سطر مادة
 * F3  — قائمة المواد
 * F7  — قائمة العملاء
 * F10 — حفظ
 */
(function () {
  'use strict';

  var modal = null;
  var mode = ''; // customers | items
  var qTimer = null;

  function baseUrl(path) {
    var b =
      typeof window.__HYPEX_BASE__ === 'string' && window.__HYPEX_BASE__
        ? window.__HYPEX_BASE__
        : '';
    if (b && b.charAt(b.length - 1) === '/') b = b.slice(0, -1);
    if (!path || path.charAt(0) !== '/') path = '/' + (path || '');
    return b + path;
  }

  function isEditableTarget(el) {
    if (!el || el === document.body) return false;
    var tag = (el.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'select') return true;
    if (tag === 'input') {
      var t = String(el.type || 'text').toLowerCase();
      return t !== 'button' && t !== 'submit' && t !== 'checkbox' && t !== 'radio' && t !== 'file';
    }
    if (el.isContentEditable) return true;
    return false;
  }

  function clickFirst(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el && !el.disabled && el.offsetParent !== null) {
        el.click();
        return el;
      }
    }
    return null;
  }

  function findBtnByText(re) {
    var nodes = document.querySelectorAll(
      'button, a.si-btn, a.btn, input[type="submit"], [role="button"]'
    );
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.disabled || el.getAttribute('aria-disabled') === 'true') continue;
      if (el.classList && el.classList.contains('no-print') === false) {
        /* keep */
      }
      var label = (el.textContent || el.value || el.getAttribute('aria-label') || '').trim();
      if (re.test(label) && el.offsetParent !== null) return el;
    }
    return null;
  }

  function doSave() {
    if (clickFirst([
      '[data-hx-save]',
      '#si-save:not([disabled])',
      '#sr-save:not([disabled])',
      '#dl-save:not([disabled])',
      '#co-save:not([disabled])',
      '#df-save:not([disabled])',
      '#jv-save:not([disabled])',
      'button.si-tb--save:not([disabled])',
      'button.si-btn--primary[type="submit"]:not([disabled])',
      'form[data-hx-save] button[type="submit"]',
    ])) {
      return true;
    }
    var byText = findBtnByText(/^حفظ|^حفظ\s/);
    if (byText) {
      byText.click();
      return true;
    }
    var form = document.querySelector('form[data-hx-save], form.si-meta, form.si-doc-form');
    if (form) {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
      return true;
    }
    toast('لا يوجد زر حفظ في هذه الشاشة');
    return false;
  }

  function doAddLine() {
    var el = clickFirst([
      '[data-hx-add-line]',
      '#si-add-line:not([disabled])',
      '#sr-add-line:not([disabled])',
      '#dl-add-line:not([disabled])',
      '#co-add-line:not([disabled])',
      '#df-add-line:not([disabled])',
      '#jv-add-line:not([disabled])',
      'button.si-tb--accent:not([disabled])',
    ]);
    if (el) {
      setTimeout(function () {
        var itemInp = document.querySelector(
          '#si-lines-body tr:last-child input.js-item-code, ' +
            '#si-lines-body tr:last-child input[type="search"], ' +
            'tbody tr:last-child .js-item-code, ' +
            'tbody tr:last-child [data-hx-item-input]'
        );
        if (itemInp) {
          itemInp.focus();
          itemInp.select && itemInp.select();
        }
      }, 60);
      return true;
    }
    var byText = findBtnByText(/\+\s*سطر|إضافة\s*سطر|سطر\s*جديد|مادة\s*جديدة|\+\s*line/i);
    if (byText) {
      byText.click();
      return true;
    }
    toast('هذه الشاشة لا تدعم إضافة سطر مادة');
    return false;
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
      '<span dir="ltr">F7 عملاء · F3 مواد · Esc إغلاق</span>' +
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
      }, 180);
    });
    qEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        var first = modal.querySelector('.hx-lk__row');
        if (first) first.focus();
      }
    });
    return modal;
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    mode = '';
  }

  function openModal(kind) {
    ensureModal();
    mode = kind;
    var title = kind === 'customers' ? 'قائمة العملاء' : 'قائمة المواد';
    var kicker = kind === 'customers' ? 'F7' : 'F3';
    modal.querySelector('#hx-lk-title').textContent = title;
    modal.querySelector('#hx-lk-kicker').textContent = 'اختصار ' + kicker;
    var full = modal.querySelector('#hx-lk-open-full');
    full.href = baseUrl(kind === 'customers' ? '/customers/list' : '/inventory/items');
    full.textContent =
      kind === 'customers' ? 'قائمة العملاء الكاملة' : 'قائمة المواد الكاملة';
    var qEl = modal.querySelector('#hx-lk-q');
    qEl.value = '';
    modal.hidden = false;
    qEl.focus();
    loadList('');
  }

  function loadList(q) {
    var list = document.getElementById('hx-lk-list');
    if (!list) return;
    list.innerHTML = '<p class="hx-lk__empty">جاري التحميل…</p>';
    var url =
      mode === 'customers'
        ? baseUrl('/api/lookup/customers?q=') + encodeURIComponent(q || '')
        : baseUrl('/api/lookup/items?q=') + encodeURIComponent(q || '');
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
          list.innerHTML = '<p class="hx-lk__empty">لا توجد نتائج</p>';
          return;
        }
        list.innerHTML = '';
        rows.slice(0, 80).forEach(function (row) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'hx-lk__row';
          if (mode === 'customers') {
            btn.innerHTML =
              '<strong>' +
              esc(row.name_ar || '') +
              '</strong><span dir="ltr">' +
              esc(row.code || '') +
              (row.phone ? ' · ' + esc(row.phone) : '') +
              '</span>';
            btn.addEventListener('click', function () {
              applyCustomer(row);
            });
          } else {
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

  function applyCustomer(c) {
    var pairs = [
      ['inv_customer', 'inv_customer_id'],
      ['ret_customer', 'ret_customer_id'],
      ['dl_customer', 'dl_customer_id'],
      ['co_customer', 'co_customer_id'],
    ];
    var filled = false;
    for (var i = 0; i < pairs.length; i++) {
      var nameEl = document.getElementById(pairs[i][0]);
      var idEl = document.getElementById(pairs[i][1]);
      if (nameEl && !nameEl.readOnly) {
        nameEl.value = (c.code || '') + ' — ' + (c.name_ar || '');
        if (idEl) idEl.value = c.id;
        nameEl.dispatchEvent(new Event('change', { bubbles: true }));
        filled = true;
        break;
      }
    }
    var generic = document.querySelector('[data-hx-customer-input]');
    if (!filled && generic) {
      generic.value = (c.code || '') + ' — ' + (c.name_ar || '');
      var hid = document.querySelector('[data-hx-customer-id]');
      if (hid) hid.value = c.id;
      filled = true;
    }
    closeModal();
    if (filled) toast('تم اختيار العميل: ' + (c.name_ar || c.code || ''));
    else toast((c.code || '') + ' — ' + (c.name_ar || ''));
    document.dispatchEvent(
      new CustomEvent('hx:customer-picked', { detail: c, bubbles: true })
    );
  }

  function applyItem(it) {
    // حاول تعبئة آخر خلية مادة فارغة أو الحالية
    var boxes = document.querySelectorAll(
      'input.js-item-code, input[data-hx-item-input], #si-lines-body input[type="search"]'
    );
    var target = null;
    for (var i = boxes.length - 1; i >= 0; i--) {
      if (!boxes[i].readOnly && !boxes[i].disabled) {
        target = boxes[i];
        break;
      }
    }
    if (target) {
      target.focus();
      target.value = it.code || it.sku || it.name_ar || '';
      target.dispatchEvent(new Event('input', { bubbles: true }));
      // انتظر اقتراحات الشاشة ثم اختر الأول أو املأ يدوياً
      setTimeout(function () {
        var suggest =
          target.parentElement &&
          target.parentElement.querySelector('.js-item-suggest button, .si-suggest button');
        if (suggest) suggest.click();
      }, 280);
      closeModal();
      toast('مادة: ' + (it.name_ar || it.code || ''));
    } else {
      closeModal();
      toast((it.code || '') + ' — ' + (it.name_ar || ''));
    }
    document.dispatchEvent(new CustomEvent('hx:item-picked', { detail: it, bubbles: true }));
  }

  function openCustomers() {
    // إن وُجد حقل عميل على الشاشة: ركّز عليه وافتح الاقتراحات + نافذة الاختيار
    var cust =
      document.getElementById('inv_customer') ||
      document.getElementById('ret_customer') ||
      document.getElementById('dl_customer') ||
      document.getElementById('co_customer') ||
      document.querySelector('[data-hx-customer-input]');
    if (cust && !cust.readOnly) {
      cust.focus();
      cust.dispatchEvent(new Event('focus', { bubbles: true }));
      cust.dispatchEvent(new Event('input', { bubbles: true }));
    }
    openModal('customers');
  }

  function openItems() {
    openModal('items');
  }

  function onKey(e) {
    var k = e.key;
    if (k !== 'F2' && k !== 'F3' && k !== 'F7' && k !== 'F10' && k !== 'Escape') return;

    // لا تُفسِد اختصارات المتصفح مع Ctrl/Alt/Meta
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    if (k === 'Escape' && modal && !modal.hidden) {
      e.preventDefault();
      closeModal();
      return;
    }

    if (k === 'F2' || k === 'F3' || k === 'F7' || k === 'F10') {
      e.preventDefault();
      e.stopPropagation();
    }

    if (k === 'F10') {
      doSave();
      return;
    }
    if (k === 'F2') {
      doAddLine();
      return;
    }
    if (k === 'F7') {
      openCustomers();
      return;
    }
    if (k === 'F3') {
      openItems();
    }
  }

  document.addEventListener('keydown', onKey, true);

  // تصدير للاستخدام اليدوي
  window.HypexShortcuts = {
    save: doSave,
    addLine: doAddLine,
    customers: openCustomers,
    items: openItems,
    close: closeModal,
  };
})();
