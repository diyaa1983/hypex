'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, isoToDmy, todayIso, parseDateToIso } = require('../lib/html');
const svc = require('./departuresService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/departures';
const REPORT = '/hr/reports/departures';
const PERM = 'hr_employee_departures';
const REPORT_PERM = 'report_hr_employee_departures';

function canEntry(user) {
  return user.is_admin || auth.userCan(user, PERM);
}

function canReport(user) {
  return (
    user.is_admin ||
    auth.userCan(user, REPORT_PERM) ||
    auth.userCan(user, PERM)
  );
}

function canPost(user) {
  return user.is_admin || auth.userCan(user, 'action_post_employee_departure');
}

function canUnpost(user) {
  return user.is_admin || auth.userCan(user, 'action_unpost_employee_departure');
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    p !== BASE &&
    !p.startsWith(BASE + '/') &&
    p !== REPORT &&
    !p.startsWith(REPORT + '/')
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

/* ─── قائمة / إدخال ─── */
router.get(BASE, async (req, res) => {
  if (!canEntry(req.session.user)) return forbid(res);

  // بحث برقم السند
  const voucherLookup = String(req.query.voucher_lookup || '').trim();
  if (voucherLookup) {
    const found = await svc.lookupByVoucher(voucherLookup);
    if (!found) {
      return res.redirect(
        BASE + '?err=' + encodeURIComponent('لم يُعثر على سند يطابق «' + voucherLookup + '».')
      );
    }
    return res.redirect(BASE + '?id=' + found.id + '&msg=' + encodeURIComponent('تم فتح السند.'));
  }

  const editId = Number(req.query.id || 0) || 0;
  const fromQ = String(req.query.from || '').trim();
  const toQ = String(req.query.to || '').trim();
  const empFilter = Number(req.query.employee_id || 0) || 0;

  const [rows, employees, types, nextVoucher] = await Promise.all([
    svc.listDepartures({
      from: fromQ || undefined,
      to: toQ || undefined,
      employeeId: empFilter,
      limit: 250,
    }),
    svc.listEmployees(),
    svc.listTypes(true),
    svc.nextVoucherNo(),
  ]);

  let form = {
    id: 0,
    voucher_no: nextVoucher,
    employee_id: empFilter || 0,
    departure_type_id: 0,
    departure_date: todayIso(),
    time_from: '09:00',
    time_to: '10:00',
    notes: '',
    is_posted: 0,
    employee_name: '',
    emp_code: '',
    created_at: '',
  };

  if (editId > 0) {
    const selected = await svc.getDeparture(editId);
    if (selected) {
      form = {
        id: Number(selected.id),
        voucher_no: String(selected.voucher_no || ''),
        employee_id: Number(selected.employee_id || 0),
        departure_type_id: Number(selected.departure_type_id || 0),
        departure_date: String(selected.departure_date || '').slice(0, 10),
        time_from: svc.formatTime(selected.time_from) || '09:00',
        time_to: svc.formatTime(selected.time_to) || '10:00',
        notes: String(selected.notes || ''),
        is_posted: Number(selected.is_posted) === 1 ? 1 : 0,
        employee_name: String(selected.employee_name || ''),
        emp_code: String(selected.emp_code || ''),
        created_at: String(selected.created_at || ''),
      };
    }
  }

  const posted = form.is_posted === 1;
  const canEdit = !posted;
  const user = req.session.user;
  const showPost = form.id > 0 && !posted && canPost(user);
  const showUnpost = form.id > 0 && posted && canUnpost(user);

  const empOpts = employees
    .map(
      (e) =>
        `<option value="${e.id}" ${Number(form.employee_id) === Number(e.id) ? 'selected' : ''}>${esc(
          (e.emp_code ? e.emp_code + ' — ' : '') + (e.name_ar || '')
        )}</option>`
    )
    .join('');

  const typeOpts = types
    .map(
      (t) =>
        `<option value="${t.id}" ${
          Number(form.departure_type_id) === Number(t.id) ? 'selected' : ''
        }>${esc((t.type_code ? t.type_code + ' — ' : '') + (t.name_ar || ''))}</option>`
    )
    .join('');

  const filterEmpOpts =
    `<option value="0">كل الموظفين</option>` +
    employees
      .map(
        (e) =>
          `<option value="${e.id}" ${empFilter === Number(e.id) ? 'selected' : ''}>${esc(
            (e.emp_code ? e.emp_code + ' — ' : '') + (e.name_ar || '')
          )}</option>`
      )
      .join('');

  const rowsHtml =
    rows
      .map((r) => {
        const isPosted = Number(r.is_posted) === 1;
        const active = Number(r.id) === form.id;
        return `<tr class="${active ? 'is-active-row' : ''}">
        <td class="si-num" dir="ltr">${esc(r.voucher_no || '')}</td>
        <td>${esc((r.emp_code ? r.emp_code + ' — ' : '') + (r.employee_name || ''))}</td>
        <td class="si-num" dir="ltr">${esc(isoToDmy(r.departure_date))}</td>
        <td>${esc(r.type_name || '')}</td>
        <td class="si-num" dir="ltr">${esc(svc.formatTime(r.time_from))} – ${esc(
          svc.formatTime(r.time_to)
        )}</td>
        <td>${isPosted ? ui.statusPill('ok', 'مرحّل') : ui.statusPill('lock', 'مسودة')}</td>
        <td class="sh-actions">
          <a class="si-btn" href="${BASE}?id=${r.id}">فتح</a>
        </td>
      </tr>`;
      })
      .join('') ||
    `<tr><td colspan="7" class="empty">لا توجد مغادرات — اضغط «مغادرة جديدة».</td></tr>`;

  const readonlyAttr = canEdit ? '' : 'readonly';

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'مغادرات الموظفين',
        subtitle: 'إدخال سندات المغادرة وترحيلها',
        actions: [
          { label: 'مغادرة جديدة', href: BASE + '?new=1', primary: true },
          { label: 'التقرير', href: REPORT },
          { label: 'أنواع المغادرات', href: '/hr/departure-types' },
          { label: 'لوحة شؤون الموظفين', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${
        types.length === 0
          ? `<p class="si-pill si-pill--lock" style="display:inline-block">لا توجد أنواع مغادرات نشطة.
               <a href="/hr/departure-types">أضِف أنواعاً أولاً</a>.</p>`
          : ''
      }

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>${form.id ? (posted ? 'سند مرحّل' : 'تعديل سند') : 'سند مغادرة جديد'}</h2>
          <div class="sh-actions">
            <a class="si-btn" href="${BASE}?new=1">جديد</a>
            ${
              form.id
                ? showPost
                  ? `<form method="post" action="${BASE}/post" style="display:inline">
                      <input type="hidden" name="id" value="${form.id}">
                      <button class="si-btn si-btn--primary" type="submit">ترحيل</button>
                    </form>`
                  : showUnpost
                    ? `<form method="post" action="${BASE}/unpost" style="display:inline"
                          onsubmit="return confirm('فك ترحيل السند؟');">
                        <input type="hidden" name="id" value="${form.id}">
                        <button class="si-btn" type="submit">فك ترحيل</button>
                      </form>`
                    : ''
                : ''
            }
            ${
              form.id && canEdit
                ? `<form method="post" action="${BASE}/delete" style="display:inline"
                      onsubmit="return confirm('حذف سند المغادرة؟');">
                    <input type="hidden" name="id" value="${form.id}">
                    <button class="si-btn si-btn--danger" type="submit">حذف</button>
                  </form>`
                : ''
            }
          </div>
        </div>
        <div class="sh-body">
          <form method="get" action="${BASE}" class="sh-form" style="margin-bottom:.85rem">
            <label>بحث برقم السند
              <input class="si-field si-field--mono" name="voucher_lookup" dir="ltr"
                     placeholder="رقم السند" value="">
            </label>
            <div class="sh-form-actions">
              <button class="si-btn" type="submit">بحث</button>
            </div>
          </form>

          <form method="post" action="${BASE}/save" class="sh-form" id="dep-form">
            <input type="hidden" name="id" value="${form.id || 0}">
            <label>رقم السند
              <input class="si-field si-field--mono" dir="ltr" readonly
                     value="${esc(form.voucher_no)}">
            </label>
            <label>الحالة
              <input class="si-field" readonly
                     value="${posted ? 'مرحّل' : form.id ? 'مسودة' : 'جديد'}">
            </label>
            <label>الموظف *
              ${
                canEdit
                  ? `<select class="si-field" name="employee_id" required>
                      <option value="">— اختر —</option>
                      ${empOpts}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      (form.emp_code ? form.emp_code + ' — ' : '') + form.employee_name
                    )}">
                    <input type="hidden" name="employee_id" value="${form.employee_id}">`
              }
            </label>
            <label>تاريخ المغادرة *
              <input class="si-field si-field--mono" type="date" name="departure_date" required
                     value="${esc(form.departure_date)}" ${readonlyAttr}>
            </label>
            <label>نوع المغادرة *
              ${
                canEdit
                  ? `<select class="si-field" name="departure_type_id" required>
                      <option value="">— اختر —</option>
                      ${typeOpts}
                    </select>`
                  : `<input class="si-field" readonly value="${esc(
                      types.find((t) => Number(t.id) === form.departure_type_id)?.name_ar || ''
                    )}">
                    <input type="hidden" name="departure_type_id" value="${form.departure_type_id}">`
              }
            </label>
            <label>بداية المغادرة *
              <input class="si-field si-field--mono" name="time_from" dir="ltr" required
                     pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="09:00"
                     value="${esc(form.time_from)}" ${readonlyAttr}>
            </label>
            <label>نهاية المغادرة *
              <input class="si-field si-field--mono" name="time_to" dir="ltr" required
                     pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="10:00"
                     value="${esc(form.time_to)}" ${readonlyAttr}>
            </label>
            <label style="grid-column:1/-1">ملاحظات
              <input class="si-field" name="notes" maxlength="500"
                     value="${esc(form.notes)}" ${readonlyAttr}>
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

      <section class="si-surface sh-section">
        <div class="si-surface-head">
          <h2>قائمة المغادرات</h2>
          <span class="si-count">${rows.length}</span>
        </div>
        <div class="sh-body" style="padding-bottom:.4rem">
          <form method="get" action="${BASE}" class="sh-form">
            <label>من تاريخ
              <input class="si-field si-field--mono" type="date" name="from" value="${esc(fromQ)}">
            </label>
            <label>إلى تاريخ
              <input class="si-field si-field--mono" type="date" name="to" value="${esc(toQ)}">
            </label>
            <label>موظف
              <select class="si-field" name="employee_id">${filterEmpOpts}</select>
            </label>
            <div class="sh-form-actions">
              <button class="si-btn si-btn--primary" type="submit">تصفية</button>
              <a class="si-btn" href="${BASE}">مسح التصفية</a>
            </div>
          </form>
        </div>
        <div class="si-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>السند</th><th>الموظف</th><th>التاريخ</th><th>النوع</th>
                <th>الفترة</th><th>الحالة</th><th>إجراء</th>
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
      title: 'مغادرات الموظفين',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save', async (req, res) => {
  if (!canEntry(req.session.user)) return forbid(res);
  const result = await svc.saveDeparture(req.body || {}, req.session.user?.id);
  if (!result.ok) {
    const id = Number(req.body?.id || 0);
    return res.redirect(
      BASE +
        (id > 0 ? '?id=' + id : '?new=1') +
        '&err=' +
        encodeURIComponent(result.error)
    );
  }
  res.redirect(
    BASE + '?id=' + result.id + '&msg=' + encodeURIComponent(result.message)
  );
});

router.post(BASE + '/delete', async (req, res) => {
  if (!canEntry(req.session.user)) return forbid(res);
  const result = await svc.deleteDeparture(req.body?.id);
  res.redirect(
    BASE +
      '?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post(BASE + '/post', async (req, res) => {
  if (!canEntry(req.session.user) || !canPost(req.session.user)) return forbid(res);
  const id = Number(req.body?.id || 0);
  const result = await svc.postDeparture(id, req.session.user?.id);
  res.redirect(
    BASE +
      '?id=' +
      id +
      '&' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post(BASE + '/unpost', async (req, res) => {
  if (!canEntry(req.session.user) || !canUnpost(req.session.user)) return forbid(res);
  const id = Number(req.body?.id || 0);
  const result = await svc.unpostDeparture(id);
  res.redirect(
    BASE +
      '?id=' +
      id +
      '&' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ─── التقرير ─── */
router.get(REPORT, async (req, res) => {
  if (!canReport(req.session.user)) return forbid(res);

  let from = parseDateToIso(req.query.from || monthStart());
  let to = parseDateToIso(req.query.to || todayIso());
  if (from > to) [from, to] = [to, from];
  const departmentId = Number(req.query.department_id || 0) || 0;
  const typeId = Number(req.query.departure_type_id || 0) || 0;
  const show = String(req.query.show || '') === '1' || Object.keys(req.query).length > 0;

  const [departments, types] = await Promise.all([svc.listDepartments(), svc.listTypes(false)]);
  const report = show
    ? await svc.reportRows({ from, to, departmentId, typeId })
    : { departments: [], row_count: 0, grand_total_duration_label: '00:00', from, to };

  const deptOpts = departments
    .map(
      (d) =>
        `<option value="${d.id}" ${departmentId === Number(d.id) ? 'selected' : ''}>${esc(
          d.name_ar || ''
        )}</option>`
    )
    .join('');
  const typeOpts = types
    .map(
      (t) =>
        `<option value="${t.id}" ${typeId === Number(t.id) ? 'selected' : ''}>${esc(
          t.name_ar || ''
        )}</option>`
    )
    .join('');

  let tablesHtml = '';
  if (!show) {
    tablesHtml = `<p class="sh-hint" style="padding:1rem">اختر الفترة والفلاتر ثم اضغط «عرض».</p>`;
  } else if (!report.row_count) {
    tablesHtml = `<p class="sh-hint" style="padding:1rem">لا توجد مغادرات في الفترة المحددة.</p>`;
  } else {
    for (const dept of report.departments) {
      const tr = dept.rows
        .map(
          (r) => `<tr>
          <td class="si-num" dir="ltr">${esc(r.voucher_no)}</td>
          <td class="si-num" dir="ltr">${esc(r.emp_code)}</td>
          <td>${esc(r.emp_name)}</td>
          <td>${esc(r.type_name)}</td>
          <td class="si-num" dir="ltr">${esc(isoToDmy(r.departure_date))}</td>
          <td class="si-num" dir="ltr">${esc(r.time_from)}</td>
          <td class="si-num" dir="ltr">${esc(r.time_to)}</td>
          <td class="si-num" dir="ltr">${esc(r.duration_label)}</td>
          <td>${r.is_posted ? 'مرحّل' : 'مسودة'}</td>
          <td>${esc(r.notes || '')}</td>
        </tr>`
        )
        .join('');
      tablesHtml += `
        <section class="si-surface sh-section">
          <div class="si-surface-head">
            <h2>${esc(dept.dept_name || '—')}</h2>
            <span class="si-count">${dept.row_count} · مدة ${esc(dept.total_duration_label)}</span>
          </div>
          <div class="si-table-wrap">
            <table class="si-table">
              <thead>
                <tr>
                  <th>سند</th><th>رمز</th><th>الموظف</th><th>النوع</th><th>التاريخ</th>
                  <th>من</th><th>إلى</th><th>المدة</th><th>الحالة</th><th>ملاحظات</th>
                </tr>
              </thead>
              <tbody>${tr}</tbody>
            </table>
          </div>
        </section>`;
    }
    tablesHtml += `<p class="sh-hint" style="padding:.5rem 1rem">المجموع: <strong>${
      report.row_count
    }</strong> مغادرة · المدة الكلية
      <strong dir="ltr">${esc(report.grand_total_duration_label)}</strong></p>`;
  }

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-shift-settings.css">
    <div class="si-stage sh-page si-report-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'تقرير المغادرات بين تاريخين',
        subtitle: 'من ' + isoToDmy(from) + ' إلى ' + isoToDmy(to),
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'مغادرات الموظفين', href: BASE },
          { label: 'لوحة شؤون الموظفين', href: HUB },
        ],
      })}
      <div class="si-rail no-print">
        <form class="si-search" method="get" action="${REPORT}"
              style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:end">
          <input type="hidden" name="show" value="1">
          <label style="display:grid;gap:.25rem;font-size:.8rem;font-weight:700;color:#5c6578">من
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}">
          </label>
          <label style="display:grid;gap:.25rem;font-size:.8rem;font-weight:700;color:#5c6578">إلى
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}">
          </label>
          <label style="display:grid;gap:.25rem;font-size:.8rem;font-weight:700;color:#5c6578">القسم
            <select class="si-field" name="department_id">
              <option value="0">جميع الأقسام</option>
              ${deptOpts}
            </select>
          </label>
          <label style="display:grid;gap:.25rem;font-size:.8rem;font-weight:700;color:#5c6578">نوع المغادرة
            <select class="si-field" name="departure_type_id">
              <option value="0">جميع الأنواع</option>
              ${typeOpts}
            </select>
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="si-print-area">
        ${tablesHtml}
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير المغادرات',
      bodyHtml: body,
      css: ['/assets/css/hr-shift-settings.css'],
      js: ['/assets/js/sales-print.js'],
      activePath: REPORT,
    })
  );
});

module.exports = router;
