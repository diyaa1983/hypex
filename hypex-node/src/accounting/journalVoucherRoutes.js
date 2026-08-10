'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const svc = require('./journalVoucherService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/journal-voucher';
const LIST = '/accounting/journals';
const PERM = 'journal_voucher';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM);
}
function canPost(user) {
  return user.is_admin || auth.userCan(user, 'action_post_journal_voucher');
}
function canUnpost(user) {
  return user.is_admin || auth.userCan(user, 'action_unpost_journal_voucher');
}
function canEdit(user) {
  return user.is_admin || auth.userCan(user, 'action_edit_journal_voucher');
}
function canDelete(user) {
  return user.is_admin || auth.userCan(user, 'action_delete_journal_voucher');
}
function canCancel(user) {
  return user.is_admin || auth.userCan(user, 'action_cancel_journal_voucher');
}
function forbid(res) {
  return res.status(403).send('ممنوع');
}
function uid(req) {
  return Number(req.session.user?.id || 0) || 0;
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (p !== BASE && !p.startsWith(BASE + '/')) return next('router');
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

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  if (!(await svc.tableReady())) {
    return res.status(500).send(
      ui.salesPage({
        user: req.session.user,
        title: 'سند قيد',
        bodyHtml: `<div class="si-stage">${ui.hero({
          mark: 'Ac',
          kicker: KICKER,
          title: 'سند قيد',
          subtitle: 'جداول القيود غير موجودة — نفّذ database/migrations/026_acc_journal_tables.sql',
          actions: [{ label: 'لوحة المحاسبة', href: HUB }],
        })}</div>`,
      })
    );
  }

  const lookup = String(req.query.no || req.query.voucher_lookup || '').trim();
  if (lookup && !req.query.id) {
    const found = await svc.findByNo(lookup);
    if (found && found._auto) {
      return res.redirect(BASE + '?new=1&err=' + encodeURIComponent(found.message));
    }
    if (!found) {
      return res.redirect(
        BASE + '?new=1&err=' + encodeURIComponent('لم يُعثر على سند قيد يدوي بهذا الرقم.')
      );
    }
    return res.redirect(BASE + '?id=' + found.id);
  }

  const editId = Number(req.query.id || 0) || 0;
  const forceNew = String(req.query.new || '') === '1';

  const [accounts, customers, suppliers, partyIds] = await Promise.all([
    svc.listLeafAccounts(),
    svc.listCustomers(),
    svc.listSuppliers(),
    svc.partyArApIds(),
  ]);

  if (!accounts.length) {
    return res.status(500).send(
      ui.salesPage({
        user: req.session.user,
        title: 'سند قيد',
        bodyHtml: `<div class="si-stage">${ui.hero({
          mark: 'Ac',
          kicker: KICKER,
          title: 'سند قيد',
          subtitle: 'لا توجد حسابات نهائية في شجرة الحسابات. أضف حسابات فرعية قابلة للترحيل أولاً.',
          actions: [
            { label: 'شجرة الحسابات', href: '/accounting/chart', primary: true },
            { label: 'لوحة المحاسبة', href: HUB },
          ],
        })}</div>`,
      })
    );
  }

  let entry = null;
  if (!forceNew && editId > 0) {
    entry = await svc.getEntry(editId);
    // تُعرض كل القيود (يدوي وتلقائي) داخل Node — لا نُحوّل للإدخال PHP/الدخول
  }

  const user = req.session.user;
  const form = {
    id: entry ? entry.id : 0,
    entry_no: entry ? entry.entry_no : '',
    entry_date: entry ? entry.entry_date : todayIso(),
    description_ar: entry ? entry.description_ar : '',
    status: entry ? entry.status : 'new',
    lines: entry && entry.lines.length ? entry.lines : [{}, {}],
    prev_id: entry ? entry.prev_id : 0,
    next_id: entry ? entry.next_id : 0,
    is_posted: entry ? entry.is_posted : false,
    is_cancelled: entry ? entry.is_cancelled : false,
    is_editable: entry ? entry.is_editable : true,
    is_manual: entry ? entry.is_manual : true,
    source: entry ? entry.source : 'manual',
    can_edit_unlock: entry ? entry.can_edit_unlock : false,
    no_delete: entry ? entry.no_delete : false,
  };

  // قيد غير موجود: لا نترك id خاطئاً في الشاشة
  if (!forceNew && editId > 0 && !entry) {
    return res.redirect(
      BASE + '?new=1&err=' + encodeURIComponent('القيد غير موجود (رقم ' + editId + ').')
    );
  }

  const canEditFields = form.is_editable;
  const statusPill =
    form.status === 'posted'
      ? ui.statusPill('ok', 'مرحّل')
      : form.status === 'cancelled'
        ? ui.statusPill('lock', 'ملغى')
        : form.id
          ? ui.statusPill('wait', 'مسودة')
          : ui.statusPill('wait', 'جديد');

  const accountsJson = JSON.stringify(
    accounts.map((a) => ({
      id: Number(a.id),
      code: String(a.code || ''),
      name: String(a.name_ar || ''),
    }))
  );
  const customersJson = JSON.stringify(
    customers.map((c) => ({
      id: Number(c.id),
      code: String(c.code || ''),
      name: String(c.name_ar || ''),
    }))
  );
  const suppliersJson = JSON.stringify(
    suppliers.map((s) => ({
      id: Number(s.id),
      code: String(s.code || ''),
      name: String(s.name_ar || ''),
    }))
  );
  const linesJson = JSON.stringify(
    form.lines.map((ln) => ({
      account_id: Number(ln.account_id || 0),
      debit: Number(ln.debit || 0) || '',
      credit: Number(ln.credit || 0) || '',
      memo: String(ln.memo || ''),
      party_type: String(ln.party_type || ''),
      party_id: Number(ln.party_id || 0),
    }))
  );

  const actions = [];
  if (form.prev_id) {
    actions.push(
      `<a class="si-btn" href="${BASE}?id=${form.prev_id}" title="السابق">‹</a>`
    );
  }
  if (form.next_id) {
    actions.push(
      `<a class="si-btn" href="${BASE}?id=${form.next_id}" title="التالي">›</a>`
    );
  }
  if (canEditFields) {
    actions.push(
      `<button class="si-btn si-btn--primary" type="submit" form="jv-form" name="_intent" value="save">حفظ</button>`
    );
    if (form.id && canPost(user)) {
      actions.push(
        `<button class="si-btn si-btn--primary" type="submit" form="jv-form" name="_intent" value="save_post">حفظ وترحيل</button>`
      );
      actions.push(
        `<form method="post" action="${BASE}/post" style="display:inline" class="no-print">
           <input type="hidden" name="id" value="${form.id}">
           <button class="si-btn" type="submit">ترحيل</button>
         </form>`
      );
    }
  }
  if (form.id && form.is_posted && !form.is_cancelled && canEdit(user)) {
    actions.push(
      `<details class="jv-edit-unlock no-print">
        <summary class="si-btn">تعديل</summary>
        <form method="post" action="${BASE}/edit-unlock" class="jv-edit-form">
          <input type="hidden" name="id" value="${form.id}">
          <label>كلمة المرور
            <input class="si-field" type="password" name="password" required autocomplete="current-password">
          </label>
          <button class="si-btn si-btn--primary" type="submit">فك الترحيل للتعديل</button>
        </form>
      </details>`
    );
  }
  if (form.id && form.is_posted && !form.is_cancelled && canUnpost(user)) {
    actions.push(
      `<form method="post" action="${BASE}/unpost" style="display:inline" class="no-print"
         onsubmit="return confirm('فك ترحيل السند؟ لن يظهر في التقارير.');">
         <input type="hidden" name="id" value="${form.id}">
         <button class="si-btn" type="submit">فك الترحيل</button>
       </form>`
    );
  }
  if (form.id && form.is_posted && !form.is_cancelled && canCancel(user)) {
    actions.push(
      `<form method="post" action="${BASE}/cancel" style="display:inline" class="no-print"
         onsubmit="return confirm('إلغاء السند مع المحافظة على الرقم؟');">
         <input type="hidden" name="id" value="${form.id}">
         <button class="si-btn si-btn--danger" type="submit">إلغاء السند</button>
       </form>`
    );
  }
  if (form.id && canEditFields && !form.no_delete && canDelete(user)) {
    actions.push(
      `<form method="post" action="${BASE}/delete" style="display:inline" class="no-print"
         onsubmit="return confirm('حذف سند القيد؟');">
         <input type="hidden" name="id" value="${form.id}">
         <button class="si-btn si-btn--danger" type="submit">حذف</button>
       </form>`
    );
  }
  actions.push(
    `<button type="button" class="si-btn no-print" onclick="window.print()">طباعة</button>`
  );

  const body = `
    <link rel="stylesheet" href="/assets/css/fin-journal-node.css">
    <div class="si-stage sh-page jv-page" id="jv-screen"
         data-ar="${partyIds.ar || 0}"
         data-ap="${partyIds.ap || 0}"
         data-editable="${canEditFields ? '1' : '0'}">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'سند قيد',
        subtitle: form.id
          ? 'سند رقم ' +
            form.entry_no +
            ' · ' +
            (form.status === 'posted' ? 'مرحّل' : form.status === 'cancelled' ? 'ملغى' : 'مسودة') +
            (!form.is_manual ? ' · قيد تلقائي (عرض فقط)' : '')
          : 'قيد يدوي مزدوج القيد',
        actions: [
          { label: '+ سند قيد جديد', href: BASE + '?new=1', primary: true },
          { label: 'قائمة القيود', href: LIST },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${
        form.id && !form.is_manual
          ? `<p class="si-pill si-pill--wait" style="display:inline-block;margin:.25rem 0">
               هذا قيد تلقائي (مصدر: ${esc(form.source || '—')}). يُعرض للقراءة فقط — التعديل من المستند الأصلي.
             </p>`
          : ''
      }
      <div class="rc-status jv-status">${statusPill}</div>

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>بيانات السند</h2>
          <div class="sh-actions jv-toolbar">${actions.join('')}</div>
        </div>
        <div class="sh-body">
          <form method="get" action="${BASE}" class="sh-form jv-lookup no-print">
            <label>بحث برقم السند
              <input class="si-field si-field--mono" name="no" dir="ltr" placeholder="رقم السند — Enter للبحث"
                     value="">
            </label>
            <div class="sh-form-actions"><button class="si-btn" type="submit">بحث</button></div>
          </form>

          <form method="post" action="${BASE}/save" class="sh-form" id="jv-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <input type="hidden" name="lines_json" id="jv_lines_json" value="[]">
            <input type="hidden" name="_intent" id="jv_intent" value="save">

            <label>رقم السند
              <input class="si-field si-field--mono" name="entry_no" dir="ltr"
                     value="${esc(form.entry_no)}" placeholder="يُولَّد تلقائياً"
                     ${form.id || !canEditFields ? 'readonly' : ''}>
            </label>
            <label>التاريخ *
              <input class="si-field si-field--mono" type="date" name="entry_date" required
                     value="${esc(form.entry_date)}" ${canEditFields ? '' : 'readonly'}>
            </label>
            <label style="grid-column:1/-1">بيان السند (عام)
              <input class="si-field" name="description_ar" maxlength="500"
                     value="${esc(form.description_ar)}"
                     placeholder="وصف مختصر لسند القيد"
                     ${canEditFields ? '' : 'readonly'}>
            </label>
          </form>

          <div class="jv-lines-wrap">
            <table class="si-table jv-lines" id="jv-lines-table">
              <thead>
                <tr>
                  <th class="no-print" style="width:2.5rem"></th>
                  <th>الحساب (من شجرة الحسابات) *</th>
                  <th>عميل / مورد</th>
                  <th>مدين</th>
                  <th>دائن</th>
                  <th>البيان</th>
                </tr>
              </thead>
              <tbody id="jv-lines-body"></tbody>
              <tfoot>
                <tr class="jv-totals">
                  <td class="no-print"></td>
                  <td><strong>المجموع</strong></td>
                  <td></td>
                  <td class="si-num" dir="ltr" id="jv-total-debit">0.000</td>
                  <td class="si-num" dir="ltr" id="jv-total-credit">0.000</td>
                  <td><span id="jv-balance-hint" class="jv-bal-ok">متوازن</span></td>
                </tr>
              </tfoot>
            </table>
            ${
              canEditFields
                ? `<div class="jv-add-row no-print">
                    <button type="button" class="si-btn" id="jv-add-line">+ سطر حركة</button>
                  </div>`
                : ''
            }
          </div>
        </div>
      </section>
    </div>
    <script>
    window.__JV__ = {
      accounts: ${accountsJson},
      customers: ${customersJson},
      suppliers: ${suppliersJson},
      lines: ${linesJson},
      ar: ${Number(partyIds.ar || 0)},
      ap: ${Number(partyIds.ap || 0)},
      editable: ${canEditFields ? 'true' : 'false'}
    };
    </script>
    <script src="/assets/js/fin-journal-node.js"></script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'سند قيد',
      bodyHtml: body,
      css: ['/assets/css/fin-journal-node.css', '/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

function parseLines(body) {
  let lines = [];
  try {
    const raw = body.lines_json;
    if (typeof raw === 'string' && raw.trim()) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) lines = parsed;
    }
  } catch {
    lines = [];
  }
  return lines.map((ln) => ({
    account_id: Number(ln.account_id || 0) || 0,
    debit: Number(ln.debit || 0) || 0,
    credit: Number(ln.credit || 0) || 0,
    memo: String(ln.memo || ''),
    party_type: String(ln.party_type || ''),
    party_id: Number(ln.party_id || 0) || 0,
  }));
}

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const body = req.body || {};
  const id = Number(body.id || 0) || 0;
  const intent = String(body._intent || 'save');
  const postNow = intent === 'save_post';
  if (postNow && !canPost(req.session.user) && id > 0) {
    // allow save_post for new if has journal_voucher; post action needs post perm for existing
  }
  if (postNow && !canPost(req.session.user)) {
    return res.redirect(
      BASE +
        (id > 0 ? '?id=' + id : '?new=1') +
        '&err=' +
        encodeURIComponent('غير مصرح بالترحيل.')
    );
  }

  const result = await svc.phpAction('save', uid(req), {
    entry_id: id,
    entry_no: body.entry_no,
    entry_date: body.entry_date,
    description_ar: body.description_ar,
    lines: parseLines(body),
    post_now: postNow,
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
      (result.entry_id || id) +
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
  if (action === 'edit_unlock' && !canEdit(req.session.user)) return forbid(res);

  const id = Number(req.body?.id || 0);
  const payload = { entry_id: id };
  if (action === 'edit_unlock') payload.password = String(req.body?.password || '');

  const result = await svc.phpAction(action, uid(req), payload);
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
router.post(BASE + '/edit-unlock', (req, res) => actionRedirect(req, res, 'edit_unlock'));

module.exports = router;
