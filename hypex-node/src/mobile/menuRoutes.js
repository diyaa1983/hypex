'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { mobileCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

module.exports = createDomainRouter({
  basePath: '/mobile',
  mark: 'Mb',
  kicker: 'Hypex Mobile · Node',
  hubTitle: 'تطبيق الهاتف',
  hubSubtitle: 'شاشات الهاتف على Node — العمليات الإدخالية تفتح نفس مسار PHP للهاتف.',
  catalog: mobileCatalog,
  defaultBridgeDesc:
    'شاشة محمول: الإدخال يتم عبر واجهة الهاتف / PHP. من سطح المكتب يمكنك فتح النسخة المكافئة لإدارة البيانات.',
  listHandlers: {
    '/mobile/home': async (req, { ui }) => {
      const k = await q.homeKpis();
      const rowsHtml = `
        <tr><td>فواتير الشهر</td><td class="si-num" dir="ltr">${k.month_invoices}</td></tr>
        <tr><td>مبيعات الشهر</td><td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(k.month_sales))}</td></tr>
        <tr><td>عملاء نشطون</td><td class="si-num" dir="ltr">${k.customers}</td></tr>`;
      return {
        subtitle: 'ملخص سريع لواجهة الهاتف',
        headers: ['المؤشر', 'القيمة'],
        rowsHtml,
        count: 3,
        extraActions: [
          { label: 'فواتير المبيعات', href: '/mobile/sales-invoices', primary: true },
          { label: 'لوحة التحكم', href: '/app' },
        ],
      };
    },
    '/mobile/sales-invoices': async (req, { ui }) => {
      const rows = await q.recentSales(50);
      return {
        headers: ['الرقم', 'التاريخ', 'العميل', 'الإجمالي', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.invoice_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
            <td>${dash(ui, r.customer_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/invoices/${r.id}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        extraActions: [{ label: 'كل الفواتير', href: '/sales/invoices', primary: true }],
      };
    },
    '/mobile/user-locations': async (req, { ui }) => {
      const rows = await q.listLocations();
      return {
        headers: ['المستخدم', 'عرض', 'طول', 'وقت'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.full_name_ar || r.username)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.latitude)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.longitude)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.captured_at)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
        extraActions: [{ label: 'نسخة النظام', href: '/system/user-locations' }],
      };
    },
    '/mobile/gps-tracker': async (req, { ui }) => {
      const rows = await q.listTracks();
      return {
        headers: ['المستخدم', 'عرض', 'طول', 'وقت'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${dash(ui, r.full_name_ar || r.username)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.latitude)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.longitude)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.captured_at)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
        extraActions: [{ label: 'نسخة النظام', href: '/system/gps-tracker' }],
      };
    },
    '/mobile/rep-stock': async (req, { ui }) => {
      const rows = await q.repStockSummary();
      return {
        headers: ['الرمز', 'المستودع', 'الرصيد التقديري'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.code)}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3),
        count: rows.length,
      };
    },
    '/mobile/rep-custody': async (req, { ui }) => {
      const rows = await q.repCustodyMoves();
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
            .join('') || ui.emptyRow(5, 'لا حركات عهدة مطابقة'),
        count: rows.length,
      };
    },
  },
});
