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

  const listHtml =
    rows
      .map(
        (r, i) => `<tr class="${Number(r.id) === Number(row.id) && !isNew ? 'is-active-row' : ''}">
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr"><a href="/system/users?id=${r.id}${qv ? '&q=' + encodeURIComponent(qv) : ''}${
          activeOnly ? '&active=1' : ''
        }">${esc(r.username || '')}</a></td>
      <td>${esc(r.full_name_ar || '')}</td>
      <td>${dash(r.groups_ar)}</td>
      <td class="si-num" dir="ltr">${dash(r.email)}</td>
      <td>${Number(r.is_active) === 1 ? 'نعم' : 'لا'}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا مستخدمين');

  const formTitle = isNew ? 'مستخدم جديد' : row.id ? `تعديل: ${esc(row.username || '')}` : 'بيانات المستخدم';
  const postAction = isNew || !row.id ? '/system/users/new' : '/system/users/' + row.id;

  const body = `
    <style>
      .su-wrap{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(280px,.95fr);gap:1rem;align-items:start}
      @media (max-width:980px){.su-wrap{grid-template-columns:1fr}}
      .su-wrap .si-table tr.is-active-row td{background:rgba(11,107,203,.09)}
      .su-wrap .si-table a{color:inherit;font-weight:700;text-decoration:none}
      .su-wrap .si-table a:hover{color:#0b63ce;text-decoration:underline}
      .su-form{display:flex;flex-direction:column;gap:.85rem;padding:1rem 1.15rem 1.25rem}
      .su-form .su-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
      @media (max-width:640px){.su-form .su-row{grid-template-columns:1fr}}
      .su-form .su-row--1{grid-template-columns:1fr}
      .su-form label.su-field{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:700;color:#3d4659;min-width:0}
      .su-form label.su-field .muted{font-weight:500;font-size:.75rem;margin-top:.15rem}
      .su-form .su-check{display:flex!important;flex-direction:row!important;align-items:center;gap:.45rem;font-weight:700;font-size:.88rem;margin-top:.15rem}
      .su-form .su-check input{width:1.05rem;height:1.05rem}
      .su-groups{border:1px solid #e4e8f0;border-radius:12px;padding:.8rem 1rem;background:#fafbfd}
      .su-groups h3{margin:0 0 .35rem;font-size:.95rem}
      .su-groups .su-gitem{display:flex;align-items:flex-start;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #eef1f6;font-weight:600;font-size:.88rem;cursor:pointer}
      .su-groups .su-gitem:last-child{border-bottom:0}
      .su-groups .su-gitem input{margin-top:.2rem}
      .su-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.25rem}
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
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <div class="si-rail">
        <form class="si-search" method="get" action="/system/users" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
          <input type="search" name="q" value="${esc(qv)}" placeholder="بحث مستخدم…" style="flex:1;min-width:10rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="active" value="1" ${activeOnly ? 'checked' : ''}> نشطون فقط
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="su-wrap">
        <section class="si-surface">
          <div class="si-surface-head"><h2>قائمة المستخدمين</h2><span class="si-count">${rows.length}</span></div>
          <p class="muted" style="padding:0 1rem;margin:.35rem 0 .5rem;font-size:.85rem">اختر مستخدماً للتعديل أو اضغط «جديد».</p>
          <div class="si-table-wrap" style="padding:0 0 1rem">
            <table class="si-table">
              <thead><tr>
                <th>#</th><th>اسم المستخدم</th><th>الاسم</th><th>المجموعات</th><th>البريد</th><th>نشط</th>
              </tr></thead>
              <tbody>${listHtml}</tbody>
            </table>
          </div>
        </section>
        <section class="si-surface">
          <div class="si-surface-head"><h2>${formTitle}</h2></div>
          <form method="post" action="${postAction}" class="su-form">
            <input type="hidden" name="id" value="${row.id || 0}">
            <div class="su-row">
              <label class="su-field">اسم المستخدم *
                <input class="si-field si-field--mono" name="username" required value="${esc(
                  row.username || ''
                )}" autocomplete="off" dir="ltr" placeholder="بدون مسافات">
              </label>
              <label class="su-field">الاسم الكامل *
                <input class="si-field" name="full_name_ar" required value="${esc(
                  row.full_name_ar || ''
                )}" autocomplete="off">
              </label>
            </div>
            <div class="su-row su-row--1">
              <label class="su-field">البريد الإلكتروني *
                <input class="si-field" type="email" name="email" required value="${esc(
                  row.email || ''
                )}" dir="ltr" autocomplete="off">
                <span class="muted">مطلوب لاستعادة كلمة المرور</span>
              </label>
            </div>
            <div class="su-row">
              <label class="su-field">مندوب المبيعات (للهاتف)
                <select class="si-field" name="sales_rep_id">
                  <option value="0">— بدون ربط —</option>
                  ${repOpts}
                </select>
                <span class="muted">لمستخدمي التطبيق كمندوبين (عهدة / تحميل)</span>
              </label>
              <label class="su-check">
                <input type="checkbox" name="is_active" value="1" ${
                  Number(row.is_active) === 1 || isNew ? 'checked' : ''
                }>
                <span>نشط</span>
              </label>
            </div>
            <div class="su-row">
              <label class="su-field">كلمة المرور ${
                isNew || !row.id
                  ? '*'
                  : '<span class="muted" style="display:inline">(فارغ = بدون تغيير)</span>'
              }
                <input class="si-field" type="password" name="password" ${
                  isNew || !row.id ? 'required' : ''
                } autocomplete="new-password" dir="ltr" minlength="6">
              </label>
              <label class="su-field">تأكيد كلمة المرور
                <input class="si-field" type="password" name="password_confirm" autocomplete="new-password" dir="ltr">
              </label>
            </div>
            <div class="su-groups">
              <h3>المجموعات (الصلاحيات)</h3>
              <p class="muted" style="margin:0 0 .5rem;font-size:.8rem;font-weight:500">
                الصلاحيات تُحدَّد على مستوى المجموعة. <strong>لتطبيق الهاتف (APK) يجب تفعيل مجموعة «هاتف» (MOBILE)</strong> — إنشاء مستخدم فقط دون هذه المجموعة لن يسمح بالدخول من التطبيق.
              </p>
              ${
                groups.length
                  ? groups
                      .map(
                        (g) => `<label class="su-gitem">
                  <input type="checkbox" name="group_ids" value="${g.id}" ${
                          selectedGroups.has(Number(g.id)) ? 'checked' : ''
                        }>
                  <span>
                    ${esc(g.name_ar || g.code || '')}
                    ${g.code ? `<span class="muted" dir="ltr"> (${esc(g.code)})</span>` : ''}
                    ${
                      g.description
                        ? `<br><span class="muted" style="font-size:.78rem;font-weight:500">${esc(g.description)}</span>`
                        : ''
                    }
                  </span>
                </label>`
                      )
                      .join('')
                  : '<p class="muted">لا توجد مجموعات</p>'
              }
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
