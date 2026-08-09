'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');
const svc = require('./employeesService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/employees';
const PERM = 'hr_employees';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM);
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
    (msg ? `<p class="si-pill si-pill--ok" style="display:inline-block;margin:.25rem 0">${esc(msg)}</p>` : '') +
    (err ? `<p class="si-pill si-pill--lock" style="display:inline-block;margin:.25rem 0">${esc(err)}</p>` : '')
  );
}

function optsHtml(rows, selected, emptyLabel) {
  let html = `<option value="0">${esc(emptyLabel)}</option>`;
  for (const r of rows) {
    const id = Number(r.id);
    html += `<option value="${id}" ${Number(selected) === id ? 'selected' : ''}>${esc(r.name_ar || '')}</option>`;
  }
  return html;
}

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const rows = await svc.listEmployees({ q: qv, activeOnly: !showAll });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${esc(r.emp_code || '')}</td>
      <td>${esc(r.name_ar || '')}</td>
      <td>${esc(r.department || '—')}</td>
      <td>${esc(r.job_title || '—')}</td>
      <td class="si-num" dir="ltr">${esc(r.phone || '—')}</td>
      <td class="si-num" dir="ltr">${esc(isoToDmy(r.hire_date) || '—')}</td>
      <td>${
        Number(r.is_resigned_posted) === 1
          ? ui.statusPill('lock', 'مستقيل مرحّل')
          : Number(r.is_active) === 1
            ? ui.statusPill('ok', 'نشط')
            : ui.statusPill('lock', 'موقوف')
      }</td>
      <td><a class="si-btn" href="${BASE}/edit?id=${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(8);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'بيانات الموظف الأساسية',
        subtitle: 'قائمة الموظفين — إضافة · تعديل · ترحيل استقالة · حذف',
        actions: [
          { label: '＋ موظف جديد', href: BASE + '/add', primary: true },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      <div class="si-rail">
        <form class="si-search" method="get" action="${BASE}" style="max-width:100%;margin:0;flex:1;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
          <input type="search" name="q" value="${esc(qv)}" placeholder="بحث بالاسم أو الرقم أو الهاتف…" autocomplete="off" style="flex:1;min-width:12rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> الكل
          </label>
          <button class="si-btn si-btn--primary" type="submit">بحث</button>
        </form>
      </div>
      ${ui.tableSurface('الموظفون', rows.length + ' صف', ['الرمز', 'الاسم', 'القسم', 'المسمى', 'هاتف', 'التعيين', 'الحالة', ''], rowsHtml)}
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'بيانات الموظف الأساسية',
      bodyHtml: body,
      activePath: BASE,
    })
  );
});

