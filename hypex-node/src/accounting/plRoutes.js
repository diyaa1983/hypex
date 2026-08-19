'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');
const svc = require('./plService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/reports/pl';
const INCOME = '/accounting/reports/income';
const PERM = 'report_income_statement_comprehensive';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM);
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (p !== BASE && !p.startsWith(BASE + '/')) return next('router');
  return auth.requireAuth(req, res, next);
});

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) {
    return res.status(403).send('ممنوع');
  }
  const fromQ = String(req.query.from || req.query.date_from || '');
  const toQ = String(req.query.to || req.query.date_to || '');
  const report = await svc.comprehensivePl(fromQ, toQ);
  const from = report.date_from;
  const to = report.date_to;
  const isProfit = !!report.totals.net_is_profit;
  const netAmt = Number(report.totals.net_income || 0);
  const heroCls = isProfit ? 'pl-hero--profit' : 'pl-hero--loss';
  const heroLabel = isProfit ? 'صافي الربح' : 'صافي الخسارة';

  let rowsHtml = '';
  if (!report.has_activity) {
    rowsHtml = `<tr><td colspan="3" class="pl-empty">لا حركات على الإيرادات أو المصروفات في هذه الفترة.</td></tr>`;
  } else {
    rowsHtml = (report.summary_rows || [])
      .map((row) => {
        const style = String(row.style || 'normal');
        let cls = 'pl-row';
        if (style === 'subtotal') cls += ' pl-row--sub';
        if (style === 'total') cls += isProfit ? ' pl-row--total' : ' pl-row--total pl-row--loss';
        const amt = Number(row.amount || 0);
        let amtHtml = esc(fmtAmt(amt));
        if (row.deduction && Math.abs(amt) > 1e-6) {
          amtHtml = `<span class="pl-deduct">(${esc(fmtAmt(Math.abs(amt)))})</span>`;
        }
        return `<tr class="${cls}">
          <td class="si-num" dir="ltr">${Number(row.line_no) || ''}</td>
          <td>${esc(row.label || '')}</td>
          <td class="si-num" dir="ltr">${amtHtml}</td>
        </tr>`;
      })
      .join('');
  }

  const detailHref =
    INCOME +
    '?from=' +
    encodeURIComponent(from) +
    '&to=' +
    encodeURIComponent(to);

  const body = `
    <link rel="stylesheet" href="/assets/css/pl-report.css">
    <div class="si-stage si-report-page pl-report-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'الأرباح والخسائر',
        subtitle: 'تقرير ملخص لنتائج الفترة — إيرادات · تكلفة · مصروفات · صافي',
        actions: [
          ui.printAction(),
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <div class="si-rail no-print">
        <form class="si-search pl-filters" method="get" action="${BASE}" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
          <label class="pl-field">من تاريخ
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}">
          </label>
          <label class="pl-field">إلى تاريخ
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}">
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
          ${ui.siPrintBtnHtml('طباعة')}
          <a class="si-btn" href="${esc(detailHref)}">قائمة الدخل التفصيلية</a>
        </form>
      </div>

      <div class="si-print-area si-surface pl-result">
        <div class="pl-doc-head">
          <strong>${esc(report.company)}</strong>
          <h2>تقرير الأرباح والخسائر</h2>
          <p class="pl-dates" dir="ltr">من: ${esc(isoToDmy(from))} | إلى: ${esc(isoToDmy(to))}</p>
        </div>

        <div class="pl-hero ${heroCls}">
          <span class="pl-hero__label">${esc(heroLabel)}</span>
          <strong class="pl-hero__amount" dir="ltr">${esc(fmtAmt(netAmt))}</strong>
        </div>

        <div class="si-table-wrap pl-table-wrap">
          <table class="si-table pl-table">
            <thead>
              <tr>
                <th style="width:3rem">م</th>
                <th>البيان</th>
                <th style="width:9rem">المبلغ</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'الأرباح والخسائر',
      bodyHtml: body,
      css: ['/assets/css/pl-report.css'],
      js: ['/assets/js/sales-print.js'],
      activePath: BASE,
    })
  );
});

module.exports = router;
