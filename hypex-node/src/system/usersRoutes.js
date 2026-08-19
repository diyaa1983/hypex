'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./usersService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex System · Node';
const HUB = '/system';

function can(user) {
  return user.is_admin || auth.userCan(user, 'users');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!p.startsWith('/system/users')) return next('router');
  return auth.requireAuth(req, res, next);
});

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

router.get('/system/users', async (req, res) => {
  if (!can(req.session.user)) return res.status(403).send('ممنوع');
  const qv = String(req.query.q || '');
  const activeOnly = String(req.query.active || '') === '1';
  const isNew = String(req.query.id || '') === 'new' || String(req.query.action || '') === 'add';
  const editId = isNew ? 0 : Number(req.query.id || 0) || 0;
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');

  const rows = await svc.listUsers({ q: qv, activeOnly });
  const groups = await svc.listGroups();
  const reps = await svc.listSalesReps();

  let row = {
    id: 0,
    username: '',
    full_name_ar: '',
    email: '',
    sales_rep_id: null,
    is_active: 1,
    group_ids: [],
  };
  if (!isNew) {
    const targetId = editId > 0 ? editId : rows[0] ? Number(rows[0].id) : 0;
    if (targetId > 0) {
      const loaded = await svc.getUser(targetId);
      if (loaded) row = loaded;
    }
  }

  const selectedGroups = new Set((row.group_ids || []).map(Number));

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${Number(row.sales_rep_id) === Number(r.id) ? 'selected' : ''}>${esc(
          r.name_ar
        )}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  const listQuery = `${qv ? '&q=' + encodeURIComponent(qv) : ''}${activeOnly ? '&active=1' : ''}`;

  const listHtml =
    rows
      .map(
        (r, i) => `<tr class="${Number(r.id) === Number(row.id) && !isNew ? 'is-active-row' : ''}">
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="su-user" dir="ltr"><a href="/system/users?id=${r.id}${listQuery}">${esc(r.username || '')}</a></td>
      <td>${esc(r.full_name_ar || '')}</td>
      <td class="su-groups-cell">${dash(r.groups_ar)}</td>
      <td class="su-status">${Number(r.is_active) === 1 ? '<span class="su-pill su-pill--ok">نشط</span>' : '<span class="su-pill">معطّل</span>'}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(5, 'لا مستخدمين');

  const formTitle = isNew ? 'مستخدم جديد' : row.id ? `تعديل: ${esc(row.username || '')}` : 'بيانات المستخدم';
  const postAction = isNew || !row.id ? '/system/users/new' : '/system/users/' + row.id;
  const showPassword = isNew || !row.id;

  const groupsHtml = groups.length
    ? `<div class="su-ggrid">${groups
        .map(
          (g) => `<label class="su-gitem">
          <input type="checkbox" name="group_ids" value="${g.id}" ${
            selectedGroups.has(Number(g.id)) ? 'checked' : ''
          }>
          <span class="su-gitem-body">
            <span class="su-gitem-title">${esc(g.name_ar || g.code || '')}${
            g.code ? ` <span class="muted" dir="ltr">(${esc(g.code)})</span>` : ''
          }</span>
            ${
              g.description
                ? `<span class="su-gitem-desc">${esc(g.description)}</span>`
                : ''
            }
          </span>
        </label>`
        )
        .join('')}</div>`
    : '<p class="muted">لا توجد مجموعات</p>';

  const body = `
    <style>
      .su-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,420px);gap:1rem;align-items:start}
      @media (max-width:980px){.su-wrap{grid-template-columns:1fr}}
      .su-list{min-width:0}
      .su-form-panel{position:sticky;top:.75rem;max-height:calc(100vh - 1.5rem);overflow:auto}
      .su-wrap .si-table tr.is-active-row td{background:rgba(11,107,203,.09)}
      .su-wrap .si-table tr{cursor:pointer}
      .su-wrap .si-table .su-user a{color:inherit;font-weight:700;text-decoration:none}
      .su-wrap .si-table .su-user a:hover{color:#0b63ce;text-decoration:underline}
      .su-wrap .si-table .su-groups-cell{font-size:.82rem;color:#5c6578;max-width:12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .su-status{text-align:center}
      .su-pill{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700;background:#eef1f6;color:#5c6578}
      .su-pill--ok{background:rgba(34,139,84,.12);color:#1a7a45}
      .su-list-head{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.65rem 1rem;border-bottom:1px solid #e8ecf2}
      .su-list-head .si-search{margin:0;flex:1;min-width:12rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
      .su-list-head .si-search input[type=search]{flex:1;min-width:8rem}
      .su-list-hint{padding:0 1rem;margin:.35rem 0 .25rem;font-size:.82rem}
      .su-form{display:flex;flex-direction:column;gap:0;padding:0 0 1rem}
      .su-sec{padding:.85rem 1.15rem;border-bottom:1px solid #eef1f6}
      .su-sec:last-of-type{border-bottom:0}
      .su-sec-title{margin:0 0 .65rem;font-size:.88rem;font-weight:800;color:#2a3344;letter-spacing:.01em}
      .su-sec .su-row{display:grid;grid-template-columns:1fr 1fr;gap:.65rem .75rem}
      @media (max-width:640px){.su-sec .su-row{grid-template-columns:1fr}}
      .su-sec .su-row--1{grid-template-columns:1fr}
      .su-sec label.su-field{display:flex;flex-direction:column;gap:.3rem;font-size:.8rem;font-weight:700;color:#3d4659;min-width:0}
      .su-sec label.su-field .muted{font-weight:500;font-size:.72rem;line-height:1.35}
      .su-sec .su-check-row{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;margin-top:.15rem}
      .su-sec .su-check{display:flex!important;flex-direction:row!important;align-items:center;gap:.4rem;font-weight:700;font-size:.85rem;margin:0}
      .su-sec .su-check input{width:1rem;height:1rem;margin:0}
      .su-ggrid{display:grid;grid-template-columns:1fr;gap:.35rem}
      @media (min-width:360px){.su-ggrid{grid-template-columns:1fr 1fr}}
      .su-gitem{display:flex;align-items:flex-start;gap:.45rem;padding:.5rem .55rem;border:1px solid #e8ecf2;border-radius:10px;background:#fafbfd;font-weight:600;font-size:.82rem;cursor:pointer;min-height:2.75rem}
      .su-gitem:has(input:checked){border-color:#0b6bcb;background:rgba(11,107,203,.06)}
      .su-gitem input{margin-top:.15rem;flex-shrink:0}
      .su-gitem-body{display:flex;flex-direction:column;gap:.15rem;min-width:0}
      .su-gitem-title{line-height:1.3}
      .su-gitem-desc{font-size:.72rem;font-weight:500;color:#6b7280;line-height:1.35}
      .su-mobile-note{margin:0 0 .6rem;font-size:.75rem;font-weight:500;color:#5c6578;line-height:1.45}
      .su-actions{display:flex;flex-wrap:wrap;gap:.5rem;padding:.85rem 1.15rem 0;border-top:1px solid #eef1f6;margin-top:.25rem}
      details.su-pw{margin-top:.5rem}
      details.su-pw>summary{cursor:pointer;font-size:.8rem;font-weight:700;color:#0b6bcb;list-style:none;display:flex;align-items:center;gap:.35rem}
      details.su-pw>summary::-webkit-details-marker{display:none}
      details.su-pw .su-row{margin-top:.55rem}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Us',
        kicker: KICKER,
        title: 'المستخدمون',
        subtitle: 'إدارة حسابات النظام والمجموعات وربط مندوب التطبيق',
        actions: [
          { label: '＋ جديد', href: '/system/users?id=new', primary: true },
          { label: 'المجموعات', href: '/system/groups' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block;margin-bottom:.5rem">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block;margin-bottom:.5rem">${esc(err)}</p>` : ''}
      <div class="su-wrap">
        <section class="si-surface su-list">
          <div class="si-surface-head"><h2>قائمة المستخدمين</h2><span class="si-count">${rows.length}</span></div>
          <div class="su-list-head">
            <form class="si-search" method="get" action="/system/users">
              <input type="search" name="q" value="${esc(qv)}" placeholder="بحث بالاسم أو البريد…">
              <label style="font-size:.78rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem;white-space:nowrap">
                <input type="checkbox" name="active" value="1" ${activeOnly ? 'checked' : ''}> نشطون فقط
              </label>
              <button class="si-btn si-btn--primary" type="submit">عرض</button>
            </form>
          </div>
          <p class="muted su-list-hint">اختر مستخدماً من القائمة أو اضغط «جديد».</p>
          <div class="si-table-wrap" style="padding:0 0 .75rem">
            <table class="si-table">
              <thead><tr>
                <th>#</th><th>اسم المستخدم</th><th>الاسم</th><th>المجموعات</th><th>الحالة</th>
              </tr></thead>
              <tbody>${listHtml}</tbody>
            </table>
          </div>
        </section>
        <section class="si-surface su-form-panel">
          <div class="si-surface-head"><h2>${formTitle}</h2></div>
          <form method="post" action="${postAction}" class="su-form">
            <input type="hidden" name="id" value="${row.id || 0}">
            <div class="su-sec">
              <h3 class="su-sec-title">الحساب</h3>
              <div class="su-row">
                <label class="su-field">اسم المستخدم *
                  <input class="si-field si-field--mono" name="username" required value="${esc(
                    row.username || ''
                  )}" autocomplete="off" dir="ltr" placeholder="بدون مسافات">
                </label>
                <label class="su-check su-check-row">
                  <input type="checkbox" name="is_active" value="1" ${
                    Number(row.is_active) === 1 || isNew ? 'checked' : ''
                  }>
                  <span>نشط</span>
                </label>
              </div>
              ${
                showPassword
                  ? `<div class="su-row" style="margin-top:.65rem">
                <label class="su-field">كلمة المرور *
                  <input class="si-field" type="password" name="password" required autocomplete="new-password" dir="ltr" minlength="6">
                </label>
                <label class="su-field">تأكيد كلمة المرور
                  <input class="si-field" type="password" name="password_confirm" autocomplete="new-password" dir="ltr">
                </label>
              </div>`
                  : `<details class="su-pw">
                <summary>تغيير كلمة المرور</summary>
                <div class="su-row">
                  <label class="su-field">كلمة المرور
                    <input class="si-field" type="password" name="password" autocomplete="new-password" dir="ltr" minlength="6">
                    <span class="muted">اتركها فارغة للإبقاء على الحالية</span>
                  </label>
                  <label class="su-field">تأكيد
                    <input class="si-field" type="password" name="password_confirm" autocomplete="new-password" dir="ltr">
                  </label>
                </div>
              </details>`
              }
            </div>
            <div class="su-sec">
              <h3 class="su-sec-title">البيانات الشخصية</h3>
              <div class="su-row">
                <label class="su-field">الاسم الكامل *
                  <input class="si-field" name="full_name_ar" required value="${esc(
                    row.full_name_ar || ''
                  )}" autocomplete="off">
                </label>
                <label class="su-field">البريد الإلكتروني *
                  <input class="si-field" type="email" name="email" required value="${esc(
                    row.email || ''
                  )}" dir="ltr" autocomplete="off">
                  <span class="muted">مطلوب لاستعادة كلمة المرور</span>
                </label>
              </div>
            </div>
            <div class="su-sec">
              <h3 class="su-sec-title">مندوب التطبيق</h3>
              <div class="su-row su-row--1">
                <label class="su-field">مندوب المبيعات (للهاتف)
                  <select class="si-field" name="sales_rep_id">
                    <option value="0">— بدون ربط —</option>
                    ${repOpts}
                  </select>
                  <span class="muted">لمستخدمي التطبيق كمندوبين (عهدة / تحميل)</span>
                </label>
              </div>
            </div>
            <div class="su-sec">
              <h3 class="su-sec-title">المجموعات (الصلاحيات)</h3>
              <p class="su-mobile-note">
                الصلاحيات على مستوى المجموعة. <strong>لتطبيق الهاتف (APK) فعّل مجموعة «هاتف» (MOBILE)</strong>.
              </p>
              ${groupsHtml}
            </div>
            <div class="su-actions">
              <button class="si-btn si-btn--primary" type="submit">حفظ</button>
              <a class="si-btn" href="/system/users?id=new">جديد</a>
            </div>
          </form>
        </section>
      </div>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'المستخدمون', bodyHtml: body }));
});

async function handleSave(req, res, idForce) {
  if (!can(req.session.user)) return res.status(403).send('ممنوع');
  const body = { ...(req.body || {}) };
  if (idForce != null) body.id = idForce;
  body.group_ids = [].concat(body.group_ids || []).filter(Boolean);
  const result = await svc.saveUser(body, req.session.user.id);
  if (!result.ok) {
    const id = Number(body.id || 0);
    return res.redirect(
      '/system/users?err=' +
        encodeURIComponent(result.error) +
        (id > 0 ? '&id=' + id : '&id=new')
    );
  }
  res.redirect(
    '/system/users?id=' + result.id + '&msg=' + encodeURIComponent(result.message || 'تم الحفظ')
  );
}

router.post('/system/users/new', (req, res) => handleSave(req, res, 0));
router.post('/system/users/:id', (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return handleSave(req, res, id);
});

module.exports = router;