async function renderForm(req, res, mode) {
  if (!can(req.session.user)) return forbid(res);
  const isEdit = mode === 'edit';
  let row = svc.emptyRow();
  if (isEdit) {
    const id = Number(req.query.id || 0);
    const loaded = await svc.getEmployee(id);
    if (!loaded) {
      return res.redirect(BASE + '?err=' + encodeURIComponent('الموظف غير موجود.'));
    }
    row = { ...svc.emptyRow(), ...loaded };
  } else {
    // next code preview only — actual assigned on save if empty
  }

  const [depts, jobs, nats, picker] = await Promise.all([
    svc.listDepartments(),
    svc.listJobTitles(),
    svc.listNationalities(),
    svc.listEmployees({ activeOnly: false }),
  ]);

  const locked = Number(row.is_resigned_posted) === 1;
  const title = isEdit ? 'تعديل موظف' : 'موظف جديد';
  const isResigned = !!(row.resignation_date && String(row.resignation_date).length > 4);
  const gender = String(row.gender || '');

  const pickerOpts = picker
    .map(
      (r) =>
        `<option value="${r.id}" ${Number(r.id) === Number(row.id) ? 'selected' : ''}>${esc(
          (r.emp_code ? r.emp_code + ' — ' : '') + (r.name_ar || '')
        )}</option>`
    )
    .join('');

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-employees.css">
    <div class="si-stage">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title,
        subtitle: locked
          ? 'البطاقة مرحّلة كاستقالة — فك الترحيل للتعديل'
          : 'بيانات شخصية · وظيفية · ضمان · ضريبة',
        actions: [
          { label: '＋ جديد', href: BASE + '/add' },
          { label: 'القائمة', href: BASE },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${
        locked
          ? '<p class="si-pill si-pill--lock" style="display:inline-block">مستقيل مرحّل — الحقول للقراءة فقط</p>'
          : ''
      }

      <div class="he-picker si-surface no-print">
        <label>اختيار موظف
          <select class="si-field" onchange="if(this.value) location.href='${BASE}/edit?id='+this.value">
            <option value="">— موظف جديد / اختر —</option>
            ${pickerOpts}
          </select>
        </label>
      </div>

      <form method="post" action="${BASE}/save" class="he-form si-surface" ${locked ? 'data-locked="1"' : ''}>
        <input type="hidden" name="id" value="${row.id || 0}">
        <div class="he-toolbar no-print">
          <button class="si-btn si-btn--primary" type="submit" ${locked ? 'disabled' : ''}>حفظ</button>
          ${
            isEdit && row.id
              ? `<button class="si-btn si-btn--warn" type="submit" formaction="${BASE}/post-resign" formmethod="post"
                    ${locked ? 'disabled' : ''} onclick="return confirm('ترحيل استقالة هذا الموظف؟');">ترحيل</button>
                 <button class="si-btn" type="submit" formaction="${BASE}/unpost-resign" formmethod="post"
                    ${!locked ? 'disabled' : ''} onclick="return confirm('فك ترحيل الاستقالة؟');">فك الترحيل</button>
                 <button class="si-btn si-btn--danger" type="submit" formaction="${BASE}/delete" formmethod="post"
                    ${locked ? 'disabled' : ''} onclick="return confirm('حذف الموظف نهائياً؟');">حذف</button>`
              : ''
          }
          <a class="si-btn" href="${BASE}">إلغاء</a>
        </div>

        <section class="he-section">
          <h3>الهوية</h3>
          <div class="he-grid">
            <label class="he-field">الرقم الوظيفي
              <input class="si-field si-field--mono" name="emp_code" dir="ltr" value="${esc(row.emp_code || '')}"
                     ${locked ? 'readonly' : ''} placeholder="تلقائي إذا تُرك فارغاً">
            </label>
            <label class="he-field">الاسم *
              <input class="si-field" name="name_first" required value="${esc(row.name_first || '')}" ${locked ? 'readonly' : ''} autofocus>
            </label>
            <label class="he-field">اسم الأب
              <input class="si-field" name="name_father" value="${esc(row.name_father || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">اسم الجد
              <input class="si-field" name="name_grandfather" value="${esc(row.name_grandfather || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">العائلة
              <input class="si-field" name="name_family" value="${esc(row.name_family || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">الجنس
              <select class="si-field" name="gender" ${locked ? 'disabled' : ''}>
                <option value="" ${!gender ? 'selected' : ''}>غير محدد</option>
                <option value="male" ${gender === 'male' ? 'selected' : ''}>ذكر</option>
                <option value="female" ${gender === 'female' ? 'selected' : ''}>أنثى</option>
              </select>
              ${locked ? `<input type="hidden" name="gender" value="${esc(gender)}">` : ''}
            </label>
            <label class="he-field">الجنسية
              <select class="si-field" name="nationality_id" ${locked ? 'disabled' : ''}>
                ${optsHtml(nats, row.nationality_id, 'اختر الجنسية')}
              </select>
              ${locked ? `<input type="hidden" name="nationality_id" value="${Number(row.nationality_id) || 0}">` : ''}
            </label>
            <label class="he-field he-field--full">الحالة الاجتماعية
              <span class="he-radio-row">
                <label><input type="radio" name="is_married" value="0" ${Number(row.is_married) !== 1 ? 'checked' : ''} ${locked ? 'disabled' : ''}> أعزب</label>
                <label><input type="radio" name="is_married" value="1" ${Number(row.is_married) === 1 ? 'checked' : ''} ${locked ? 'disabled' : ''}> متزوج</label>
              </span>
              ${locked ? `<input type="hidden" name="is_married" value="${Number(row.is_married) === 1 ? 1 : 0}">` : ''}
            </label>
            <label class="he-field">الرقم الوطني
              <input class="si-field si-field--mono" name="national_id" dir="ltr" value="${esc(row.national_id || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">تاريخ الميلاد
              <input class="si-field si-field--mono" type="date" name="birth_date" value="${esc(row.birth_date || '')}" ${locked ? 'readonly' : ''}>
            </label>
          </div>
        </section>

        <section class="he-section">
          <h3>معلومات الاتصال والعنوان</h3>
          <div class="he-grid">
            <label class="he-field">الهاتف
              <input class="si-field si-field--mono" name="phone" dir="ltr" value="${esc(row.phone || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">البريد
              <input class="si-field" name="email" type="email" dir="ltr" value="${esc(row.email || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">المدينة
              <input class="si-field" name="address_city" value="${esc(row.address_city || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">الحي
              <input class="si-field" name="address_district" value="${esc(row.address_district || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field he-field--full">العنوان
              <input class="si-field" name="address_ar" value="${esc(row.address_ar || '')}" ${locked ? 'readonly' : ''}>
            </label>
          </div>
        </section>

        <section class="he-section">
          <h3>البيانات الوظيفية</h3>
          <div class="he-grid">
            <label class="he-field">القسم
              <select class="si-field" name="department_id" ${locked ? 'disabled' : ''}>
                ${optsHtml(depts, row.department_id, 'اختر القسم')}
              </select>
              ${locked ? `<input type="hidden" name="department_id" value="${Number(row.department_id) || 0}">` : ''}
            </label>
            <label class="he-field">المسمى الوظيفي
              <select class="si-field" name="job_title_id" ${locked ? 'disabled' : ''}>
                ${optsHtml(jobs, row.job_title_id, 'بدون مسمى')}
              </select>
              ${locked ? `<input type="hidden" name="job_title_id" value="${Number(row.job_title_id) || 0}">` : ''}
            </label>
            <label class="he-field">تاريخ التعيين *
              <input class="si-field si-field--mono" type="date" name="hire_date" required value="${esc(row.hire_date || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">الراتب الأساسي
              <input class="si-field si-field--mono" name="base_salary" dir="ltr" inputmode="decimal"
                     value="${esc(String(row.base_salary != null ? row.base_salary : 0))}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field he-field--full">ملاحظات
              <textarea class="si-field" name="notes" rows="2" ${locked ? 'readonly' : ''}>${esc(row.notes || '')}</textarea>
            </label>
          </div>
        </section>

        <section class="he-section">
          <h3>الاستقالة والضمان وضريبة الدخل</h3>
          <div class="he-grid">
            <label class="he-check he-field">
              <input type="checkbox" name="is_resigned" value="1" ${isResigned ? 'checked' : ''} ${locked ? 'disabled' : ''}>
              <span>مستقيل</span>
            </label>
            <label class="he-field">تاريخ الاستقالة
              <input class="si-field si-field--mono" type="date" name="resignation_date" value="${esc(row.resignation_date || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-field">رقم الضمان
              <input class="si-field si-field--mono" name="social_security_no" dir="ltr" value="${esc(row.social_security_no || '')}" ${locked ? 'readonly' : ''}>
            </label>
            <label class="he-check he-field">
              <input type="checkbox" name="subject_to_social_security" value="1"
                ${Number(row.subject_to_social_security) === 1 ? 'checked' : ''} ${locked ? 'disabled' : ''}>
              <span>خاضع للضمان</span>
              ${
                locked
                  ? `<input type="hidden" name="subject_to_social_security" value="${Number(row.subject_to_social_security) === 1 ? 1 : 0}">`
                  : ''
              }
            </label>
            <label class="he-check he-field">
              <input type="checkbox" name="subject_to_income_tax" value="1"
                ${Number(row.subject_to_income_tax) === 1 ? 'checked' : ''} ${locked ? 'disabled' : ''}>
              <span>خاضع لضريبة الدخل</span>
              ${
                locked
                  ? `<input type="hidden" name="subject_to_income_tax" value="${Number(row.subject_to_income_tax) === 1 ? 1 : 0}">`
                  : ''
              }
            </label>
          </div>
        </section>
      </form>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title,
      bodyHtml: body,
      css: ['/assets/css/hr-employees.css'],
      activePath: BASE,
    })
  );
}

