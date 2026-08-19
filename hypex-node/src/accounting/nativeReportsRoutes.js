'use strict';

/**
 * تقارير المحاسبة + الأرصدة الافتتاحية + إغلاق الأشهر/السنة — Node UI · PHP logic
 */
const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const svc = require('./nativeService');
const db = require('../db');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}
function uid(req) {
  return Number(req.session.user?.id || 0) || 0;
}
function forbid(res) {
  return res.status(403).send('ممنوع');
}
function flash(req) {
  const msg = String(req.query.msg || '');
  const err = String(req.query.err || '');
  return (
    (msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : '') +
    (err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : '')
  );
}
function money(n) {
  return esc(fmtAmt(Number(n) || 0));
}
function dmy(iso) {
  return esc(isoToDmy(String(iso || '').slice(0, 10)));
}
function numPlain(n) {
  const x = Number(n) || 0;
  return x.toFixed(3);
}
function csvCell(v) {
  return `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
}
function csvRow(cells) {
  return cells.map(csvCell).join(',');
}
/** CSV (BOM) يفتحه Excel مباشرة */
function sendExcelCsv(res, filename, rows) {
  const bom = '\uFEFF';
  const body = bom + rows.map(csvRow).join('\r\n') + '\r\n';
  const safe = String(filename || 'export')
    .replace(/[^\w.\-]+/g, '_')
    .slice(0, 80);
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${safe}.csv"`);
  res.setHeader('Cache-Control', 'no-store');
  return res.send(body);
}

/**
 * تصدير Excel كجدول HTML (.xls) — يحفظ شكل التقرير (ترويسة + أعمدة + مجاميع).
 * Excel يفتحه مع دعم عربي واتجاه RTL.
 */
