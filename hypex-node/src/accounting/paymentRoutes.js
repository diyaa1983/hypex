'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy, todayIso, parseDateToIso } = require('../lib/html');
const svc = require('./paymentService');
const advSvc = require('./advancesService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/payments/entry';
const LIST = '/accounting/payments';
const PERM = 'cash_payment';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM) || auth.userCan(user, 'cash_payments_list');
}
function canPost(user) {
  return user.is_admin || auth.userCan(user, 'action_post_cash_payment');
}
function canUnpost(user) {
  return user.is_admin || auth.userCan(user, 'action_unpost_cash_payment');
}
function canDelete(user) {
  return user.is_admin || auth.userCan(user, 'action_delete_cash_payment');
}
function canCancel(user) {
  return user.is_admin || auth.userCan(user, 'action_cancel_cash_payment');
}
function forbid(res) {
  return res.status(403).send('ممنوع');
}
function uid(req) {
  return Number(req.session.user?.id || 0) || 0;
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    p !== BASE &&
    !p.startsWith(BASE + '/') &&
    p !== LIST &&
    !p.startsWith(LIST + '/')
  ) {
    return next('router');
  }
  return auth.requireAuth(req, res, next);
});

function flashHtml(req) {
  const msg = String(req.query.msg || '');
  const err = String(req.query.err || '');
  return (
    (msg
      ? `<p class="si-pill si-pill--ok" style="display:inline-block;margin:.25rem 0">${esc(msg)}</p>`
      : '') +
    (err
      ? `<p class="si-pill si-pill--lock" style="display:inline-block;margin:.25rem 0">${esc(err)}</p>`
      : '')
  );
}

function partyLabel(t) {
  return (
    { supplier: 'مورد', customer: 'عميل', employee: 'موظف', account: 'حساب آخر' }[t] || t
  );
}
function payLabel(m) {
  if (m === 'check') return 'شيك';
  if (m === 'bank') return 'بنك';
  return 'نقداً';
}

