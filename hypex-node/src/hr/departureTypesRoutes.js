'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const svc = require('./departureTypesService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/departure-types';
const PERM = 'hr_departure_types';

function can(user) {
  return (
    user.is_admin ||
    auth.userCan(user, PERM) ||
    auth.userCan(user, 'hr_employee_departures')
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

  const editId = Number(req.query.id || 0) || 0;
  const [types, nextCode] = await Promise.all([svc.listTypes(), svc.nextCode()]);

  let form = {
    id: 0,
    type_code: nextCode,
    name_ar: '',
    is_active: 1,
  };

  if (editId > 0) {
    const row = await svc.getType(editId);
    if (row) {
      form = {
        id: Number(row.id),
        type_code: String(row.type_code || ''),
        name_ar: String(row.name_ar || ''),
        is_active: Number(row.is_active) === 1 ? 1 : 0,
      };
    }
  }

  const rowsHtml =
    types
      .map((r) => {
        const active = Number(r.is_active) === 1;
        return `<tr class="${Number(r.id) === form.id ? 'is-active-row' : ''}">
        <td class="si-num" dir="ltr">${esc(r.type_code || '')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td>${active ? ui.statusPill('ok', 'نشط') : ui.statusPill('lock', 'موقوف')}</td>
        <td class="sh-actions">
          <a class="si-btn" href="${BASE}?id=${r.id}">تعديل</a>
          <form method="post" action="${BASE}/toggle" style="display:inline">
            <input type="hidden" name="id" value="${r.id}">
            <input type="hidden" name="is_active" value="${active ? 0 : 1}">
            <button class="si-btn" type="submit">${active ? 'إيقاف' : 'تفعيل'}</button>
          </form>
          <form method="post" action="${BASE}/delete" style="display:inline"
                onsubmit="return confirm('حذف نوع المغادرة؟');">
            <input type="hidden" name="id" value="${r.id}">
            <button class="si-btn si-btn--danger" type="submit">حذف</button>
          </form>
        </td>
      </tr>`;
      })
      .join('') ||
    `<tr><td colspan="4" class="empty">لا توجد أنواع مغادرات — اضغط «جديد» ثم احفظ.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'أنواع المغادرات',
        subtitle: 'تعريف أنواع المغادرات المستخدمة في سندات المغادرة',
        actions: [
          { label: 'مغادرات الموظفين', href: '/hr/departures' },
          { label: 'لوحة شؤون الموظفين', href: HUB },
        ],
      })}
      ${flashHtml(req)}

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>${form.id ? 'تعديل نوع مغادرة' : 'إضافة نوع مغادرة'}</h2>
          <a class="si-btn" href="${BASE}?new=1">جديد</a>
        </div>
        <div class="sh-body">
          <form method="post" action="${BASE}/save" class="sh-form" id="dt-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <label>رقم المغادرة
              <input class="si-field si-field--mono" dir="ltr" readonly value="${esc(form.type_code)}">
            </label>
            <label>اسم المغادرة *
              <input class="si-field" name="name_ar" required maxlength="120" autofocus
                     value="${esc(form.name_ar)}" placeholder="مغادرة خاصة / رسمية…">
            </label>
            <label class="sh-check">
              <input type="checkbox" name="is_active" value="1" ${form.is_active ? 'checked' : ''}>
              <span>نشط</span>
            </label>
            <div class="sh-form-actions">
              <button class="si-btn si-btn--primary" type="submit">حفظ</button>
              <a class="si-btn" href="${BASE}?new=1">جديد</a>
            </div>
          </form>
        </div>
      </section>

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>قائمة أنواع المغادرات</h2>
          <span class="si-count">${types.length}</span>
        </div>
        <div class="si-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>رقم المغادرة</th><th>اسم المغادرة</th><th>الحالة</th><th>إجراء</th>
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
      title: 'أنواع المغادرات',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.saveType(req.body || {});
  if (!result.ok) {
    const id = Number(req.body?.id || 0);
    return res.redirect(
      BASE +
        (id > 0 ? '?id=' + id : '?new=1') +
        '&err=' +
        encodeURIComponent(result.error)
    );
  }
  res.redirect(BASE + '?msg=' + encodeURIComponent(result.message));
});

router.post(BASE + '/delete', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.deleteType(req.body?.id);
  res.redirect(
    BASE +
      '?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post(BASE + '/toggle', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const active = String(req.body?.is_active || '') === '1';
  const result = await svc.toggleActive(req.body?.id, active);
  res.redirect(
    BASE +
      '?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

module.exports = router;