function sendExcelHtml(res, filename, innerHtml, sheetName) {
  const safe = String(filename || 'export')
    .replace(/[^\w.\-]+/g, '_')
    .slice(0, 80);
  const sheet = String(sheetName || 'تقرير')
    .replace(/[<>&'"]/g, '')
    .slice(0, 31);
  const bom = '\uFEFF';
  const html =
    bom +
    `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40" lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]>
<xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>${sheet}</x:Name>
    <x:WorksheetOptions>
     <x:DisplayRightToLeft/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body, table {
    font-family: Arial, Tahoma, sans-serif;
    font-size: 11pt;
    color: #0f172a;
    direction: rtl;
  }
  .ora-xl-title {
    font-size: 15pt;
    font-weight: 800;
    text-align: center;
    padding: 6px 4px 10px;
    color: #1e3a5f;
  }
  .ora-xl-meta td {
    border: 0 !important;
    padding: 2px 4px;
    font-size: 11pt;
  }
  .ora-xl-meta .lbl { color: #64748b; font-weight: 700; }
  .ora-xl-meta .val { font-weight: 700; color: #0f172a; }
  .ora-xl-period {
    text-align: center;
    font-weight: 800;
    font-size: 12pt;
    padding: 6px 4px 12px !important;
    border: 0 !important;
  }
  table.ora-xl-data {
    border-collapse: collapse;
    width: 100%;
  }
  table.ora-xl-data th {
    background: #5b6b7c;
    color: #fff;
    font-weight: 700;
    border: 1px solid #4a5568;
    padding: 6px 5px;
    text-align: center;
    white-space: nowrap;
  }
  table.ora-xl-data td {
    border: 1px solid #94a3b8;
    padding: 4px 5px;
    vertical-align: middle;
    background: #fff;
  }
  table.ora-xl-data td.num {
    mso-number-format: "0.000";
    text-align: left;
    direction: ltr;
    font-variant-numeric: tabular-nums;
  }
  table.ora-xl-data td.date {
    mso-number-format: "\\@";
    text-align: center;
    direction: ltr;
    white-space: nowrap;
  }
  table.ora-xl-data td.desc { text-align: right; }
  table.ora-xl-data tr.open td { background: #f1f5f9; font-weight: 700; }
  table.ora-xl-data tr.foot td {
    background: #e2e8f0;
    font-weight: 800;
    border-top: 2px solid #0f172a;
  }
  .ora-xl-chq-title {
    font-size: 12pt;
    font-weight: 800;
    text-decoration: underline;
    padding: 16px 4px 6px !important;
    border: 0 !important;
    text-align: right;
  }
  table.ora-xl-chq {
    border-collapse: collapse;
    width: auto;
    min-width: 420px;
  }
  table.ora-xl-chq th {
    border: 0;
    border-bottom: 1px solid #000;
    text-decoration: underline;
    font-weight: 800;
    padding: 3px 8px;
    text-align: right;
    background: transparent;
    color: #000;
  }
  table.ora-xl-chq td {
    border: 0;
    font-weight: 700;
    padding: 2px 8px;
    text-align: right;
  }
  table.ora-xl-chq td.num {
    mso-number-format: "0.000";
    text-align: left;
    direction: ltr;
  }
  table.ora-xl-chq tr.foot td {
    border-top: 1px dashed #000;
    text-decoration: underline;
    padding-top: 6px;
  }
  .spacer td { border: 0 !important; height: 8px; }
</style>
</head>
<body dir="rtl">
${innerHtml}
</body>
</html>`;
  res.setHeader('Content-Type', 'application/vnd.ms-excel; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${safe}.xls"`);
  res.setHeader('Cache-Control', 'no-store');
  return res.send(html);
}

function pathOk(p) {
  return (
    p.startsWith('/accounting/reports/') ||
    p === '/accounting/general-ledger' ||
    p === '/accounting/opening-balance' ||
    p.startsWith('/accounting/opening-balance/') ||
    p === '/accounting/period-close' ||
    p.startsWith('/accounting/period-close/') ||
    p === '/accounting/year-close' ||
    p.startsWith('/accounting/year-close/')
  );
}

router.use((req, res, next) => {
  if (!pathOk(req.path || '')) return next('router');
  return auth.requireAuth(req, res, next);
});

function sendPage(res, req, title, bodyHtml) {
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
  res.setHeader('Pragma', 'no-cache');
  res.send(
    ui.salesPage({
      user: req.session.user,
      title,
      bodyHtml,
      css: ['/assets/css/hr-shift-settings.css', '/assets/css/acc-reports-node.css'],
      js: ['/assets/js/sales-print.js'],
      activePath: req.path,
    })
  );
}

function shell(title, subtitle, filtersHtml, resultHtml, extraActions = []) {
  return `
    <div class="si-stage sh-page ar-report">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title,
        subtitle,
        actions: [
          ui.printAction(),
          ...extraActions,
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <div class="si-rail no-print">${filtersHtml}</div>
      <div class="si-print-area">${resultHtml}</div>
    </div>`;
}

function optList(rows, sel, allLabel) {
  let html = allLabel != null ? `<option value="0">${esc(allLabel)}</option>` : `<option value="">—</option>`;
  for (const r of rows || []) {
    const id = Number(r.id);
    const lab = (r.code ? r.code + ' — ' : '') + (r.name_ar || r.name || '');
    html += `<option value="${id}" ${Number(sel) === id ? 'selected' : ''}>${esc(lab)}</option>`;
  }
  return html;
}

function dateFields(from, to, names = { from: 'from', to: 'to' }) {
  return `
    <label>من
      <input class="si-field si-field--mono" type="date" name="${names.from}" value="${esc(from)}" required>
    </label>
    <label>إلى
      <input class="si-field si-field--mono" type="date" name="${names.to}" value="${esc(to)}" required>
    </label>`;
}

/* ═══════════════ Trial Balance ═══════════════ */
async function trialBalance(req, res, detailed) {
  const code = detailed ? 'report_trial_balance_detailed' : 'report_trial_balance';
  if (!can(req.session.user, code)) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.to);
  const path = detailed
    ? '/accounting/reports/trial-balance-detailed'
    : '/accounting/reports/trial-balance';
  let rowsHtml = '<p class="muted">حدّد الفترة ثم اضغط عرض التقرير.</p>';
  let count = 0;
  let footer = '';
  if (run) {
    const data = await svc.run(detailed ? 'trial_balance_detailed' : 'trial_balance', uid(req), {
      from,
      to,
    });
    if (!data.ok) {
      rowsHtml = `<p class="si-pill si-pill--lock">${esc(data.error || 'خطأ')}</p>`;
    } else {
      const rows = data.rows || [];
      count = rows.length;
      rowsHtml = `
        <div class="si-surface sh-section">
          <div class="si-surface-head"><h2>ميزان المراجعة</h2><span class="si-count">${count}</span></div>
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr>
              <th>الكود</th><th>الحساب</th>
              <th>افتتاحي مدين</th><th>افتتاحي دائن</th>
              <th>حركة مدين</th><th>حركة دائن</th>
              <th>رصيد مدين</th><th>رصيد دائن</th>
            </tr></thead>
            <tbody>
            ${
              rows
                .map(
                  (r) => `<tr>
                <td class="si-num" dir="ltr">${esc(r.code || '')}</td>
                <td>${esc(r.name_ar || '')}</td>
                <td class="si-num" dir="ltr">${money(r.opening_debit)}</td>
                <td class="si-num" dir="ltr">${money(r.opening_credit)}</td>
                <td class="si-num" dir="ltr">${money(r.period_debit)}</td>
                <td class="si-num" dir="ltr">${money(r.period_credit)}</td>
                <td class="si-num" dir="ltr">${money(r.closing_debit)}</td>
                <td class="si-num" dir="ltr">${money(r.closing_credit)}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="8" class="empty">لا أرصدة في الفترة.</td></tr>`
            }
            </tbody>
          </table></div>
        </div>`;
      const t = data.totals || {};
      footer = `<p class="ar-totals">ختامي مدين: <strong dir="ltr">${money(
        t.closing_debit
      )}</strong> — ختامي دائن: <strong dir="ltr">${money(t.closing_credit)}</strong></p>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="${path}">
      <input type="hidden" name="run" value="1">
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>${footer}`;
  sendPage(
    res,
    req,
    detailed ? 'ميزان مراجعة تفصيلي' : 'ميزان المراجعة',
    shell(
      detailed ? 'ميزان مراجعة تفصيلي' : 'ميزان المراجعة',
      `من ${from} إلى ${to}`,
      filters,
      rowsHtml
    )
  );
}

router.get('/accounting/reports/trial-balance', (req, res) => trialBalance(req, res, false));
router.get('/accounting/reports/trial-balance-detailed', (req, res) => trialBalance(req, res, true));

/* ═══════════════ GL / Account statement ═══════════════ */
async function ledgerPage(req, res, title, perm, base) {
  if (!can(req.session.user, perm)) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const accountId = Number(req.query.account_id || 0) || 0;
  const pick = await svc.run('pickers', uid(req), {});
  const accounts = pick.accounts || [];
  let result = '<p class="muted">اختر حساباً وحدد الفترة ثم اضغط عرض.</p>';
  if (accountId > 0) {
    const data = await svc.run(
      base.includes('account-statement') ? 'account_statement' : 'general_ledger',
      uid(req),
      { from, to, account_id: accountId }
    );
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else if (!data.account) {
      result = `<p class="si-pill si-pill--lock">الحساب غير موجود.</p>`;
    } else {
      const acc = data.account;
      const pack = data.pack || {};
      const open = pack.opening || {};
      const openBal = Number(open.balance || 0);
      const lines = pack.lines || [];
      const closeBal = Number(pack.closing_balance || 0);
      result = `
        <div class="si-surface sh-section">
          <div class="si-surface-head">
            <h2>${esc((acc.code || '') + ' — ' + (acc.name_ar || ''))}</h2>
          </div>
          <p>رصيد افتتاحي: <strong dir="ltr">${money(openBal)}</strong>
             — رصيد ختامي: <strong dir="ltr">${money(closeBal)}</strong>
             — حركات: <strong>${lines.length}</strong></p>
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr>
              <th>التاريخ</th><th>رقم القيد</th><th>البيان</th>
              <th>مدين</th><th>دائن</th><th>الرصيد</th>
            </tr></thead>
            <tbody>
            <tr class="ar-open">
              <td colspan="3"><strong>رصيد افتتاحي</strong></td>
              <td></td><td></td>
              <td class="si-num" dir="ltr">${money(openBal)}</td>
            </tr>
            ${
              lines
                .map(
                  (ln) => `<tr>
                <td class="si-num" dir="ltr">${dmy(ln.entry_date)}</td>
                <td class="si-num" dir="ltr">${esc(ln.entry_no || '')}</td>
                <td>${esc(ln.memo || ln.description_ar || '—')}</td>
                <td class="si-num" dir="ltr">${money(ln.debit)}</td>
                <td class="si-num" dir="ltr">${money(ln.credit)}</td>
                <td class="si-num" dir="ltr">${money(ln.running_balance)}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="6" class="empty">لا حركات في الفترة.</td></tr>`
            }
            </tbody>
          </table></div>
        </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="${base}">
      <label>الحساب *
        <select class="si-field" name="account_id" required style="min-width:18rem">
          <option value="">اضغط لاختيار حساب</option>
          ${optList(accounts, accountId, null)}
        </select>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض</button>
    </form>`;
  sendPage(res, req, title, shell(title, 'حركة حساب — رصيد افتتاحي وحركات ختامي', filters, result));
}

router.get('/accounting/general-ledger', (req, res) =>
  ledgerPage(req, res, 'دفتر الأستاذ العام', 'report_general_ledger', '/accounting/general-ledger')
);
router.get('/accounting/reports/account-statement', (req, res) =>
  ledgerPage(
    req,
    res,
    'كشف حساب',
    'report_account_statement',
    '/accounting/reports/account-statement'
  )
);

/* ═══════════════ Income statement ═══════════════ */
router.get('/accounting/reports/income', async (req, res) => {
  if (!can(req.session.user, 'report_income_statement')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.to);
  let result = '<p class="muted">حدّد الفترة ثم اعرض.</p>';
  if (run) {
    const data = await svc.run('income_statement', uid(req), { from, to });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const d = data.data || {};
      // Flexible shapes from PHP
      const rows = d.rows || d.lines || d.sections || [];
      if (Array.isArray(rows) && rows.length && rows[0] && rows[0].label != null) {
        result = `<div class="si-surface sh-section"><div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>البند</th><th>المبلغ</th></tr></thead>
          <tbody>${rows
            .map(
              (r) => `<tr>
              <td>${esc(r.label || r.name_ar || r.title || '')}</td>
              <td class="si-num" dir="ltr">${money(r.amount != null ? r.amount : r.total)}</td>
            </tr>`
            )
            .join('')}</tbody></table></div></div>`;
      } else if (d.revenue != null || d.expense != null) {
        result = `<div class="si-surface sh-section"><div class="si-table-wrap"><table class="si-table">
          <tbody>
            <tr><td>الإيرادات</td><td class="si-num" dir="ltr">${money(d.revenue || d.total_revenue)}</td></tr>
            <tr><td>المصروفات</td><td class="si-num" dir="ltr">${money(d.expense || d.total_expense)}</td></tr>
            <tr><td><strong>صافي</strong></td><td class="si-num" dir="ltr"><strong>${money(
              d.net ?? d.net_income ?? Number(d.revenue || 0) - Number(d.expense || 0)
            )}</strong></td></tr>
          </tbody></table></div>
          <p class="muted">للتقرير التفصيلي الشبيه بـ «الأرباح والخسائر» استخدم شاشة الأرباح والخسائر.</p>
          <p><a class="si-btn" href="/accounting/reports/pl?from=${esc(from)}&to=${esc(
          to
        )}">الأرباح والخسائر</a></p>
        </div>`;
      } else {
        // dump key accounts if structure is nested
        const rev = d.revenues || d.revenue_rows || [];
        const exp = d.expenses || d.expense_rows || [];
        const lines = [...(Array.isArray(rev) ? rev : []), ...(Array.isArray(exp) ? exp : [])];
        result = `<div class="si-surface sh-section"><div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
          <tbody>${
            lines
              .map(
                (r) => `<tr>
              <td>${esc((r.code ? r.code + ' — ' : '') + (r.name_ar || r.label || ''))}</td>
              <td class="si-num" dir="ltr">${money(r.debit || r.period_debit)}</td>
              <td class="si-num" dir="ltr">${money(r.credit || r.period_credit)}</td>
              <td class="si-num" dir="ltr">${money(r.balance || r.amount)}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="4" class="empty">لا بيانات.</td></tr>`
          }</tbody></table></div>
          <p><a class="si-btn si-btn--primary" href="/accounting/reports/pl?from=${esc(
            from
          )}&to=${esc(to)}">عرض الأرباح والخسائر الملخّص</a></p>
        </div>`;
      }
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/income">
      <input type="hidden" name="run" value="1">
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(
    res,
    req,
    'قائمة الدخل',
    shell('قائمة الدخل', `من ${from} إلى ${to}`, filters, result, [
      { label: 'الأرباح والخسائر', href: '/accounting/reports/pl', primary: true },
    ])
  );
});

/* ═══════════════ Balance sheet ═══════════════ */
router.get('/accounting/reports/balance-sheet', async (req, res) => {
  if (!can(req.session.user, 'report_balance_sheet')) return forbid(res);
  const asOf = String(req.query.as_of || req.query.to || todayIso()).slice(0, 10);
  const run = String(req.query.run || '') === '1' || !!req.query.as_of;
  let result = '<p class="muted">اختر تاريخ القطع ثم اعرض.</p>';
  if (run) {
    const data = await svc.run('balance_sheet', uid(req), { as_of: asOf });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const d = data.data || {};
      const assets = d.assets || d.asset_rows || d.assets_rows || [];
      const liabilities = d.liabilities || d.liability_rows || [];
      const equity = d.equity || d.equity_rows || [];
      function block(title, rows) {
        const list = Array.isArray(rows) ? rows : [];
        return `
          <h3>${esc(title)}</h3>
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr><th>الحساب</th><th>المبلغ</th></tr></thead>
            <tbody>${
              list
                .map(
                  (r) => `<tr>
                <td>${esc((r.code ? r.code + ' — ' : '') + (r.name_ar || r.label || ''))}</td>
                <td class="si-num" dir="ltr">${money(
                  r.amount != null ? r.amount : r.balance != null ? r.balance : r.total
                )}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="2" class="empty">—</td></tr>`
            }</tbody>
          </table></div>`;
      }
      result = `<div class="si-surface sh-section">
        <p>حتى تاريخ: <strong dir="ltr">${dmy(asOf)}</strong></p>
        ${block('الأصول', assets)}
        ${block('الخصوم', liabilities)}
        ${block('حقوق الملكية', equity)}
        ${
          d.totals
            ? `<p class="ar-totals">أصول: ${money(d.totals.assets)} — خصوم+حقوق: ${money(
                d.totals.liabilities_equity || d.totals.liabilities
              )}</p>`
            : ''
        }
      </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/balance-sheet">
      <input type="hidden" name="run" value="1">
      <label>حتى تاريخ *
        <input class="si-field si-field--mono" type="date" name="as_of" value="${esc(asOf)}" required>
      </label>
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'الميزانية العمومية', shell('الميزانية العمومية', 'حتى تاريخ ' + asOf, filters, result));
});

/* ═══════════════ Receivables ═══════════════ */
router.get('/accounting/reports/receivables', async (req, res) => {
  if (!can(req.session.user, 'report_receivables')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const customerId = Number(req.query.customer_id || 0) || 0;
  const repId = Number(req.query.sales_rep_id || 0) || 0;
  const mode = String(req.query.mode || 'detail') === 'summary' ? 'summary' : 'detail';
  const run = String(req.query.run || '') === '1';
  const pick = await svc.run('pickers', uid(req), {});
  let result = '<p class="muted">حدّد المعايير ثم اضغط عرض التقرير.</p>';
  if (run) {
    const data = await svc.run('receivables', uid(req), {
      from,
      to,
      customer_id: customerId,
      sales_rep_id: repId,
      mode,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const b = data.built || {};
      const totals = b.totals || {};
      if (mode === 'summary') {
        const rows = b.summary_rows || [];
        result = `<div class="si-surface sh-section">
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr><th>العميل</th><th>المبيعات</th><th>التحصيل</th><th>الذمم</th></tr></thead>
            <tbody>${
              rows
                .map(
                  (r) => `<tr>
                <td>${esc(r.customer_name || r.name_ar || '')}</td>
                <td class="si-num" dir="ltr">${money(r.sales_total || r.sales)}</td>
                <td class="si-num" dir="ltr">${money(r.collections_total || r.collections)}</td>
                <td class="si-num" dir="ltr">${money(r.balance_due || r.balance)}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="4" class="empty">لا بيانات.</td></tr>`
            }</tbody>
          </table></div>
          <p class="ar-totals">إجمالي الذمم: <strong dir="ltr">${money(totals.balance_due)}</strong></p>
        </div>`;
      } else {
        const rows = b.detail_rows || [];
        result = `<div class="si-surface sh-section">
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr>
              <th>التاريخ</th><th>العميل</th><th>المستند</th><th>مدين</th><th>دائن</th><th>الرصيد</th>
            </tr></thead>
            <tbody>${
              rows
                .map(
                  (r) => `<tr>
                <td class="si-num" dir="ltr">${dmy(r.txn_date || r.doc_date)}</td>
                <td>${esc(r.customer_name || '')}</td>
                <td>${esc(r.doc_no || r.memo || r.txn_type || '')}</td>
                <td class="si-num" dir="ltr">${money(r.debit)}</td>
                <td class="si-num" dir="ltr">${money(r.credit)}</td>
                <td class="si-num" dir="ltr">${money(r.balance)}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="6" class="empty">لا حركات.</td></tr>`
            }</tbody>
          </table></div>
          <p class="ar-totals">الذمم المستحقة: <strong dir="ltr">${money(totals.balance_due)}</strong></p>
        </div>`;
      }
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/receivables">
      <input type="hidden" name="run" value="1">
      <label>العميل
        <select class="si-field" name="customer_id">${optList(pick.customers, customerId, 'جميع العملاء')}</select>
      </label>
      <label>المندوب
        <select class="si-field" name="sales_rep_id">${optList(pick.reps, repId, 'جميع المندوبين')}</select>
      </label>
      ${dateFields(from, to)}
      <div class="ar-mode">
        <label><input type="radio" name="mode" value="detail" ${mode === 'detail' ? 'checked' : ''}> تفصيلي</label>
        <label><input type="radio" name="mode" value="summary" ${mode === 'summary' ? 'checked' : ''}> إجمالي</label>
      </div>
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'كشف ذمم العملاء', shell('كشف ذمم العملاء', '', filters, result));
});

/* ═══════════════ Aging ═══════════════ */
router.get('/accounting/reports/receivables-aging', async (req, res) => {
  if (!can(req.session.user, 'report_receivables_aging')) return forbid(res);
  const asOf = String(req.query.as_of || todayIso()).slice(0, 10);
  const customerId = Number(req.query.customer_id || 0) || 0;
  const repId = Number(req.query.sales_rep_id || 0) || 0;
  const mode = String(req.query.mode || 'summary') === 'detail' ? 'detail' : 'summary';
  const run = String(req.query.run || '') === '1';
  const pick = await svc.run('pickers', uid(req), {});
  let result = '<p class="muted">حدّد التاريخ ثم اعرض. يُحسب العمر FIFO من تاريخ الفاتورة.</p>';
  if (run) {
    const data = await svc.run('receivables_aging', uid(req), {
      as_of: asOf,
      customer_id: customerId,
      sales_rep_id: repId,
      mode,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const b = data.built || {};
      const rows = b.rows || b.summary_rows || b.customers || [];
      const labels = b.bucket_labels || {
        d0_30: '0–30',
        d31_60: '31–60',
        d61_90: '61–90',
        d91_120: '91–120',
        d121p: '121+',
      };
      result = `<div class="si-surface sh-section">
        <p class="muted">يُحسب عمر الذمة حتى ${dmy(asOf)} مع خصم التحصيلات (FIFO).</p>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr>
            <th>العميل</th><th>الذمم المستحقة</th>
            <th>${esc(labels.d0_30 || '0-30')}</th>
            <th>${esc(labels.d31_60 || '31-60')}</th>
            <th>${esc(labels.d61_90 || '61-90')}</th>
            <th>${esc(labels.d91_120 || '91-120')}</th>
            <th>${esc(labels.d121p || '121+')}</th>
          </tr></thead>
          <tbody>${
            rows
              .map((r) => {
                const bk = r.buckets || r;
                return `<tr>
                <td>${esc(r.customer_name || r.name_ar || r.code || '')}</td>
                <td class="si-num" dir="ltr">${money(r.balance_due || r.total || bk.total)}</td>
                <td class="si-num" dir="ltr">${money(bk.d0_30 || bk['0_30'] || 0)}</td>
                <td class="si-num" dir="ltr">${money(bk.d31_60 || 0)}</td>
                <td class="si-num" dir="ltr">${money(bk.d61_90 || 0)}</td>
                <td class="si-num" dir="ltr">${money(bk.d91_120 || 0)}</td>
                <td class="si-num" dir="ltr">${money(bk.d121p || bk.d120_plus || 0)}</td>
              </tr>`;
              })
              .join('') || `<tr><td colspan="7" class="empty">لا ذمم.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/receivables-aging">
      <input type="hidden" name="run" value="1">
      <label>حتى تاريخ *
        <input class="si-field si-field--mono" type="date" name="as_of" value="${esc(asOf)}" required>
      </label>
      <label>المندوب
        <select class="si-field" name="sales_rep_id">${optList(pick.reps, repId, 'جميع المندوبين')}</select>
      </label>
      <label>العميل
        <select class="si-field" name="customer_id">${optList(pick.customers, customerId, 'جميع العملاء')}</select>
      </label>
      <div class="ar-mode">
        <label><input type="radio" name="mode" value="summary" ${mode === 'summary' ? 'checked' : ''}> إجمالي</label>
        <label><input type="radio" name="mode" value="detail" ${mode === 'detail' ? 'checked' : ''}> تفصيلي</label>
      </div>
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'أعمار الذمم', shell('أعمار الذمم', '', filters, result));
});

/* ═══════════════ Payables ═══════════════ */
router.get('/accounting/reports/payables', async (req, res) => {
  if (!can(req.session.user, 'report_supplier_payables')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const supplierId = Number(req.query.supplier_id || 0) || 0;
  const run = String(req.query.run || '') === '1';
  const pick = await svc.run('pickers', uid(req), {});
  let result = '<p class="muted">اختر الفترة ثم اعرض. لاختيار مورد واحد يُعرض كشف حركاته.</p>';
  if (run) {
    const data = await svc.run('payables', uid(req), { from, to, supplier_id: supplierId });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else if (data.built) {
      const rows = (data.built.rows || data.built.lines || []).map
        ? data.built.rows || data.built.lines || []
        : [];
      const lines = Array.isArray(rows) ? rows : [];
      result = `<div class="si-surface sh-section">
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>التاريخ</th><th>المستند</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
          <tbody>${
            lines
              .map(
                (r) => `<tr>
              <td class="si-num" dir="ltr">${dmy(r.txn_date || r.doc_date)}</td>
              <td>${esc(r.doc_no || r.memo || r.txn_type || '')}</td>
              <td class="si-num" dir="ltr">${money(r.debit)}</td>
              <td class="si-num" dir="ltr">${money(r.credit)}</td>
              <td class="si-num" dir="ltr">${money(r.balance)}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="5" class="empty">لا حركات.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    } else {
      const rows = data.rows || [];
      result = `<div class="si-surface sh-section">
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>المورد</th><th>الرصيد حتى ${dmy(to)}</th></tr></thead>
          <tbody>${
            rows
              .map(
                (r) => `<tr>
              <td>${esc((r.code ? r.code + ' — ' : '') + (r.name_ar || ''))}</td>
              <td class="si-num" dir="ltr">${money(r.balance)}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="2" class="empty">لا موردين.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/payables">
      <input type="hidden" name="run" value="1">
      <label>المورد
        <select class="si-field" name="supplier_id">${optList(pick.suppliers, supplierId, 'جميع الموردين')}</select>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'كشف ذمم الموردين', shell('كشف ذمم الموردين', '', filters, result));
});

/* ═══════════════ Party statement ═══════════════ */
router.get('/accounting/reports/party-statement', async (req, res) => {
  if (!can(req.session.user, 'report_party_statement')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const partyType = String(req.query.party_type || 'customer') === 'supplier' ? 'supplier' : 'customer';
  const partyId = Number(req.query.party_id || 0) || 0;
  const run = String(req.query.run || '') === '1';
  const pick = await svc.run('pickers', uid(req), {});
  let result = '<p class="muted">اختر الطرف والفترة ثم اعرض.</p>';
  if (run && partyId > 0) {
    const data = await svc.run('party_statement', uid(req), {
      from,
      to,
      party_type: partyType,
      party_id: partyId,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const b = data.built || {};
      const lines = b.rows || b.lines || b.movements || [];
      result = `<div class="si-surface sh-section">
        <p>رصيد افتتاحي: <strong dir="ltr">${money(b.opening_balance || b.opening)}</strong>
           — رصيد ختامي: <strong dir="ltr">${money(b.closing_balance || b.closing || b.balance)}</strong></p>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>التاريخ</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
          <tbody>${
            (Array.isArray(lines) ? lines : [])
              .map(
                (r) => `<tr>
              <td class="si-num" dir="ltr">${dmy(r.txn_date || r.doc_date)}</td>
              <td>${esc(r.description || r.memo || r.doc_no || r.txn_type || '')}</td>
              <td class="si-num" dir="ltr">${money(r.debit)}</td>
              <td class="si-num" dir="ltr">${money(r.credit)}</td>
              <td class="si-num" dir="ltr">${money(r.balance)}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="5" class="empty">لا حركات.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  }
  const partyOpts =
    partyType === 'supplier'
      ? optList(pick.suppliers, partyId, null)
      : optList(pick.customers, partyId, null);
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/party-statement" id="party-form">
      <input type="hidden" name="run" value="1">
      <div class="ar-mode">
        <label><input type="radio" name="party_type" value="customer" ${
          partyType === 'customer' ? 'checked' : ''
        } onchange="this.form.submit()"> عميل</label>
        <label><input type="radio" name="party_type" value="supplier" ${
          partyType === 'supplier' ? 'checked' : ''
        } onchange="this.form.elements.run.value='';this.form.submit()"> مورد</label>
      </div>
      <label>الطرف *
        <select class="si-field" name="party_id" required>
          <option value="">— اختر —</option>
          ${partyOpts}
        </select>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'كشف حساب مورد - عميل', shell('كشف حساب مورد - عميل', '', filters, result));
});

/* ═══════════════ Oracle ═══════════════ */
/* توافق روابط قديمة oracle_statement */
router.get(
  ['/accounting/reports/oracle-statement', '/accounting/reports/oracle_statement'],
  async (req, res) => {

  if (!can(req.session.user, 'report_oracle_customer_statement')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  let accountNo = String(req.query.account_no || req.query.account || '')
    .trim()
    .replace(/\D+/g, '');
  let customerId = Number(req.query.customer_id || 0) || 0;
  const run = String(req.query.run || '') === '1';
  const wantExcel = String(req.query.excel || '') === '1';

  let customers = [];
  try {
    customers = await db.query(
      `SELECT id, code, name_ar,
              COALESCE(NULLIF(TRIM(oracle_key), ''), code) AS acc_no
       FROM crm_customer
       WHERE is_active = 1
         AND (
           (oracle_key IS NOT NULL AND TRIM(oracle_key) <> '')
           OR code LIKE '112%'
         )
       ORDER BY name_ar
       LIMIT 8000`
    );
  } catch {
    customers = [];
  }

  let selectedParty = null;
  if (customerId > 0) {
    selectedParty = customers.find((c) => Number(c.id) === customerId) || null;
    if (!selectedParty) {
      try {
        const rows = await db.query(
          `SELECT id, code, name_ar,
                  COALESCE(NULLIF(TRIM(oracle_key), ''), code) AS acc_no
           FROM crm_customer WHERE id = ? LIMIT 1`,
          [customerId]
        );
        selectedParty = rows[0] || null;
      } catch {
        /* ignore */
      }
    }
  }
  if (!selectedParty && accountNo) {
    selectedParty =
      customers.find(
        (c) =>
          String(c.acc_no || '').replace(/\D+/g, '') === accountNo ||
          String(c.code || '').replace(/\D+/g, '') === accountNo
      ) || null;
    if (selectedParty) customerId = Number(selectedParty.id);
  }
  if (selectedParty && !accountNo) {
    accountNo = String(selectedParty.acc_no || selectedParty.code || '').replace(/\D+/g, '');
  }

  /** مندوب مرتبط بالعميل (الحقل الرئيسي ثم جدول الربط المتعدد) */
  let salesRepName = '';
  if (customerId > 0) {
    try {
      const repRows = await db.query(
        `SELECT COALESCE(r.name_ar, '') AS sales_rep_name
         FROM crm_customer c
         LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
         WHERE c.id = ?
         LIMIT 1`,
        [customerId]
      );
      salesRepName = String(repRows[0]?.sales_rep_name || '').trim();
      if (!salesRepName) {
        const multi = await db.query(
          `SELECT GROUP_CONCAT(r.name_ar ORDER BY ccsr.sort_order SEPARATOR '، ') AS names
           FROM crm_customer_sales_rep ccsr
           INNER JOIN crm_sales_rep r ON r.id = ccsr.sales_rep_id
           WHERE ccsr.customer_id = ?
           GROUP BY ccsr.customer_id`,
          [customerId]
        );
        salesRepName = String(multi[0]?.names || '').trim();
      }
    } catch {
      salesRepName = '';
    }
  }

  const searchLabel = selectedParty
    ? `${String(selectedParty.code || selectedParty.acc_no || '')} — ${String(
        selectedParty.name_ar || ''
      )}`
    : accountNo
      ? accountNo
      : '';

  // ── تصدير Excel بنفس شكل التقرير ──
  if (wantExcel) {
    if (!accountNo) {
      return res.status(400).send('اختر عميلاً أو رقم الحساب قبل تصدير Excel.');
    }
    const data = await svc.run('oracle_statement', uid(req), {
      from,
      to,
      account_no: accountNo,
    });
    if (!data || data.ok === false) {
      return res
        .status(502)
        .send(String(data?.error || data?.message || 'تعذر جلب كشف الحساب من Oracle.'));
    }
    const lines = Array.isArray(data.lines)
      ? data.lines
      : Array.isArray(data.rows)
        ? data.rows
        : [];
    const name =
      String(data.name || '').trim() ||
      (selectedParty ? String(selectedParty.name_ar || '') : '') ||
      '';
    const partyCode = String(
      (selectedParty && (selectedParty.code || selectedParty.acc_no)) || data.account || accountNo
    );
    const nTrans = lines.filter((r) => !r.is_opening).length;
    const fromLabel = isoToDmy(from);
    const toLabel = isoToDmy(to);

    const bodyRowsHtml =
      lines
        .map((r) => {
          const isOpen = !!r.is_opening;
          const docLabel = isOpen
            ? '—'
            : [r.doc_type, r.doc_no].filter(Boolean).join(' · ') || String(r.doc_no || '—');
          const debit =
            isOpen && !Number(r.debit) ? '—' : numPlain(r.debit);
          const credit =
            isOpen && !Number(r.credit) ? '—' : numPlain(r.credit);
          const debitCls = debit === '—' ? '' : ' class="num"';
          const creditCls = credit === '—' ? '' : ' class="num"';
          return `<tr class="${isOpen ? 'open' : ''}">
            <td class="date">${esc(isoToDmy(r.trn_date))}</td>
            <td class="desc">${esc(docLabel)}</td>
            <td class="desc">${esc(String(r.description || ''))}</td>
            <td${debitCls}>${esc(debit)}</td>
            <td${creditCls}>${esc(credit)}</td>
            <td class="num"><strong>${esc(numPlain(r.balance))}</strong></td>
          </tr>`;
        })
        .join('') ||
      `<tr><td colspan="6" style="text-align:center;color:#64748b">${esc(
        data.message || 'لا توجد حركات في هذه الفترة.'
      )}</td></tr>`;

    const cheques = Array.isArray(data.cheques) ? data.cheques : [];
    let chequesHtml = '';
    if (cheques.length) {
      chequesHtml = `
        <table class="ora-xl-meta" style="width:100%"><tr>
          <td class="ora-xl-chq-title" colspan="4">الشيكات قيد التحصيل</td>
        </tr></table>
        <table class="ora-xl-chq">
          <thead>
            <tr>
              <th>الشيك</th>
              <th>التاريخ</th>
              <th>قيمة الشيك</th>
              <th>تاريخ القبض</th>
            </tr>
          </thead>
          <tbody>
            ${cheques
              .map(
                (c) => `<tr>
              <td dir="ltr">${esc(String(c.chq_no || ''))}</td>
              <td class="date" dir="ltr">${esc(isoToDmy(c.chq_date))}</td>
              <td class="num">${esc(numPlain(c.amount))}</td>
              <td class="date" dir="ltr">${esc(
                isoToDmy(c.receipt_date || c.recv_date || c.chq_recv_date || '') || ''
              )}</td>
            </tr>`
              )
              .join('')}
            <tr class="foot">
              <td colspan="2">مجموع الشيكات قيد التحصيل</td>
              <td class="num"><strong>${esc(numPlain(data.cheque_total))}</strong></td>
              <td></td>
            </tr>
          </tbody>
        </table>`;
    }

    const inner = `
      <div class="ora-xl-title">كشف حساب تفصيلي Oracle</div>
      <table class="ora-xl-meta" style="width:100%">
        <tr>
          <td>
            <span class="lbl">اسم العميل:</span>
            <span class="val">${esc(name || '—')}</span>
          </td>
          <td class="ora-xl-period" rowspan="3">
            من ${esc(fromLabel)} إلى ${esc(toLabel)}
          </td>
        </tr>
        <tr>
          <td>
            <span class="lbl">المندوب:</span>
            <span class="val">${esc(salesRepName || '—')}</span>
          </td>
        </tr>
        <tr>
          <td>
            <span class="lbl">رقم الحساب:</span>
            <span class="val" dir="ltr">${esc(partyCode || '—')}</span>
            &nbsp;&nbsp;
            <span class="val">${nTrans} حركة</span>
          </td>
        </tr>
      </table>
      <table class="spacer"><tr><td></td></tr></table>
      <table class="ora-xl-data">
        <thead>
          <tr>
            <th>التاريخ</th>
            <th>المستند</th>
            <th>البيان</th>
            <th>مدين</th>
            <th>دائن</th>
            <th>الرصيد</th>
          </tr>
        </thead>
        <tbody>
          ${bodyRowsHtml}
          <tr class="foot">
            <td colspan="3">الإجمالي</td>
            <td class="num">${esc(numPlain(data.total_debit))}</td>
            <td class="num">${esc(numPlain(data.total_credit))}</td>
            <td class="num"><strong>${esc(numPlain(data.balance))}</strong></td>
          </tr>
        </tbody>
      </table>
      ${chequesHtml}`;

    const fname = `statement_${partyCode || 'x'}_${String(from).slice(0, 10)}_${String(to).slice(0, 10)}`;
    return sendExcelHtml(res, fname, inner, 'كشف حساب');
  }

  let result = `
    <div class="ora-empty no-print">
      <p>ابحث باسم العميل أو رقم حسابه (مثل 112…) ثم اختر من القائمة وحدّد الفترة واضغط «عرض التقرير».</p>
    </div>`;

  if (run && accountNo) {
    const data = await svc.run('oracle_statement', uid(req), {
      from,
      to,
      account_no: accountNo,
    });

    if (!data || data.ok === false) {
      const errMsg =
        data?.error ||
        data?.message ||
        'تعذر الاتصال بـ Oracle. تحقق من config/oracle.local.php ومشغّلات PHP على السيرفر.';
      result = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(errMsg)}</p>`;
    } else {
      const lines = Array.isArray(data.lines)
        ? data.lines
        : Array.isArray(data.rows)
          ? data.rows
          : [];
      const name =
        String(data.name || '').trim() ||
        (selectedParty ? String(selectedParty.name_ar || '') : '') ||
        '';
      const cheques = Array.isArray(data.cheques) ? data.cheques : [];
      const nTrans = lines.filter((r) => !r.is_opening).length;

      const head = `
        <header class="ora-stmt-head print-area">
          <div class="ora-stmt-head__party">
            <h2 class="ora-stmt-name"><span class="ora-stmt-label">اسم العميل:</span> ${esc(name || '—')}</h2>
            <p class="ora-stmt-rep"><span class="ora-stmt-label">المندوب:</span> ${esc(salesRepName || '—')}</p>
            <p class="ora-stmt-count">${nTrans} حركة</p>
          </div>
          <div class="ora-stmt-head__period">
            <span class="ora-stmt-label">من</span>
            <strong dir="ltr">${dmy(from)}</strong>
            <span class="ora-stmt-label">إلى</span>
            <strong dir="ltr">${dmy(to)}</strong>
          </div>
        </header>`;

      const bodyRows =
        lines
          .map((r) => {
            const isOpen = !!r.is_opening;
            const docLabel = isOpen
              ? '—'
              : [r.doc_type, r.doc_no].filter(Boolean).join(' · ') || String(r.doc_no || '—');
            return `<tr class="${isOpen ? 'ora-row-open is-opening' : ''}">
              <td class="ora-c-date" dir="ltr">${dmy(r.trn_date)}</td>
              <td class="ora-c-doc">${esc(docLabel)}</td>
              <td class="ora-c-desc">${esc(String(r.description || ''))}</td>
              <td class="ora-c-amt" dir="ltr">${
                isOpen && !Number(r.debit) ? '—' : money(r.debit)
              }</td>
              <td class="ora-c-amt" dir="ltr">${
                isOpen && !Number(r.credit) ? '—' : money(r.credit)
              }</td>
              <td class="ora-c-bal" dir="ltr"><strong>${money(r.balance)}</strong></td>
            </tr>`;
          })
          .join('') ||
        `<tr><td colspan="6" class="empty">${esc(
          data.message || 'لا توجد حركات في هذه الفترة.'
        )}</td></tr>`;

      const foot = `
        <tbody class="ora-totals-body">
          <tr class="ora-foot hx-print-total-row">
            <td colspan="3">الإجمالي</td>
            <td dir="ltr">${money(data.total_debit)}</td>
            <td dir="ltr">${money(data.total_credit)}</td>
            <td dir="ltr"><strong>${money(data.balance)}</strong></td>
          </tr>
        </tbody>`;

      let chequesHtml = '';
      if (cheques.length) {
        chequesHtml = `
          <section class="ora-stmt-cheques print-area" aria-label="الشيكات قيد التحصيل">
            <h3 class="ora-stmt-cheques__title">الشيكات قيد التحصيل</h3>
            <div class="ora-stmt-chq-wrap">
              <table class="ora-stmt-chq-table">
                <colgroup>
                  <col class="col-chq-no">
                  <col class="col-chq-date">
                  <col class="col-chq-amt">
                  <col class="col-chq-recv">
                </colgroup>
                <thead>
                  <tr>
                    <th>الشيك</th>
                    <th>التاريخ</th>
                    <th class="col-money">قيمة الشيك</th>
                    <th>تاريخ القبض</th>
                  </tr>
                </thead>
                <tbody>
                  ${cheques
                    .map(
                      (c) => `<tr>
                    <td dir="ltr">${esc(String(c.chq_no || ''))}</td>
                    <td class="col-chq-date" dir="ltr">${dmy(c.chq_date)}</td>
                    <td class="col-money" dir="ltr">${money(c.amount)}</td>
                    <td class="col-chq-recv" dir="ltr">${dmy(c.receipt_date || c.recv_date || c.chq_recv_date || '')}</td>
                  </tr>`
                    )
                    .join('')}
                </tbody>
                <tfoot>
                  <tr class="ora-stmt-chq-total">
                    <td colspan="2">مجموع الشيكات قيد التحصيل</td>
                    <td class="col-money" dir="ltr"><strong>${money(data.cheque_total)}</strong></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>`;
      }

      result = `<div class="ora-stmt print-area">
        ${head}
        <div class="si-surface sh-section ora-stmt-body">
          <div class="si-table-wrap">
            <table class="si-table ora-table" id="ora-stmt-table">
              <thead><tr>
                <th class="ora-c-date">التاريخ</th>
                <th class="ora-c-doc">المستند</th>
                <th class="ora-c-desc">البيان</th>
                <th class="ora-c-amt">مدين</th>
                <th class="ora-c-amt">دائن</th>
                <th class="ora-c-bal">الرصيد</th>
              </tr></thead>
              <tbody>${bodyRows}</tbody>
              ${foot}
            </table>
          </div>
        </div>
        ${chequesHtml}
      </div>`;
    }
  } else if (run && !accountNo) {
    result = `<p class="si-pill si-pill--lock" style="display:inline-block">اختر عميلاً من البحث الذكي أو أدخل رقم الحساب.</p>`;
  }

  const catalogJson = JSON.stringify(
    customers.map((c) => ({
      id: Number(c.id),
      code: String(c.code || ''),
      acc: String(c.acc_no || c.code || '').replace(/\D+/g, ''),
      name: String(c.name_ar || ''),
    }))
  ).replace(/</g, '\\u003c');

  const excelQs = new URLSearchParams({
    run: '1',
    excel: '1',
    from: String(from || ''),
    to: String(to || ''),
    account_no: String(accountNo || ''),
    customer_id: String(customerId || 0),
  }).toString();
  const excelHref = `/accounting/reports/oracle-statement?${excelQs}`;
  const hasReport = !!(run && accountNo);

  const filters = `
    <form class="ora-filters no-print" method="get" action="/accounting/reports/oracle-statement" id="ora-stmt-form" autocomplete="off">
      <input type="hidden" name="run" value="1">
      <input type="hidden" name="customer_id" id="ora-cid" value="${customerId || 0}">

      <div class="ora-filters__grid">
        <label class="ora-field ora-field--search">
          <span>العميل <em>(بحث بالاسم أو الرقم)</em></span>
          <div class="ora-search-wrap si-cust-wrap">
            <input type="search" class="si-field ora-search-input" id="ora-search"
                   value="${esc(searchLabel)}"
                   placeholder="ابحث بالاسم أو رقم العميل…"
                   autocomplete="off" spellcheck="false">
            <div class="si-suggest ora-suggest" id="ora-suggest" hidden></div>
          </div>
        </label>

        <label class="ora-field ora-field--acc">
          <span class="ora-field__hint">رقم العميل</span>
          <input class="si-field si-field--mono" name="account_no" id="ora-account"
                 value="${esc(accountNo)}" placeholder="" dir="ltr" inputmode="numeric" autocomplete="off">
        </label>

        <label class="ora-field">
          <span>من تاريخ</span>
          <input class="si-field" type="date" name="from" value="${esc(from)}">
        </label>
        <label class="ora-field">
          <span>إلى تاريخ</span>
          <input class="si-field" type="date" name="to" value="${esc(to)}">
        </label>

        <div class="ora-field ora-field--actions">
          <button class="si-btn si-btn--primary ora-run-btn" type="submit">عرض التقرير</button>
          ${
            hasReport
              ? `<a class="si-btn no-print" href="${esc(excelHref)}" id="ora-excel-btn">Excel</a>
                 ${ui.siPrintBtnHtml('طباعة')}`
              : ''
          }
        </div>
      </div>
    </form>
    <script type="application/json" id="ora-catalog">${catalogJson}</script>
    <script>
      (function () {
        var catalog = [];
        try {
          catalog = JSON.parse(document.getElementById('ora-catalog').textContent || '[]');
        } catch (e) { catalog = []; }
        var search = document.getElementById('ora-search');
        var suggest = document.getElementById('ora-suggest');
        var cid = document.getElementById('ora-cid');
        var acc = document.getElementById('ora-account');
        if (!search || !suggest || !cid || !acc) return;

        function norm(s) {
          return String(s || '').toLowerCase().replace(/\\s+/g, ' ').trim();
        }
        function digits(s) {
          return String(s || '').replace(/\\D+/g, '');
        }
        function labelOf(c) {
          return (c.code || c.acc || '') + ' — ' + (c.name || '');
        }
        function pick(c) {
          cid.value = String(c.id || 0);
          acc.value = c.acc || digits(c.code) || '';
          search.value = labelOf(c);
          suggest.hidden = true;
          suggest.innerHTML = '';
        }
        function render(list) {
          if (!list.length) {
            suggest.innerHTML = '<div class="ora-suggest-empty">لا نتائج مطابقة</div>';
            suggest.hidden = false;
            return;
          }
          suggest.innerHTML = list
            .map(function (c) {
              return (
                '<button type="button" class="ora-suggest-item" data-id="' +
                c.id +
                '">' +
                '<span class="ora-suggest-acc" dir="ltr">' +
                String(c.acc || c.code || '') +
                '</span>' +
                '<span class="ora-suggest-name">' +
                String(c.name || '') +
                '</span>' +
                '</button>'
              );
            })
            .join('');
          suggest.hidden = false;
          suggest.querySelectorAll('.ora-suggest-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var id = Number(btn.getAttribute('data-id') || 0);
              var c = catalog.find(function (x) { return Number(x.id) === id; });
              if (c) pick(c);
            });
          });
        }
        function filter(q) {
          q = norm(q);
          var qd = digits(q);
          if (!q) return catalog.slice(0, 40);
          return catalog
            .filter(function (c) {
              var name = norm(c.name);
              var code = norm(c.code);
              var a = digits(c.acc || c.code);
              if (name.indexOf(q) !== -1 || code.indexOf(q) !== -1) return true;
              if (qd && a.indexOf(qd) !== -1) return true;
              return false;
            })
            .slice(0, 40);
        }
        var t = null;
        function openList(q) {
          render(filter(q || ''));
          suggest.hidden = false;
          suggest.removeAttribute('hidden');
        }
        search.addEventListener('input', function () {
          cid.value = '0';
          clearTimeout(t);
          t = setTimeout(function () {
            openList(search.value);
          }, 120);
        });
        search.addEventListener('focus', function () {
          openList(search.value);
        });
        search.addEventListener('click', function () {
          if (suggest.hidden) openList(search.value);
        });
        search.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            suggest.hidden = true;
            suggest.setAttribute('hidden', '');
          }
          if (e.key === 'Enter') {
            var first = suggest.querySelector('.ora-suggest-item');
            if (first && !suggest.hidden) {
              e.preventDefault();
              first.click();
            }
          }
        });
        document.addEventListener('click', function (e) {
          if (!suggest.contains(e.target) && e.target !== search) {
            suggest.hidden = true;
            suggest.setAttribute('hidden', '');
          }
        });
        acc.addEventListener('input', function () {
          cid.value = '0';
          var d = digits(acc.value);
          if (!d) return;
          var hit = catalog.find(function (c) {
            return digits(c.acc || c.code) === d;
          });
          if (hit) {
            cid.value = String(hit.id);
            search.value = labelOf(hit);
          }
        });
      })();
    </script>`;

  const extraActions = hasReport
    ? [{ label: 'Excel', href: excelHref, className: 'si-btn--excel' }]
    : [];

  sendPage(
    res,
    req,
    'كشف حساب تفصيلي Oracle',
    shell(
      'كشف حساب تفصيلي Oracle',
      'بحث ذكي بالاسم أو رقم الحساب · بيانات من Oracle',
      filters,
      result,
      extraActions
    )
  );
});

/* ═══════════════ فاتورة بيع Oracle — نُقلت إلى المبيعات ═══════════════ */
router.get('/accounting/reports/oracle-sales-invoice', (req, res) => {
  const q = req.url.includes('?') ? req.url.slice(req.url.indexOf('?')) : '';
  return res.redirect(302, '/sales/reports/oracle-sales-invoice' + q);
});

/* ═══════════════ Checks reports ═══════════════ */
router.get('/accounting/reports/checks-in', async (req, res) => {
  if (!can(req.session.user, 'report_incoming_checks')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const customerId = Number(req.query.customer_id || 0) || 0;
  const checkNo = String(req.query.check_no || '').trim();
  const dateField = String(req.query.date_field || 'voucher') === 'due' ? 'due' : 'voucher';
  const posted = ['all', 'posted', 'unposted'].includes(String(req.query.posted || 'all'))
    ? String(req.query.posted || 'all')
    : 'all';
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.to);
  const pick = await svc.run('pickers', uid(req), {});
  let result = '';
  if (run) {
    const data = await svc.run('checks_in', uid(req), {
      from,
      to,
      customer_id: customerId,
      check_no: checkNo,
      date_field: dateField,
      posted,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const rows = data.rows || [];
      result = `<div class="si-surface sh-section">
        <div class="si-surface-head"><h2>الشيكات الواردة</h2>
          <span class="si-count">${rows.length} — ${money(data.sum)}</span></div>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr>
            <th>تاريخ السند</th><th>رقم السند</th><th>العميل</th>
            <th>رقم الشيك</th><th>البنك</th><th>المبلغ</th><th>الاستحقاق</th><th>الحالة</th>
          </tr></thead>
          <tbody>${
            rows
              .map(
                (r) => `<tr>
              <td class="si-num" dir="ltr">${dmy(r.voucher_date)}</td>
              <td class="si-num" dir="ltr">${esc(r.voucher_no || '')}</td>
              <td>${esc(r.customer_name || r.party_name || '')}</td>
              <td class="si-num" dir="ltr">${esc(r.check_no || '')}</td>
              <td>${esc(r.bank_name || '')}</td>
              <td class="si-num" dir="ltr">${money(r.check_amount || r.amount)}</td>
              <td class="si-num" dir="ltr">${dmy(r.due_date)}</td>
              <td>${Number(r.is_posted) === 1 ? 'مرحّل' : 'مسودة'}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="8" class="empty">لا شيكات.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  } else {
    result = '<p class="muted">حدّد المعايير ثم اعرض.</p>';
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/checks-in">
      <input type="hidden" name="run" value="1">
      <label>العميل
        <select class="si-field" name="customer_id">${optList(pick.customers, customerId, 'جميع العملاء')}</select>
      </label>
      <label>رقم الشيك
        <input class="si-field si-field--mono" name="check_no" value="${esc(checkNo)}">
      </label>
      <label>الفترة حسب
        <select class="si-field" name="date_field">
          <option value="voucher" ${dateField === 'voucher' ? 'selected' : ''}>تاريخ الشيك/السند</option>
          <option value="due" ${dateField === 'due' ? 'selected' : ''}>تاريخ الاستحقاق</option>
        </select>
      </label>
      <label>حالة الترحيل
        <select class="si-field" name="posted">
          <option value="all" ${posted === 'all' ? 'selected' : ''}>الكل</option>
          <option value="posted" ${posted === 'posted' ? 'selected' : ''}>مرحّل</option>
          <option value="unposted" ${posted === 'unposted' ? 'selected' : ''}>غير مرحّل</option>
        </select>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'تقرير الشيكات الواردة', shell('تقرير الشيكات الواردة', '', filters, result));
});

router.get('/accounting/reports/checks-out', async (req, res) => {
  if (!can(req.session.user, 'report_outgoing_checks')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const supplierId = Number(req.query.supplier_id || 0) || 0;
  const checkNo = String(req.query.check_no || '').trim();
  const dateField = String(req.query.date_field || 'voucher') === 'due' ? 'due' : 'voucher';
  const posted = ['all', 'posted', 'unposted'].includes(String(req.query.posted || 'all'))
    ? String(req.query.posted || 'all')
    : 'all';
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.to);
  const pick = await svc.run('pickers', uid(req), {});
  let result = '';
  if (run) {
    const data = await svc.run('checks_out', uid(req), {
      from,
      to,
      supplier_id: supplierId,
      check_no: checkNo,
      date_field: dateField,
      posted,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const rows = data.rows || [];
      result = `<div class="si-surface sh-section">
        <div class="si-surface-head"><h2>الشيكات الصادرة</h2>
          <span class="si-count">${rows.length} — ${money(data.sum)}</span></div>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr>
            <th>التاريخ</th><th>رقم السند</th><th>المورد/الطرف</th>
            <th>رقم الشيك</th><th>البنك</th><th>المبلغ</th><th>الحالة</th>
          </tr></thead>
          <tbody>${
            rows
              .map(
                (r) => `<tr>
              <td class="si-num" dir="ltr">${dmy(r.voucher_date)}</td>
              <td class="si-num" dir="ltr">${esc(r.voucher_no || '')}</td>
              <td>${esc(r.supplier_name || r.party_name || '')}</td>
              <td class="si-num" dir="ltr">${esc(r.check_no || '')}</td>
              <td>${esc(r.bank_name || '')}</td>
              <td class="si-num" dir="ltr">${money(r.check_amount || r.amount)}</td>
              <td>${Number(r.is_posted) === 1 ? 'مرحّل' : 'مسودة'}</td>
            </tr>`
              )
              .join('') || `<tr><td colspan="7" class="empty">لا شيكات.</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  } else {
    result = '<p class="muted">حدّد المعايير ثم اعرض.</p>';
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/checks-out">
      <input type="hidden" name="run" value="1">
      <label>المورد
        <select class="si-field" name="supplier_id">${optList(pick.suppliers, supplierId, 'جميع الموردين')}</select>
      </label>
      <label>رقم الشيك
        <input class="si-field si-field--mono" name="check_no" value="${esc(checkNo)}">
      </label>
      <label>الفترة حسب
        <select class="si-field" name="date_field">
          <option value="voucher" ${dateField === 'voucher' ? 'selected' : ''}>تاريخ السند</option>
          <option value="due" ${dateField === 'due' ? 'selected' : ''}>تاريخ الاستحقاق</option>
        </select>
      </label>
      <label>حالة الترحيل
        <select class="si-field" name="posted">
          <option value="all" ${posted === 'all' ? 'selected' : ''}>الكل</option>
          <option value="posted" ${posted === 'posted' ? 'selected' : ''}>مرحّل</option>
          <option value="unposted" ${posted === 'unposted' ? 'selected' : ''}>غير مرحّل</option>
        </select>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(res, req, 'تقرير الشيكات الصادرة', shell('تقرير الشيكات الصادرة', '', filters, result));
});

/* ═══════════════ Period close ═══════════════ */
router.get('/accounting/period-close', async (req, res) => {
  if (!can(req.session.user, 'acc_period_close')) return forbid(res);
  const year = Number(req.query.year || new Date().getFullYear());
  const data = await svc.run('periods_get', uid(req), { year });
  if (!data.ok) {
    return sendPage(
      res,
      req,
      'إغلاق الأشهر المحاسبية',
      `<div class="si-stage">${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'إغلاق الأشهر',
        subtitle: data.error || 'خطأ',
      })}</div>`
    );
  }
  const months = data.months || [];
  const curY = Number(data.cur_y);
  const curM = Number(data.cur_m);
  const years = [];
  for (let y = curY + 1; y >= curY - 10; y--) years.push(y);
  const rows = months
    .map((m) => {
      const mon = Number(m.month);
      const isCurrent = year === curY && mon === curM;
      let status = m.is_locked ? 'مغلق — لا إدخال' : 'مفتوح — يُسمح بالإدخال';
      if (isCurrent && !m.is_locked) status = 'الشهر الحالي — مفتوح';
      else if (m.is_default && m.is_locked && (year < curY || (year === curY && mon < curM)))
        status = 'مغلق تلقائياً (انتهى الشهر)';
      return `<tr class="${m.is_locked ? 'is-locked' : 'is-open'}${
        isCurrent ? ' is-current' : ''
      }">
        <td>${mon}</td>
        <td>${esc(m.name_ar || '')}${
        isCurrent ? ' <span class="ar-badge">الحالي</span>' : ''
      }</td>
        <td style="text-align:center">
          <input type="checkbox" name="locked_${mon}" value="1" ${m.is_locked ? 'checked' : ''}>
        </td>
        <td>${esc(status)}</td>
      </tr>`;
    })
    .join('');
  const body = `
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'إغلاق الأشهر المحاسبية',
        subtitle: 'قفل الأشهر لمنع إدخال/تعديل المستندات',
        actions: [{ label: 'لوحة المحاسبة', href: HUB }],
      })}
      ${flash(req)}
      <form method="get" class="si-search ar-filters no-print" action="/accounting/period-close">
        <label>السنة
          <select class="si-field" name="year" onchange="this.form.submit()">
            ${years.map((y) => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}
          </select>
        </label>
      </form>
      <p class="muted">عدّل خانات مغلق ثم اضغط حفظ. الأشهر السابقة تُغلق تلقائياً عند انتهائها.</p>
      <form method="post" action="/accounting/period-close/save" class="si-surface sh-section">
        <input type="hidden" name="year" value="${year}">
        <div class="si-surface-head">
          <h2>الأشهر المحاسبية — ${year}</h2>
          <button class="si-btn si-btn--primary" type="submit">حفظ</button>
        </div>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>#</th><th>الشهر</th><th>مغلق</th><th>الحالة</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </form>
    </div>`;
  sendPage(res, req, 'إغلاق الأشهر المحاسبية', body);
});

router.post('/accounting/period-close/save', async (req, res) => {
  if (!can(req.session.user, 'acc_period_close')) return forbid(res);
  const year = Number(req.body.year || new Date().getFullYear());
  const locked = {};
  for (let m = 1; m <= 12; m++) {
    locked[m] = req.body['locked_' + m] ? 1 : 0;
  }
  const data = await svc.run('periods_save', uid(req), { year, locked });
  res.redirect(
    '/accounting/period-close?year=' +
      year +
      '&' +
      (data.ok ? 'msg=' : 'err=') +
      encodeURIComponent(data.message || data.error || '')
  );
});

/* ═══════════════ Opening balance ═══════════════ */
function obPartyRowsHtml(rows, prefix, posted) {
  return (rows || [])
    .map((r) => {
      const id = Number(r.party_id || 0);
      const d = Number(r.debit || 0);
      const c = Number(r.credit || 0);
      const label =
        (r.party_code ? String(r.party_code) + ' — ' : '') + String(r.party_name || '');
      return `<tr class="ob-party-row" data-party-id="${id}">
        <td class="si-num" dir="ltr">${esc(r.party_code || id)}</td>
        <td>${esc(r.party_name || '')}
          <input type="hidden" name="${prefix}_id[]" value="${id}">
          <input type="hidden" name="${prefix}_name[]" value="${esc(r.party_name || '')}">
          <input type="hidden" name="${prefix}_code[]" value="${esc(r.party_code || '')}">
        </td>
        <td><input class="si-field si-field--mono ob-d" name="${prefix}_d[]" dir="ltr"
                   value="${d > 0 ? d : ''}" ${posted ? 'readonly' : ''}></td>
        <td><input class="si-field si-field--mono ob-c" name="${prefix}_c[]" dir="ltr"
                   value="${c > 0 ? c : ''}" ${posted ? 'readonly' : ''}></td>
        <td class="no-print">${
          posted
            ? ''
            : `<button type="button" class="si-btn ob-party-del" title="حذف">حذف</button>`
        }</td>
      </tr>`;
    })
    .join('');
}

router.get('/accounting/opening-balance', async (req, res) => {
  if (!can(req.session.user, 'acc_opening_balance')) return forbid(res);
  const year = Number(req.query.year || new Date().getFullYear());
  const data = await svc.run('opening_get', uid(req), { year });
  if (!data.ok) {
    return sendPage(
      res,
      req,
      'الأرصدة الافتتاحية',
      `<div class="si-stage"><p class="si-pill si-pill--lock">${esc(data.error || '')}</p></div>`
    );
  }
  const grid = data.grid || [];
  const status = data.status || {};
  const posted = !!data.is_posted;
  const parties = data.parties || { customers: [], suppliers: [], ar_account_id: 0, ap_account_id: 0 };
  const entryDate = String(req.query.entry_date || status.entry_date || `${year}-01-01`).slice(
    0,
    10
  );
  let withBal = 0;
  let sumD = Number(status.total_debit || 0);
  let sumC = Number(status.total_credit || 0);
  if (!sumD && !sumC) {
    sumD = 0;
    sumC = 0;
  }
  const typeLabel = {
    asset: 'أصل',
    liability: 'خصم',
    equity: 'حقوق',
    revenue: 'إيراد',
    expense: 'مصروف',
  };
  const rows = grid
    .map((r) => {
      const d = Number(r.debit || 0);
      const c = Number(r.credit || 0);
      if (d > 0 || c > 0) withBal++;
      return `<tr data-code="${esc(r.code || '')}" data-name="${esc(r.name_ar || '')}">
        <td class="si-num" dir="ltr"><a href="/accounting/chart">${esc(r.code || '')}</a></td>
        <td>${esc(r.name_ar || '')}</td>
        <td><span class="ar-badge">${esc(typeLabel[r.account_type] || r.account_type || '')}</span></td>
        <td><input class="si-field si-field--mono ob-d" name="d_${r.account_id}" dir="ltr"
                   value="${d > 0 ? d : ''}" ${posted ? 'readonly' : ''} data-id="${r.account_id}"></td>
        <td><input class="si-field si-field--mono ob-c" name="c_${r.account_id}" dir="ltr"
                   value="${c > 0 ? c : ''}" ${posted ? 'readonly' : ''} data-id="${r.account_id}"></td>
      </tr>`;
    })
    .join('');

  /* إعادة حساب الفرق من الشبكة + الأطراف المعروضة */
  sumD = 0;
  sumC = 0;
  withBal = 0;
  for (const r of grid) {
    const d = Number(r.debit || 0);
    const c = Number(r.credit || 0);
    sumD += d;
    sumC += c;
    if (d || c) withBal++;
  }
  for (const r of parties.customers || []) {
    sumD += Number(r.debit || 0);
    sumC += Number(r.credit || 0);
    if (Number(r.debit || 0) || Number(r.credit || 0)) withBal++;
  }
  for (const r of parties.suppliers || []) {
    sumD += Number(r.debit || 0);
    sumC += Number(r.credit || 0);
    if (Number(r.debit || 0) || Number(r.credit || 0)) withBal++;
  }

  const custRows = obPartyRowsHtml(parties.customers || [], 'pc', posted);
  const supRows = obPartyRowsHtml(parties.suppliers || [], 'ps', posted);
  const arOk = Number(parties.ar_account_id || 0) > 0;
  const apOk = Number(parties.ap_account_id || 0) > 0;

  const curY = new Date().getFullYear();
  const years = [];
  for (let y = curY + 1; y >= curY - 10; y--) years.push(y);
  const body = `
    <div class="si-stage sh-page ob-page" id="ob-page" data-posted="${posted ? '1' : '0'}">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'الأرصدة الافتتاحية',
        subtitle: 'سنة ' + year + (posted ? ' · مرحّل' : ' · مسودة'),
        actions: [
          { label: 'شجرة الحسابات', href: '/accounting/chart' },
          { label: 'ربط الحسابات', href: '/accounting/account-mapping' },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flash(req)}
      ${
        !arOk || !apOk
          ? `<p class="si-pill si-pill--lock" style="display:block;margin-bottom:.75rem">
        ${!arOk ? 'عرّف حساب ذمم العملاء في ربط الحسابات. ' : ''}
        ${!apOk ? 'عرّف حساب ذمم الموردين في ربط الحسابات.' : ''}
      </p>`
          : ''
      }

      <form method="post" action="/accounting/opening-balance/save" id="ob-form">
        <input type="hidden" name="year" value="${year}">
        <div class="si-surface ob-card">
          <div class="si-surface-head ob-head">
            <div>
              <h2>إعدادات الافتتاح — ${year}</h2>
              <p class="muted ob-head-hint">قيد افتتاحي واحد متوازن بتاريخ بداية السنة · عميل مدين = عليه · مورد دائن = له</p>
            </div>
            <div class="ob-head-actions no-print">
              ${
                !posted
                  ? `<button class="si-btn si-btn--primary" type="submit">حفظ وترحيل</button>`
                  : `<button class="si-btn" type="submit" formaction="/accounting/opening-balance/unpost"
                       formmethod="post" onclick="return confirm('فك ترحيل الأرصدة؟');">فك الترحيل</button>`
              }
            </div>
          </div>

          <div class="ob-toolbar no-print">
            <label class="ob-field">
              <span class="ob-lab">السنة</span>
              <select class="si-field" id="ob-year-jump"
                onchange="location.href=this.dataset.base+'?year='+encodeURIComponent(this.value)"
                data-base="/accounting/opening-balance">
                ${years.map((y) => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}
              </select>
            </label>
            <label class="ob-field">
              <span class="ob-lab">تاريخ الافتتاح</span>
              <input class="si-field si-field--mono" type="date" name="entry_date" value="${esc(
                entryDate
              )}" ${posted ? 'readonly' : ''}>
            </label>
            <div class="ob-summary" id="ob-stats">
              <span><em id="ob-lines">${withBal}</em> سطر</span>
              <span>مدين <strong dir="ltr" id="ob-sum-d">${money(sumD)}</strong></span>
              <span>دائن <strong dir="ltr" id="ob-sum-c">${money(sumC)}</strong></span>
              <span class="ob-summary__diff">فرق <strong dir="ltr" id="ob-diff">${money(sumD - sumC)}</strong></span>
            </div>
          </div>

          <div class="ob-tabs no-print" role="tablist">
            <button type="button" class="ob-tab is-active" data-ob-tab="accounts">الحسابات</button>
            <button type="button" class="ob-tab" data-ob-tab="customers">العملاء <b>${(parties.customers || []).length}</b></button>
            <button type="button" class="ob-tab" data-ob-tab="suppliers">الموردون <b>${(parties.suppliers || []).length}</b></button>
          </div>

          <div class="ob-pane" data-ob-pane="accounts">
            <div class="ob-pane-bar no-print">
              <label class="ob-field ob-field--grow">
                <span class="ob-lab">بحث حساب</span>
                <input class="si-field" type="search" id="ob-filter" placeholder="كود أو اسم الحساب…" autocomplete="off">
              </label>
            </div>
            <div class="ob-table-wrap">
              <table class="si-table ob-table" id="ob-table">
                <thead><tr>
                  <th class="ob-col-code">الكود</th>
                  <th>الحساب</th>
                  <th class="ob-col-type">النوع</th>
                  <th class="ob-col-amt">مدين</th>
                  <th class="ob-col-amt">دائن</th>
                </tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>

          <div class="ob-pane" data-ob-pane="customers" hidden>
            ${
              !posted
                ? `<div class="ob-pane-bar no-print">
              <label class="ob-field ob-field--grow ob-party-search-wrap">
                <span class="ob-lab">إضافة عميل</span>
                <input type="search" class="si-field" id="ob-cust-search" placeholder="ابحث بالكود أو الاسم ثم اختر من القائمة…" autocomplete="off">
              </label>
              <p class="ob-hint">مدين = رصيد عليه · دائن = رصيد له</p>
            </div>`
                : ''
            }
            <div class="ob-table-wrap">
              <table class="si-table ob-table ob-table--party" id="ob-cust-table">
                <thead><tr>
                  <th class="ob-col-code">الكود</th>
                  <th>العميل</th>
                  <th class="ob-col-amt">مدين</th>
                  <th class="ob-col-amt">دائن</th>
                  <th class="ob-col-act no-print"></th>
                </tr></thead>
                <tbody>${custRows || `<tr class="ob-empty"><td colspan="5" class="empty">لا أرصدة عملاء بعد — ابحث أعلاه للإضافة</td></tr>`}</tbody>
              </table>
            </div>
          </div>

          <div class="ob-pane" data-ob-pane="suppliers" hidden>
            ${
              !posted
                ? `<div class="ob-pane-bar no-print">
              <label class="ob-field ob-field--grow ob-party-search-wrap">
                <span class="ob-lab">إضافة مورد</span>
                <input type="search" class="si-field" id="ob-sup-search" placeholder="ابحث بالكود أو الاسم ثم اختر من القائمة…" autocomplete="off">
              </label>
              <p class="ob-hint">دائن = رصيد له علينا · مدين = رصيد عليه لنا</p>
            </div>`
                : ''
            }
            <div class="ob-table-wrap">
              <table class="si-table ob-table ob-table--party" id="ob-sup-table">
                <thead><tr>
                  <th class="ob-col-code">الكود</th>
                  <th>المورد</th>
                  <th class="ob-col-amt">مدين</th>
                  <th class="ob-col-amt">دائن</th>
                  <th class="ob-col-act no-print"></th>
                </tr></thead>
                <tbody>${supRows || `<tr class="ob-empty"><td colspan="5" class="empty">لا أرصدة موردين بعد — ابحث أعلاه للإضافة</td></tr>`}</tbody>
              </table>
            </div>
          </div>
        </div>
      </form>
    </div>
    <style>
      .ob-page .ob-card{overflow:visible}
      .ob-head{align-items:flex-start;gap:1rem}
      .ob-head-hint{margin:.25rem 0 0;font-size:.78rem;font-weight:500;max-width:36rem;line-height:1.45}
      .ob-head-actions{display:flex;gap:.4rem;flex-shrink:0}
      .ob-toolbar{display:grid;grid-template-columns:minmax(7rem,.7fr) minmax(9rem,.9fr) minmax(16rem,2fr);gap:.75rem 1rem;align-items:end;padding:.85rem 1.05rem;border-bottom:1px solid rgba(15,23,42,.06);background:linear-gradient(180deg,#f8fafc,#fff)}
      .ob-field{display:grid;gap:.28rem;min-width:0}
      .ob-field--grow{flex:1;min-width:14rem}
      .ob-lab{font-size:.76rem;font-weight:700;color:#64748b}
      .ob-summary{display:flex;flex-wrap:wrap;gap:.55rem .9rem;align-items:center;padding:.55rem .75rem;border-radius:10px;background:#fff;border:1px solid rgba(15,23,42,.08);font-size:.82rem;color:#475569}
      .ob-summary strong{font-variant-numeric:tabular-nums;color:#0f172a}
      .ob-summary__diff strong{color:#b45309}
      .ob-summary em{font-style:normal;font-weight:800;color:#0369a1}
      .ob-tabs{display:flex;flex-wrap:wrap;gap:.4rem;padding:.75rem 1.05rem .35rem;border-bottom:1px solid rgba(15,23,42,.06)}
      .ob-tab{appearance:none;border:1px solid rgba(15,23,42,.1);background:#f8fafc;color:#334155;border-radius:999px;padding:.42rem .95rem;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer}
      .ob-tab b{display:inline-block;min-width:1.1rem;margin-inline-start:.25rem;font-weight:800;color:#0369a1}
      .ob-tab.is-active{background:#0284c7;border-color:#0284c7;color:#fff}
      .ob-tab.is-active b{color:#e0f2fe}
      .ob-pane-bar{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:end;padding:.75rem 1.05rem}
      .ob-hint{margin:0;font-size:.78rem;color:#64748b;padding-bottom:.35rem}
      .ob-table-wrap{max-height:min(58vh,560px);overflow:auto;padding:0 .35rem .85rem;border-top:1px solid rgba(15,23,42,.04)}
      .ob-table{width:100%;table-layout:fixed}
      .ob-table th,.ob-table td{vertical-align:middle}
      .ob-col-code{width:7.5rem}
      .ob-col-type{width:5.5rem}
      .ob-col-amt{width:8.5rem}
      .ob-col-act{width:4.2rem}
      .ob-table .ob-d,.ob-table .ob-c{width:100%;text-align:center}
      .ob-table--party .ob-party-del{padding:.28rem .55rem;font-size:.78rem;color:#b91c1c;border-color:rgba(185,28,28,.25);background:#fef2f2}
      .ob-sug{
        position:fixed!important;z-index:99999!important;max-height:min(18rem,48vh);overflow:auto;
        background:#fff!important;border:1px solid rgba(15,23,42,.14)!important;border-radius:12px!important;
        box-shadow:0 14px 40px rgba(15,23,42,.18)!important;padding:.35rem!important;
        display:none;
      }
      .ob-sug.ob-sug--open{display:block!important;visibility:visible!important;opacity:1!important;pointer-events:auto!important}
      .ob-sug button{
        display:grid!important;grid-template-columns:minmax(5.5rem,auto) 1fr;gap:.35rem .65rem;align-items:center;
        width:100%;text-align:right!important;border:0;background:transparent;padding:.5rem .7rem!important;
        font:inherit;cursor:pointer;border-radius:8px
      }
      .ob-sug button:hover,.ob-sug button.is-active{background:#e0f2fe!important}
      .ob-sug-code{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;font-weight:700;color:#0369a1;white-space:nowrap}
      .ob-sug-name{font-size:.86rem;font-weight:600;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .ob-sug-empty{padding:.7rem .85rem;color:#64748b;font-size:.85rem}
      @media (max-width:900px){
        .ob-toolbar{grid-template-columns:1fr 1fr}
        .ob-summary{grid-column:1/-1}
      }
      @media (max-width:560px){
        .ob-toolbar{grid-template-columns:1fr}
        .ob-col-type{display:none}
      }
    </style>
    <script>
    (function(){
      var page=document.getElementById('ob-page');
      var posted=page&&page.getAttribute('data-posted')==='1';
      var form=document.getElementById('ob-form');
      var tb=document.getElementById('ob-table');
      var custTb=document.getElementById('ob-cust-table');
      var supTb=document.getElementById('ob-sup-table');
      var filter=document.getElementById('ob-filter');
      var diffEl=document.getElementById('ob-diff');
      var sumDEl=document.getElementById('ob-sum-d');
      var sumCEl=document.getElementById('ob-sum-c');
      var linesEl=document.getElementById('ob-lines');
      function baseUrl(path){
        if(typeof window.__hypexUrl==='function') return window.__hypexUrl(path);
        var b=(window.__HYPEX_BASE__||'').replace(/\\/$/,'');
        return b&&path.charAt(0)==='/'?b+path:path;
      }
      function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
      function num(v){var n=parseFloat(String(v||'').replace(/,/g,''));return isFinite(n)?n:0;}
      function money(n){return (Number(n)||0).toFixed(3);}
      function recompute(){
        var d=0,c=0,n=0;
        document.querySelectorAll('#ob-form tbody tr').forEach(function(tr){
          if(tr.classList.contains('ob-empty')) return;
          if(tr.style.display==='none') return;
          var di=tr.querySelector('.ob-d');
          var ci=tr.querySelector('.ob-c');
          var dv=num(di&&di.value), cv=num(ci&&ci.value);
          d+=dv; c+=cv; if(dv||cv) n++;
        });
        /* احتساب كل الصفوف حتى المخفية في فلتر الحسابات للفرق الحقيقي */
        d=0;c=0;n=0;
        document.querySelectorAll('#ob-form tbody tr').forEach(function(tr){
          if(tr.classList.contains('ob-empty')) return;
          var di=tr.querySelector('.ob-d');
          var ci=tr.querySelector('.ob-c');
          var dv=num(di&&di.value), cv=num(ci&&ci.value);
          d+=dv; c+=cv; if(dv||cv) n++;
        });
        if(diffEl) diffEl.textContent=money(d-c);
        if(sumDEl) sumDEl.textContent=money(d);
        if(sumCEl) sumCEl.textContent=money(c);
        if(linesEl) linesEl.textContent=String(n);
      }
      document.querySelectorAll('[data-ob-tab]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var tab=btn.getAttribute('data-ob-tab');
          document.querySelectorAll('[data-ob-tab]').forEach(function(b){b.classList.toggle('is-active', b===btn);});
          document.querySelectorAll('[data-ob-pane]').forEach(function(p){
            p.hidden = p.getAttribute('data-ob-pane') !== tab;
          });
          closeSug();
          if(tab==='customers'){
            var c=document.getElementById('ob-cust-search'); if(c) c.focus();
          }
          if(tab==='suppliers'){
            var s=document.getElementById('ob-sup-search'); if(s) s.focus();
          }
        });
      });
      if(filter&&tb){ filter.addEventListener('input', function(){
        var q=filter.value.trim().toLowerCase();
        tb.querySelectorAll('tbody tr').forEach(function(tr){
          var code=(tr.getAttribute('data-code')||'').toLowerCase();
          var name=(tr.getAttribute('data-name')||'').toLowerCase();
          tr.style.display = (!q||code.indexOf(q)>=0||name.indexOf(q)>=0) ? '' : 'none';
        });
      });}
      function bindAmountInputs(root){
        (root||document).querySelectorAll('.ob-d,.ob-c').forEach(function(inp){
          if(inp._obBound) return;
          inp._obBound=true;
          inp.addEventListener('input', function(){
            var tr=inp.closest('tr');
            if(inp.classList.contains('ob-d') && num(inp.value)>0){
              var o=tr.querySelector('.ob-c'); if(o) o.value='';
            }
            if(inp.classList.contains('ob-c') && num(inp.value)>0){
              var o=tr.querySelector('.ob-d'); if(o) o.value='';
            }
            recompute();
          });
        });
      }
      bindAmountInputs(document);
      document.addEventListener('click', function(e){
        var btn=e.target.closest('.ob-party-del');
        if(!btn) return;
        var tr=btn.closest('tr');
        if(tr) tr.remove();
        recompute();
      });

      function ensureTbody(table){
        var tbod=table.querySelector('tbody');
        var empty=tbod.querySelector('.ob-empty');
        if(empty) empty.remove();
        return tbod;
      }
      function hasParty(table, id){
        return !!table.querySelector('tr[data-party-id="'+id+'"]');
      }
      function addPartyRow(table, prefix, party){
        if(!table||!party||!party.id) return;
        if(hasParty(table, party.id)){
          if(window.HypexUI&&HypexUI.toast) HypexUI.toast('مضاف مسبقاً','warn');
          return;
        }
        var tbod=ensureTbody(table);
        var tr=document.createElement('tr');
        tr.className='ob-party-row';
        tr.setAttribute('data-party-id', String(party.id));
        var name=party.name_ar||party.name||'';
        var code=party.code||'';
        tr.innerHTML=
          '<td class="si-num" dir="ltr">'+escHtml(code||party.id)+'</td>'+
          '<td>'+escHtml(name)+
            '<input type="hidden" name="'+prefix+'_id[]" value="'+party.id+'">'+
            '<input type="hidden" name="'+prefix+'_name[]" value="'+escHtml(name)+'">'+
            '<input type="hidden" name="'+prefix+'_code[]" value="'+escHtml(code)+'">'+
          '</td>'+
          '<td><input class="si-field si-field--mono ob-d" name="'+prefix+'_d[]" dir="ltr" value=""></td>'+
          '<td><input class="si-field si-field--mono ob-c" name="'+prefix+'_c[]" dir="ltr" value=""></td>'+
          '<td class="no-print"><button type="button" class="si-btn ob-party-del" title="حذف">حذف</button></td>';
        tbod.appendChild(tr);
        bindAmountInputs(tr);
        var focus=tr.querySelector(prefix==='ps'?'.ob-c':'.ob-d');
        if(focus) focus.focus();
        recompute();
      }

      var sug=document.createElement('div');
      sug.id='ob-party-suggest';
      sug.className='ob-sug';
      sug.hidden=true;
      document.body.appendChild(sug);
      var sugTimer=null, sugSeq=0, sugRows=[];
      function closeSug(){
        sug.hidden=true;
        sug.setAttribute('hidden','');
        sug.classList.remove('ob-sug--open');
        sug.style.display='none';
        sug.innerHTML='';
        sugRows=[];
      }
      function placeSug(anchor){
        var r=anchor.getBoundingClientRect();
        var width=Math.max(340, r.width);
        var left=r.left;
        if(left+width>window.innerWidth-8) left=Math.max(8, window.innerWidth-width-8);
        sug.style.position='fixed';
        sug.style.left=left+'px';
        sug.style.right='auto';
        sug.style.top=(r.bottom+4)+'px';
        sug.style.width=width+'px';
        sug.style.zIndex='99999';
        sug.hidden=false;
        sug.removeAttribute('hidden');
        sug.classList.add('ob-sug--open');
        sug.style.display='block';
      }
      function renderSug(anchor, rows, emptyMsg){
        sugRows=rows||[];
        if(!sugRows.length){
          sug.innerHTML='<div class="ob-sug-empty">'+escHtml(emptyMsg||'لا نتائج')+'</div>';
        } else {
          sug.innerHTML=sugRows.map(function(r){
            return '<button type="button" data-id="'+r.id+'">'+
              '<span class="ob-sug-code" dir="ltr">'+escHtml(r.code||'—')+'</span>'+
              '<span class="ob-sug-name">'+escHtml(r.name_ar||r.name||'—')+'</span>'+
              '</button>';
          }).join('');
        }
        placeSug(anchor);
        sug.querySelectorAll('button[data-id]').forEach(function(btn){
          btn.addEventListener('mousedown', function(e){e.preventDefault();});
          btn.addEventListener('click', function(){
            var id=Number(btn.getAttribute('data-id'));
            var p=sugRows.find(function(x){return Number(x.id)===id;});
            if(!p||!sug._ctx) return;
            addPartyRow(sug._ctx.table, sug._ctx.prefix, p);
            if(sug._ctx.input) sug._ctx.input.value='';
            closeSug();
          });
        });
      }
      function openPartyList(input, apiPath, table, prefix, q){
        var seq=++sugSeq;
        sug._ctx={input:input,table:table,prefix:prefix};
        sug.innerHTML='<div class="ob-sug-empty">جاري البحث…</div>';
        placeSug(input);
        var url=baseUrl(apiPath)+'?q='+encodeURIComponent(q||'')+'&limit=60';
        fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}})
          .then(function(r){ if(!r.ok) throw new Error('http'); return r.json(); })
          .then(function(d){
            if(seq!==sugSeq) return;
            var rows=(d&&d.ok?d.rows:[])||[];
            renderSug(input, rows, rows.length?'':'لا نتائج مطابقة');
          })
          .catch(function(){
            if(seq!==sugSeq) return;
            renderSug(input, [], 'تعذر تحميل القائمة');
          });
      }
      function bindPartySearch(input, apiPath, table, prefix){
        if(!input||posted) return;
        function schedule(){
          clearTimeout(sugTimer);
          sugTimer=setTimeout(function(){
            openPartyList(input, apiPath, table, prefix, String(input.value||'').trim());
          }, 140);
        }
        input.addEventListener('input', schedule);
        input.addEventListener('focus', function(){ openPartyList(input, apiPath, table, prefix, String(input.value||'').trim()); });
        input.addEventListener('click', function(){ openPartyList(input, apiPath, table, prefix, String(input.value||'').trim()); });
        input.addEventListener('keydown', function(e){
          if(e.key==='Escape'){ closeSug(); return; }
          var btns=Array.prototype.slice.call(sug.querySelectorAll('button[data-id]'));
          if(e.key==='ArrowDown'||e.key==='ArrowUp'){
            e.preventDefault();
            e.stopPropagation();
            if(sug.hidden||!btns.length){ openPartyList(input, apiPath, table, prefix, String(input.value||'').trim()); return; }
            var cur=btns.findIndex(function(b){return b.classList.contains('is-active');});
            var next=e.key==='ArrowDown'?(cur<btns.length-1?cur+1:0):(cur>0?cur-1:btns.length-1);
            btns.forEach(function(b,i){b.classList.toggle('is-active',i===next);});
            try{ btns[next].scrollIntoView({block:'nearest'}); }catch(err){}
            return;
          }
          if(e.key==='Enter'){
            var active=sug.querySelector('button.is-active')||btns[0];
            if(!sug.hidden&&active){ e.preventDefault(); active.click(); }
          }
        });
      }
      bindPartySearch(document.getElementById('ob-cust-search'), '/api/lookup/customers', custTb, 'pc');
      bindPartySearch(document.getElementById('ob-sup-search'), '/api/lookup/suppliers', supTb, 'ps');
      document.addEventListener('mousedown', function(e){
        if(sug.contains(e.target)) return;
        if(e.target&&(e.target.id==='ob-cust-search'||e.target.id==='ob-sup-search')) return;
        closeSug();
      });
      window.addEventListener('scroll', function(){
        if(sug.hidden) return;
        var a=document.activeElement;
        if(a&&(a.id==='ob-cust-search'||a.id==='ob-sup-search')) placeSug(a);
      }, true);
      window.addEventListener('resize', function(){
        if(sug.hidden) return;
        var a=document.activeElement;
        if(a&&(a.id==='ob-cust-search'||a.id==='ob-sup-search')) placeSug(a);
      });
      /* إصلاح روابط السنة تحت /hypex */
      var yj=document.getElementById('ob-year-jump');
      if(yj){
        var base=yj.getAttribute('data-base')||'/accounting/opening-balance';
        yj.setAttribute('data-base', baseUrl(base));
      }
      recompute();
    })();
    </script>`;
  sendPage(res, req, 'الأرصدة الافتتاحية', body);
});

router.post('/accounting/opening-balance/save', async (req, res) => {
  if (!can(req.session.user, 'acc_opening_balance')) return forbid(res);
  const year = Number(req.body.year || new Date().getFullYear());
  const amounts = {};
  for (const [k, v] of Object.entries(req.body || {})) {
    if (k.startsWith('d_')) {
      const id = k.slice(2);
      if (!amounts[id]) amounts[id] = { debit: 0, credit: 0 };
      amounts[id].debit = String(v || '');
    }
    if (k.startsWith('c_')) {
      const id = k.slice(2);
      if (!amounts[id]) amounts[id] = { debit: 0, credit: 0 };
      amounts[id].credit = String(v || '');
    }
  }

  function collectParties(prefix, partyType) {
    const ids = [].concat(req.body[prefix + '_id'] || []);
    const debits = [].concat(req.body[prefix + '_d'] || []);
    const credits = [].concat(req.body[prefix + '_c'] || []);
    const out = [];
    for (let i = 0; i < ids.length; i++) {
      const partyId = Number(ids[i] || 0);
      if (partyId < 1) continue;
      out.push({
        party_type: partyType,
        party_id: partyId,
        debit: String(debits[i] || ''),
        credit: String(credits[i] || ''),
      });
    }
    return out;
  }
  const parties = [...collectParties('pc', 'customer'), ...collectParties('ps', 'supplier')];

  const data = await svc.run('opening_save', uid(req), {
    year,
    entry_date: req.body.entry_date,
    amounts,
    parties,
  });
  res.redirect(
    '/accounting/opening-balance?year=' +
      year +
      '&' +
      (data.ok ? 'msg=' : 'err=') +
      encodeURIComponent(data.message || data.error || '')
  );
});

router.post('/accounting/opening-balance/unpost', async (req, res) => {
  if (!can(req.session.user, 'acc_opening_balance')) return forbid(res);
  const year = Number(req.body.year || new Date().getFullYear());
  const data = await svc.run('opening_unpost', uid(req), { year });
  res.redirect(
    '/accounting/opening-balance?year=' +
      year +
      '&' +
      (data.ok ? 'msg=' : 'err=') +
      encodeURIComponent(data.message || data.error || '')
  );
});

/* ═══════════════ Year close ═══════════════ */
router.get('/accounting/year-close', async (req, res) => {
  if (!can(req.session.user, 'acc_year_close')) return forbid(res);
  const year = Number(req.query.year || new Date().getFullYear());
  const data = await svc.run('year_board', uid(req), { year });
  if (!data.ok) {
    return sendPage(
      res,
      req,
      'إقفال السنة المالية',
      `<div class="si-stage"><p class="si-pill si-pill--lock">${esc(data.error || '')}</p></div>`
    );
  }
  const years = board.years || board.rows || (Array.isArray(board) ? board : board.list) || [];
  const list = Array.isArray(data.board) ? data.board : Array.isArray(years) ? years : Object.values(years || {});
  const pre = data.preflight || {};
  const curY = new Date().getFullYear();
  const yearOpts = [];
  for (let y = curY + 2; y >= curY - 10; y--) yearOpts.push(y);

  let closedCount = 0;
  const rows = list
    .map((r) => {
      const y = Number(r.fiscal_year || r.year || r);
      const status = String(r.status || '');
      const statusLabel = String(r.status_label || status || 'غير مسجّلة');
      const isCurrent = y === year;
      const closed = status === 'closed';
      if (closed) closedCount++;
      const canClose = r.can_close !== false && !closed;
      const canReopen = !!r.can_reopen || closed;
      let actions = '';
      if (status === 'legacy' || status === '' || statusLabel.includes('غير مسج')) {
        actions = `<form method="post" action="/accounting/year-close/register" style="display:inline">
          <input type="hidden" name="year" value="${y}">
          <button class="si-btn si-btn--primary" type="submit">تسجيل</button>
        </form>`;
      } else if (closed) {
        actions = canReopen
          ? `<form method="post" action="/accounting/year-close/reopen" style="display:inline"
          onsubmit="return confirm('فتح السنة ${y}؟');">
          <input type="hidden" name="year" value="${y}">
          <button class="si-btn" type="submit">فتح</button>
        </form>`
          : '—';
      } else {
        actions = canClose
          ? `<form method="post" action="/accounting/year-close/close" style="display:inline"
          onsubmit="return confirm('إقفال السنة ${y}؟ هذا ينقل صافي الربح ويقفل الأشهر.');">
          <input type="hidden" name="year" value="${y}">
          <button class="si-btn si-btn--primary" type="submit">إقفال</button>
        </form>
        <form method="post" action="/accounting/year-close/register" style="display:inline">
          <input type="hidden" name="year" value="${y}">
          <button class="si-btn" type="submit">تسجيل</button>
        </form>`
          : `<form method="post" action="/accounting/year-close/register" style="display:inline">
          <input type="hidden" name="year" value="${y}">
          <button class="si-btn si-btn--primary" type="submit">تسجيل</button>
        </form>`;
      }
      return `<tr class="${isCurrent ? 'is-current' : ''}">
        <td class="si-num">${y}${isCurrent ? ' <span class="ar-badge">الحالية</span>' : ''}</td>
        <td>${esc(statusLabel)}</td>
        <td class="si-num" dir="ltr">${r.closed_at ? dmy(r.closed_at) : '—'}</td>
        <td class="si-num" dir="ltr">${r.journal_id || '—'}</td>
        <td class="sh-actions">${actions}</td>
      </tr>`;
    })
    .join('');

  const body = `
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'إقفال السنة المالية',
        subtitle: 'تسجيل · إقفال الإيرادات والمصروفات · فتح السنة التالية',
        actions: [
          { label: 'إغلاق الأشهر', href: '/accounting/period-close?year=' + year },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flash(req)}
      <form method="get" class="si-search ar-filters no-print" action="/accounting/year-close">
        <label>معاينة سنة
          <select class="si-field" name="year" onchange="this.form.submit()">
            ${yearOpts
              .map((y) => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`)
              .join('')}
          </select>
        </label>
      </form>
      <p class="si-pill" style="display:block;padding:.75rem 1rem;background:#f1f5f9;line-height:1.6">
        إقفال السنة يصفّر الإيرادات والمصروفات ويحوّل صافي الربح إلى أرباح محتجزة ويقفل أشهر السنة.
        المسار: 1) تسجيل 2) إقفال. «فتح» للتراجع عند الحاجة.
        ${
          pre.ok === false || pre.error
            ? `<br><strong>تنبيه:</strong> ${esc(pre.message || pre.error || '')}`
            : ''
        }
      </p>
      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>حالة السنوات المالية</h2>
          <span class="si-count">${list.length} سنة — ${closedCount} مغلقة</span>
        </div>
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr>
            <th>السنة</th><th>الحالة</th><th>تاريخ الإقفال</th><th>قيد الإقفال</th><th>إجراء</th>
          </tr></thead>
          <tbody>${rows || `<tr><td colspan="5" class="empty">لا بيانات.</td></tr>`}</tbody>
        </table></div>
      </section>
    </div>`;
  sendPage(res, req, 'إقفال السنة المالية', body);
});

function yearAction(action) {
  return async (req, res) => {
    if (!can(req.session.user, 'acc_year_close')) return forbid(res);
    const year = Number(req.body.year || new Date().getFullYear());
    const data = await svc.run(action, uid(req), { year });
    res.redirect(
      '/accounting/year-close?year=' +
        year +
        '&' +
        (data.ok ? 'msg=' : 'err=') +
        encodeURIComponent(data.message || data.error || '')
    );
  };
}

router.post('/accounting/year-close/register', yearAction('year_register'));
router.post('/accounting/year-close/close', yearAction('year_close'));
router.post('/accounting/year-close/reopen', yearAction('year_reopen'));

module.exports = router;
