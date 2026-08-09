'use strict';

/**
 * تقارير الضريبة — Node UI · PHP logic
 */
const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');
const svc = require('./nativeService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Tax · Node';
const HUB = '/accounting';
const TAX_HUB = '/accounting#vat';

const PATHS = new Set([
  '/accounting/tax/declaration',
  '/accounting/tax/ar3',
  '/accounting/tax/vat-net',
  '/accounting/tax/sales',
  '/accounting/tax/purchases',
  '/accounting/tax/sales-return',
  '/accounting/tax/purchase-return',
]);

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}
function uid(req) {
  return Number(req.session.user?.id || 0) || 0;
}
function forbid(res) {
  return res.status(403).send('ممنوع');
}
function money(n) {
  return esc(fmtAmt(Number(n) || 0));
}
function dmy(iso) {
  return esc(isoToDmy(String(iso || '').slice(0, 10)));
}

router.use((req, res, next) => {
  if (!PATHS.has(req.path || '')) return next('router');
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

function shell(title, subtitle, filtersHtml, resultHtml, extra = []) {
  return `
    <div class="si-stage sh-page ar-report">
      ${ui.hero({
        mark: 'Tx',
        kicker: KICKER,
        title,
        subtitle,
        actions: [
          { label: 'طباعة', print: true },
          ...extra,
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <div class="si-rail no-print">${filtersHtml}</div>
      <div class="si-print-area">${resultHtml}</div>
    </div>`;
}

function dateFields(from, to) {
  return `
    <label>من تاريخ
      <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}" required>
    </label>
    <label>إلى تاريخ
      <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}" required>
    </label>`;
}

/* ─── الإقرار الضريبي ─── */
router.get('/accounting/tax/declaration', async (req, res) => {
  if (!can(req.session.user, 'report_tax_declaration')) return forbid(res);
  const { from, to } = svc.range(req.query.from || req.query.date_from, req.query.to || req.query.date_to);
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.date_from);
  let result = '<p class="muted">اختر فترة الإقرار ثم اضغط عرض.</p>';
  if (run) {
    const data = await svc.run('tax_declaration', uid(req), { from, to });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const d = data.decl || {};
      const lines = d.lines || [];
      result = `
        <div class="si-surface sh-section">
          <div class="tax-meta">
            <p><span class="muted">اسم المنشأة</span> <strong>${esc(d.company_name || '—')}</strong></p>
            ${d.trade_name ? `<p><span class="muted">الاسم التجاري</span> ${esc(d.trade_name)}</p>` : ''}
            ${d.tax_id || d.vat_no ? `<p><span class="muted">الرقم الضريبي</span> <span dir="ltr">${esc(d.tax_id || d.vat_no)}</span></p>` : ''}
            <p><span class="muted">الفترة</span> ${dmy(from)} — ${dmy(to)}</p>
          </div>
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr><th>البند</th><th>الأساس الخاضع</th><th>الضريبة</th></tr></thead>
            <tbody>
            ${
              lines
                .map((ln) => {
                  const cls = ln.is_subtotal ? 'ar-open' : ln.is_total ? 'pl-row--total' : '';
                  const label =
                    (ln.is_deduction ? '(-) ' : '') + (ln.label || '');
                  return `<tr class="${cls}">
                    <td>${esc(label)}</td>
                    <td class="si-num" dir="ltr">${money(ln.taxable_base)}</td>
                    <td class="si-num" dir="ltr">${money(ln.tax_amount)}</td>
                  </tr>`;
                })
                .join('') || `<tr><td colspan="3" class="empty">لا بيانات.</td></tr>`
            }
            </tbody>
          </table></div>
          <p class="ar-totals">
            ${
              d.net_payable != null || d.net_after_remittance != null
                ? `صافي المستحق: <strong dir="ltr">${money(
                    d.net_payable != null ? d.net_payable : d.net_after_remittance
                  )}</strong>
                   ${d.is_payable === false ? ' (رصيد لصالح المكلف)' : ' (مستحق للدائرة)'}`
                : ''
            }
          </p>
        </div>`;
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/tax/declaration">
      <input type="hidden" name="run" value="1">
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض الإقرار</button>
      <a class="si-btn" href="/accounting/tax/vat-net?run=1&from=${esc(from)}&to=${esc(to)}">تفاصيل الضريبة</a>
    </form>
    <p class="muted no-print">اختر فترة الإقرار (شهر أو شهرين حسب الدورة الضريبية).</p>`;
  sendPage(res, req, 'الإقرار الضريبي', shell('الإقرار الضريبي', 'ملخص ضريبة المبيعات والمشتريات', filters, result));
});

/* ─── أمانات ضريبة مبيعات ─── */
router.get('/accounting/tax/vat-net', async (req, res) => {
  if (!can(req.session.user, 'report_vat_net_payable')) return forbid(res);
  const { from, to } = svc.range(req.query.from || req.query.date_from, req.query.to || req.query.date_to);
  const run = String(req.query.run || '') === '1' || !!(req.query.from || req.query.date_from);
  let result = '<p class="muted">حدّد الفترة ثم اعرض.</p>';
  if (run) {
    const data = await svc.run('vat_net', uid(req), { from, to });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const v = data.summary || {};
      const outId = Number(v.output_account_id || 0);
      const inId = Number(v.input_account_id || 0);
      const trustId = Number(v.trust_account_id || 0);
      if (outId < 1 || inId < 1) {
        result = `<p class="si-pill si-pill--lock">ربط حسابي vat_output و vat_input غير مكتمل — راجع ربط الحسابات.</p>`;
      } else if (trustId < 1) {
        result = `<p class="si-pill si-pill--lock">حساب أمانات الضريبة غير موجود في الشجرة.</p>`;
      } else {
        const trustLabel =
          (v.trust_account_code ? v.trust_account_code + ' — ' : '') +
          (v.trust_account_name || 'أمانات ضريبة مبيعات');
        result = `
          <div class="si-surface sh-section">
            <p class="muted">من ${dmy(from)} إلى ${dmy(to)}</p>
            <div class="tax-hero">
              <div class="muted">رصيد حساب ${esc(trustLabel)} (ختامي)</div>
              <div class="tax-hero-amt" dir="ltr">${money(v.gl_closing_balance)}</div>
              <div class="muted">رصيد افتتاحي: <strong dir="ltr">${money(v.gl_opening_balance)}</strong></div>
            </div>
            <div class="si-table-wrap" style="margin-top:1rem"><table class="si-table">
              <tbody>
                <tr><td>ضريبة مبيعات (مخرجات)</td><td class="si-num" dir="ltr">${money(v.sales_tax)}</td></tr>
                <tr><td>ضريبة مردود بيع</td><td class="si-num" dir="ltr">${money(v.sale_return_tax)}</td></tr>
                <tr><td>ضريبة مشتريات (مدخلات)</td><td class="si-num" dir="ltr">${money(v.purchase_tax)}</td></tr>
                <tr><td>ضريبة مردود شراء</td><td class="si-num" dir="ltr">${money(v.purchase_return_tax)}</td></tr>
                <tr class="ar-open"><td>صافي قبل التوريد</td><td class="si-num" dir="ltr">${money(
                  v.invoice_net != null ? v.invoice_net : Number(v.sales_tax || 0) - Number(v.purchase_tax || 0)
                )}</td></tr>
                <tr><td>توريد / مدفوع للدائرة</td><td class="si-num" dir="ltr">${money(
                  v.remittance_tax || v.gl_other_debit
                )}</td></tr>
              </tbody>
            </table></div>
            <p class="no-print" style="margin-top:.75rem">
              <a class="si-btn" href="/accounting/reports/account-statement?account_id=${trustId}&from=${esc(
          from
        )}&to=${esc(to)}">كشف حساب الأمانات</a>
            </p>
          </div>`;
      }
    }
  }
  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/tax/vat-net">
      <input type="hidden" name="run" value="1">
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض</button>
      <a class="si-btn" href="/accounting/tax/sales?run=1&from=${esc(from)}&to=${esc(to)}">ضريبة فواتير البيع</a>
      <a class="si-btn" href="/accounting/tax/declaration?run=1&from=${esc(from)}&to=${esc(to)}">الإقرار</a>
    </form>
    <p class="muted no-print">للفترة الضريبية (كل شهرين) اختر بداية ونهاية الفترة. الرصيد الختامي = رصيد حساب الأمانات في كشف الحساب.</p>`;
  sendPage(
    res,
    req,
    'أمانات ضريبة مبيعات',
    shell('أمانات ضريبة مبيعات', 'ملخص صافي الضريبة الأردني', filters, result)
  );
});

