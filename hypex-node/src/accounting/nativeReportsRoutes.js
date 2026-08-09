'use strict';

/**
 * تقارير المحاسبة + الأرصدة الافتتاحية + إغلاق الأشهر/السنة — Node UI · PHP logic
 */
const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const svc = require('./nativeService');

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
          { label: 'طباعة', print: true },
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
router.get('/accounting/reports/oracle-statement', async (req, res) => {
  if (!can(req.session.user, 'report_oracle_customer_statement')) return forbid(res);
  const { from, to } = svc.range(req.query.from, req.query.to);
  const accountNo = String(req.query.account_no || '').trim();
  const run = String(req.query.run || '') === '1';
  let result = '<p class="muted">أدخل رقم حساب Oracle والفترة. يتطلب اتصال Oracle مُعدّاً.</p>';
  if (run && accountNo) {
    const data = await svc.run('oracle_statement', uid(req), {
      from,
      to,
      account_no: accountNo,
    });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || data.message || '')}</p>`;
    } else {
      const rows = data.rows || data.data || [];
      result = `<div class="si-surface sh-section">
        <div class="si-table-wrap"><table class="si-table">
          <thead><tr><th>التاريخ</th><th>المستند</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
          <tbody>${
            (Array.isArray(rows) ? rows : [])
              .map((r) => {
                const keys = Object.keys(r);
                return `<tr>${keys
                  .slice(0, 6)
                  .map((k) => `<td>${esc(String(r[k] ?? ''))}</td>`)
                  .join('')}</tr>`;
              })
              .join('') ||
            `<tr><td colspan="6" class="empty">${esc(
              data.message || 'لا بيانات أو فشل الاتصال.'
            )}</td></tr>`
          }</tbody>
        </table></div>
      </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/reports/oracle-statement">
      <input type="hidden" name="run" value="1">
      <label>رقم الحساب
        <input class="si-field si-field--mono" name="account_no" value="${esc(accountNo)}" required>
      </label>
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>`;
  sendPage(
    res,
    req,
    'كشف حساب تفصيلي Oracle',
    shell('كشف حساب تفصيلي Oracle', '', filters, result)
  );
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
  const entryDate = String(req.query.entry_date || status.entry_date || `${year}-01-01`).slice(
    0,
    10
  );
  let withBal = 0;
  let sumD = 0;
  let sumC = 0;
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
      if (d > 0 || c > 0) {
        withBal++;
        sumD += d;
        sumC += c;
      }
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
  const curY = new Date().getFullYear();
  const years = [];
  for (let y = curY + 1; y >= curY - 10; y--) years.push(y);
  const body = `
    <div class="si-stage sh-page" id="ob-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'الأرصدة الافتتاحية',
        subtitle: 'سنة ' + year + (posted ? ' · مرحّل' : ' · مسودة'),
        actions: [
          { label: 'شجرة الحسابات', href: '/accounting/chart' },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flash(req)}
      <p class="si-pill" style="display:block;padding:.75rem 1rem;background:#f1f5f9;line-height:1.6">
        إدخال رصيد افتتاحي لكل حساب نهائي لبدء النظام أو الانتقال من نظام سابق.
        يُنشأ قيد افتتاحي واحد بتاريخ بداية السنة. 1) اختر السنة 2) أدخل الأرصدة 3) حفظ وترحيل.
      </p>
      <form method="get" class="si-search ar-filters no-print" action="/accounting/opening-balance">
        <label>السنة
          <select class="si-field" name="year" onchange="this.form.submit()">
            ${years.map((y) => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}
          </select>
        </label>
      </form>
      <form method="post" action="/accounting/opening-balance/save" id="ob-form">
        <input type="hidden" name="year" value="${year}">
        <div class="si-surface sh-section">
          <div class="si-surface-head">
            <h2>إعدادات الافتتاح — ${year}</h2>
            <div class="sh-actions">
              ${
                !posted
                  ? `<button class="si-btn si-btn--primary" type="submit">حفظ وترحيل</button>`
                  : `<button class="si-btn" type="submit" formaction="/accounting/opening-balance/unpost"
                       formmethod="post" onclick="return confirm('فك ترحيل الأرصدة؟');">فك الترحيل</button>`
              }
            </div>
          </div>
          <div class="sh-form ar-filters" style="margin-bottom:.75rem">
            <label>تاريخ الافتتاح
              <input class="si-field si-field--mono" type="date" name="entry_date" value="${esc(
                entryDate
              )}" ${posted ? 'readonly' : ''}>
            </label>
            <label class="no-print">بحث حساب
              <input class="si-field" type="search" id="ob-filter" placeholder="كود أو اسم الحساب…">
            </label>
            <p id="ob-stats" class="muted">${grid.length} حساب — ${withBal} برصيد — فرق:
              <strong dir="ltr" id="ob-diff">${money(sumD - sumC)}</strong></p>
          </div>
          <div class="si-table-wrap" style="max-height:65vh;overflow:auto">
            <table class="si-table" id="ob-table">
              <thead><tr>
                <th>الكود</th><th>الحساب</th><th>النوع</th><th>مدين</th><th>دائن</th>
              </tr></thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      </form>
    </div>
    <script>
    (function(){
      var tb = document.getElementById('ob-table');
      var filter = document.getElementById('ob-filter');
      var diffEl = document.getElementById('ob-diff');
      function num(v){ var n=parseFloat(String(v||'').replace(/,/g,'')); return isFinite(n)?n:0; }
      function recompute(){
        var d=0,c=0,n=0;
        tb.querySelectorAll('tbody tr').forEach(function(tr){
          if(tr.style.display==='none') return;
          var di=tr.querySelector('.ob-d');
          var ci=tr.querySelector('.ob-c');
          var dv=num(di&&di.value), cv=num(ci&&ci.value);
          d+=dv; c+=cv; if(dv||cv) n++;
        });
        if(diffEl) diffEl.textContent = (d-c).toFixed(3);
      }
      if(filter){ filter.addEventListener('input', function(){
        var q=filter.value.trim().toLowerCase();
        tb.querySelectorAll('tbody tr').forEach(function(tr){
          var code=(tr.getAttribute('data-code')||'').toLowerCase();
          var name=(tr.getAttribute('data-name')||'').toLowerCase();
          tr.style.display = (!q||code.indexOf(q)>=0||name.indexOf(q)>=0) ? '' : 'none';
        });
      });}
      tb.querySelectorAll('.ob-d,.ob-c').forEach(function(inp){
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
  const data = await svc.run('opening_save', uid(req), {
    year,
    entry_date: req.body.entry_date,
    amounts,
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
