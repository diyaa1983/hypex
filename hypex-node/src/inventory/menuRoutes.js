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
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('warehouse_moves', 'id=' + r.id))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(7),
        count: rows.length,
        filtersHtml: ui.dateFilters('/inventory/moves', range.from, range.to),
        extraActions: [{ label: 'حركة جديدة', href: ui.embedUrl('warehouse_moves'), primary: true }],
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
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('inventory_stocktake', 'id=' + r.id))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        searchPath: '/inventory/stocktake',
        qVal: String(req.query.q || ''),
        extraActions: [
          { label: 'جرد جديد', href: ui.embedUrl('inventory_stocktake'), primary: true },
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
        headers: ['SKU', 'الاسم', 'الفئة', 'الرصيد', 'تكلفة', 'بيع'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.sku)}</td>
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
        headers: ['SKU', 'الاسم', 'المستودع', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.sku)}</td>
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
        headers: ['SKU', 'الاسم', 'المستودع', 'الرصيد'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.sku)}</td>
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
      const items = await q.listItems({ activeOnly: true });
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

      const itemOpts = items
        .map(
          (it) =>
            `<option value="${it.id}" ${itemId === Number(it.id) ? 'selected' : ''}>${ui.esc(
              (it.sku ? it.sku + ' — ' : '') + (it.name_ar || '')
            )}</option>`
        )
        .join('');
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
          <div class="si-rail no-print">
            <form method="get" action="/inventory/reports/item-moves" class="si-search" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;max-width:100%">
              <input type="hidden" name="run" value="1">
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">المادة *
                <select name="item_id" class="si-field" required style="min-width:14rem">
                  <option value="">— اختر المادة —</option>
                  ${itemOpts}
                </select>
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
          ['فاتورة', 'التاريخ', 'مستودع', 'SKU', 'المادة', 'كمية', 'سعر', 'الإجمالي'],
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
              ['SKU', 'المادة', 'الكمية', 'قبل الضريبة', 'مع الضريبة', 'فواتير'],
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
