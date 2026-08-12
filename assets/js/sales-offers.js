/**
 * شاشة العرض — بنود المواد + نوع العرض
 */
(function () {
  'use strict';

  var root = document.getElementById('so-root');
  if (!root) return;

  var tbody = document.getElementById('so-tbody');
  var form = document.getElementById('so-form');
  var linesInput = document.getElementById('so-lines-json');
  var lines = [];
  var itemTimers = {};

  function emptyLine() {
    return {
      item_id: 0,
      item_code: '',
      item_name: '',
      offer_type: 'bonus',
      trigger_qty: 10,
      bonus_qty: 1,
      discount_pct: '',
    };
  }

  function cleanBarcodeText(s) {
    s = String(s || '').trim();
    if (!s) return '';
    if (s.indexOf(' — ') >= 0) s = s.split(' — ')[0].trim();
    return s;
  }

  try {
    lines = JSON.parse((document.getElementById('so-initial') || {}).textContent || '[]') || [];
  } catch (e) {
    lines = [];
  }
  lines = (lines || []).map(function (ln) {
    ln = ln || emptyLine();
    return {
      item_id: Number(ln.item_id) || 0,
      item_code: cleanBarcodeText(ln.item_code || ln.item_barcode || ''),
      item_name: String(ln.item_name || '').trim(),
      offer_type: String(ln.offer_type) === 'discount_pct' ? 'discount_pct' : 'bonus',
      trigger_qty: ln.trigger_qty != null && ln.trigger_qty !== '' ? ln.trigger_qty : 10,
      bonus_qty: ln.bonus_qty != null && ln.bonus_qty !== '' ? ln.bonus_qty : 1,
      discount_pct: ln.discount_pct != null && ln.discount_pct !== '' ? ln.discount_pct : '',
    };
  });
  if (!lines.length) lines.push(emptyLine());

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function syncJson() {
    if (linesInput) linesInput.value = JSON.stringify(lines);
  }

  function syncFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-idx]').forEach(readRow);
  }

  function trashBtnHtml() {
    return (
      '<button type="button" class="si-del js-del" title="حذف" aria-label="حذف">' +
      '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" d="M4 7h16M9 7V5h6v2M18.5 7l-.7 12.2a1.5 1.5 0 0 1-1.5 1.4H7.7a1.5 1.5 0 0 1-1.5-1.4L5.5 7M10 11v6M14 11v6"/></svg>' +
      '</button>'
    );
  }

  function typeSelectHtml(ln) {
    var t = ln.offer_type === 'discount_pct' ? 'discount_pct' : 'bonus';
    return (
      '<select class="js-offer-type si-field" data-nav="1">' +
      '<option value="bonus"' +
      (t === 'bonus' ? ' selected' : '') +
      '>كمية إضافية</option>' +
      '<option value="discount_pct"' +
      (t === 'discount_pct' ? ' selected' : '') +
      '>خصم %</option>' +
      '</select>'
    );
  }

  function render(focusOpts) {
    if (!tbody) return;
    tbody.innerHTML = '';
    lines.forEach(function (ln, idx) {
      var isBonus = ln.offer_type !== 'discount_pct';
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr" class="si-row-num">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-code-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input class="js-item-code" type="text" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="باركود" data-nav="1" value="' +
        escAttr(ln.item_code || '') +
        '">' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td class="si-item-name-cell">' +
        '<input class="js-item-name" type="text" autocomplete="off" spellcheck="false" dir="rtl" ' +
        'placeholder="اسم المادة" data-nav="1" value="' +
        escAttr(ln.item_name || '') +
        '" ' +
        (ln.item_id ? 'readonly' : '') +
        '>' +
        '</td>' +
        '<td>' +
        typeSelectHtml(ln) +
        '</td>' +
        '<td><input class="js-trigger" type="number" min="0.001" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.trigger_qty) +
        '"></td>' +
        '<td><input class="js-bonus" type="number" min="0" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.bonus_qty) +
        '" ' +
        (isBonus ? '' : 'disabled') +
        '></td>' +
        '<td><input class="js-disc" type="number" min="0" max="100" step="0.001" data-nav="1" dir="ltr" value="' +
        escAttr(ln.discount_pct) +
        '" ' +
        (isBonus ? 'disabled' : '') +
        '></td>' +
        '<td>' +
        trashBtnHtml() +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    syncJson();
    if (focusOpts && focusOpts.idx != null) {
      window.setTimeout(function () {
        focusLineField(focusOpts.idx, focusOpts.cls || '.js-item-code', !!focusOpts.select);
      }, 0);
    }
  }

  function focusLineField(idx, selector, doSelect) {
    var tr = tbody.querySelector('tr[data-idx="' + String(idx) + '"]');
    if (!tr) return;
    var el = tr.querySelector(selector);
    if (!el || el.disabled) return;
    try {
      el.focus();
      if (doSelect && el.select) el.select();
    } catch (e) {
      /* ignore */
    }
  }

  function readRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = lines[idx] || emptyLine();
    var hid = tr.querySelector('.js-item-id');
    if (hid) ln.item_id = Number(hid.value) || 0;
    var codeEl = tr.querySelector('.js-item-code');
    var nameEl = tr.querySelector('.js-item-name');
    if (codeEl) ln.item_code = cleanBarcodeText(codeEl.value);
    if (nameEl) ln.item_name = String(nameEl.value || '').trim();
    var typ = tr.querySelector('.js-offer-type');
    if (typ) ln.offer_type = typ.value === 'discount_pct' ? 'discount_pct' : 'bonus';
    var tg = tr.querySelector('.js-trigger');
    var bn = tr.querySelector('.js-bonus');
    var dc = tr.querySelector('.js-disc');
    if (tg) ln.trigger_qty = tg.value;
    if (bn) ln.bonus_qty = bn.value;
    if (dc) ln.discount_pct = dc.value;
    lines[idx] = ln;
    syncJson();
  }

  function applyItem(idx, it) {
    lines[idx] = lines[idx] || emptyLine();
    lines[idx].item_id = it.id;
    lines[idx].item_code = cleanBarcodeText(it.barcode || it.code || it.sku || '');
    lines[idx].item_name = String(it.name_ar || it.name || '').trim();
    render({ idx: idx, cls: '.js-offer-type', select: false });
  }

  function getSuggestBox() {
    var el = document.getElementById('so-global-suggest');
    if (!el) {
      el = document.createElement('div');
      el.id = 'so-global-suggest';
      el.className = 'pa-global-suggest';
      el.hidden = true;
      document.body.appendChild(el);
    }
    return el;
  }

  function hideSuggest() {
    var box = getSuggestBox();
    box.hidden = true;
    box.innerHTML = '';
    box.style.display = 'none';
  }

  function showSuggest(anchor, rows, onPick) {
    var box = getSuggestBox();
    if (!rows || !rows.length) {
      hideSuggest();
      return;
    }
    box.innerHTML = rows
      .map(function (r) {
        return (
          '<button type="button" data-id="' +
          escAttr(r.id) +
          '"><span dir="ltr">' +
          escAttr(r.code || r.barcode || '') +
          '</span> — ' +
          escAttr(r.name_ar || '') +
          '</button>'
        );
      })
      .join('');
    box.hidden = false;
    box.style.display = 'block';
    box.className = 'pa-global-suggest si-suggest si-suggest--pa si-suggest--float';
    var rect = anchor.getBoundingClientRect();
    box.style.position = 'fixed';
    box.style.zIndex = '99999';
    box.style.left = Math.max(8, rect.left) + 'px';
    box.style.top = rect.bottom + 4 + 'px';
    box.style.minWidth = Math.max(260, rect.width) + 'px';
    box.style.maxHeight = '240px';
    box.style.overflow = 'auto';
    box.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) {
        e.preventDefault();
        var id = Number(btn.getAttribute('data-id'));
        var it = rows.find(function (x) {
          return Number(x.id) === id;
        });
        if (it) onPick(it);
        hideSuggest();
      });
    });
  }

  function searchItems(q, cb) {
    fetch('/api/lookup/items?q=' + encodeURIComponent(q || ''), { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        cb(d && d.ok ? d.rows || [] : []);
      })
      .catch(function () {
        cb([]);
      });
  }

  function bindRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));

    tr.querySelector('.js-del') &&
      tr.querySelector('.js-del').addEventListener('click', function () {
        syncFromDom();
        lines.splice(idx, 1);
        if (!lines.length) lines.push(emptyLine());
        render();
      });

    var typ = tr.querySelector('.js-offer-type');
    if (typ) {
      typ.addEventListener('change', function () {
        readRow(tr);
        render({ idx: idx, cls: typ.value === 'discount_pct' ? '.js-disc' : '.js-bonus' });
      });
    }

    ['.js-trigger', '.js-bonus', '.js-disc'].forEach(function (sel) {
      var el = tr.querySelector(sel);
      if (el) el.addEventListener('change', function () {
        readRow(tr);
      });
    });

    var codeEl = tr.querySelector('.js-item-code');
    var nameEl = tr.querySelector('.js-item-name');

    function scheduleSearch(fromEl) {
      clearTimeout(itemTimers[idx]);
      itemTimers[idx] = setTimeout(function () {
        var q = fromEl.value;
        if (!String(q || '').trim()) {
          hideSuggest();
          return;
        }
        searchItems(q, function (rows) {
          showSuggest(fromEl, rows, function (it) {
            applyItem(idx, it);
          });
        });
      }, 180);
    }

    if (codeEl) {
      codeEl.addEventListener('input', function () {
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        lines[idx].item_id = 0;
        scheduleSearch(codeEl);
      });
      codeEl.addEventListener('blur', function () {
        setTimeout(hideSuggest, 180);
      });
      codeEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          var q = codeEl.value;
          searchItems(q, function (rows) {
            if (rows.length === 1) applyItem(idx, rows[0]);
            else showSuggest(codeEl, rows, function (it) {
              applyItem(idx, it);
            });
          });
        }
      });
    }
    if (nameEl) {
      nameEl.addEventListener('input', function () {
        if (nameEl.readOnly) return;
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        lines[idx].item_id = 0;
        scheduleSearch(nameEl);
      });
      nameEl.addEventListener('blur', function () {
        setTimeout(hideSuggest, 180);
      });
    }
  }

  var addBtn = document.getElementById('so-add-line');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      syncFromDom();
      for (var i = 0; i < lines.length; i++) {
        if (!Number(lines[i].item_id)) {
          focusLineField(i, '.js-item-code', true);
          return;
        }
      }
      lines.push(emptyLine());
      render({ idx: lines.length - 1, cls: '.js-item-code', select: true });
    });
  }

  if (form) {
    form.addEventListener('submit', function () {
      syncFromDom();
      syncJson();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'F10') {
      e.preventDefault();
      if (form) {
        syncFromDom();
        syncJson();
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
    }
  });

  render();

  if (window.HypexDocNav && root) {
    window.HypexDocNav.bind({
      input: 'so_no',
      firstBtn: 'so_first',
      prevBtn: 'so_prev',
      nextBtn: 'so_next',
      lastBtn: 'so_last',
      firstId: Number(root.getAttribute('data-first-id') || 0),
      prevId: Number(root.getAttribute('data-prev-id') || 0),
      nextId: Number(root.getAttribute('data-next-id') || 0),
      lastId: Number(root.getAttribute('data-last-id') || 0),
      currentId: Number(root.getAttribute('data-current-id') || 0),
      currentNo: root.getAttribute('data-offer-no') || '',
      openPath: '/sales/offers',
      findApi: '/api/sales/offers/by-no',
    });
  }
})();
