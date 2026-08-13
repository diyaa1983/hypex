'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { inventoryCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

module.exports = createDomainRouter({
  basePath: '/inventory',
  mark: 'Wh',
  kicker: 'Hypex Inventory · Node',
  hubTitle: 'المستودعات',
  hubSubtitle: 'المواد والمستودعات والحركات والجرد بتصميم 2027 — إدارة أصلية على Node.',
  catalog: inventoryCatalog,
  listHandlers: {
    // warehouses + items + categories + units + movement-types: mastersRoutes
    '/inventory/moves': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listMoves({ q: qv, from: range.from, to: range.to });
      return {
        subtitle: `من ${range.from} إلى ${range.to}`,
        headers: ['الرقم', 'التاريخ', 'النوع', 'من', 'إلى', 'الحالة', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.move_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.move_date))}</td>
            <td>${dash(ui, r.movement_type_code)}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td>${dash(ui, r.warehouse_to_name)}</td>
            <td>${dash(ui, r.status)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc('/inventory/moves?id=' + r.id)}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(7),
        count: rows.length,
        filtersHtml: ui.dateFilters('/inventory/moves', range.from, range.to),
        extraActions: [{ label: 'حركة جديدة', href: '/inventory/moves', primary: true }],
      };
    },
    '/inventory/stocktake': async (req, { ui }) => {
      const rows = await q.listStocktakeDocs({ q: String(req.query.q || '') });
      return {
        headers: ['الرقم', 'التاريخ', 'المستودع', 'الحالة', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.doc_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.doc_date))}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td>${dash(ui, r.status)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc('/inventory/stocktake?id=' + r.id)}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        searchPath: '/inventory/stocktake',
        qVal: String(req.query.q || ''),
        extraActions: [
          { label: 'جرد جديد', href: '/inventory/stocktake', primary: true },
        ],
      };
    },
    '/inventory/reports/stocktake': async (req, { ui }) => {
      const rows = await q.listStocktakeDocs({});
      return {
        title: 'قوائم الجرد',
        headers: ['الرقم', 'التاريخ', 'المستودع', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.doc_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.doc_date))}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td>${dash(ui, r.status)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
      };
    },
  },
  reportHandlers: {
    '/inventory/reports/items': async (req, { ui }) => {
      const rows = await q.reportItems();
      return {
        useDateFilters: false,
        headers: ['الباركود', 'الاسم', 'الفئة', 'الرصيد', 'تكلفة', 'بيع'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.item_code || r.barcode || r.sku)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.category_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.default_cost))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.default_sale))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
      };
    },
    '/inventory/reports/zero-qty': async (req, { ui }) => {
      const rows = await q.reportQtyFilter('zero');
      return {
        useDateFilters: false,
        headers: ['الباركود', 'الاسم', 'المستودع', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.item_code || r.barcode || r.sku)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
      };
    },
    '/inventory/reports/negative-qty': async (req, { ui }) => {
      const rows = await q.reportQtyFilter('neg');
      return {
        useDateFilters: false,
        headers: ['الباركود', 'الاسم', 'المستودع', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.item_code || r.barcode || r.sku)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
      };
    },
    '/inventory/reports/moves': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.reportMoves(range.from, range.to);
      return {
        headers: ['الرقم', 'التاريخ', 'النوع', 'المستودع', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.move_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.move_date))}</td>
            <td>${dash(ui, r.movement_type_code)}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td>${dash(ui, r.status)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
    '/inventory/reports/item-moves': async (req, { ui }) => {
      const warehouses = await q.listWarehouses({ activeOnly: true });
      const items = await q.listItems({ activeOnly: true, limit: 3000 });
      const itemId = Number(req.query.item_id || 0) || 0;
      const warehouseId = Number(req.query.warehouse_id || 0) || 0;
      const run = String(req.query.run || '') === '1';
      let err = '';
      let onHand = null;
      let rows = [];
      let item = null;
      let whLabel = '';

      if (run) {
        if (itemId < 1) err = 'اختر المادة.';
        else if (warehouseId < 1) err = 'اختر المستودع.';
        else {
          item = await q.getItemBrief(itemId);
          const wh = warehouses.find((w) => Number(w.id) === warehouseId);
          if (!item) err = 'المادة غير موجودة.';
          else if (!wh) err = 'المستودع غير موجود.';
          else {
            whLabel = wh.name_ar || wh.code || '';
            onHand = await q.itemOnHand(itemId, warehouseId);
            rows = await q.itemStockLedger(itemId, warehouseId);
          }
        }
      }

      const selectedItem = itemId > 0 ? items.find((it) => Number(it.id) === itemId) : null;
      const selectedItemLabel = selectedItem
        ? ((selectedItem.barcode || selectedItem.sku) ? (selectedItem.barcode || selectedItem.sku) + ' — ' : '') +
          (selectedItem.name_ar || '')
        : '';

      const itemCatalogJson = JSON.stringify(
        items.map((it) => ({
          id: Number(it.id),
          code: String(it.barcode || it.sku || ''),
          name: String(it.name_ar || ''),
        }))
      ).replace(/</g, '\\u003c');

      const whOpts = warehouses
        .map(
          (w) =>
            `<option value="${w.id}" ${warehouseId === Number(w.id) ? 'selected' : ''}>${ui.esc(
              w.name_ar || w.code || ''
            )}</option>`
        )
        .join('');

      const rowsHtml =
        rows
          .map(
            (r, i) => `<tr>
          <td class="si-num" dir="ltr">${i + 1}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.move_date))}</td>
          <td>${ui.esc(r.mov_type_label || r.ref_type || '—')}</td>
          <td class="si-num" dir="ltr">${r.ref_id || '—'}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty_delta))}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.balance_after))}</td>
          <td>${dash(ui, r.note)}</td>
        </tr>`
          )
          .join('') || (run && !err ? ui.emptyRow(7, 'لا حركات لهذه المادة في المستودع') : ui.emptyRow(7, 'اختر المادة والمستودع ثم ابحث'));

      const body = `
        <div class="si-stage si-report-page">
          ${ui.hero({
            mark: '📋',
            kicker: 'Hypex Inventory · Node',
            title: 'كشف حركات مادة',
            subtitle: run && item && !err
              ? `${ui.esc(item.name_ar || '')} · ${ui.esc(whLabel)} · رصيد: ${ui.esc(ui.fmtAmt(onHand))}`
              : 'اختر المادة والمستودع لعرض سجل الحركات والرصيد',
            actions: [
              { label: '🖨 طباعة', primary: true, print: true },
              { label: 'لوحة المستودعات', href: '/inventory' },
            ],
          })}
          ${
            err
              ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>`
              : ''
          }
          <div class="si-rail no-print" style="overflow:visible;border-radius:16px">
            <form method="get" action="/inventory/reports/item-moves" class="si-search" id="imv-form" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;max-width:100%;overflow:visible" autocomplete="off">
              <input type="hidden" name="run" value="1">
              <input type="hidden" name="item_id" id="imv-item-id" value="${itemId || 0}">
              <label style="font-size:.8rem;font-weight:700;color:#5c6578;position:relative;min-width:18rem;flex:1 1 18rem;z-index:30">المادة *
                <div class="si-cust-wrap" style="position:relative;z-index:30">
                  <input type="search" class="si-field" id="imv-item-search"
                         value="${ui.esc(selectedItemLabel)}"
                         placeholder="ابحث بالباركود أو اسم المادة…"
                         autocomplete="off" spellcheck="false">
                  <div class="si-suggest" id="imv-item-suggest" hidden></div>
                </div>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">المستودع *
                <select name="warehouse_id" class="si-field" required style="min-width:11rem">
                  <option value="">— اختر المستودع —</option>
                  ${whOpts}
                </select>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">الرصيد الحالي
                <input class="si-field si-field--mono" readonly value="${
                  onHand == null ? '—' : ui.esc(ui.fmtAmt(onHand))
                }" style="min-width:7rem" dir="ltr">
              </label>
              <button class="si-btn si-btn--primary" type="submit">بحث</button>
            </form>
          </div>
          <div class="si-print-area">
            ${ui.tableSurface(
              'سجل الحركات',
              `${rows.length} حركة`,
              ['#', 'التاريخ', 'نوع', 'مرجع', 'الكمية ±', 'الرصيد بعد', 'ملاحظة'],
              rowsHtml
            )}
          </div>
          <script type="application/json" id="imv-catalog">${itemCatalogJson}</script>
          <script>
          (function () {
            var catalog = [];
            try {
              catalog = JSON.parse(document.getElementById('imv-catalog').textContent || '[]');
            } catch (e) { catalog = []; }
            var search = document.getElementById('imv-item-search');
            var suggest = document.getElementById('imv-item-suggest');
            var itemId = document.getElementById('imv-item-id');
            var form = document.getElementById('imv-form');
            if (!search || !suggest || !itemId) return;

            function escHtml(s) {
              return String(s || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
            }
            function norm(s) {
              return String(s || '').toLowerCase().replace(/\\s+/g, ' ').trim();
            }
            function digits(s) {
              return String(s || '').replace(/\\D+/g, '');
            }
            function labelOf(c) {
              return (c.code || '') + (c.code && c.name ? ' — ' : '') + (c.name || '');
            }
            function placeFloat() {
              var r = search.getBoundingClientRect();
              var width = Math.max(r.width, 320);
              var left = r.left;
              if (left + width > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - width - 8);
              }
              suggest.classList.add('si-suggest--float');
              suggest.style.position = 'fixed';
              suggest.style.left = left + 'px';
              suggest.style.right = 'auto';
              suggest.style.top = r.bottom + 4 + 'px';
              suggest.style.width = width + 'px';
              suggest.style.maxHeight = 'min(22rem, 55vh)';
              suggest.style.zIndex = '4000';
            }
            function closeList() {
              suggest.hidden = true;
              suggest.setAttribute('hidden', '');
              suggest.classList.remove('si-suggest--float');
              suggest.style.left = '';
              suggest.style.top = '';
              suggest.style.width = '';
              suggest.style.position = '';
              suggest.dataset.hxUserNav = '';
              suggest.querySelectorAll('button.is-active').forEach(function (b) {
                b.classList.remove('is-active');
              });
            }
            function pick(c) {
              itemId.value = String(c.id || 0);
              search.value = labelOf(c);
              closeList();
            }
            function render(list) {
              if (!list.length) {
                suggest.innerHTML = '<div class="si-suggest-empty" style="padding:.65rem .8rem;color:#64748b;font-size:.85rem">لا نتائج مطابقة</div>';
                suggest.hidden = false;
                suggest.removeAttribute('hidden');
                placeFloat();
                return;
              }
              suggest.innerHTML = list
                .map(function (c) {
                  return (
                    '<button type="button" data-id="' +
                    c.id +
                    '">' +
                    escHtml(labelOf(c)) +
                    '</button>'
                  );
                })
                .join('');
              suggest.hidden = false;
              suggest.removeAttribute('hidden');
              placeFloat();
              suggest.querySelectorAll('button[data-id]').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
                btn.addEventListener('click', function () {
                  var id = Number(btn.getAttribute('data-id') || 0);
                  var c = catalog.find(function (x) { return Number(x.id) === id; });
                  if (c) pick(c);
                });
              });
            }
            function filter(q) {
              q = norm(q);
              /* إن كان النص هو اختيار سابق (باركود — اسم) خذ الباركود فقط للفلترة */
              if (q.indexOf(' — ') >= 0) q = norm(q.split(' — ')[0]);
              var qd = digits(q);
              if (!q) return catalog.slice(0, 80);
              return catalog
                .filter(function (c) {
                  var name = norm(c.name);
                  var code = norm(c.code);
                  var a = digits(c.code);
                  if (name.indexOf(q) !== -1 || code.indexOf(q) !== -1) return true;
                  if (qd && a.indexOf(qd) !== -1) return true;
                  return false;
                })
                .slice(0, 80);
            }
            function openList(opts) {
              opts = opts || {};
              /* عند الضغط/التركيز مع مادة مختارة: اعرض كل القائمة لتغيير الاختيار */
              if (opts.all || Number(itemId.value) > 0) {
                render(catalog.slice(0, 80));
                return;
              }
              render(filter(search.value || ''));
            }
            function moveActive(dir) {
              var btns = Array.prototype.slice.call(suggest.querySelectorAll('button[data-id]'));
              if (!btns.length || suggest.hidden) return false;
              var cur = -1;
              for (var i = 0; i < btns.length; i++) {
                if (btns[i].classList.contains('is-active')) { cur = i; break; }
              }
              if (cur < 0) cur = dir > 0 ? -1 : 0;
              var next = cur + dir;
              if (next < 0) next = btns.length - 1;
              if (next >= btns.length) next = 0;
              btns.forEach(function (b, i) {
                if (i === next) b.classList.add('is-active');
                else b.classList.remove('is-active');
              });
              suggest.dataset.hxUserNav = '1';
              try { btns[next].scrollIntoView({ block: 'nearest' }); } catch (e) {}
              return true;
            }
            var t = null;
            search.addEventListener('input', function () {
              itemId.value = '0';
              clearTimeout(t);
              t = setTimeout(function () {
                render(filter(search.value || ''));
              }, 120);
            });
            search.addEventListener('focus', function () { openList({ all: true }); });
            search.addEventListener('click', function () { openList({ all: true }); });
            search.addEventListener('keydown', function (e) {
              if (e.key === 'Escape') {
                closeList();
                return;
              }
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (suggest.hidden) openList({ all: true });
                moveActive(1);
                return;
              }
              if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (suggest.hidden) openList({ all: true });
                moveActive(-1);
                return;
              }
              if (e.key === 'Enter') {
                var active = suggest.querySelector('button.is-active');
                var first = suggest.querySelector('button[data-id]');
                if (!suggest.hidden && (active || first)) {
                  e.preventDefault();
                  (active || first).click();
                }
              }
            });
            document.addEventListener('click', function (e) {
              if (!suggest.contains(e.target) && e.target !== search) closeList();
            });
            window.addEventListener('scroll', function () {
              if (!suggest.hidden) placeFloat();
            }, true);
            window.addEventListener('resize', function () {
              if (!suggest.hidden) placeFloat();
            });
            if (form) {
              form.addEventListener('submit', function (e) {
                if (!(Number(itemId.value) > 0)) {
                  e.preventDefault();
                  openList({ all: true });
                  search.focus();
                  if (window.HypexUI && window.HypexUI.alert) {
                    window.HypexUI.alert('اختر المادة من القائمة.', 'error');
                  } else {
                    alert('اختر المادة من القائمة.');
                  }
                }
              });
            }
          })();
          </script>
        </div>`;
      return {
        __raw: ui.salesPage({
          user: req.session.user,
          title: 'كشف حركات مادة',
          bodyHtml: body,
          js: ['/assets/js/sales-print.js'],
        }),
      };
    },
    '/inventory/reports/customer-purchases': async (req, { ui }) => {
      const warehouses = await q.listWarehouses({ activeOnly: true });
      const customers = await q.listCustomersForPicker(String(req.query.cq || ''), 150);
      const customerId = Number(req.query.customer_id || 0) || 0;
      const warehouseId = Number(req.query.warehouse_id || 0) || 0;
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const summaryOnly = String(req.query.summary_only || '') === '1';
      const run = String(req.query.run || '') === '1';

      let err = '';
      let data = { summary: [], details: [], totals: { qty: 0, line_total: 0, line_gross: 0 } };
      if (run) {
        if (customerId < 1) err = 'اختر العميل.';
        else {
          data = await q.customerPurchasesByItem({
            customerId,
            from: range.from,
            to: range.to,
            warehouseId,
            summaryOnly,
          });
        }
      }

      const custOpts = customers
        .map(
          (c) =>
            `<option value="${c.id}" ${customerId === Number(c.id) ? 'selected' : ''}>${ui.esc(
              (c.code ? c.code + ' — ' : '') + (c.name_ar || '')
            )}</option>`
        )
        .join('');
      const whOpts = warehouses
        .map(
          (w) =>
            `<option value="${w.id}" ${warehouseId === Number(w.id) ? 'selected' : ''}>${ui.esc(
              w.name_ar || ''
            )}</option>`
        )
        .join('');

      const sumHtml =
        data.summary
          .map(
            (r) => `<tr>
          <td class="si-num" dir="ltr">${dash(ui, r.item_sku)}</td>
          <td>${ui.esc(r.item_name || '')}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_total))}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
          <td class="si-num" dir="ltr">${Number(r.invoice_count || 0)}</td>
        </tr>`
          )
          .join('') || ui.emptyRow(6, run && !err ? 'لا مشتريات في الفترة' : 'حدّد العميل والتواريخ ثم اعرض');

      let detBlock = '';
      if (run && !summaryOnly && !err) {
        const detHtml =
          data.details
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.invoice_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
            <td>${dash(ui, r.warehouse_name)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.item_sku)}</td>
            <td>${ui.esc(r.item_name || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.unit_price))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(8);
        detBlock = `<div style="margin-top:.85rem">${ui.tableSurface(
          'تفصيل الفواتير',
          `${data.details.length} بند`,
          ['فاتورة', 'التاريخ', 'مستودع', 'الباركود', 'المادة', 'كمية', 'سعر', 'الإجمالي'],
          detHtml
        )}</div>`;
      }

      const body = `
        <div class="si-stage si-report-page">
          ${ui.hero({
            mark: '🛒',
            kicker: 'Hypex Inventory · Node',
            title: 'تقرير مشتريات العميل حسب المادة',
            subtitle: run && !err
              ? `من ${range.from} إلى ${range.to} · كمية ${ui.esc(ui.fmtAmt(data.totals.qty))} · إجمالي ${ui.esc(
                  ui.fmtAmt(data.totals.line_gross)
                )}`
              : 'مبيعات مؤكدة مجمّعة حسب المادة للعميل المحدد',
            actions: [
              { label: '🖨 طباعة', primary: true, print: true },
              { label: 'لوحة المستودعات', href: '/inventory' },
            ],
          })}
          ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
          <div class="si-rail no-print">
            <form method="get" action="/inventory/reports/customer-purchases" class="si-search"
              style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;max-width:100%">
              <input type="hidden" name="run" value="1">
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">العميل *
                <select name="customer_id" class="si-field" required style="min-width:14rem">
                  <option value="">— اختر العميل —</option>
                  ${custOpts}
                </select>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">المستودع
                <select name="warehouse_id" class="si-field" style="min-width:10rem">
                  <option value="0">جميع المستودعات</option>
                  ${whOpts}
                </select>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">من تاريخ
                <input class="si-field" type="date" name="from" value="${ui.esc(range.from)}" required>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى تاريخ
                <input class="si-field" type="date" name="to" value="${ui.esc(range.to)}" required>
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.35rem">
                <input type="checkbox" name="summary_only" value="1" ${summaryOnly ? 'checked' : ''}>
                عرض الملخص فقط
              </label>
              <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
            </form>
          </div>
          <div class="si-print-area">
            ${ui.tableSurface(
              'الملخص حسب المادة',
              `${data.summary.length} مادة`,
              ['الباركود', 'المادة', 'الكمية', 'قبل الضريبة', 'مع الضريبة', 'فواتير'],
              sumHtml
            )}
            ${detBlock}
          </div>
        </div>`;
      return {
        __raw: ui.salesPage({
          user: req.session.user,
          title: 'مشتريات العميل حسب المادة',
          bodyHtml: body,
          js: ['/assets/js/sales-print.js'],
        }),
      };
    },
  },
});
