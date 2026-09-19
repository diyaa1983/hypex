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
        headers: [
          'الباركود',
          'اسم المادة بالعربي مع التعبئة',
          'الفئة',
          'الوحدة',
          'سعر البيع',
          'سعر الجملة',
          'سعر الكلفة',
          'الحالة',
        ],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.barcode || r.item_code || r.sku)}</td>
            <td>
              <strong>${ui.esc(r.name_ar || '')}</strong>
              ${
                r.pack_label
                  ? `<div class="muted" style="font-size:.78rem;font-weight:600;margin-top:.15rem">التعبئة: ${ui.esc(
                      r.pack_label
                    )}</div>`
                  : ''
              }
              ${
                r.name_en
                  ? `<div class="muted" style="font-size:.78rem;font-weight:500" dir="ltr">${ui.esc(r.name_en)}</div>`
                  : ''
              }
            </td>
            <td>${dash(ui, r.category_name)}</td>
            <td>${dash(ui, r.unit_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtUnitPrice(r.default_sale))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtUnitPrice(r.default_wholesale))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtUnitPrice(r.default_cost))}</td>
            <td>${
              Number(r.is_active) === 1
                ? ui.statusPill('ok', 'نشط')
                : ui.statusPill('lock', 'موقوف')
            }</td>
          </tr>`
            )
            .join('') || ui.emptyRow(8),
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
      const itemId = Number(req.query.item_id || 0) || 0;
      let warehouseId = Number(req.query.warehouse_id || 0) || 0;
      if (warehouseId < 1) {
        warehouseId = q.resolveDefaultWarehouseId(warehouses);
      }
      const run = String(req.query.run || '') === '1';
      let err = '';
      let onHand = null;
      let rows = [];
      let item = null;
      let whLabel = '';
      let inTotal = 0;
      let outTotal = 0;

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
            for (const r of rows) {
              const qv = Number(r.qty_delta || 0);
              if (qv > 0) inTotal += qv;
              else if (qv < 0) outTotal += Math.abs(qv);
            }
          }
        }
      }

      const selectedItemLabel = item
        ? ((item.barcode || item.sku || item.item_code)
            ? (item.barcode || item.sku || item.item_code) + ' — '
            : '') + (item.name_ar || '')
        : '';

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
          .map((r, i) => {
            const qty = Number(r.qty_delta || 0);
            const qtyCls = qty > 0 ? 'imv-qty imv-qty--in' : qty < 0 ? 'imv-qty imv-qty--out' : 'imv-qty';
            const qtyTxt = (qty > 0 ? '+' : '') + ui.fmtAmt(qty);
            const docInner = r.doc_url
              ? `<a class="imv-doc-link" href="${ui.esc(r.doc_url)}">${ui.esc(r.doc_no || '—')}</a>`
              : ui.esc(r.doc_no || '—');
            const party = r.party_name
              ? `<div class="imv-party muted">${ui.esc(r.party_name)}</div>`
              : '';
            return `<tr>
          <td class="si-num" dir="ltr">${i + 1}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.move_date))}</td>
          <td><span class="imv-type">${ui.esc(r.mov_type_label || r.ref_type || '—')}</span></td>
          <td class="si-num" dir="ltr">${docInner}${party}</td>
          <td class="si-num ${qtyCls}" dir="ltr">${ui.esc(qtyTxt)}</td>
          <td class="si-num imv-bal" dir="ltr">${ui.esc(ui.fmtAmt(r.balance_after))}</td>
          <td>${dash(ui, r.note)}</td>
        </tr>`;
          })
          .join('') ||
        (run && !err
          ? ui.emptyRow(7, 'لا حركات لهذه المادة في المستودع')
          : ui.emptyRow(7, 'اختر المستودع والمادة ثم ابحث'));

      const summaryHtml =
        run && item && !err
          ? `<div class="imv-summary si-print-area">
              <div class="imv-summary__item">
                <span class="imv-summary__label">المادة</span>
                <strong>${ui.esc(item.name_ar || '')}</strong>
                <span class="imv-summary__meta" dir="ltr">${ui.esc(
                  item.barcode || item.sku || item.item_code || ''
                )}</span>
              </div>
              <div class="imv-summary__item">
                <span class="imv-summary__label">المستودع</span>
                <strong>${ui.esc(whLabel)}</strong>
              </div>
              <div class="imv-summary__item imv-summary__item--in">
                <span class="imv-summary__label">إجمالي وارد</span>
                <strong dir="ltr">+${ui.esc(ui.fmtAmt(inTotal))}</strong>
              </div>
              <div class="imv-summary__item imv-summary__item--out">
                <span class="imv-summary__label">إجمالي صادر</span>
                <strong dir="ltr">−${ui.esc(ui.fmtAmt(outTotal))}</strong>
              </div>
              <div class="imv-summary__item imv-summary__item--bal">
                <span class="imv-summary__label">الرصيد الحالي</span>
                <strong dir="ltr">${ui.esc(ui.fmtAmt(onHand))}</strong>
              </div>
            </div>`
          : '';

      const body = `
        <div class="si-stage si-report-page imv-page">
          ${ui.hero({
            mark: '📋',
            kicker: 'Hypex Inventory · Node',
            title: 'كشف حركات مادة',
            subtitle:
              run && item && !err
                ? `${ui.esc(item.name_ar || '')} · ${ui.esc(whLabel)}`
                : 'اختر المستودع ثم المادة لعرض سجل الحركات والرصيد',
            actions: [
              ui.printAction(),
              { label: 'لوحة المستودعات', href: '/inventory' },
            ],
          })}
          ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
          <section class="si-surface imv-filters no-print">
            <div class="si-surface-head">
              <h2>معايير البحث</h2>
              <span class="si-count">مستودع ثم مادة</span>
            </div>
            <form method="get" action="/inventory/reports/item-moves" class="imv-form" id="imv-form" autocomplete="off">
              <input type="hidden" name="run" value="1">
              <input type="hidden" name="item_id" id="imv-item-id" value="${itemId || 0}">
              <label class="imv-field imv-field--wh">
                <span class="imv-field__lab">المستودع *</span>
                <select name="warehouse_id" id="imv-warehouse" class="si-field" required>
                  <option value="">— اختر المستودع —</option>
                  ${whOpts}
                </select>
              </label>
              <label class="imv-field imv-field--item">
                <span class="imv-field__lab">المادة *</span>
                <div class="si-cust-wrap imv-item-wrap">
                  <input type="search" class="si-field" id="imv-item-search"
                         value="${ui.esc(selectedItemLabel)}"
                         placeholder="ابحث بالباركود أو اسم المادة…"
                         autocomplete="off" spellcheck="false"
                         aria-autocomplete="list" aria-controls="imv-item-suggest">
                  <button type="button" class="imv-item-open" id="imv-item-open" title="عرض قائمة المواد" aria-label="عرض قائمة المواد">▾</button>
                  <div id="imv-item-suggest" class="si-suggest si-suggest--name imv-suggest" hidden></div>
                </div>
              </label>
              <label class="imv-field imv-field--bal">
                <span class="imv-field__lab">الرصيد الحالي</span>
                <input class="si-field si-field--mono" readonly tabindex="-1"
                       value="${onHand == null ? '—' : ui.esc(ui.fmtAmt(onHand))}" dir="ltr">
              </label>
              <div class="imv-field imv-field--actions">
                <button class="si-btn si-btn--primary" type="submit">بحث</button>
              </div>
            </form>
          </section>
          ${summaryHtml}
          <div class="si-print-area imv-table-wrap">
            ${ui.tableSurface(
              'سجل الحركات',
              `${rows.length} حركة`,
              ['#', 'التاريخ', 'النوع', 'المرجع', 'الكمية ±', 'الرصيد بعد', 'ملاحظة'],
              rowsHtml
            )}
          </div>
        </div>`;
      return {
        __raw: ui.salesPage({
          user: req.session.user,
          title: 'كشف حركات مادة',
          bodyHtml: body,
          js: ['/assets/js/sales-print.js', '/assets/js/item-moves.js'],
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
              ui.printAction(),
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
