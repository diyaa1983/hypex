(function () {
  'use strict';

  var root = document.getElementById('dl-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var locked = !!state.is_locked;
  var msgEl = document.getElementById('dl-msg');
  var tbody = document.getElementById('dl-lines-body');
  var custTimer = null;
  var itemTimers = {};

  function setMsg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
  }

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function renderLines() {
    if (!tbody) return;
    tbody.innerHTML = '';
    (state.lines || []).forEach(function (ln, idx) {
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr">' +
        (idx + 1) +
        '</td>' +
        '<td class="si-item-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input class="js-item" type="search" placeholder="رمز / باركود / اسم" value="' +
        escAttr((ln.item_code ? ln.item_code + ' — ' : '') + (ln.name_ar || '')) +
        '" ' +
        (locked ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td><input class="js-qty" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty != null ? ln.qty : 1) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td>' +
        (locked ? '' : '<button type="button" class="si-del js-del" title="حذف">×</button>') +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
  }

  function bindRow(tr) {
    var qty = tr.querySelector('.js-qty');
    if (qty) {
      qty.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        state.lines[idx] = state.lines[idx] || {};
        state.lines[idx].qty = qty.value;
      });
    }
    var del = tr.querySelector('.js-del');
    if (del) {
      del.addEventListener('click', function () {
        state.lines.splice(Number(tr.getAttribute('data-idx')), 1);
        if (!state.lines.length) addEmptyLine();
        else renderLines();
      });
    }
    var itemInput = tr.querySelector('.js-item');
    var suggest = tr.querySelector('.js-item-suggest');
    if (itemInput && suggest && !locked) {
      itemInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(itemInput.value, suggest, tr);
        }, 220);
      });
      itemInput.addEventListener('focus', function () {
        if (!itemInput.value) searchItems('', suggest, tr);
      });
    }
  }

  function searchItems(q, box, tr) {
    fetch('/api/sales/delivery/items?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) return;
        box.innerHTML = '';
        (data.rows || []).slice(0, 25).forEach(function (it) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = (it.code || '') + ' — ' + (it.name_ar || '');
          b.addEventListener('click', function () {
            var idx = Number(tr.getAttribute('data-idx'));
            state.lines[idx] = state.lines[idx] || {};
            state.lines[idx].item_id = it.id;
            state.lines[idx].item_code = it.code;
            state.lines[idx].name_ar = it.name_ar;
            if (!state.lines[idx].qty) state.lines[idx].qty = 1;
            box.hidden = true;
            renderLines();
          });
          box.appendChild(b);
        });
        box.hidden = !(data.rows && data.rows.length);
      })
      .catch(function () {
        box.hidden = true;
      });
  }

  function addEmptyLine() {
    state.lines = state.lines || [];
    state.lines.push({ item_id: 0, item_code: '', name_ar: '', qty: 1 });
    renderLines();
  }

  document.addEventListener('hx:item-picked', function (e) {
    if (locked || !document.getElementById('dl-lines-body')) return;
    var it = e.detail;
    if (!it || !it.id) return;
    e.preventDefault();
    var idx = -1;
    for (var i = 0; i < (state.lines || []).length; i++) {
      if (!state.lines[i] || !state.lines[i].item_id) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      addEmptyLine();
      idx = state.lines.length - 1;
    }
    state.lines[idx] = state.lines[idx] || {};
    state.lines[idx].item_id = it.id;
    state.lines[idx].item_code = it.code || it.sku || '';
    state.lines[idx].name_ar = it.name_ar || '';
    if (!state.lines[idx].qty) state.lines[idx].qty = 1;
    renderLines();
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (locked || !document.getElementById('dl_customer')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    e.preventDefault();
    if (custId) custId.value = c.id;
    if (custInput) custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
    if (custBox) custBox.hidden = true;
  });

  document.addEventListener('hx:add-line', function (e) {
    if (locked || !document.getElementById('dl-add-line')) return;
    e.preventDefault();
    addEmptyLine();
  });

  var custInput = document.getElementById('dl_customer');
  var custId = document.getElementById('dl_customer_id');
  var custBox = document.getElementById('cust_suggest');
  if (custInput && custBox && !locked) {
    custInput.addEventListener('input', function () {
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        fetch('/api/sales/delivery/customers?q=' + encodeURIComponent(custInput.value || ''))
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) return;
            custBox.innerHTML = '';
            (data.rows || []).slice(0, 25).forEach(function (c) {
              var b = document.createElement('button');
              b.type = 'button';
              b.textContent = (c.code || '') + ' — ' + (c.name_ar || '');
              b.addEventListener('click', function () {
                custId.value = c.id;
                custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
                custBox.hidden = true;
              });
              custBox.appendChild(b);
            });
            custBox.hidden = !(data.rows && data.rows.length);
          });
      }, 220);
    });
    document.addEventListener('click', function (e) {
      if (!custBox.contains(e.target) && e.target !== custInput) custBox.hidden = true;
      document.querySelectorAll('.js-item-suggest').forEach(function (box) {
        if (!box.contains(e.target) && !box.parentElement.contains(e.target)) box.hidden = true;
      });
    });
  }

  var addBtn = document.getElementById('dl-add-line');
  if (addBtn) addBtn.addEventListener('click', addEmptyLine);

  var saveBtn = document.getElementById('dl-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      if (locked) return;
      tbody.querySelectorAll('tr').forEach(function (tr) {
        var idx = Number(tr.getAttribute('data-idx'));
        state.lines[idx] = state.lines[idx] || {};
        state.lines[idx].qty = tr.querySelector('.js-qty').value;
      });
      var payload = {
        id: state.id || 0,
        delivery_date: (document.getElementById('dl_date') || {}).value || '',
        customer_id: Number((document.getElementById('dl_customer_id') || {}).value || 0),
        warehouse_id: Number((document.getElementById('dl_wh') || {}).value || 0) || null,
        notes: (document.getElementById('dl_notes') || {}).value || '',
        lines: (state.lines || []).filter(function (ln) {
          return ln && ln.item_id;
        }),
      };
      if (!payload.customer_id) {
        setMsg('اختر العميل.', 'error');
        return;
      }
      if (!payload.lines.length) {
        setMsg('أضف مادة واحدة على الأقل.', 'error');
        return;
      }
      setMsg('جاري الحفظ…');
      saveBtn.disabled = true;
      fetch('/api/sales/delivery', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          saveBtn.disabled = false;
          if (!data.ok) {
            setMsg(data.error || 'تعذر الحفظ', 'error');
            return;
          }
          setMsg('تم الحفظ · ' + (data.delivery_no || ''), 'ok');
          if (data.id && Number(data.id) !== Number(state.id)) {
            window.location.href = '/sales/delivery/' + data.id;
          } else {
            state.id = data.id;
            var noEl = document.getElementById('dl_no');
            if (noEl && data.delivery_no) noEl.value = data.delivery_no;
          }
        })
        .catch(function () {
          saveBtn.disabled = false;
          setMsg('تعذر الاتصال بالخادم', 'error');
        });
    });
  }

  renderLines();
})();