router.get(LIST, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const qv = String(req.query.q || '').trim();
  const rows = await svc.listUnposted({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
        <td class="si-num" dir="ltr"><a class="si-inv-no" href="${BASE}?id=${r.id}">${esc(
          r.voucher_no || ''
        )}</a></td>
        <td class="si-num" dir="ltr">${esc(isoToDmy(r.voucher_date))}</td>
        <td>${esc(partyLabel(r.party_type))}</td>
        <td>${esc(r.party_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(r.amount))}</td>
        <td>${esc(r.description || '—')}</td>
        <td>${ui.statusPill('wait', 'مسودة')}</td>
        <td class="sh-actions"><a class="si-btn" href="${BASE}?id=${r.id}">فتح</a></td>
      </tr>`
      )
      .join('') || `<tr><td colspan="8" class="empty">لا توجد سندات بانتظار الترحيل.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'ترحيل سندات الصرف',
        subtitle: 'سندات صرف غير مرحّلة',
        actions: [
          { label: 'سند صرف جديد', href: BASE, primary: true },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <div class="si-rail">
        <form class="si-search" method="get" action="${LIST}">
          <input type="search" name="q" value="${esc(qv)}" placeholder="رقم السند أو الطرف…" autocomplete="off">
          <button class="si-btn si-btn--primary" type="submit">بحث</button>
        </form>
      </div>
      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>قائمة السندات</h2>
          <span class="si-count">${rows.length}</span>
        </div>
        <div class="si-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>الرقم</th><th>التاريخ</th><th>النوع</th><th>الطرف</th>
                <th>المبلغ</th><th>البيان</th><th>الحالة</th><th></th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
      </section>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'ترحيل سندات الصرف',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: LIST,
    })
  );
});

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  const lookup = String(req.query.voucher_lookup || req.query.no || '').trim();
  if (lookup) {
    const found = await svc.findByNo(lookup);
    if (!found) {
      return res.redirect(
        BASE + '?new=1&err=' + encodeURIComponent('لم يُعثر على سند يطابق «' + lookup + '».')
      );
    }
    return res.redirect(BASE + '?id=' + found.id);
  }

  const editId = Number(req.query.id || 0) || 0;
  const isNew = String(req.query.new || '') === '1' || editId < 1;

  const [customers, suppliers, employees, cashAccounts, offsetAccounts] = await Promise.all([
    svc.listCustomers(),
    svc.listSuppliers(),
    svc.listEmployees(),
    svc.listCashAccounts(),
    svc.listOffsetAccounts(),
  ]);

  let form = {
    id: 0,
    voucher_no: '',
    voucher_date: todayIso(),
    party_type: 'supplier',
    party_id: 0,
    party_label: '',
    offset_account_id: 0,
    cash_account_id: cashAccounts[0] ? Number(cashAccounts[0].id) : 0,
    pay_method: 'cash',
    amount: '',
    check_no: '',
    check_amount: '',
    bank_name: '',
    check_due_date: '',
    notes: '',
    hr_advance_id: 0,
    is_posted: 0,
    is_cancelled: 0,
    prev_id: 0,
    next_id: 0,
  };

  if (!isNew && editId > 0) {
    const v = await svc.getVoucher(editId);
    if (v) {
      form = {
        id: Number(v.id),
        voucher_no: String(v.voucher_no || ''),
        voucher_date: String(v.voucher_date || '').slice(0, 10),
        party_type: String(v.party_type || 'supplier'),
        party_id: Number(v.party_id || 0),
        party_label:
          (v.party_code ? v.party_code + ' — ' : '') + (v.party_name || ''),
        offset_account_id: Number(v.offset_account_id || 0),
        cash_account_id: Number(v.cash_account_id || 0),
        pay_method: String(v.pay_method || 'cash'),
        amount: v.amount != null ? String(v.amount) : '',
        check_no: String(v.check_no || ''),
        check_amount:
          v.check_amount != null && Number(v.check_amount) > 0
            ? String(v.check_amount)
            : '',
        bank_name: String(v.bank_name || ''),
        check_due_date: String(v.check_due_date || ''),
        notes: String(v.description || ''),
        hr_advance_id: Number(v.hr_advance_id || 0),
        is_posted: Number(v.is_posted) === 1 ? 1 : 0,
        is_cancelled: Number(v.is_cancelled) === 1 ? 1 : 0,
        prev_id: Number(v.prev_id || 0),
        next_id: Number(v.next_id || 0),
      };
    }
  } else if (isNew) {
    const disburseId = Number(req.query.disburse_advance || 0) || 0;
    const cashQ = Number(req.query.cash_account_id || 0) || 0;
    if (disburseId > 0) {
      const boot = await advSvc.getDisburseBootstrap(disburseId, cashQ);
      if (boot) {
        form.party_type = 'employee';
        form.party_id = Number(boot.employee_id || 0);
        form.party_label =
          (boot.emp_code ? boot.emp_code + ' — ' : '') + (boot.emp_name || '');
        form.amount = boot.amount > 0 ? String(boot.amount) : '';
        form.notes = String(boot.notes || '');
        form.hr_advance_id = Number(boot.advance_id || 0);
        if (Number(boot.cash_account_id) > 0) {
          form.cash_account_id = Number(boot.cash_account_id);
        }
      }
    }
  }

  const posted = form.is_posted === 1;
  const cancelled = form.is_cancelled === 1;
  const canEdit = !posted && !cancelled;
  const lockHr = form.hr_advance_id > 0 && canEdit;
  const user = req.session.user;
  const ro = canEdit ? '' : 'readonly';
  const dis = canEdit ? '' : 'disabled';

  const optHtml = (rows, sel, codeKey = 'code', nameKey = 'name_ar') =>
    rows
      .map((r) => {
        const label =
          (r[codeKey] || r.emp_code ? (r[codeKey] || r.emp_code) + ' — ' : '') +
          (r[nameKey] || '');
        return `<option value="${r.id}" ${Number(sel) === Number(r.id) ? 'selected' : ''}>${esc(
          label
        )}</option>`;
      })
      .join('');

  const cashOpts = cashAccounts
    .map(
      (a) =>
        `<option value="${a.id}" ${
          Number(form.cash_account_id) === Number(a.id) ? 'selected' : ''
        }>${esc((a.code || '') + ' — ' + (a.name_ar || ''))}</option>`
    )
    .join('');

  const statusPill = cancelled
    ? ui.statusPill('lock', 'ملغى')
    : posted
      ? ui.statusPill('ok', 'مرحّل')
      : form.id
        ? ui.statusPill('wait', 'مسودة')
        : ui.statusPill('wait', 'جديد');

  const body = `
    <link rel="stylesheet" href="/assets/css/fin-receipt-node.css">
    <div class="si-stage sh-page rc-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'سند صرف',
        subtitle: form.id
          ? 'سند رقم ' + form.voucher_no + ' · ' + partyLabel(form.party_type)
          : form.hr_advance_id
            ? 'صرف سلفة موظف رقم ' + form.hr_advance_id
            : 'صرف لمورد أو عميل أو موظف أو حساب',
        actions: [
          { label: 'سند جديد', href: BASE + '?new=1', primary: true },
          { label: 'السلف', href: '/accounting/advances' },
          { label: 'ترحيل السندات', href: LIST },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${
        form.hr_advance_id
          ? `<p class="si-pill" style="display:block;margin:.35rem 0;padding:.65rem 1rem;background:#e8f4fc;color:#1a3a52;border-radius:8px">
              صرف سلفة موظفين — رقم المرجع: <strong dir="ltr">${form.hr_advance_id}</strong>
              · الموظف والحقل مربوطان بالسلفة.
            </p>`
          : ''
      }
      <div class="rc-status">${statusPill}</div>

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>بيانات السند</h2>
          <div class="sh-actions">
            ${
              form.prev_id
                ? `<a class="si-btn" href="${BASE}?id=${form.prev_id}">‹</a>`
                : ''
            }
            ${
              form.next_id
                ? `<a class="si-btn" href="${BASE}?id=${form.next_id}">›</a>`
                : ''
            }
            ${
              form.id && !posted && !cancelled && canPost(user)
                ? `<form method="post" action="${BASE}/post" style="display:inline">
                    <input type="hidden" name="id" value="${form.id}">
                    <button class="si-btn si-btn--primary" type="submit">ترحيل</button>
                  </form>`
                : ''
            }
            ${
              form.id && posted && !cancelled && canUnpost(user)
                ? `<form method="post" action="${BASE}/unpost" style="display:inline"
                      onsubmit="return confirm('فك ترحيل السند؟');">
                    <input type="hidden" name="id" value="${form.id}">
                    <button class="si-btn" type="submit">فك الترحيل</button>
                  </form>`
                : ''
            }
            ${
              form.id && canEdit && canDelete(user)
                ? `<form method="post" action="${BASE}/delete" style="display:inline"
                      onsubmit="return confirm('حذف سند الصرف؟');">
                    <input type="hidden" name="id" value="${form.id}">
                    <button class="si-btn si-btn--danger" type="submit">حذف</button>
                  </form>`
                : ''
            }
            ${
              form.id && !cancelled && canCancel(user) && posted
                ? `<form method="post" action="${BASE}/cancel" style="display:inline"
                      onsubmit="return confirm('إلغاء السند مع المحافظة على الرقم؟');">
                    <input type="hidden" name="id" value="${form.id}">
                    <button class="si-btn si-btn--danger" type="submit">إلغاء السند</button>
                  </form>`
                : ''
            }
            <button type="button" class="si-btn" onclick="window.print()">طباعة</button>
          </div>
        </div>
        <div class="sh-body">
          <form method="get" action="${BASE}" class="sh-form rc-lookup">
            <label>بحث برقم السند
              <input class="si-field si-field--mono" name="voucher_lookup" dir="ltr" placeholder="رقم السند">
            </label>
            <div class="sh-form-actions"><button class="si-btn" type="submit">بحث</button></div>
          </form>

          <form method="post" action="${BASE}/save" class="sh-form" id="py-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <input type="hidden" name="hr_advance_id" value="${form.hr_advance_id || 0}">
            <label>رقم السند
              <input class="si-field si-field--mono" name="voucher_no" dir="ltr"
                     value="${esc(form.voucher_no)}" placeholder="يُولَّد تلقائياً"
                     ${form.id ? 'readonly' : ''}>
            </label>
            <label>التاريخ *
              <input class="si-field si-field--mono" type="date" name="voucher_date" required
                     value="${esc(form.voucher_date)}" ${ro}>
            </label>

            <div class="rc-pay-row" style="grid-column:1/-1">
              <span style="font-weight:700;color:#5c6578;font-size:.8rem">نوع الطرف *</span>
              ${['supplier', 'customer', 'employee', 'account']
                .map(
                  (t) => `<label class="rc-pay-opt">
                <input type="radio" name="party_type" value="${t}" ${
                  form.party_type === t ? 'checked' : ''
                } ${dis || lockHr ? 'disabled' : ''}> ${partyLabel(t)}
              </label>`
                )
                .join('')}
              ${
                !canEdit || lockHr
                  ? `<input type="hidden" name="party_type" value="${esc(form.party_type)}">`
                  : ''
              }
            </div>

            <label class="py-party" data-party="supplier">المورد *
              ${
                canEdit
                  ? `<select class="si-field" name="supplier_id" id="py_supplier">
                      <option value="">— اختر —</option>
                      ${optHtml(suppliers, form.party_type === 'supplier' ? form.party_id : 0)}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      form.party_type === 'supplier' ? form.party_label : ''
                    )}">`
              }
            </label>
            <label class="py-party" data-party="customer">العميل *
              ${
                canEdit
                  ? `<select class="si-field" name="customer_id" id="py_customer">
                      <option value="">— اختر —</option>
                      ${optHtml(customers, form.party_type === 'customer' ? form.party_id : 0)}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      form.party_type === 'customer' ? form.party_label : ''
                    )}">`
              }
            </label>
            <label class="py-party" data-party="employee">الموظف *
              ${
                canEdit && !lockHr
                  ? `<select class="si-field" name="employee_id" id="py_employee">
                      <option value="">— اختر —</option>
                      ${optHtml(employees, form.party_type === 'employee' ? form.party_id : 0)}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      form.party_type === 'employee' ? form.party_label : ''
                    )}">
                    <input type="hidden" name="employee_id" value="${
                      form.party_type === 'employee' ? form.party_id : 0
                    }">`
              }
            </label>
            <label class="py-party" data-party="account">الحساب المُصروف إليه *
              ${
                canEdit
                  ? `<select class="si-field" name="offset_account_id" id="py_offset">
                      <option value="">— اختر —</option>
                      ${optHtml(offsetAccounts, form.offset_account_id)}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      form.party_type === 'account' ? form.party_label : ''
                    )}">
                    <input type="hidden" name="offset_account_id" value="${form.offset_account_id}">`
              }
            </label>
            <p class="sh-hint" style="grid-column:1/-1;margin:0">
              مورد = ذمم / عميل = مردود أو تسوية / موظف = سلف ورواتب / حساب آخر = مصروف أو خصم من الشجرة.
            </p>

            <div class="rc-pay-row" style="grid-column:1/-1">
              ${['cash', 'bank', 'check']
                .map(
                  (m) => `<label class="rc-pay-opt">
                <input type="radio" name="pay_method" value="${m}" ${
                  form.pay_method === m ? 'checked' : ''
                } ${dis}> ${payLabel(m)}
              </label>`
                )
                .join('')}
              ${!canEdit ? `<input type="hidden" name="pay_method" value="${esc(form.pay_method)}">` : ''}
            </div>

            <label>يُخصم من *
              ${
                canEdit
                  ? `<select class="si-field" name="cash_account_id" required>
                      <option value="">— اختر —</option>
                      ${cashOpts}
                    </select>`
                  : (() => {
                      const a = cashAccounts.find(
                        (x) => Number(x.id) === Number(form.cash_account_id)
                      );
                      return `<input class="si-field" readonly value="${esc(
                        a ? a.code + ' — ' + a.name_ar : String(form.cash_account_id)
                      )}">
                      <input type="hidden" name="cash_account_id" value="${form.cash_account_id}">`;
                    })()
              }
            </label>

            <label class="py-amt">المبلغ *
              <input class="si-field si-field--mono" name="amount" dir="ltr"
                     value="${esc(form.amount)}" placeholder="0.00" ${ro}>
            </label>

            <div class="py-check-fields" style="grid-column:1/-1;display:contents">
              <label>رقم الشيك
                <input class="si-field si-field--mono" name="check_no" dir="ltr"
                       value="${esc(form.check_no)}" ${ro}>
              </label>
              <label>قيمة الشيك
                <input class="si-field si-field--mono" name="check_amount" dir="ltr"
                       value="${esc(form.check_amount)}" ${ro}>
              </label>
              <label>البنك
                <input class="si-field" name="bank_name" value="${esc(form.bank_name)}" ${ro}>
              </label>
              <label>تاريخ الاستحقاق
                <input class="si-field si-field--mono" type="date" name="check_due_date"
                       value="${esc(form.check_due_date)}" ${ro}>
              </label>
            </div>

            <label style="grid-column:1/-1">ملاحظات
              <textarea class="si-field" name="notes" rows="2" ${ro}
                        placeholder="اختياري">${esc(form.notes)}</textarea>
            </label>

            ${
              canEdit
                ? `<div class="sh-form-actions">
                    <button class="si-btn si-btn--primary" type="submit">حفظ</button>
                    <a class="si-btn" href="${BASE}?new=1">جديد</a>
                  </div>`
                : ''
            }
          </form>
        </div>
      </section>
    </div>
    <script>
    (function(){
      var form = document.getElementById('py-form');
      if (!form) return;
      function syncParty(){
        var m = (form.querySelector('input[name=party_type]:checked')||{}).value || 'supplier';
        form.querySelectorAll('.py-party').forEach(function(el){
          el.hidden = el.getAttribute('data-party') !== m;
        });
      }
      function syncPay(){
        var m = (form.querySelector('input[name=pay_method]:checked')||{}).value || 'cash';
        form.querySelectorAll('.py-check-fields label').forEach(function(el){
          el.style.display = m === 'check' ? '' : 'none';
        });
      }
      form.querySelectorAll('input[name=party_type]').forEach(function(r){ r.addEventListener('change', syncParty); });
      form.querySelectorAll('input[name=pay_method]').forEach(function(r){ r.addEventListener('change', syncPay); });
      syncParty(); syncPay();
    })();
    </script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'سند صرف',
      bodyHtml: body,
      css: ['/assets/css/fin-receipt-node.css', '/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const body = req.body || {};
  const id = Number(body.id || 0) || 0;
  const hrAdvanceId = Number(body.hr_advance_id || 0) || 0;
  const result = await svc.phpAction('save', uid(req), {
    voucher_id: id,
    voucher_no: body.voucher_no,
    voucher_date: parseDateToIso(body.voucher_date || '', todayIso()),
    party_type: body.party_type,
    supplier_id: body.supplier_id,
    customer_id: body.customer_id,
    employee_id: body.employee_id,
    offset_account_id: body.offset_account_id,
    cash_account_id: body.cash_account_id,
    pay_method: body.pay_method,
    amount: body.amount,
    check_no: body.check_no,
    check_amount: body.check_amount || body.amount,
    bank_name: body.bank_name,
    check_due_date: body.check_due_date
      ? parseDateToIso(body.check_due_date, null) || body.check_due_date
      : body.check_due_date,
    notes: body.notes,
    hr_advance_id: hrAdvanceId,
  });
  if (!result.ok) {
    const back =
      id > 0
        ? '?id=' + id
        : hrAdvanceId > 0
          ? '?new=1&disburse_advance=' +
            hrAdvanceId +
            (body.cash_account_id
              ? '&cash_account_id=' + encodeURIComponent(String(body.cash_account_id))
              : '')
          : '?new=1';
    return res.redirect(BASE + back + '&err=' + encodeURIComponent(result.error || 'تعذر الحفظ'));
  }
  res.redirect(
    BASE +
      '?id=' +
      (result.voucher_id || id) +
      '&msg=' +
      encodeURIComponent(result.message || 'تم الحفظ')
  );
});

async function actionRedirect(req, res, action) {
  if (!can(req.session.user)) return forbid(res);
  if (action === 'post' && !canPost(req.session.user)) return forbid(res);
  if (action === 'unpost' && !canUnpost(req.session.user)) return forbid(res);
  if (action === 'delete' && !canDelete(req.session.user)) return forbid(res);
  if (action === 'cancel' && !canCancel(req.session.user)) return forbid(res);
  const id = Number(req.body?.id || 0);
  const result = await svc.phpAction(action, uid(req), { voucher_id: id });
  if (action === 'delete' && result.ok) {
    return res.redirect(
      BASE + '?new=1&msg=' + encodeURIComponent(result.message || 'تم الحذف')
    );
  }
  res.redirect(
    BASE +
      '?id=' +
      id +
      '&' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
}

router.post(BASE + '/post', (req, res) => actionRedirect(req, res, 'post'));
router.post(BASE + '/unpost', (req, res) => actionRedirect(req, res, 'unpost'));
router.post(BASE + '/delete', (req, res) => actionRedirect(req, res, 'delete'));
router.post(BASE + '/cancel', (req, res) => actionRedirect(req, res, 'cancel'));

module.exports = router;
