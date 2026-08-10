'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { accountingCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

function voucherRows(ui, rows, phpRoute) {
  return (
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.voucher_no || '')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.voucher_date))}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount))}</td>
      <td>${dash(ui, r.description)}</td>
      <td>${ui.statusPill(Number(r.is_cancelled) === 1 ? 'lock' : Number(r.is_posted) === 1 ? 'ok' : 'wait', Number(r.is_cancelled) === 1 ? 'ملغى' : Number(r.is_posted) === 1 ? 'مرحّل' : 'مسودة')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl(phpRoute, 'id=' + r.id))}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6)
  );
}

module.exports = createDomainRouter({
  basePath: '/accounting',
  mark: 'Ac',
  kicker: 'Hypex Accounting · Node',
  hubTitle: 'المحاسبة',
  hubSubtitle: 'العمليات المالية، القيود، الشيكات والتقارير — تصميم 2027. التقارير الثقيلة تفتح على PHP.',
  catalog: accountingCatalog,
  listHandlers: {
    // سند قبض + قائمة الترحيل: receiptRoutes.js
    // سند صرف + قائمة الترحيل: paymentRoutes.js
    // شجرة الحسابات: chartRoutes.js (شجرة + إضافة/تعديل) — لا جدول مسطّح هنا
    '/accounting/journals': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const from = String(req.query.from || '');
      const to = String(req.query.to || '');
      const range = q.dateRange(from, to);
      const rows = await q.listJournals({ q: qv, from: range.from, to: range.to });
      const rowsHtml =
        rows
          .map(
            (r) => `<tr>
          <td class="si-num" dir="ltr">${ui.esc(r.entry_no || '')}</td>
          <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.entry_date))}</td>
          <td>${dash(ui, r.description_ar)}</td>
          <td>${dash(ui, r.status)}</td>
          <td>${dash(ui, r.ref_type || r.source)}</td>
          <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/accounting/journal-voucher?id=${Number(
            r.id
          )}">فتح</a></td>
        </tr>`
          )
          .join('') || ui.emptyRow(6);
      return {
        subtitle: `من ${range.from} إلى ${range.to}`,
        headers: ['الرقم', 'التاريخ', 'البيان', 'الحالة', 'المصدر', ''],
        rowsHtml,
        count: rows.length,
        filtersHtml: ui.dateFilters('/accounting/journals', range.from, range.to),
      };
    },
    '/accounting/checks-in': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const rows = await q.listCheckVouchers({ direction: 'in', q: qv });
      return {
        subtitle: 'سندات قبض بشيك',
        headers: ['الرقم', 'التاريخ', 'المبلغ', 'رقم الشيك', 'البنك', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.voucher_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.voucher_date))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount))}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.check_no)}</td>
            <td>${dash(ui, r.bank_name)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('fin_checks', 'id=' + r.id))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
        searchPath: '/accounting/checks-in',
        qVal: qv,
      };
    },
    '/accounting/checks-out': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const rows = await q.listCheckVouchers({ direction: 'out', q: qv });
      return {
        subtitle: 'سندات صرف بشيك',
        headers: ['الرقم', 'التاريخ', 'المبلغ', 'رقم الشيك', 'البنك', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.voucher_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.voucher_date))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount))}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.check_no)}</td>
            <td>${dash(ui, r.bank_name)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('fin_outgoing_checks'))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
        searchPath: '/accounting/checks-out',
        qVal: qv,
      };
    },
    '/accounting/checks-private': async (req, { ui }) => {
      const rows = await q.listPrivateChecks({ q: String(req.query.q || '') });
      return {
        subtitle: 'شيكات خاصة',
        headers: ['الرقم', 'الشيك', 'البنك', 'المبلغ', 'الاستحقاق', 'المستفيد', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.entry_no)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.check_no)}</td>
            <td>${dash(ui, r.bank_name)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.check_amount))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.due_date))}</td>
            <td>${dash(ui, r.beneficiary)}</td>
            <td>${dash(ui, r.status)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(7),
        count: rows.length,
        searchPath: '/accounting/checks-private',
        qVal: String(req.query.q || ''),
      };
    },
    '/accounting/debit-notes': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const rows = await q.listDebitNotes({ q: qv });
      const partyLabel = (r) => {
        const t = String(r.party_type || '');
        const name = r.party_name || '—';
        if (t === 'customer') return 'عميل: ' + name;
        if (t === 'supplier') return 'مورد: ' + name;
        return name;
      };
      return {
        subtitle: 'قائمة إشعارات المدينة — الإضافة والتعديل عبر شاشة الإشعار الكاملة',
        headers: ['الرقم', 'التاريخ', 'الطرف', 'المبلغ', 'السبب', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.doc_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.doc_date))}</td>
            <td>${ui.esc(partyLabel(r))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
            <td>${dash(ui, r.reason)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('debit_notes', 'id=' + r.id))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(6, 'لا إشعارات مدينة — اضغط «+ إشعار مدين جديد»'),
        count: rows.length,
        searchPath: '/accounting/debit-notes',
        qVal: qv,
        extraActions: [
          {
            label: '＋ إشعار مدين جديد',
            href: '/accounting/debit-notes/entry',
            primary: true,
          },
          { label: 'فتح الشاشة الأصلية', href: ui.embedUrl('debit_notes') },
        ],
      };
    },
    '/accounting/credit-notes': async (req, { ui }) => {
      const qv = String(req.query.q || '');
      const rows = await q.listCreditNotes({ q: qv });
      const partyLabel = (r) => {
        const t = String(r.party_type || '');
        const name = r.party_name || '—';
        if (t === 'customer') return 'عميل: ' + name;
        if (t === 'supplier') return 'مورد: ' + name;
        return name;
      };
      return {
        subtitle: 'قائمة إشعارات الدائنة — الإضافة والتعديل عبر شاشة الإشعار الكاملة',
        headers: ['الرقم', 'التاريخ', 'الطرف', 'المبلغ', 'السبب', ''],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.doc_no)}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.doc_date))}</td>
            <td>${ui.esc(partyLabel(r))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
            <td>${dash(ui, r.reason)}</td>
            <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(ui.embedUrl('credit_notes', 'id=' + r.id))}">فتح</a></td>
          </tr>`
            )
            .join('') || ui.emptyRow(6, 'لا إشعارات دائنة — اضغط «+ إشعار دائن جديد»'),
        count: rows.length,
        searchPath: '/accounting/credit-notes',
        qVal: qv,
        extraActions: [
          {
            label: '＋ إشعار دائن جديد',
            href: '/accounting/credit-notes/entry',
            primary: true,
          },
          { label: 'فتح الشاشة الأصلية', href: ui.embedUrl('credit_notes') },
        ],
      };
    },
  },
  reportHandlers: {
    '/accounting/reports/vouchers': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listVouchers({ from: range.from, to: range.to, limit: 200 });
      return {
        subtitle: 'سندات الفترة',
        headers: ['النوع', 'الرقم', 'التاريخ', 'المبلغ', 'البيان', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${ui.esc(r.voucher_type || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(r.voucher_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.voucher_date))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount))}</td>
            <td>${dash(ui, r.description)}</td>
            <td>${Number(r.is_cancelled) === 1 ? 'ملغى' : Number(r.is_posted) === 1 ? 'مرحّل' : 'مسودة'}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
    '/accounting/reports/cancelled-vouchers': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listVouchers({ from: range.from, to: range.to, cancelledOnly: true, limit: 200 });
      return {
        subtitle: 'السندات الملغاة',
        headers: ['النوع', 'الرقم', 'التاريخ', 'المبلغ', 'البيان'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td>${ui.esc(r.voucher_type || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(r.voucher_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.voucher_date))}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.amount))}</td>
            <td>${dash(ui, r.description)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
    '/accounting/reports/chart': async (req, { ui }) => {
      const rows = await q.listAccounts({ activeOnly: false });
      return {
        useDateFilters: false,
        subtitle: 'طباعة دليل الحسابات',
        headers: ['الرمز', 'الاسم', 'النوع', 'الأب', 'الحالة'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.code || '')}</td>
            <td>${ui.esc(r.name_ar || '')}</td>
            <td>${dash(ui, r.account_type)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.parent_code)}</td>
            <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
      };
    },
    '/accounting/reports/journal': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const rows = await q.listJournals({ from: range.from, to: range.to });
      return {
        subtitle: 'قيود الفترة',
        headers: ['الرقم', 'التاريخ', 'البيان', 'الحالة', 'المصدر'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${ui.esc(r.entry_no || '')}</td>
            <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.entry_date))}</td>
            <td>${dash(ui, r.description_ar)}</td>
            <td>${dash(ui, r.status)}</td>
            <td>${dash(ui, r.ref_type || r.source)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(5),
        count: rows.length,
        from: range.from,
        to: range.to,
      };
    },
  },
});
