'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy, todayIso, parseDateToIso } = require('../lib/html');
const svc = require('./receiptService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/receipts/entry';
const LIST = '/accounting/receipts';
const PERM = 'cash_receipt';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM) || auth.userCan(user, 'cash_receipts_list');
}

function canPost(user) {
  return user.is_admin || auth.userCan(user, 'action_post_cash_receipt');
}
function canUnpost(user) {
  return user.is_admin || auth.userCan(user, 'action_unpost_cash_receipt');
}
function canDelete(user) {
  return user.is_admin || auth.userCan(user, 'action_delete_cash_receipt');
}
function canCancel(user) {
  return user.is_admin || auth.userCan(user, 'action_cancel_cash_receipt');
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

function payLabel(m) {
  if (m === 'check') return 'شيك';
  if (m === 'bank') return 'بنك';
  return 'نقداً';
}

/* ── قائمة الترحيل ── */
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
        <td>${esc(r.customer_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(r.amount))}</td>
        <td>${esc(r.description || '—')}</td>
        <td>${ui.statusPill('wait', 'مسودة')}</td>
        <td class="sh-actions"><a class="si-btn" href="${BASE}?id=${r.id}">فتح</a></td>
      </tr>`
      )
      .join('') || `<tr><td colspan="7" class="empty">لا توجد سندات بانتظار الترحيل.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'ترحيل سندات القبض',
        subtitle: 'سندات قبض غير مرحّلة',
        actions: [
          { label: 'سند قبض جديد', href: BASE, primary: true },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      <div class="si-rail">
        <form class="si-search" method="get" action="${LIST}">
          <input type="search" name="q" value="${esc(qv)}" placeholder="رقم السند أو العميل…" autocomplete="off">
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
                <th>الرقم</th><th>التاريخ</th><th>العميل</th><th>المبلغ</th><th>البيان</th><th>الحالة</th><th></th>
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
      title: 'ترحيل سندات القبض',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: LIST,
    })
  );
});

