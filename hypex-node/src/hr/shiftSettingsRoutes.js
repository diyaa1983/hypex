'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const svc = require('./shiftSettingsService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/attendance/settings';
const PERM = 'hr_attendance_settings';

function can(user) {
  return (
    user.is_admin ||
    auth.userCan(user, PERM) ||
    auth.userCan(user, 'hr_employee_schedule') ||
    auth.userCan(user, 'hr_employee_attendance')
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
  const isNew = String(req.query.new || '') === '1' || editId < 1;
  const [shifts, nextCode] = await Promise.all([svc.listShifts(), svc.nextCode()]);

  let form = {
    id: 0,
    shift_code: nextCode,
    shift_name: '',
    start_time: '07:00',
    end_time: '15:00',
    is_active: 1,
  };

  if (editId > 0) {
    const row = await svc.getShift(editId);
    if (row) {
      form = {
        id: Number(row.id),
        shift_code: String(row.shift_code || ''),
        shift_name: String(row.shift_name || ''),
        start_time: svc.formatTime(row.start_time) || '07:00',
        end_time: svc.formatTime(row.end_time) || '15:00',
        is_active: Number(row.is_active) === 1 ? 1 : 0,
      };
    }
  }

  const rowsHtml =
    shifts
      .map((r) => {
        const start = svc.formatTime(r.start_time);
        const end = svc.formatTime(r.end_time);
        const active = Number(r.is_active) === 1;
        return `<tr class="${Number(r.id) === form.id ? 'is-active-row' : ''}">
        <td class="si-num" dir="ltr">${esc(r.shift_code || '')}</td>
        <td>${esc(r.shift_name || '')}</td>
        <td class="si-num" dir="ltr">${esc(start)}</td>
        <td class="si-num" dir="ltr">${esc(end)}</td>
        <td>${active ? ui.statusPill('ok', 'مفعّل') : ui.statusPill('lock', 'موقوف')}</td>
        <td class="sh-actions">
          <a class="si-btn" href="${BASE}?id=${r.id}">تعديل</a>
          <form method="post" action="${BASE}/toggle" style="display:inline">
            <input type="hidden" name="id" value="${r.id}">
            <input type="hidden" name="is_active" value="${active ? 0 : 1}">
            <button class="si-btn" type="submit">${active ? 'إيقاف' : 'تفعيل'}</button>
          </form>
          <form method="post" action="${BASE}/delete" style="display:inline"
                onsubmit="return confirm('حذف هذا الشفت؟');">
            <input type="hidden" name="id" value="${r.id}">
            <button class="si-btn si-btn--danger" type="submit">حذف</button>
          </form>
        </td>
      </tr>`;
      })
      .join('') ||
    `<tr><td colspan="6" class="empty">لا توجد شفتات بعد — اضغط «جديد» وأدخل الاسم ثم احفظ.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'إعدادات دوام الموظفين',
        subtitle: 'تعريف الشفتات المستخدمة في جدول دوام الموظف',
        actions: [
          { label: 'تعريف دوام الموظف', href: '/hr/attendance/schedule' },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>${form.id ? 'تعديل شفت' : 'إضافة شفت'}</h2>
          <a class="si-btn" href="${BASE}?new=1">جديد</a>
        </div>
        <div class="sh-body">
          <p class="sh-hint">
            رقم الشفت أرقام فقط (يُولَّد تلقائياً). الأوقات بصيغة <strong dir="ltr">HH:mm</strong>.
            للعطل ضع البداية والنهاية <strong dir="ltr">00:00</strong>.
            للشفت الليلي (مثل 19:00 → 07:00) ضع النهاية قبل البداية.
          </p>
          <form method="post" action="${BASE}/save" class="sh-form" id="sh-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <label>رقم الشفت
              <input class="si-field si-field--mono" dir="ltr" readonly value="${esc(form.shift_code)}">
            </label>
            <label>اسم الشفت *
              <input class="si-field" name="shift_name" required maxlength="80" autofocus
                     value="${esc(form.shift_name)}" placeholder="صباحي / مسائي / عطلة…">
            </label>
            <label>بداية الشفت *
              <input class="si-field si-field--mono" name="start_time" dir="ltr" required
                     pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" value="${esc(form.start_time)}"
                     placeholder="07:00">
            </label>
            <label>نهاية الشفت *
              <input class="si-field si-field--mono" name="end_time" dir="ltr" required
                     pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" value="${esc(form.end_time)}"
                     placeholder="15:00">
            </label>
            <label class="sh-check">
              <input type="checkbox" name="is_active" value="1" ${form.is_active ? 'checked' : ''}>
              <span>مفعّل</span>
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
          <h2>قائمة الشفتات</h2>
          <span class="si-count">${shifts.length}</span>
        </div>
        <div class="si-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>رقم الشفت</th><th>اسم الشفت</th><th>بداية</th><th>نهاية</th><th>تنشيط</th><th>إجراء</th>
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
      title: 'إعدادات دوام الموظفين',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.saveShift(req.body || {});
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
  const result = await svc.deleteShift(req.body?.id);
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