/* ─── فواتير / مردود: shared ─── */
async function vatDocTax(req, res, conf) {
  if (!can(req.session.user, conf.perm)) return forbid(res);

  const { from, to } = svc.range(req.query.from, req.query.to);
  const run = String(req.query.run || '') === '1';
  let result = '<p class="muted">حدّد الفترة ثم اعرض التقرير.</p>';
  if (run) {
    const data = await svc.run(conf.action, uid(req), { from, to, kind: conf.kind });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else {
      const rows = data.rows || [];
      const partyH = conf.kind === 'purchase' ? 'المورد' : 'العميل';
      result = `
        <div class="si-surface sh-section">
          <div class="si-surface-head">
            <h2>${esc(conf.title)}</h2>
            <span class="si-count">${rows.length}</span>
          </div>
          <p class="muted">من ${dmy(from)} إلى ${dmy(to)} — ${esc(conf.kindLabel)}</p>
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr>
              <th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>${partyH}</th>
              <th>الإجمالي (شامل)</th><th>قيمة الضريبة</th>
            </tr></thead>
            <tbody>
            ${
              rows
                .map(
                  (r, i) => `<tr>
                <td>${i + 1}</td>
                <td class="si-num" dir="ltr">${esc(r.doc_no || '')}</td>
                <td class="si-num" dir="ltr">${dmy(r.doc_date)}</td>
                <td>${esc(r.party_name || '')}</td>
                <td class="si-num" dir="ltr">${money(r.total)}</td>
                <td class="si-num" dir="ltr">${money(r.tax_amount)}</td>
              </tr>`
                )
                .join('') || `<tr><td colspan="6" class="empty">لا مستندات في الفترة.</td></tr>`
            }
            </tbody>
            ${
              rows.length
                ? `<tfoot><tr class="ar-open">
                    <td colspan="4"><strong>المجموع</strong></td>
                    <td class="si-num" dir="ltr"><strong>${money(data.sum_total)}</strong></td>
                    <td class="si-num" dir="ltr"><strong>${money(data.sum_tax)}</strong></td>
                  </tr></tfoot>`
                : ''
            }
          </table></div>
        </div>`;
    }
  }
  const peer = conf.peer
    ? `<a class="si-btn" href="${conf.peer}?run=1&from=${esc(from)}&to=${esc(to)}">${esc(
        conf.peerLabel || 'التقرير المقابل'
      )}</a>`
    : '';
  const filters = `
    <form class="si-search ar-filters" method="get" action="${conf.path}">
      <input type="hidden" name="run" value="1">
      ${dateFields(from, to)}
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
      ${peer}
      <a class="si-btn" href="/accounting/tax/vat-net?run=1&from=${esc(from)}&to=${esc(to)}">أمانات الضريبة</a>
    </form>
    <p class="muted no-print">${esc(conf.hint || '')}</p>`;
  sendPage(res, req, conf.title, shell(conf.title, conf.subtitle || '', filters, result));
}