/* ── إدخال / تعديل ── */
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
  const customers = await svc.listCustomers();

  let form = {
    id: 0,
    voucher_no: '',
    voucher_date: todayIso(),
    customer_id: 0,
    customer_label: '',
    sales_rep_name: '',
    pay_method: 'cash',
    amount: '',
    notes: '',
    is_posted: 0,
    is_cancelled: 0,
    prev_id: 0,
    next_id: 0,
    checks: [],
  };

  if (!isNew && editId > 0) {
    const v = await svc.getVoucher(editId);
    if (v) {
      form = {
        id: Number(v.id),
        voucher_no: String(v.voucher_no || ''),
        voucher_date: String(v.voucher_date || '').slice(0, 10),
        customer_id: Number(v.party_id || 0),
        customer_label:
          (v.customer_code ? v.customer_code + ' — ' : '') + (v.customer_name || ''),
        sales_rep_name: String(v.sales_rep_name || ''),
        pay_method: String(v.pay_method || 'cash'),
        amount: v.amount != null ? String(v.amount) : '',
        notes: String(v.description || ''),
        is_posted: Number(v.is_posted) === 1 ? 1 : 0,
        is_cancelled: Number(v.is_cancelled) === 1 ? 1 : 0,
        prev_id: Number(v.prev_id || 0),
        next_id: Number(v.next_id || 0),
        checks: Array.isArray(v.checks) ? v.checks : [],
      };
    }
  }

  const posted = form.is_posted === 1;
  const cancelled = form.is_cancelled === 1;
  const canEdit = !posted && !cancelled;
  const user = req.session.user;

  const custOpts = customers
    .map((c) => {
      const label = (c.code ? c.code + ' — ' : '') + (c.name_ar || '');
      const rep = String(c.sales_rep_name || '');
      return `<option value="${c.id}" data-rep="${esc(rep)}" ${
        Number(form.customer_id) === Number(c.id) ? 'selected' : ''
      }>${esc(label)}</option>`;
    })
    .join('');

  // build check rows for form (at least one empty when check method)
  let checks = form.checks.length ? form.checks : [];
  if ((form.pay_method === 'check' && !checks.length) || !form.id) {
    if (form.pay_method === 'check' && !checks.length) {
      checks = [{ check_no: '', bank_name: '', check_amount: '', due_date: '' }];
    }
  }
  if (form.pay_method === 'check' && canEdit && checks.length < 1) {
    checks = [{ check_no: '', bank_name: '', check_amount: '', due_date: '' }];
  }

  const checksHtml = checks
    .map((chk, i) => {
      const due = chk.due_date ? String(chk.due_date).slice(0, 10) : '';
      return `<tr>
        <td class="si-num">${i + 1}</td>
        <td><input class="si-field si-field--mono" name="checks[${i}][check_no]" dir="ltr"
                   value="${esc(chk.check_no || '')}" ${canEdit ? '' : 'readonly'}></td>
        <td><input class="si-field si-field--mono" name="checks[${i}][check_amount]" dir="ltr"
                   value="${esc(chk.check_amount != null ? chk.check_amount : '')}" ${
                     canEdit ? '' : 'readonly'
                   }></td>
        <td><input class="si-field" name="checks[${i}][bank_name]"
                   value="${esc(chk.bank_name || '')}" ${canEdit ? '' : 'readonly'}></td>
        <td><input class="si-field si-field--mono" type="date" name="checks[${i}][due_date]"
                   value="${esc(due)}" ${canEdit ? '' : 'readonly'}></td>
      </tr>`;
    })
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
        title: 'سند قبض',
        subtitle: form.id
          ? 'سند رقم ' + form.voucher_no + ' · ' + payLabel(form.pay_method)
          : 'إدخال قبض نقدي أو بنكي أو شيك',
        actions: [
          { label: 'سند جديد', href: BASE + '?new=1', primary: true },
          { label: 'ترحيل السندات', href: LIST },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      <div class="rc-status">${statusPill}</div>

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>بيانات السند</h2>
          <div class="sh-actions">
            ${
              form.prev_id
                ? `<a class="si-btn" href="${BASE}?id=${form.prev_id}" title="السابق">‹</a>`
                : ''
            }
            ${
              form.next_id
                ? `<a class="si-btn" href="${BASE}?id=${form.next_id}" title="التالي">›</a>`
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
                      onsubmit="return confirm('حذف سند القبض؟');">
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
              <input class="si-field si-field--mono" name="voucher_lookup" dir="ltr"
                     placeholder="اكتب الرقم واضغط Enter" value="">
            </label>
            <div class="sh-form-actions">
              <button class="si-btn" type="submit">بحث</button>
            </div>
          </form>

          <form method="post" action="${BASE}/save" class="sh-form" id="rc-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <label>رقم السند
              <input class="si-field si-field--mono" name="voucher_no" dir="ltr"
                     value="${esc(form.voucher_no)}"
                     placeholder="يُولَّد تلقائياً عند الحفظ"
                     ${form.id ? 'readonly' : ''}>
            </label>
            <label>التاريخ *
              <input class="si-field si-field--mono" type="date" name="voucher_date" required
                     value="${esc(form.voucher_date)}" ${canEdit ? '' : 'readonly'}>
            </label>
            <label>العميل *
              ${
                canEdit
                  ? `<select class="si-field" name="customer_id" id="rc_customer" required>
                      <option value="">— اختر العميل —</option>
                      ${custOpts}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(form.customer_label)}">
                    <input type="hidden" name="customer_id" value="${form.customer_id}">`
              }
            </label>
            <label>المندوب
              <input class="si-field" id="rc_rep" readonly tabindex="-1"
                     value="${esc(form.sales_rep_name || '—')}">
            </label>

            <div class="rc-pay-row" role="radiogroup" aria-label="طريقة الدفع">
              <label class="rc-pay-opt">
                <input type="radio" name="pay_method" value="cash" ${
                  form.pay_method === 'cash' ? 'checked' : ''
                } ${canEdit ? '' : 'disabled'}> نقداً
              </label>
              <label class="rc-pay-opt">
                <input type="radio" name="pay_method" value="bank" ${
                  form.pay_method === 'bank' ? 'checked' : ''
                } ${canEdit ? '' : 'disabled'}> بنك
              </label>
              <label class="rc-pay-opt">
                <input type="radio" name="pay_method" value="check" ${
                  form.pay_method === 'check' ? 'checked' : ''
                } ${canEdit ? '' : 'disabled'}> شيك
              </label>
              ${
                !canEdit
                  ? `<input type="hidden" name="pay_method" value="${esc(form.pay_method)}">`
                  : ''
              }
            </div>

            <label class="rc-amount-field" id="rc-amount-wrap" ${
              form.pay_method === 'check' ? 'hidden' : ''
            }>المبلغ *
              <input class="si-field si-field--mono" name="amount" dir="ltr"
                     value="${esc(form.amount)}" placeholder="0.00" ${canEdit ? '' : 'readonly'}>
            </label>

            <div class="rc-checks" id="rc-checks-wrap" ${
              form.pay_method === 'check' ? '' : 'hidden'
            } style="grid-column:1/-1">
              <div class="si-surface-head" style="padding:0 0 .5rem;border:0">
                <h3 style="margin:0;font-size:.95rem">قائمة الشيكات</h3>
              </div>
              <div class="si-table-wrap">
                <table class="si-table">
                  <thead>
                    <tr>
                      <th>#</th><th>رقم الشيك</th><th>المبلغ *</th><th>البنك</th><th>الاستحقاق</th>
                    </tr>
                  </thead>
                  <tbody id="rc-checks-body">${
                    checksHtml ||
                    `<tr><td colspan="5" class="empty">لا شيكات</td></tr>`
                  }</tbody>
                </table>
              </div>
              ${
                canEdit
                  ? `<button type="button" class="si-btn" id="rc-add-check" style="margin-top:.4rem">+ شيك</button>`
                  : ''
              }
            </div>

            <label style="grid-column:1/-1">وذلك عن
              <textarea class="si-field" name="notes" rows="2" ${
                canEdit ? '' : 'readonly'
              } placeholder="بيان السند / سبب الدفع">${esc(form.notes)}</textarea>
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
      var form = document.getElementById('rc-form');
      if (!form) return;
      var cust = document.getElementById('rc_customer');
      var rep = document.getElementById('rc_rep');
      var amountWrap = document.getElementById('rc-amount-wrap');
      var checksWrap = document.getElementById('rc-checks-wrap');
      var tbody = document.getElementById('rc-checks-body');
      var addBtn = document.getElementById('rc-add-check');
      function syncRep(){
        if (!cust || !rep) return;
        var opt = cust.options[cust.selectedIndex];
        rep.value = (opt && opt.getAttribute('data-rep')) || '—';
      }
      if (cust) cust.addEventListener('change', syncRep);
      function pay(){
        var m = (form.querySelector('input[name=pay_method]:checked')||{}).value || 'cash';
        if (amountWrap) amountWrap.hidden = (m === 'check');
        if (checksWrap) checksWrap.hidden = (m !== 'check');
      }
      form.querySelectorAll('input[name=pay_method]').forEach(function(r){
        r.addEventListener('change', pay);
      });
      pay();
      if (addBtn && tbody) {
        addBtn.addEventListener('click', function(){
          var i = tbody.querySelectorAll('tr').length;
          var tr = document.createElement('tr');
          tr.innerHTML =
            '<td class="si-num">'+(i+1)+'</td>'+
            '<td><input class="si-field si-field--mono" name="checks['+i+'][check_no]" dir="ltr"></td>'+
            '<td><input class="si-field si-field--mono" name="checks['+i+'][check_amount]" dir="ltr" placeholder="0.00"></td>'+
            '<td><input class="si-field" name="checks['+i+'][bank_name]"></td>'+
            '<td><input class="si-field si-field--mono" type="date" name="checks['+i+'][due_date]"></td>';
          if (tbody.querySelector('.empty')) tbody.innerHTML = '';
          tbody.appendChild(tr);
        });
      }
    })();
    </script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'سند قبض',
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
  const checks = svc.parseChecksFromBody(body);
  const result = await svc.phpAction('save', uid(req), {
    voucher_id: id,
    voucher_no: body.voucher_no,
    voucher_date: body.voucher_date,
    customer_id: body.customer_id,
    pay_method: body.pay_method,
    amount: body.amount,
    notes: body.notes,
    check_no: body.check_no,
    check_amount: body.check_amount,
    bank_name: body.bank_name,
    checks,
  });
  if (!result.ok) {
    return res.redirect(
      BASE +
        (id > 0 ? '?id=' + id : '?new=1') +
        '&err=' +
        encodeURIComponent(result.error || 'تعذر الحفظ')
    );
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
