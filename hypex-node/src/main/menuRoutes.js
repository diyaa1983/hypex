'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { mainCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

function kpiRows(ui, pairs) {
  return pairs
    .map(
      ([label, value]) =>
        `<tr><td>${ui.esc(label)}</td><td class="si-num" dir="ltr">${ui.esc(String(value))}</td></tr>`
    )
    .join('');
}

module.exports = createDomainRouter({
  basePath: '/main',
  mark: 'Mn',
  kicker: 'Hypex Main · Node',
  hubTitle: 'رئيسي',
  hubSubtitle: 'لوحة التحكم ومؤشرات الشاشة الرئيسية — كل النظام يعمل من Node.',
  catalog: mainCatalog,
  listHandlers: {
    '/main/kpi/sales': async (req, { ui }) => {
      const k = await q.salesKpis();
      return {
        headers: ['المؤشر', 'القيمة'],
        rowsHtml: kpiRows(ui, [
          ['فواتير هذا الشهر', k.count_month],
          ['إجمالي مبيعات الشهر', ui.fmtAmt(k.total_month)],
          ['إجمالي المبيعات (كل الفترات)', ui.fmtAmt(k.total_all)],
          ['عدد الفواتير المؤكدة', k.count_all],
        ]),
        count: 4,
        extraActions: [{ label: 'فواتير المبيعات', href: '/sales/invoices', primary: true }],
      };
    },
    '/main/kpi/journal': async (req, { ui }) => {
      const k = await q.journalDaily();
      return {
        headers: ['المؤشر', 'القيمة'],
        rowsHtml: kpiRows(ui, [
          ['قيود اليوم (الكل)', k.count],
          ['قيود اليوم (مرحّلة / تقريبي)', k.posted],
        ]),
        count: 2,
        extraActions: [{ label: 'القيود', href: '/accounting/journals', primary: true }],
      };
    },
    '/main/kpi/purchases': async (req, { ui }) => {
      const k = await q.purchaseKpis();
      return {
        headers: ['المؤشر', 'القيمة'],
        rowsHtml: kpiRows(ui, [
          ['فواتير شراء هذا الشهر', k.count_month],
          ['إجمالي مشتريات الشهر', ui.fmtAmt(k.total_month)],
        ]),
        count: 2,
        extraActions: [{ label: 'فواتير الشراء', href: '/purchases/invoices', primary: true }],
      };
    },
    '/main/kpi/cashflow': async (req, { ui }) => {
      const k = await q.cashflowKpis();
      return {
        headers: ['المؤشر', 'القيمة'],
        rowsHtml: kpiRows(ui, [
          ['مقبوضات الشهر', ui.fmtAmt(k.receipts)],
          ['مدفوعات الشهر', ui.fmtAmt(k.payments)],
          ['صافي تقريبي', ui.fmtAmt(Number(k.receipts) - Number(k.payments))],
        ]),
        count: 3,
        extraActions: [{ label: 'سندات القبض', href: '/accounting/receipts' }],
      };
    },
    '/main/kpi/receivables': async (req, { ui }) => {
      const rows = await q.receivablesList();
      return {
        subtitle: 'فواتير بيع آجلة (تقريبي)',
        headers: ['الرقم', 'التاريخ', 'العميل', 'المبلغ', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.invoice_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
            <td>${dash(ui, r.party_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/unpaid">عرض</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        extraActions: [{ label: 'غير المسددة', href: '/sales/unpaid', primary: true }],
      };
    },
    '/main/kpi/payables': async (req, { ui }) => {
      const rows = await q.payablesList();
      return {
        headers: ['الرقم', 'التاريخ', 'المورد', 'المبلغ'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.invoice_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
            <td>${dash(ui, r.party_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(4),
        count: rows.length,
        extraActions: [{ label: 'غير المدفوعة', href: '/purchases/unpaid', primary: true }],
      };
    },
    '/main/panel/treasury': async (req, { ui }) => {
      const rows = await q.treasuryRows();
      return {
        headers: ['الرمز', 'الحساب', 'النوع'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.code)}</td>
            <td>${dash(ui, r.name_ar)}</td>
            <td>${dash(ui, r.account_type)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(3, 'لا حسابات مربوطة — اضبط من النظام'),
        count: rows.length,
        extraActions: [{ label: 'حسابات اللوحة', href: '/system/dashboard-accounts' }],
      };
    },
    '/main/panel/liabilities': async (req, { ui }) => {
      const rec = await q.receivablesList(20);
      const pay = await q.payablesList(20);
      const rowsHtml =
        rec
          .map(
            (r) =>
              `<tr><td>عميل</td><td>${dash(ui, r.party_name)}</td><td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td></tr>`
          )
          .join('') +
        pay
          .map(
            (r) =>
              `<tr><td>مورد</td><td>${dash(ui, r.party_name)}</td><td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td></tr>`
          )
          .join('');
      return {
        headers: ['طرف', 'الاسم', 'المبلغ'],
        rowsHtml: rowsHtml || ui.emptyRow(3),
        count: rec.length + pay.length,
      };
    },
    '/main/panel/checks': async (req, { ui }) => {
      const k = await q.checksKpis();
      return {
        headers: ['المؤشر', 'القيمة'],
        rowsHtml: kpiRows(ui, [
          ['شيكات واردة (تقريبي)', k.incoming],
          ['شيكات صادرة (تقريبي)', k.outgoing],
        ]),
        count: 2,
        extraActions: [
          { label: 'واردة', href: '/accounting/checks-in' },
          { label: 'صادرة', href: '/accounting/checks-out' },
        ],
      };
    },
    '/main/panel/recent-sales': async (req, { ui }) => {
      const rows = await q.recentSales();
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
      };
    },
  },
});
