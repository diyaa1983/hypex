'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, isoToDmy } = require('../lib/html');
const svc = require('./scheduleService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/attendance/schedule';
const PERM = 'hr_employee_schedule';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM) || auth.userCan(user, 'hr_employee_attendance');
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

function shiftOptionsHtml(shifts, selected) {
  let h = `<option value="0">— الشفت الافتراضي —</option>`;
  for (const s of shifts) {
    h += `<option value="${s.id}" ${Number(selected) === Number(s.id) ? 'selected' : ''}>${esc(
      s.label
    )}</option>`;
  }
  return h;
}

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  const employeeId = Number(req.query.employee_id || 0) || 0;
  const weeklyId = Number(req.query.weekly_id || 0) || 0;
  const isNewWeek = String(req.query.new_week || '') === '1';
  const tab = String(req.query.tab || 'weekly') === 'default' ? 'default' : 'weekly';

  const [employees, shifts, schedule, employee] = await Promise.all([
    svc.listEmployees(),
    svc.listShifts(),
    svc.loadSchedule(employeeId),
    employeeId > 0 ? svc.getEmployee(employeeId) : Promise.resolve(null),
  ]);

  const periods = schedule.weekly_periods || [];
  let draftFrom = '';
  let draftTo = '';
  let dayShifts = { 0: 0, 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0 };
  let currentWeeklyId = 0;

  if (employeeId > 0) {
    if (isNewWeek) {
      const sug = svc.suggestNextWeek(periods);
      draftFrom = sug.date_from;
      draftTo = sug.date_to;
    } else if (weeklyId > 0) {
      const found = periods.find((p) => Number(p.id) === weeklyId);
      if (found) {
        currentWeeklyId = found.id;
        draftFrom = found.date_from;
        draftTo = found.date_to;
        dayShifts = { ...found.days };
      }
    } else if (periods.length) {
      const last = periods[periods.length - 1];
      currentWeeklyId = last.id;
      draftFrom = last.date_from;
      draftTo = last.date_to;
      dayShifts = { ...last.days };
    } else {
      const sug = svc.suggestNextWeek([]);
      draftFrom = sug.date_from;
      draftTo = sug.date_to;
    }
  }

  // auto-fix Friday when Saturday selected via script
  const dayDates =
    draftFrom && /^\d{4}-\d{2}-\d{2}$/.test(draftFrom)
      ? svc.dayDatesForWeek(draftFrom)
      : [0, 1, 2, 3, 4, 5, 6].map((i) => ({
          index: i,
          name: svc.DAY_NAMES[i],
          date: '',
        }));

  const empOpts = employees
    .map(
      (e) =>
        `<option value="${e.id}" ${employeeId === Number(e.id) ? 'selected' : ''}>${esc(
          (e.emp_code ? e.emp_code + ' — ' : '') + (e.name_ar || '')
        )}</option>`
    )
    .join('');

  const periodListHtml =
    periods.length === 0
      ? '<p class="sch-muted">لا فترات أسبوعية محفوظة بعد.</p>'
      : `<ul class="sch-period-list">${periods
          .map(
            (p) =>
              `<li class="${Number(p.id) === currentWeeklyId && !isNewWeek ? 'is-active' : ''}">
                <a href="${BASE}?employee_id=${employeeId}&weekly_id=${p.id}&tab=weekly">
                  ${esc(isoToDmy(p.date_from))} — ${esc(isoToDmy(p.date_to))}
                </a>
              </li>`
          )
          .join('')}</ul>`;

  const weekRows = dayDates
    .map(
      (d) => `<tr>
      <td class="si-num">${d.index + 1}</td>
      <td>${esc(d.name)}</td>
      <td class="si-num" dir="ltr">${d.date ? esc(isoToDmy(d.date)) : '—'}</td>
      <td>
        <select class="si-field" name="day_shift_${d.index}" ${!employeeId || !shifts.length ? 'disabled' : ''}>
          ${shiftOptionsHtml(shifts, dayShifts[d.index] || 0)}
        </select>
      </td>
    </tr>`
    )
    .join('');

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-schedule.css">
    <div class="si-stage sch-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'تعريف دوام الموظف',
        subtitle: 'شفت افتراضي · جدول أسبوعي (سبت → جمعة)',
        actions: [
          { label: 'إعدادات الدوام', href: '/hr/attendance/settings' },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}

      <section class="si-surface sch-section">
        <div class="si-surface-head"><h2>اختيار الموظف</h2></div>
        <div class="sch-body">
          <form method="get" action="${BASE}" class="sch-pick">
            <input type="hidden" name="tab" value="${esc(tab)}">
            <label>الموظف
              <select class="si-field" name="employee_id" required>
                <option value="">— اضغط لاختيار الموظف —</option>
                ${empOpts}
              </select>
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
          </form>
          ${
            employee
              ? `<p class="sch-emp-line"><strong dir="ltr">${esc(
                  employee.emp_code || ''
                )}</strong> — ${esc(employee.name_ar || '')}</p>`
              : '<p class="sch-muted">اختر موظفاً ثم اضغط «عرض» لتحميل جدول دوامه.</p>'
          }
        </div>
      </section>

      ${
        employeeId < 1
          ? ''
          : `
      <div class="sch-tabs no-print">
        <a class="sch-tab${tab === 'weekly' ? ' is-active' : ''}"
           href="${BASE}?employee_id=${employeeId}&tab=weekly${currentWeeklyId && !isNewWeek ? '&weekly_id=' + currentWeeklyId : ''}">جدول أسبوعي</a>
        <a class="sch-tab${tab === 'default' ? ' is-active' : ''}"
           href="${BASE}?employee_id=${employeeId}&tab=default">الشفت الافتراضي</a>
      </div>

      ${
        tab === 'default'
          ? `
      <section class="si-surface sch-section">
        <div class="si-surface-head"><h2>الشفت الافتراضي</h2></div>
        <div class="sch-body">
          <p class="sch-muted">يُستخدم لأي يوم لم يُحدَّد له شفت في الجدول الأسبوعي.</p>
          ${
            shifts.length === 0
              ? '<p class="sch-muted">عرّف الشفتات أولاً من إعدادات دوام الموظفين.</p>'
              : `<form method="post" action="${BASE}/save-default" class="sch-default-form">
                  <input type="hidden" name="employee_id" value="${employeeId}">
                  <label>الشفت
                    <select class="si-field" name="default_shift_id">
                      <option value="0">— بلا شفت افتراضي —</option>
                      ${shifts
                        .map(
                          (s) =>
                            `<option value="${s.id}" ${
                              Number(schedule.default_shift_id) === Number(s.id) ? 'selected' : ''
                            }>${esc(s.label)}</option>`
                        )
                        .join('')}
                    </select>
                  </label>
                  <button class="si-btn si-btn--primary" type="submit">حفظ</button>
                </form>`
          }
        </div>
      </section>`
          : `
      <section class="si-surface sch-section">
        <div class="si-surface-head">
          <h2>الفترات الأسبوعية</h2>
          <a class="si-btn ${shifts.length ? '' : 'is-disabled'}"
             href="${BASE}?employee_id=${employeeId}&new_week=1&tab=weekly">＋ إضافة فترة جديدة</a>
        </div>
        <div class="sch-body">${periodListHtml}</div>
      </section>

      <section class="si-surface sch-section">
        <div class="si-surface-head"><h2>${
          isNewWeek || currentWeeklyId < 1 ? 'فترة أسبوعية جديدة' : 'تعديل الفترة'
        }</h2></div>
        <div class="sch-body">
          ${
            shifts.length === 0
              ? '<p class="sch-muted">لا شفتات مفعّلة — أضف شفتات من إعدادات الدوام.</p>'
              : `<form method="post" action="${BASE}/save-weekly" id="sch-weekly-form">
                  <input type="hidden" name="employee_id" value="${employeeId}">
                  <input type="hidden" name="weekly_id" value="${isNewWeek ? 0 : currentWeeklyId}">
                  <div class="sch-range">
                    <label>من (سبت)
                      <input class="si-field si-field--mono" type="date" name="date_from" id="sch-from"
                             required value="${esc(draftFrom)}" data-week-start="1">
                    </label>
                    <label>إلى (جمعة)
                      <input class="si-field si-field--mono" type="date" name="date_to" id="sch-to"
                             required value="${esc(draftTo)}" readonly>
                    </label>
                  </div>
                  <div class="si-table-wrap">
                    <table class="si-table">
                      <thead><tr><th>#</th><th>اليوم</th><th>التاريخ</th><th>الشفت</th></tr></thead>
                      <tbody>${weekRows}</tbody>
                    </table>
                  </div>
                  <div class="sch-actions">
                    <button class="si-btn si-btn--primary" type="submit">حفظ</button>
                    ${
                      currentWeeklyId > 0 && !isNewWeek
                        ? `<button class="si-btn si-btn--danger" type="submit" formaction="${BASE}/delete-weekly"
                             onclick="return confirm('حذف هذه الفترة الأسبوعية؟');">حذف</button>`
                        : ''
                    }
                  </div>
                </form>
                <script>
                (function(){
                  var from=document.getElementById('sch-from');
                  var to=document.getElementById('sch-to');
                  if(!from||!to) return;
                  function satOf(iso){
                    var d=new Date(iso+'T12:00:00');
                    if(isNaN(d)) return null;
                    var idx=(d.getDay()+1)%7;
                    d.setDate(d.getDate()-idx);
                    return d.toISOString().slice(0,10);
                  }
                  function friOf(sat){
                    var d=new Date(sat+'T12:00:00');
                    d.setDate(d.getDate()+6);
                    return d.toISOString().slice(0,10);
                  }
                  function sync(){
                    var v=from.value;
                    if(!v) return;
                    var s=satOf(v);
                    if(!s) return;
                    if(v!==s) from.value=s;
                    to.value=friOf(from.value);
                  }
                  from.addEventListener('change', sync);
                })();
                </script>`
          }
        </div>
      </section>`
      }
      `
      }
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تعريف دوام الموظف',
      bodyHtml: body,
      css: ['/assets/css/hr-schedule.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save-default', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const eid = Number(req.body?.employee_id || 0);
  const result = await svc.saveDefault(eid, req.body?.default_shift_id);
  res.redirect(
    BASE +
      '?employee_id=' +
      eid +
      '&tab=default&' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post(BASE + '/save-weekly', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const eid = Number(req.body?.employee_id || 0);
  const wid = Number(req.body?.weekly_id || 0);
  const dayShifts = {};
  for (let i = 0; i <= 6; i++) {
    dayShifts[i] = Number(req.body?.['day_shift_' + i] || 0);
  }
  const result = await svc.saveWeekly(
    eid,
    wid,
    req.body?.date_from,
    req.body?.date_to,
    dayShifts
  );
  if (!result.ok) {
    return res.redirect(
      BASE +
        '?employee_id=' +
        eid +
        (wid ? '&weekly_id=' + wid : '&new_week=1') +
        '&tab=weekly&err=' +
        encodeURIComponent(result.error)
    );
  }
  res.redirect(
    BASE +
      '?employee_id=' +
      eid +
      '&weekly_id=' +
      result.id +
      '&tab=weekly&msg=' +
      encodeURIComponent(result.message)
  );
});

router.post(BASE + '/delete-weekly', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const eid = Number(req.body?.employee_id || 0);
  const wid = Number(req.body?.weekly_id || 0);
  const result = await svc.deleteWeekly(eid, wid);
  res.redirect(
    BASE +
      '?employee_id=' +
      eid +
      '&tab=weekly&' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

module.exports = router;