router.get('/accounting/tax/sales', (req, res) =>
  vatDocTax(req, res, {
    path: '/accounting/tax/sales',
    perm: 'report_invoice_tax',
    action: 'vat_invoice_tax',
    kind: 'sale',
    kindLabel: 'بيع',
    title: 'ضريبة فواتير البيع',
    subtitle: 'فواتير بيع مرحّلة — بدون مردودات',
    hint: 'فواتير بيع مرحّلة فقط — بدون مردودات.',
    peer: '/accounting/tax/purchases',
    peerLabel: 'فواتير الشراء',
  })
);

router.get('/accounting/tax/purchases', (req, res) =>
  vatDocTax(req, res, {
    path: '/accounting/tax/purchases',
    perm: 'report_invoice_tax',
    action: 'vat_invoice_tax',
    kind: 'purchase',
    kindLabel: 'شراء',
    title: 'ضريبة فواتير الشراء',
    subtitle: 'فواتير شراء مرحّلة — بدون مردودات',
    hint: 'فواتير شراء مرحّلة فقط — بدون مردودات.',
    peer: '/accounting/tax/sales',
    peerLabel: 'فواتير البيع',
  })
);

router.get('/accounting/tax/sales-return', (req, res) =>
  vatDocTax(req, res, {
    path: '/accounting/tax/sales-return',
    perm: 'report_vat_return_tax',
    action: 'vat_return_tax',
    kind: 'sale',
    kindLabel: 'مردود بيع',
    title: 'ضريبة مردود البيع',
    subtitle: 'مردود بيع مرحّل محاسبياً',
    hint: 'مردود بيع مرحّل — ضريبة المردود فقط.',
    peer: '/accounting/tax/purchase-return',
    peerLabel: 'مردود الشراء',
  })
);