router.get(BASE + '/add', (req, res) => renderForm(req, res, 'add'));
router.get(BASE + '/edit', (req, res) => renderForm(req, res, 'edit'));

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.saveEmployee(req.body || {});
  if (!result.ok) {
    const id = Number(req.body?.id || 0);
    const back = id > 0 ? BASE + '/edit?id=' + id : BASE + '/add';
    return res.redirect(back + (back.includes('?') ? '&' : '?') + 'err=' + encodeURIComponent(result.error));
  }
  res.redirect(BASE + '/edit?id=' + result.id + '&msg=' + encodeURIComponent(result.message));
});

router.post(BASE + '/delete', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.deleteEmployee(req.body?.id);
  if (!result.ok) {
    return res.redirect(
      BASE + '/edit?id=' + Number(req.body?.id || 0) + '&err=' + encodeURIComponent(result.error)
    );
  }
  res.redirect(BASE + '?msg=' + encodeURIComponent(result.message));
});

router.post(BASE + '/post-resign', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const id = Number(req.body?.id || 0);
  const result = await svc.postResignation(id, req.body?.resignation_date);
  const q = result.ok ? 'msg' : 'err';
  res.redirect(BASE + '/edit?id=' + id + '&' + q + '=' + encodeURIComponent(result.message || result.error));
});

router.post(BASE + '/unpost-resign', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const id = Number(req.body?.id || 0);
  const result = await svc.unpostResignation(id);
  const q = result.ok ? 'msg' : 'err';
  res.redirect(BASE + '/edit?id=' + id + '&' + q + '=' + encodeURIComponent(result.message || result.error));
});

module.exports = router;
