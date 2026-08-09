'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');
const svc = require('./advancesService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/advances';
const PAY = '/accounting/payments/entry';
const PERM = 'fin_employee_advances';

function can(user) {
  return (
    user.is_admin ||
    auth.userCan(user, PERM) ||
    auth.userCan(user, 'cash_payment') ||
    auth.userCan(user, 'cash_payments_list')
  );
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (p !== BASE && !p.startsWith(BASE + '/')) return next('router');
  return auth.requireAuth(req, res, next);
});

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  if (!(await svc.tableReady())) {
    return res.status(500).send(
      ui.salesPage({
        user: req.session.user,
        title: 'السلف',
        bodyHtml: `<div class="si-stage">${ui.hero({
          mark: 'Ac',
          kicker: KICKER,
          title: 'السلف',
          subtitle:
            'جدول hr_employee_advance غير جاهز — نفّذ ترحيلات قاعدة البيانات 154 و164 و167.',
          actions: [{ label: 'لوحة المحاسبة', href: HUB }],
        })}</div>`,
      })
    );
  }

  const [rows, cashAccounts, payableLabel] = await Promise.all([
    svc.listPendingDisbursement(),
    svc.listCashAccounts(),
    svc.payableAccountLabel(),
  ]);
  const defaultCash = await svc.defaultCashAccountId(cashAccounts);
  const sum = rows.reduce((s, r) => s + Number(r.total_amount || 0), 0);

  const cashOpts = (selected) =>
    cashAccounts
      .map(
        (a) =>
          `<option value="${a.id}" ${Number(selected) === Number(a.id) ? 'selected' : ''}>${esc(
            (a.code || '') + ' — ' + (a.name_ar || '')
          )}</option>`
      )
      .join('');

  const rowsHtml =
    rows
      .map((r) => {
        const empLabel =
          ((r.emp_code ? r.emp_code + ' — ' : '') + (r.emp_name || '')).trim() || '—';
        const posted = r.posted_at ? isoToDmy(String(r.posted_at).slice(0, 10)) : '—';
        const notes = String(r.notes || '').trim() || '—';
        const cashCell =
          cashAccounts.length === 0
            ? `<span class="muted">لا توجد حسابات صندوق/بنك</span>`
            : `<select class="si-field" id="adv-cash-${r.id}" aria-label="حساب الصندوق أو البنك">
                 ${cashOpts(defaultCash)}
               </select>`;
        const action =
          cashAccounts.length === 0
            ? `<button class="si-btn si-btn--primary" type="button" disabled>صرف السلفة</button>`
            : `<button type="button" class="si-btn si-btn--primary adv-disburse"
                 data-advance-id="${r.id}">صرف السلفة</button>`;
        return `<tr>
          <td class="si-num" dir="ltr">${esc(r.advance_code || String(r.id))}</td>
          <td>${esc(empLabel)}</td>
          <td>${esc(r.advance_type_label || '—')}</td>
          <td>${esc(r.period_label || '—')}</td>
          <td class="si-num" dir="ltr">${esc(fmtAmt(r.total_amount))}</td>
          <td class="si-num" dir="ltr">${esc(posted)}</td>
          <td>${esc(notes)}</td>
          <td>${cashCell}</td>
          <td class="sh-actions">${action}</td>
        </tr>`;
      })
      .join('') ||
    `<tr><td colspan="9" class="empty">لا توجد سلف بانتظار الصرف.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page" id="adv-screen" data-payment-url="${PAY}">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'السلف',
        subtitle: 'سلف معتمدة ومرحّلة بانتظار الصرف النقدي',
        actions: [
          { label: 'سند صرف', href: PAY + '?new=1' },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <p class="si-pill" style="display:block;margin:.5rem 0 1rem;padding:.75rem 1rem;background:#e8f4fc;color:#1a3a52;border-radius:8px;line-height:1.6">
        السلف المعتمدة والمرحّلة من شؤون الموظفين بانتظار الصرف النقدي.
        حساب الصرف المُدين: <strong>${esc(payableLabel)}</strong>.
        بعد حفظ وترحيل سند الصرف تختفي السلفة من هذه القائمة.
      </p>
      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>السلف بانتظار الصرف</h2>
          <span class="si-count">${rows.length} — الإجمالي ${esc(fmtAmt(sum))}</span>
        </div>
        <div class="si-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>رقم السلفة</th>
                <th>الموظف</th>
                <th>نوع السلفة</th>
                <th>الفترة</th>
                <th>المبلغ</th>
                <th>تاريخ الترحيل</th>
                <th>ملاحظات</th>
                <th>يُخصم من حساب</th>
                <th>إجراء</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
      </section>
    </div>
    <script>
    (function(){
      var screen = document.getElementById('adv-screen');
      if (!screen) return;
      var paymentBase = screen.getAttribute('data-payment-url') || '';
      screen.querySelectorAll('.adv-disburse').forEach(function(btn){
        btn.addEventListener('click', function(){
          var advanceId = parseInt(btn.getAttribute('data-advance-id') || '0', 10);
          if (advanceId < 1) return;
          var sel = document.getElementById('adv-cash-' + advanceId);
          var cashId = sel ? parseInt(sel.value || '0', 10) : 0;
          if (cashId < 1) {
            alert('اختر حساب الصندوق أو البنك الذي يُخصم منه مبلغ السلفة.');
            if (sel) sel.focus();
            return;
          }
          var url = paymentBase
            + (paymentBase.indexOf('?') >= 0 ? '&' : '?')
            + 'disburse_advance=' + encodeURIComponent(String(advanceId))
            + '&cash_account_id=' + encodeURIComponent(String(cashId));
          window.location.href = url;
        });
      });
    })();
    </script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'السلف',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

module.exports = router;
