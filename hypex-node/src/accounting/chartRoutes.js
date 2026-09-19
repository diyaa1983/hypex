'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const svc = require('./chartService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/chart';

function can(user) {
  return user.is_admin || auth.userCan(user, 'chart_of_accounts');
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (p !== BASE && !p.startsWith(BASE + '/')) return next('router');
  return auth.requireAuth(req, res, next);
});

function flashFromQuery(req) {
  const msg = String(req.query.msg || '');
  const err = String(req.query.err || '');
  return (
    (msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : '') +
    (err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : '')
  );
}

function renderTreeNodes(nodes, qLower) {
  if (!nodes.length) return '';
  return (
    `<ul class="coa-tree-ul">` +
    nodes
      .map((n) => {
        const kids = n.children || [];
        const hasKids = kids.length > 0;
        const tone = svc.TYPE_TONE[n.account_type] || 'default';
        const inactive = Number(n.is_active) !== 1 ? ' is-inactive' : '';
        const leaf = Number(n.is_leaf) === 1 ? ' is-leaf' : ' is-parent';
        const code = svc.formatCode(n.code);
        const label = `${code} — ${n.name_ar || ''}`;
        const searchHit =
          qLower &&
          (String(n.name_ar || '').toLowerCase().includes(qLower) ||
            String(code).toLowerCase().includes(qLower) ||
            String(n.code || '').toLowerCase().includes(qLower));
        return `<li class="coa-tree-li${inactive}${leaf}${searchHit ? ' is-match' : ''}" data-id="${n.id}">
          <div class="coa-tree-row coa-tone--${tone}" data-id="${n.id}" tabindex="0" role="treeitem"
               data-code="${esc(code)}" data-name="${esc(n.name_ar || '')}"
               data-type="${esc(n.account_type || '')}" data-type-label="${esc(svc.typeLabel(n.account_type))}"
               data-leaf="${Number(n.is_leaf) === 1 ? '1' : '0'}" data-active="${Number(n.is_active) === 1 ? '1' : '0'}"
               data-parent="${n.parent_id || 0}">
            ${
              hasKids
                ? `<button type="button" class="coa-toggle" aria-label="توسيع" data-toggle="${n.id}">▾</button>`
                : `<span class="coa-toggle coa-toggle--empty"></span>`
            }
            <span class="coa-tree-code" dir="ltr">${esc(code)}</span>
            <span class="coa-tree-name">${esc(n.name_ar || '')}</span>
            ${Number(n.is_leaf) === 1 ? '<span class="coa-badge">نهائي</span>' : ''}
            ${Number(n.is_active) !== 1 ? '<span class="coa-badge coa-badge--off">موقوف</span>' : ''}
          </div>
          ${hasKids ? renderTreeNodes(kids, qLower) : ''}
        </li>`;
      })
      .join('') +
    `</ul>`
  );
}

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const qv = String(req.query.q || '');
  const activeOnly = String(req.query.active || '') === '1';
  const data = await svc.treePageData({ q: qv, activeOnly });
  const qLower = qv.trim().toLowerCase();
  const treeHtml = renderTreeNodes(data.tree, qLower) || '<p class="coa-empty">لا حسابات</p>';

  const body = `
    <link rel="stylesheet" href="/assets/css/coa-tree.css">
    <div class="si-stage">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'شجرة الحسابات',
        subtitle: 'شجرة محاسبية — إضافة · تعديل · حذف · بحث',
        actions: [
          { label: '＋ حساب رئيسي', href: BASE + '/add', primary: true },
          { label: 'طباعة', href: '/accounting/reports/chart' },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flashFromQuery(req)}
      <div class="si-rail" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <form class="si-search" method="get" action="${BASE}" style="flex:1;min-width:12rem;margin:0;max-width:100%">
          <input type="search" name="q" value="${esc(qv)}" placeholder="بحث برقم أو اسم الحساب…" autocomplete="off">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem;white-space:nowrap">
            <input type="checkbox" name="active" value="1" ${activeOnly ? 'checked' : ''}> نشط فقط
          </label>
          <button class="si-btn si-btn--primary" type="submit">بحث</button>
        </form>
      </div>
      <div class="coa-split si-surface">
        <section class="coa-panel coa-panel--tree">
          <div class="coa-panel-head">
            <span>الشجرة</span>
            <span class="si-count">${data.count}</span>
          </div>
          <div class="coa-tree-scroll" id="coa-tree-root" role="tree">
            ${treeHtml}
          </div>
        </section>
        <section class="coa-panel coa-panel--detail">
          <div class="coa-panel-head">تفاصيل الحساب</div>
          <div class="coa-detail" id="coa-detail">
            <p class="coa-detail-placeholder">اختر حساباً من الشجرة لعرض التفاصيل والإجراءات.</p>
          </div>
          <div class="coa-actions" id="coa-actions" hidden>
            <a class="si-btn si-btn--primary" id="coa-btn-add-child" href="#">＋ فرعي</a>
            <a class="si-btn" id="coa-btn-edit" href="#">تعديل</a>
            <form method="post" action="${BASE}/delete" id="coa-del-form" style="display:inline"
                  onsubmit="return confirm('حذف هذا الحساب نهائياً؟');">
              <input type="hidden" name="id" id="coa-del-id" value="">
              <button class="si-btn si-btn--danger" type="submit" id="coa-btn-del">حذف</button>
            </form>
          </div>
        </section>
      </div>
    </div>
    <script src="/assets/js/coa-tree.js"></script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'شجرة الحسابات',
      bodyHtml: body,
      css: ['/assets/css/coa-tree.css'],
      activePath: BASE,
    })
  );
});

async function renderForm(req, res, mode) {
  if (!can(req.session.user)) return forbid(res);
  const isEdit = mode === 'edit';
  let row = {
    id: 0,
    code: '',
    name_ar: '',
    parent_id: null,
    account_type: 'asset',
    is_leaf: 1,
    is_active: 1,
  };
  let parent = null;
  let hasKids = false;
  let nextPreview = '';

  if (isEdit) {
    const id = Number(req.query.id || 0);
    const loaded = await svc.getAccount(id);
    if (!loaded) {
      return res.redirect(BASE + '?err=' + encodeURIComponent('حساب غير موجود.'));
    }
    row = loaded;
    if (row.parent_id) parent = await svc.getAccount(row.parent_id);
    hasKids = await svc.hasChildren(id);
  } else {
    const parentId = Number(req.query.parent_id || 0);
    if (parentId > 0) {
      parent = await svc.getAccount(parentId);
      if (!parent) {
        return res.redirect(BASE + '?err=' + encodeURIComponent('الحساب الأب غير موجود.'));
      }
      row.parent_id = parentId;
      row.account_type = parent.account_type;
      row.is_leaf = 1;
    }
    try {
      nextPreview = await svc.nextCode(row.parent_id);
    } catch (e) {
      return res.redirect(BASE + '?err=' + encodeURIComponent(e.message || 'خطأ'));
    }
  }

  const title = isEdit
    ? 'تعديل حساب'
    : parent
      ? 'إضافة حساب فرعي'
      : 'إضافة حساب رئيسي';
  const codeShow = isEdit ? svc.formatCode(row.code) : svc.formatCode(nextPreview);
  const typeOpts = Object.entries(svc.TYPE_LABELS)
    .map(
      ([k, lab]) =>
        `<option value="${k}" ${String(row.account_type) === k ? 'selected' : ''}>${esc(lab)}</option>`
    )
    .join('');

  const body = `
    <link rel="stylesheet" href="/assets/css/coa-tree.css">
    <div class="si-stage">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title,
        subtitle: parent
          ? `تحت: ${esc(svc.formatCode(parent.code))} — ${esc(parent.name_ar || '')}`
          : 'دليل الحسابات',
        actions: [{ label: 'رجوع للشجرة', href: BASE }],
      })}
      ${flashFromQuery(req)}
      <form method="post" action="${BASE}/save" class="coa-form si-surface">
        <input type="hidden" name="id" value="${row.id || 0}">
        ${
          parent
            ? `<input type="hidden" name="parent_id" value="${parent.id}">`
            : isEdit
              ? ''
              : '<input type="hidden" name="parent_id" value="0">'
        }
        <div class="coa-form-grid">
          <label class="coa-field">رقم الحساب
            <input class="si-field si-field--mono" readonly dir="ltr" value="${esc(codeShow)}">
            <span class="coa-hint">يُولَّد تلقائياً ولا يُعدَّل</span>
          </label>
          <label class="coa-field">اسم الحساب *
            <input class="si-field" name="name_ar" required autofocus value="${esc(row.name_ar || '')}">
          </label>
          ${
            !isEdit && !parent
              ? `<label class="coa-field">نوع الحساب *
                  <select class="si-field" name="account_type" required>${typeOpts}</select>
                </label>`
              : `<input type="hidden" name="account_type" value="${esc(row.account_type || 'asset')}">
                 <p class="coa-hint">نوع الحساب: <strong>${esc(svc.typeLabel(row.account_type))}</strong></p>`
          }
        </div>
        <div class="coa-form-checks">
          ${hasKids ? '<input type="hidden" name="is_leaf" value="0">' : ''}
          <label class="coa-check">
            <input type="checkbox" name="is_leaf" value="1" ${
              Number(row.is_leaf) === 1 ? 'checked' : ''
            } ${hasKids ? 'disabled' : ''}>
            <span>حساب نهائي (يُستخدم في القيود والسندات)</span>
          </label>
          <label class="coa-check">
            <input type="checkbox" name="is_active" value="1" ${
              Number(row.is_active) === 1 || !isEdit ? 'checked' : ''
            }>
            <span>نشط</span>
          </label>
        </div>
        ${
          hasKids
            ? '<p class="coa-hint">لا يمكن جعل الحساب «نهائي» لأنه يحتوي حسابات فرعية.</p>'
            : ''
        }
        <div class="coa-form-actions">
          <button class="si-btn si-btn--primary" type="submit">حفظ</button>
          <a class="si-btn" href="${BASE}">إلغاء</a>
        </div>
      </form>
    </div>`;
  res.send(
    ui.salesPage({ user: req.session.user, title, bodyHtml: body, activePath: BASE })
  );
}

router.get(BASE + '/add', (req, res) => renderForm(req, res, 'add'));
router.get(BASE + '/edit', (req, res) => renderForm(req, res, 'edit'));

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const body = { ...(req.body || {}) };
  // unchecked is_leaf on edit with disabled sends nothing; preserve when has kids via service validation
  if (body.is_leaf === undefined && Number(body.id) > 0) {
    // only when disabled checkbox omitted — keep current leaf if has kids handled in service
    const cur = await svc.getAccount(body.id);
    if (cur && (await svc.hasChildren(cur.id))) body.is_leaf = Number(cur.is_leaf);
  }
  const result = await svc.saveAccount(body);
  if (!result.ok) {
    const back =
      Number(body.id) > 0
        ? BASE + '/edit?id=' + body.id
        : BASE + '/add' + (body.parent_id ? '?parent_id=' + body.parent_id : '');
    return res.redirect(back + (back.includes('?') ? '&' : '?') + 'err=' + encodeURIComponent(result.error));
  }
  res.redirect(BASE + '?msg=' + encodeURIComponent(result.message));
});

router.post(BASE + '/delete', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.deleteAccount(req.body?.id);
  res.redirect(
    BASE +
      '?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

module.exports = router;