router.get('/accounting/tax/purchase-return', (req, res) =>
  vatDocTax(req, res, {
    path: '/accounting/tax/purchase-return',
    perm: 'report_vat_return_tax',
    action: 'vat_return_tax',
    kind: 'purchase',
    kindLabel: 'مردود شراء',
    title: 'ضريبة مردود الشراء',
    subtitle: 'مردود شراء مرحّل محاسبياً',
    hint: 'مردود شراء مرحّل — ضريبة المردود فقط.',
    peer: '/accounting/tax/sales-return',
    peerLabel: 'مردود البيع',
  })
);

/* ─── أر/3 (ضريبة رواتب) ─── */
router.get('/accounting/tax/ar3', async (req, res) => {
  if (!can(req.session.user, 'report_tax_ar3')) return forbid(res);
  const year = Number(req.query.year || new Date().getFullYear());
  const employeeId = Number(req.query.employee_id || 0) || 0;
  const show = String(req.query.show || '') === '1' || String(req.query.run || '') === '1';

  const meta = await svc.run('tax_ar3_meta', uid(req), { year });
  if (!meta.ok) {
    return sendPage(
      res,
      req,
      'تقرير الضريبة (أر/3)',
      `<div class="si-stage"><p class="si-pill si-pill--lock">${esc(meta.error || '')}</p></div>`
    );
  }
  const employees = meta.employees || [];
  const years = meta.posted_years || [];
  let yOpts = years.length
    ? years
    : Array.from({ length: 8 }, (_, i) => new Date().getFullYear() - i);
  if (!yOpts.includes(year)) yOpts = [year, ...yOpts];

  let result = '<p class="muted">اختر السنة والموظف ثم اعرض التقرير.</p>';
  if (show) {
    const data = await svc.run('tax_ar3', uid(req), { year, employee_id: employeeId });
    if (!data.ok) {
      result = `<p class="si-pill si-pill--lock">${esc(data.error || '')}</p>`;
    } else if (data.year_not_posted) {
      result = `<p class="si-pill si-pill--lock">${esc(
        data.message || 'لا رواتب مرحّلة لهذه السنة.'
      )}</p>`;
    } else {
      const r = data.report || {};
      const emp = r.employee || r.emp || {};
      const months = r.months || r.monthly || r.rows || [];
      const employer = r.employer || {};
      result = `
        <div class="si-surface sh-section">
          <h2>تقرير الضريبة (أر/3) — ${year}</h2>
          <p>
            <strong>${esc(emp.name_ar || emp.full_name || emp.name || '')}</strong>
            ${emp.emp_code || emp.code ? ` · <span dir="ltr">${esc(emp.emp_code || emp.code)}</span>` : ''}
          </p>
          ${
            employer.name || employer.company_name
              ? `<p class="muted">المنشأة: ${esc(employer.name || employer.company_name)}</p>`
              : ''
          }
          <div class="si-table-wrap"><table class="si-table">
            <thead><tr>
              <th>الشهر</th><th>الراتب/الأجر</th><th>الضريبة</th>
            </tr></thead>
            <tbody>
            ${
              (Array.isArray(months) ? months : [])
                .map((m) => {
                  const wage =
                    m.wage != null
                      ? m.wage
                      : m.salary != null
                        ? m.salary
                        : m.gross != null
                          ? m.gross
                          : m.amount;
                  const tax =
                    m.tax != null ? m.tax : m.tax_amount != null ? m.tax_amount : m.income_tax;
                  const mon = m.month_label || m.month_name || m.label || m.month || '';
                  return `<tr>
                    <td>${esc(String(mon))}</td>
                    <td class="si-num" dir="ltr">${
                      typeof wage === 'object' && wage
                        ? esc(String(wage.dinar ?? '') + (wage.fils != null ? '.' + String(wage.fils).padStart(3, '0') : ''))
                        : money(wage)
                    }</td>
                    <td class="si-num" dir="ltr">${
                      typeof tax === 'object' && tax
                        ? esc(String(tax.dinar ?? '') + (tax.fils != null ? '.' + String(tax.fils).padStart(3, '0') : ''))
                        : money(tax)
                    }</td>
                  </tr>`;
                })
                .join('') || `<tr><td colspan="3" class="empty">لا أشهر مرحّلة.</td></tr>`
            }
            </tbody>
            ${
              r.totals
                ? `<tfoot><tr class="ar-open">
                    <td><strong>المجموع</strong></td>
                    <td class="si-num" dir="ltr"><strong>${money(
                      r.totals.wage || r.totals.salary || r.totals.gross
                    )}</strong></td>
                    <td class="si-num" dir="ltr"><strong>${money(
                      r.totals.tax || r.totals.tax_amount
                    )}</strong></td>
                  </tr></tfoot>`
                : ''
            }
          </table></div>
        </div>`;
    }
  }

  const empOpts = employees
    .map(
      (e) =>
        `<option value="${e.id}" ${Number(employeeId) === Number(e.id) ? 'selected' : ''}>${esc(
          (e.emp_code || e.code ? (e.emp_code || e.code) + ' — ' : '') +
            (e.name_ar || e.name || '')
        )}</option>`
    )
    .join('');

  const filters = `
    <form class="si-search ar-filters" method="get" action="/accounting/tax/ar3">
      <input type="hidden" name="show" value="1">
      <label>السنة
        <select class="si-field" name="year">
          ${yOpts
            .map((y) => `<option value="${y}" ${Number(y) === year ? 'selected' : ''}>${y}</option>`)
            .join('')}
        </select>
      </label>
      <label>الموظف
        <select class="si-field" name="employee_id" required style="min-width:16rem">
          <option value="">— اختر —</option>
          ${empOpts}
        </select>
      </label>
      <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
    </form>
    <p class="muted no-print">يعرض بيانات ضريبة الدخل من الرواتب المرحّلة فقط (نموذج أر/3).</p>`;
  sendPage(
    res,
    req,
    'تقرير الضريبة (أر/3)',
    shell('تقرير الضريبة (أر/3)', 'ضريبة رواتب الموظفين', filters, result)
  );
});

module.exports = router;
