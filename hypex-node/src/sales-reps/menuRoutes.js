'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const auth = require('../auth');
const q = require('./domainQueries');
const masters = require('./mastersService');
const ui = require('../lib/salesUi');
const { salesRepsCatalog } = require('./catalog');
const { esc } = require('../lib/html');
const { todayIso } = require('../lib/html');

const router = express.Router();
const HUB = '/sales-reps';
const KICKER = 'Hypex Sales Reps · Node';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function requireAny(req, res, next) {
  const u = req.session.user;
  if (u.is_admin) return next();
  const flat = salesRepsCatalog.flatMap((g) => g.items);
  const any = flat.some((it) => can(u, it.r));
  // روابط قديمة للتقارير (تُحوّل إلى /sales/reports/…)
  const reportOk =
    req.path.startsWith('/sales-reps/reports/') &&
    (can(u, 'report_sales_by_rep') ||
      can(u, 'report_sales_by_region') ||
      can(u, 'report_sales_rep_tours') ||
      can(u, 'report_sales_rep_visits') ||
      can(u, 'sales_rep_route'));
  if (!any && !reportOk) return res.status(403).send('ممنوع');
  next();
}

function guard(code) {
  return (req, res, next) => {
    if (!can(req.session.user, code)) return res.status(403).send('ممنوع');
    next();
  };
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!(p.startsWith('/sales-reps') || p.startsWith('/api/sales-reps'))) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAny(req, res, next);
  });
});

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

router.get('/sales-reps', (req, res) => {
  const user = req.session.user;
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: 'المندوبين',
        subtitle: 'دليل المندوبين وجولات الزيارات — كلها على Node.',
        actions: [
          { label: 'قائمة المندوبين', href: '/sales-reps/list', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      ${ui.hubTiles(can, user, salesRepsCatalog)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'المندوبين', bodyHtml: body }));
});

/* ═══════════ List ═══════════ */
router.get('/sales-reps/list', guard('sales_reps'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const flash = String(req.query.msg || '');
  const rows = await q.listReps({ q: qv, activeOnly: !showAll });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${dash(r.code)}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td>${dash(r.warehouse_name)}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      <td>
        <div class="si-act">
          <a class="si-btn" href="/sales-reps/${r.id}">تعديل</a>
          <form method="post" action="/sales-reps/${r.id}/toggle" style="display:inline">
            <button type="submit" class="si-btn">${Number(r.is_active) === 1 ? 'إيقاف' : 'تفعيل'}</button>
          </form>
        </div>
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(7);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: 'المندوبين',
        subtitle: 'إضافة وتعديل مندوبي المبيعات على Node',
        actions: [
          { label: '＋ مندوب جديد', href: '/sales-reps/new', primary: true },
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${ui.esc(flash)}</p>` : ''}
      <div class="si-rail">
        <form class="si-search" method="get" action="/sales-reps/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
          <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث بالرمز / الاسم / الهاتف…" style="flex:1;min-width:10rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفين
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      ${ui.tableSurface('المندوبين', `${rows.length} صف`, ['الرمز', 'الاسم', 'الهاتف', 'مستودع العهدة', 'عملاء', 'الحالة', ''], rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'المندوبين', bodyHtml: body }));
});

/* ═══════════ Form ═══════════ */
async function repForm(req, res, id) {
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const row = id ? await masters.getRep(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');
  const warehouses = await masters.listWarehouses();
  const whOpts = warehouses
    .map(
      (w) =>
        `<option value="${w.id}" ${Number(row?.warehouse_id) === Number(w.id) ? 'selected' : ''}>${esc(
          (w.code ? w.code + ' — ' : '') + (w.name_ar || '')
        )}</option>`
    )
    .join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: isNew ? 'إضافة مندوب' : 'تعديل مندوب',
        subtitle: isNew ? 'الرمز فارغ = تلقائي (REP-####)' : esc(row.code || ''),
        actions: [{ label: 'رجوع للقائمة', href: '/sales-reps/list' }],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface si-surface--master">
        <div class="si-surface-head">
          <h2>${isNew ? 'مندوب جديد' : esc(row.name_ar || '')}</h2>
          ${!isNew && row?.code ? `<span class="si-count" dir="ltr">${esc(row.code)}</span>` : ''}
        </div>
        <form method="post" action="${isNew ? '/sales-reps/new' : '/sales-reps/' + id}" class="si-master-form">
          <input type="hidden" name="id" value="${row ? row.id : 0}">

          <div class="si-master-grid">
            <label class="si-mf">
              <span class="si-mf-label">الرمز</span>
              <span class="si-mf-hint">رقم البائع في أوراكل (مثل 12) — فارغ = تلقائي</span>
              <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="12" autocomplete="off">
              <span class="si-mf-note">للترحيل إلى INV00024 استخدم رقم البائع في أوراكل. الرموز مثل REP-0001 تُرحَّل كبائع 1.</span>
            </label>

            <label class="si-mf">
              <span class="si-mf-label">اسم المندوب <em>*</em></span>
              <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" autocomplete="off" placeholder="الاسم الكامل">
            </label>

            <label class="si-mf">
              <span class="si-mf-label">الهاتف</span>
              <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr" placeholder="07xxxxxxxx" autocomplete="off">
            </label>

            <label class="si-mf">
              <span class="si-mf-label">مستودع العهدة</span>
              <select class="si-field" name="warehouse_id">
                <option value="0">— بدون —</option>
                ${whOpts}
              </select>
            </label>

            <label class="si-mf si-mf--full">
              <span class="si-mf-label">العنوان</span>
              <textarea class="si-field" name="address_ar" rows="3" placeholder="اختياري…">${esc(
                row?.address_ar || ''
              )}</textarea>
            </label>
          </div>

          <div class="si-form-actions">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/sales-reps/list">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: isNew ? 'إضافة مندوب' : 'تعديل مندوب', bodyHtml: body }));
}

router.get('/sales-reps/new', (req, res) => repForm(req, res, 0));
router.post('/sales-reps/new', async (req, res) => {
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.saveRep(req.body || {});
  if (!result.ok) return res.redirect('/sales-reps/new?err=' + encodeURIComponent(result.error));
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || 'تم'));
});

/* ═══════════ جولات المندوبين ═══════════ */
function statusLabel(st) {
  return String(st) === 'posted' ? 'مرحّلة' : 'مسودة';
}

function statusPill(st) {
  return String(st) === 'posted'
    ? ui.statusPill('ok', 'مرحّلة')
    : ui.statusPill('wait', 'مسودة');
}

router.get('/api/sales-reps/addresses', guard('sales_rep_route'), async (req, res) => {
  const regionId = Number(req.query.region_id || 0) || 0;
  const rows = await masters.listAddressesForRegion(regionId);
  res.json({ ok: true, rows });
});

router.get('/api/sales-reps/tour-customers', guard('sales_rep_route'), async (req, res) => {
  const rows = await masters.listTourCustomers({
    salesRepId: Number(req.query.sales_rep_id || 0) || 0,
    regionId: Number(req.query.region_id || 0) || 0,
    regionAddressId: Number(req.query.region_address_id || 0) || 0,
    q: String(req.query.q || ''),
    limit: 400,
  });
  res.json({ ok: true, rows });
});

router.get('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  await masters.ensureTourSchema();
  const filterRep = Number(req.query.sales_rep_id || 0) || 0;
  const editId = Number(req.query.id || 0) || 0;
  const wantForm = editId > 0 || String(req.query.new || '') === '1';
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const reps = await q.listRepsSimple();

  /* ── شاشة القائمة فقط (مستقلة) ── */
  if (!wantForm) {
    const tours = await masters.listTours({ salesRepId: filterRep });
    const listHtml =
      tours
        .map(
          (r) => `<tr>
      <td class="si-num" dir="ltr">#${r.id}</td>
      <td>${ui.esc(r.sales_rep_name || '')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.date_from))} – ${ui.esc(ui.isoToDmy(r.date_to))}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${statusPill(r.status)}</td>
      <td>${dash(r.notes)}</td>
      <td class="srr-table-actions">
        <a class="si-btn" href="/sales-reps/route?id=${r.id}">فتح</a>
        <a class="si-btn" href="/sales-reps/route/${r.id}/print">طباعة</a>
        ${
          String(r.status) === 'posted'
            ? `<form method="post" action="/sales-reps/route" style="display:inline">
                <input type="hidden" name="tour_action" value="unpost">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn">فك ترحيل</button>
              </form>`
            : `<form method="post" action="/sales-reps/route" style="display:inline">
                <input type="hidden" name="tour_action" value="post">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn si-btn--primary">ترحيل</button>
              </form>
              <form method="post" action="/sales-reps/route" style="display:inline" onsubmit="return confirm('حذف الجولة؟');">
                <input type="hidden" name="tour_action" value="delete">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn si-btn--danger-text">حذف</button>
              </form>`
        }
      </td>
    </tr>`
        )
        .join('') || ui.emptyRow(7, 'لا جولات بعد');

    const listBody = `
    <div class="si-stage srr-page srr-tour-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'الجولات',
        subtitle: 'شاشة مستقلة لخطط زيارات المندوبين',
        actions: [
          { label: '＋ جولة جديدة', href: '/sales-reps/route?new=1', primary: true },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits' },
          { label: 'المندوبين', href: '/sales-reps/list' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface srr-list-card">
        <div class="si-surface-head">
          <h2>الجولات</h2>
          <span class="si-count">${tours.length} صف</span>
        </div>
        <div class="srr-list-filter">
          <form method="get" action="/sales-reps/route" class="srr-filter-form">
            <label>
              <span>تصفية بالمندوب</span>
              <select name="sales_rep_id" class="si-field" onchange="this.form.submit()">
                <option value="0">كل المندوبين</option>
                ${reps
                  .map(
                    (r) =>
                      `<option value="${r.id}" ${filterRep === Number(r.id) ? 'selected' : ''}>${esc(
                        r.name_ar
                      )}</option>`
                  )
                  .join('')}
              </select>
            </label>
          </form>
        </div>
        <div class="si-table-wrap srr-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>#</th>
                <th>المندوب</th>
                <th>الفترة</th>
                <th>عملاء</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
                <th></th>
              </tr>
            </thead>
            <tbody>${listHtml}</tbody>
          </table>
        </div>
      </section>
    </div>`;

    return res.send(
      ui.salesPage({
        user: req.session.user,
        title: 'الجولات',
        bodyHtml: listBody,
        css: ['/assets/css/sales-rep-route.css'],
        js: ['/assets/js/sales-print.js'],
        activePath: '/sales-reps/route',
      })
    );
  }

  /* ── شاشة النموذج (رحلة / تعديل) ── */
  const edit = editId ? await masters.getTour(editId) : null;
  const regions = await masters.listRegionsSimple();
  const selectedRep = edit ? Number(edit.sales_rep_id) : filterRep;
  const isPosted = edit && String(edit.status) === 'posted';
  const month = masters.monthBoundsIso();
  const qFrom = masters.normalizeIsoDate(req.query.date_from);
  const qTo = masters.normalizeIsoDate(req.query.date_to);
  const dateFrom = qFrom || (edit ? String(edit.date_from).slice(0, 10) : month.from);
  const dateTo = qTo || (edit ? String(edit.date_to).slice(0, 10) : month.to);
  const editLines = edit?.lines || [];
  const dayLabels = masters.WEEKDAY_LABELS || [
    'الأحد',
    'الإثنين',
    'الثلاثاء',
    'الأربعاء',
    'الخميس',
    'الجمعة',
    'السبت',
  ];
  // ترتيب العرض: السبت أولاً (مألوف إقليمياً)
  const dayOrder = [6, 0, 1, 2, 3, 4, 5];

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${selectedRep === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}${
          r.code ? ' (' + esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');

  const regionOpts = regions
    .map((r) => `<option value="${r.id}">${esc(r.name_ar)}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`)
    .join('');

  const weekdayChips = dayOrder
    .map(
      (wd) =>
        `<button type="button" class="srr-day-chip" data-weekday="${wd}" ${isPosted ? 'disabled' : ''}>
          <span class="srr-day-chip__lab">${esc(dayLabels[wd])}</span>
          <b class="srr-day-chip__n" data-count-for="${wd}">0</b>
        </button>`
    )
    .join('');

  let selectedRows = editLines.map((l) => ({
    customer_id: Number(l.customer_id),
    code: l.customer_code || '',
    name: l.customer_name || '',
    weekday: l.weekday == null || l.weekday === '' ? 0 : Number(l.weekday),
    region_id: Number(l.region_id || 0) || null,
    region_address_id: Number(l.region_address_id || 0) || null,
    region_name: l.region_name || '',
    address_name: l.address_name || '',
  }));

  // استعادة التعيينات بعد رسالة خطأ (تُمرَّر في lines_json)
  if (!selectedRows.length && req.query.lines_json && selectedRep > 0) {
    try {
      const kept = JSON.parse(String(req.query.lines_json));
      if (Array.isArray(kept) && kept.length) {
        const custRows = await masters.listTourCustomers({
          salesRepId: selectedRep,
          limit: 2000,
        });
        const byId = new Map(custRows.map((c) => [Number(c.id), c]));
        selectedRows = kept
          .map((k) => {
            const cid = Number(k.customer_id || 0);
            const c = byId.get(cid);
            if (!cid || !c) return null;
            return {
              customer_id: cid,
              code: c.code || '',
              name: c.name_ar || '',
              weekday: Number(k.weekday || 0),
              region_id: Number(k.region_id || c.region_id || 0) || null,
              region_address_id:
                Number(k.region_address_id || c.region_address_id || 0) || null,
              region_name: c.region_name || '',
              address_name: c.address_name || '',
            };
          })
          .filter(Boolean);
      }
    } catch {
      /* تجاهل */
    }
  }

  const selectedJson = JSON.stringify(selectedRows).replace(/</g, '\\u003c');

  const formTitle = edit
    ? isPosted
      ? `عرض جولة #${edit.id}`
      : `تعديل جولة #${edit.id}`
    : 'جولة جديدة';

  const body = `
    <div class="si-stage srr-page srr-tour-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: formTitle,
        subtitle: 'عملاء المندوب فقط · فترة شهرية قابلة للتعديل · اختر يوم الأسبوع ثم العملاء لذلك اليوم',
        actions: [
          ...(isPosted
            ? [
                {
                  label: 'فك ترحيل',
                  submit: true,
                  form: 'srr-form',
                  name: 'tour_action',
                  value: 'unpost',
                },
                {
                  label: 'طباعة الجولة',
                  href: `/sales-reps/route/${edit.id}/print`,
                  primary: true,
                },
              ]
            : [
                {
                  label: 'حفظ',
                  submit: true,
                  form: 'srr-form',
                  primary: true,
                  hxSave: true,
                  title: 'F10',
                },
                {
                  label: 'حفظ وترحيل',
                  submit: true,
                  form: 'srr-form',
                  name: 'and_post',
                  value: '1',
                },
                ...(edit
                  ? [
                      {
                        label: 'ترحيل',
                        submit: true,
                        form: 'srr-form',
                        name: 'tour_action',
                        value: 'post',
                      },
                    ]
                  : []),
              ]),
          { label: 'قائمة الجولات', href: '/sales-reps/route' },
          { label: '＋ جولة جديدة', href: '/sales-reps/route?new=1' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'المندوبين', href: '/sales-reps/list' },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}

      <section class="si-surface srr-card">
        <div class="si-surface-head">
          <h2>${edit ? (isPosted ? 'عرض جولة مرحّلة' : 'تعديل جولة') : 'خطة جولة جديدة'}</h2>
          <span class="si-count">${
            edit
              ? '#' + edit.id + ' · ' + statusLabel(edit.status)
              : 'جديد · مسودة'
          }</span>
        </div>
        <form method="post" action="/sales-reps/route" class="srr-form srr-tour-form" id="srr-form">
          <input type="hidden" name="id" value="${edit ? edit.id : 0}">
          <input type="hidden" name="lines_json" id="srr-lines-json" value="">

          <div class="srr-form__fields">
            <div class="srr-step" data-step="1">
              <div class="srr-step__head"><span class="srr-step__num">1</span> المندوب وفترة الجولة</div>
              <div class="srr-form__row">
                <label class="srr-field">
                  <span>المندوب <em>*</em></span>
                  <select class="si-field" name="sales_rep_id" id="srr-rep" required ${isPosted ? 'disabled' : ''}>
                    <option value="">— اختر المندوب —</option>
                    ${repOpts}
                  </select>
                  ${isPosted ? `<input type="hidden" name="sales_rep_id" value="${selectedRep}">` : ''}
                </label>
                <label class="srr-field srr-field--date">
                  <span>من تاريخ <em>*</em></span>
                  <input class="si-field si-field--mono srr-date" type="date" name="date_from" id="srr-from"
                         required value="${esc(dateFrom)}" dir="ltr" ${isPosted ? 'readonly' : ''}>
                </label>
                <label class="srr-field srr-field--date">
                  <span>إلى تاريخ <em>*</em></span>
                  <input class="si-field si-field--mono srr-date" type="date" name="date_to" id="srr-to"
                         required value="${esc(dateTo)}" dir="ltr" ${isPosted ? 'readonly' : ''}>
                </label>
              </div>
              <div class="srr-period">
                <span class="srr-period__txt" id="srr-period-txt">—</span>
                ${
                  isPosted
                    ? ''
                    : `<span class="srr-period__quick">
                        <button type="button" class="si-btn srr-btn-sm" data-month="0">هذا الشهر</button>
                        <button type="button" class="si-btn srr-btn-sm" data-month="1">الشهر القادم</button>
                      </span>`
                }
              </div>
            </div>

            <div class="srr-step" data-step="2">
              <div class="srr-step__head">
                <span class="srr-step__num">2</span> أيام الأسبوع <em>*</em>
              </div>
              <div class="srr-weekdays" id="srr-weekdays">
                <div class="srr-weekdays__chips" id="srr-day-chips">${weekdayChips}</div>
                <p class="srr-weekdays__hint muted" id="srr-day-hint">حدد يوماً من أيام الأسبوع لبدء إضافة العملاء.</p>
              </div>
              <div class="srr-cal" id="srr-cal" aria-hidden="true"></div>
            </div>

            <div class="srr-step" data-step="3">
              <div class="srr-step__head"><span class="srr-step__num">3</span> فلترة قائمة العملاء (اختياري)</div>
              <div class="srr-form__row">
                <label class="srr-field">
                  <span>المنطقة</span>
                  <select class="si-field" id="srr-region" ${isPosted ? 'disabled' : ''}>
                    <option value="0">— كل المناطق —</option>
                    ${regionOpts}
                  </select>
                </label>
                <label class="srr-field">
                  <span>العنوان</span>
                  <select class="si-field" id="srr-address" ${isPosted ? 'disabled' : ''}>
                    <option value="0">— كل العناوين —</option>
                  </select>
                </label>
              </div>
            </div>

            <label class="srr-field srr-field--full">
              <span>ملاحظات</span>
              <textarea class="si-field srr-notes" name="notes" rows="2" placeholder="اختياري…" ${
                isPosted ? 'readonly' : ''
              }>${esc(String(req.query.notes || edit?.notes || ''))}</textarea>
            </label>

            <div class="srr-selected-panel">
              <div class="srr-selected-panel__head">
                <strong>عملاء الخطة</strong>
                <span class="srr-plan__sum" id="srr-actions-sum">لا تعيينات بعد</span>
                <span class="srr-chip-count" id="srr-selected-count">0 تعيين</span>
              </div>
              <div class="srr-plan" id="srr-plan"></div>
            </div>
          </div>

          <div class="srr-form__cust">
            <div class="srr-cust__toolbar">
              <div class="srr-cust__title">
                <strong>اختيار العملاء</strong>
                <span class="muted" id="srr-cust-day-label">— اختر يوماً —</span>
                <span class="muted" id="srr-cust-total">0</span>
              </div>
              <input type="search" class="si-field srr-cust__search" id="srr-cust-q"
                     placeholder="بحث بالاسم أو الرمز…" autocomplete="off" ${isPosted ? 'disabled' : ''}>
              <div class="srr-cust__tools">
                <button type="button" class="si-btn srr-btn-sm" id="srr-add-visible" ${
                  isPosted ? 'disabled' : ''
                }>إضافة الظاهر لليوم</button>
              </div>
            </div>
            <div class="srr-cust__list" id="srr-cust-list">
              <p class="srr-cust__empty">اختر المندوب ويوم الأسبوع لعرض عملائه</p>
            </div>
          </div>
        </form>
      </section>
    </div>
    <script>
    (function(){
      var posted = ${isPosted ? 'true' : 'false'};
      var selected = ${selectedJson};
      var dayLabels = ${JSON.stringify(dayLabels)};
      var activeWeekday = null;
      var repEl = document.getElementById('srr-rep');
      var regionEl = document.getElementById('srr-region');
      var addressEl = document.getElementById('srr-address');
      var list = document.getElementById('srr-cust-list');
      var qEl = document.getElementById('srr-cust-q');
      var totalEl = document.getElementById('srr-cust-total');
      var dayLabelEl = document.getElementById('srr-cust-day-label');
      var dayHint = document.getElementById('srr-day-hint');
      var planEl = document.getElementById('srr-plan');
      var countEl = document.getElementById('srr-selected-count');
      var sumEl = document.getElementById('srr-actions-sum');
      var calEl = document.getElementById('srr-cal');
      var linesInput = document.getElementById('srr-lines-json');
      var form = document.getElementById('srr-form');
      var addVisibleBtn = document.getElementById('srr-add-visible');
      var chips = document.getElementById('srr-day-chips');
      var fromEl = document.getElementById('srr-from');
      var toEl = document.getElementById('srr-to');
      var periodEl = document.getElementById('srr-period-txt');
      var custCache = [];
      var loadTimer = null;

      function dmy(iso){
        var m = String(iso||'').match(/^(\\d{4})-(\\d{2})-(\\d{2})$/);
        return m ? (m[3]+'-'+m[2]+'-'+m[1]) : '';
      }

      function isoOfMonth(offset, last){
        var n = new Date();
        var y = n.getFullYear(), mo = n.getMonth() + Number(offset||0);
        var d = last ? new Date(y, mo+1, 0) : new Date(y, mo, 1);
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')
          + '-' + String(d.getDate()).padStart(2,'0');
      }

      function renderPeriod(){
        if(!periodEl) return;
        var f = fromEl && fromEl.value, t = toEl && toEl.value;
        if(!f || !t){ periodEl.textContent = 'حدّد الفترة (من / إلى).'; return; }
        var days = Math.round((new Date(t+'T12:00:00') - new Date(f+'T12:00:00')) / 86400000) + 1;
        periodEl.textContent = days > 0
          ? ('الفترة: ' + dmy(f) + ' → ' + dmy(t) + ' · ' + days + ' يوماً')
          : 'تاريخ النهاية قبل البداية.';
      }

      function escHtml(s){
        return String(s==null?'':s)
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      function countByDay(){
        var map = {};
        selected.forEach(function(r){
          var w = Number(r.weekday);
          map[w] = (map[w]||0) + 1;
        });
        return map;
      }

      function syncHidden(){
        if(linesInput) linesInput.value = JSON.stringify(selected.map(function(r){
          return {
            customer_id: r.customer_id,
            weekday: Number(r.weekday),
            region_id: r.region_id || null,
            region_address_id: r.region_address_id || null
          };
        }));
        if(countEl) countEl.textContent = selected.length + ' تعيين';
        var counts = countByDay();
        if(chips){
          chips.querySelectorAll('.srr-day-chip').forEach(function(b){
            var w = Number(b.getAttribute('data-weekday'));
            var n = counts[w] || 0;
            var badge = b.querySelector('.srr-day-chip__n');
            if(badge) badge.textContent = String(n);
            b.classList.toggle('has-cust', n > 0);
          });
        }
        if(sumEl){
          var daysUsed = Object.keys(counts).length;
          sumEl.textContent = selected.length
            ? (selected.length + ' تعيين على ' + daysUsed + ' يوم من أيام الأسبوع')
            : 'لا تعيينات بعد';
        }
        renderCal();
      }

      /** معاينة الشهر: تُبرز التواريخ التي ستُنشأ فيها زيارات */
      function renderCal(){
        if(!calEl) return;
        var f = fromEl && fromEl.value, t = toEl && toEl.value;
        if(!f || !t || f > t){ calEl.innerHTML = ''; return; }
        var counts = countByDay();
        var start = new Date(f+'T12:00:00'), end = new Date(t+'T12:00:00');
        if(isNaN(start) || isNaN(end)){ calEl.innerHTML = ''; return; }
        var span = Math.round((end - start)/86400000) + 1;
        if(span < 1 || span > 120){ calEl.innerHTML = ''; return; }
        var shortLabels = ['أحد','إثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت'];
        var cells = [];
        var wdHead = [6,0,1,2,3,4,5];
        wdHead.forEach(function(w){
          cells.push('<span class="srr-cal__h">'+escHtml(shortLabels[w]||'')+'</span>');
        });
        // فراغات قبل أول يوم لمحاذاة الأعمدة (السبت أولاً)
        var lead = (start.getDay() + 1) % 7;
        for(var i=0;i<lead;i++) cells.push('<span class="srr-cal__d is-void"></span>');
        var cur = new Date(start);
        while(cur <= end){
          var w = cur.getDay();
          var n = counts[w] || 0;
          cells.push('<span class="srr-cal__d'+(n?' is-on':'')+'" title="'
            + escHtml(dayLabels[w]||'') + (n ? (' · '+n+' عميل') : '')
            + '">'+cur.getDate()+(n?'<i></i>':'')+'</span>');
          cur.setDate(cur.getDate()+1);
        }
        calEl.innerHTML = '<div class="srr-cal__grid">'+cells.join('')+'</div>';
      }

      function setActiveDay(wd){
        activeWeekday = (wd === null || wd === undefined || wd === '') ? null : Number(wd);
        if(chips){
          chips.querySelectorAll('.srr-day-chip').forEach(function(b){
            b.classList.toggle('is-active', Number(b.getAttribute('data-weekday')) === activeWeekday);
          });
        }
        if(dayLabelEl){
          dayLabelEl.textContent = activeWeekday === null ? '— اختر يوماً —' : (dayLabels[activeWeekday] || '');
        }
        if(dayHint){
          dayHint.textContent = activeWeekday === null
            ? 'حدد يوماً من أيام الأسبوع لبدء إضافة العملاء.'
            : 'اليوم النشط: ' + (dayLabels[activeWeekday] || '') + ' — أضف عملاء هذا اليوم فقط.';
        }
        if(planEl){
          planEl.querySelectorAll('.srr-plan__day').forEach(function(d){
            var b = d.querySelector('[data-goto-day]');
            d.classList.toggle('is-active', !!b && Number(b.getAttribute('data-goto-day')) === activeWeekday);
          });
        }
        renderCustList();
      }

      /** عملاء الخطة مجمّعين تحت كل يوم أسبوع */
      function renderSelected(){
        if(!planEl) return;
        if(!selected.length){
          planEl.innerHTML = '<p class="srr-plan__empty">لم يُختر عملاء بعد — اختر يوماً ثم أضف عملاءه.</p>';
          syncHidden();
          return;
        }
        var order = [6,0,1,2,3,4,5];
        var html = '';
        order.forEach(function(wd){
          var rows = selected.filter(function(r){ return Number(r.weekday)===wd; });
          if(!rows.length) return;
          rows.sort(function(a,b){
            return String(a.name||'').localeCompare(String(b.name||''),'ar');
          });
          html += '<div class="srr-plan__day'+(wd===activeWeekday?' is-active':'')+'">'
            + '<div class="srr-plan__dayhead">'
            + '<button type="button" class="srr-plan__daybtn" data-goto-day="'+wd+'">'
            + escHtml(dayLabels[wd]||'') + '</button>'
            + '<span class="srr-chip-count">'+rows.length+'</span>'
            + '</div><div class="srr-plan__items">'
            + rows.map(function(r){
                return '<span class="srr-cust-tag" title="'+escHtml([r.region_name,r.address_name].filter(Boolean).join(' / '))+'">'
                  + '<b>'+escHtml(r.name)+'</b>'
                  + '<i dir="ltr">'+escHtml(r.code)+'</i>'
                  + (posted ? '' : '<button type="button" class="srr-cust-tag__x srr-remove" data-cid="'
                      + r.customer_id + '" data-wd="' + Number(r.weekday) + '" title="حذف">×</button>')
                  + '</span>';
              }).join('')
            + '</div></div>';
        });
        planEl.innerHTML = html;
        syncHidden();
      }

      function hasAssignment(cid, wd){
        return selected.some(function(r){
          return Number(r.customer_id)===Number(cid) && Number(r.weekday)===Number(wd);
        });
      }

      function addCustomer(c){
        if(posted) return;
        if(activeWeekday === null || activeWeekday === undefined || isNaN(activeWeekday)){
          alert('اختر يوم الأسبوع أولاً.');
          return;
        }
        var id = Number(c.id);
        if(!id || hasAssignment(id, activeWeekday)) return;
        selected.push({
          customer_id: id,
          code: c.code || '',
          name: c.name_ar || c.name || '',
          weekday: Number(activeWeekday),
          region_id: c.region_id || null,
          region_address_id: c.region_address_id || null,
          region_name: c.region_name || '',
          address_name: c.address_name || ''
        });
        renderSelected();
        renderCustList();
      }

      function removeAssignment(cid, wd){
        selected = selected.filter(function(r){
          return !(Number(r.customer_id)===Number(cid) && Number(r.weekday)===Number(wd));
        });
        renderSelected();
        renderCustList();
      }

      function renderCustList(){
        if(!list) return;
        if(Number(repEl && repEl.value || 0) < 1){
          list.innerHTML = '<p class="srr-cust__empty">اختر المندوب أولاً لعرض عملائه فقط</p>';
          if(totalEl) totalEl.textContent = '0';
          return;
        }
        if(activeWeekday === null || activeWeekday === undefined || isNaN(activeWeekday)){
          list.innerHTML = '<p class="srr-cust__empty">اختر يوم الأسبوع ثم أضف عملاء هذا اليوم</p>';
          if(totalEl) totalEl.textContent = String(custCache.length || 0);
          return;
        }
        if(!custCache.length){
          list.innerHTML = '<p class="srr-cust__empty">لا يوجد عملاء تابعون لهذا المندوب'+(regionEl && Number(regionEl.value)>0 ? ' ضمن الفلتر' : '')+'</p>';
          if(totalEl) totalEl.textContent = '0';
          return;
        }
        list.innerHTML = custCache.map(function(c){
          var on = hasAssignment(c.id, activeWeekday);
          return '<label class="srr-cust__row'+(on?' is-on':'')+'">'
            + '<input type="checkbox" '+(on?'checked':'')+' data-id="'+c.id+'" '+(posted?'disabled':'')+'>'
            + '<span class="srr-cust__name">'+escHtml(c.name_ar||'')
            + (c.region_name || c.address_name
                ? ' <span class="muted">· '+escHtml([c.region_name,c.address_name].filter(Boolean).join(' / '))+'</span>'
                : '')
            + '</span>'
            + '<span class="srr-cust__code" dir="ltr">'+escHtml(c.code||'')+'</span>'
            + '</label>';
        }).join('');
        if(totalEl) totalEl.textContent = String(custCache.length);
      }

      function loadAddresses(){
        if(!addressEl) return Promise.resolve();
        var rid = Number(regionEl && regionEl.value || 0);
        addressEl.innerHTML = '<option value="0">— كل العناوين —</option>';
        if(rid < 1) return Promise.resolve();
        return fetch('/api/sales-reps/addresses?region_id='+rid, {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(j){
            (j.rows||[]).forEach(function(a){
              var o = document.createElement('option');
              o.value = a.id;
              o.textContent = a.name_ar || ('#'+a.id);
              addressEl.appendChild(o);
            });
          }).catch(function(){});
      }

      function loadCustomers(){
        if(!list) return;
        var repId = Number(repEl && repEl.value || 0);
        if(repId < 1){
          custCache = [];
          renderCustList();
          return;
        }
        list.innerHTML = '<p class="srr-cust__empty">جاري التحميل…</p>';
        var qs = new URLSearchParams({
          sales_rep_id: String(repId),
          region_id: String(regionEl && regionEl.value || 0),
          region_address_id: String(addressEl && addressEl.value || 0),
          q: String(qEl && qEl.value || '')
        });
        fetch('/api/sales-reps/tour-customers?'+qs.toString(), {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(j){
            custCache = j.rows || [];
            renderCustList();
          })
          .catch(function(){
            list.innerHTML = '<p class="srr-cust__empty">تعذر تحميل العملاء</p>';
          });
      }

      function scheduleLoad(){
        clearTimeout(loadTimer);
        loadTimer = setTimeout(loadCustomers, 200);
      }

      if(chips){
        chips.addEventListener('click', function(e){
          var btn = e.target.closest('.srr-day-chip');
          if(!btn || posted) return;
          setActiveDay(Number(btn.getAttribute('data-weekday')));
        });
      }

      if(list){
        list.addEventListener('change', function(e){
          var t = e.target;
          if(!t || t.type !== 'checkbox') return;
          var id = Number(t.getAttribute('data-id')||0);
          if(!id) return;
          if(activeWeekday === null || isNaN(activeWeekday)){
            t.checked = false;
            alert('اختر يوم الأسبوع أولاً.');
            return;
          }
          if(t.checked){
            var c = custCache.find(function(x){ return Number(x.id)===id; });
            if(c) addCustomer(c);
          } else {
            removeAssignment(id, activeWeekday);
          }
        });
      }

      if(planEl){
        planEl.addEventListener('click', function(e){
          var rm = e.target.closest('.srr-remove');
          if(rm){
            removeAssignment(Number(rm.getAttribute('data-cid')||0), Number(rm.getAttribute('data-wd')||0));
            return;
          }
          var go = e.target.closest('[data-goto-day]');
          if(go && !posted) setActiveDay(Number(go.getAttribute('data-goto-day')));
        });
      }

      if(repEl) repEl.addEventListener('change', function(){
        custCache = [];
        scheduleLoad();
      });
      if(regionEl) regionEl.addEventListener('change', function(){
        loadAddresses().then(scheduleLoad);
      });
      if(addressEl) addressEl.addEventListener('change', scheduleLoad);
      if(qEl) qEl.addEventListener('input', scheduleLoad);

      if(addVisibleBtn) addVisibleBtn.addEventListener('click', function(){
        if(activeWeekday === null || isNaN(activeWeekday)){
          alert('اختر يوم الأسبوع أولاً.');
          return;
        }
        custCache.forEach(addCustomer);
      });

      function periodChanged(){ renderPeriod(); renderCal(); }
      if(fromEl) fromEl.addEventListener('change', periodChanged);
      if(toEl) toEl.addEventListener('change', periodChanged);
      document.querySelectorAll('.srr-period__quick [data-month]').forEach(function(b){
        b.addEventListener('click', function(){
          var off = Number(b.getAttribute('data-month')||0);
          if(fromEl) fromEl.value = isoOfMonth(off, false);
          if(toEl) toEl.value = isoOfMonth(off, true);
          periodChanged();
        });
      });
      renderPeriod();

      if(form) form.addEventListener('submit', function(e){
        syncHidden();
        if(posted) return;
        if(fromEl && toEl && fromEl.value && toEl.value && fromEl.value > toEl.value){
          var tmp = fromEl.value; fromEl.value = toEl.value; toEl.value = tmp;
        }
        if(!selected.length){
          e.preventDefault();
          alert('أضف عميلاً واحداً على الأقل بعد اختيار يوم الأسبوع.');
        }
      });

      // على التعديل: فعّل أول يوم موجود في الخطة
      if(selected.length){
        setActiveDay(Number(selected[0].weekday));
      } else {
        setActiveDay(null);
      }
      renderSelected();
      if(Number(repEl && repEl.value || 0) > 0) scheduleLoad();
    })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: formTitle,
      bodyHtml: body,
      css: ['/assets/css/sales-rep-route.css'],
      js: ['/assets/js/sales-print.js'],
      activePath: '/sales-reps/route',
    })
  );
});

router.post('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  const body = req.body || {};
  const tourAction = String(body.tour_action || '').trim().toLowerCase();
  const tourId = Number(body.id || 0);

  if (tourAction === 'post' || tourAction === 'unpost' || tourAction === 'delete') {
    let result;
    if (tourAction === 'post') result = await masters.postTour(tourId, req.session.user?.id);
    else if (tourAction === 'unpost') result = await masters.unpostTour(tourId, req.session.user?.id);
    else result = await masters.deleteTour(tourId);
    const key = result.ok ? 'msg' : 'err';
    const qs =
      tourAction === 'delete'
        ? `${key}=${encodeURIComponent(result.message || result.error || '')}`
        : `id=${tourId}&${key}=${encodeURIComponent(result.message || result.error || '')}`;
    return res.redirect('/sales-reps/route?' + qs);
  }

  try {
    if (body.lines_json) {
      const parsed = JSON.parse(String(body.lines_json || '[]'));
      if (Array.isArray(parsed)) body.lines = parsed;
    }
  } catch {
    /* keep customer_ids if any */
  }
  const result = await masters.saveTour(body, req.session.user?.id);
  if (!result.ok) {
    // احفظ ما أدخله المستخدم حتى لا يعيد تعبئة الشاشة من الصفر
    const keep = new URLSearchParams();
    if (tourId > 0) {
      keep.set('id', String(tourId));
    } else {
      keep.set('new', '1');
      if (body.sales_rep_id) keep.set('sales_rep_id', String(body.sales_rep_id));
    }
    const from = masters.normalizeIsoDate(body.date_from);
    const to = masters.normalizeIsoDate(body.date_to);
    if (from) keep.set('date_from', from);
    if (to) keep.set('date_to', to);
    if (body.notes) keep.set('notes', String(body.notes).slice(0, 300));
    if (body.lines_json) keep.set('lines_json', String(body.lines_json).slice(0, 8000));
    keep.set('err', result.error);
    return res.redirect('/sales-reps/route?' + keep.toString());
  }
  if (String(body.and_post || '') === '1') {
    const post = await masters.postTour(result.id, req.session.user?.id);
    return res.redirect(
      '/sales-reps/route?id=' +
        result.id +
        (post.ok ? '&msg=' : '&err=') +
        encodeURIComponent(post.ok ? post.message : post.error || result.message)
    );
  }
  res.redirect(
    '/sales-reps/route?id=' +
      result.id +
      '&msg=' +
      encodeURIComponent(result.message || 'تم')
  );
});

router.post('/sales-reps/route/:id/post', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.postTour(req.params.id, req.session.user?.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales-reps/route?id=' +
      Number(req.params.id) +
      '&' +
      key +
      '=' +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales-reps/route/:id/unpost', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.unpostTour(req.params.id, req.session.user?.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales-reps/route?id=' +
      Number(req.params.id) +
      '&' +
      key +
      '=' +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales-reps/route/:id/delete', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.deleteTour(req.params.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect('/sales-reps/route?' + key + '=' + encodeURIComponent(result.message || result.error || ''));
});

router.get('/sales-reps/route/:id/print', guard('sales_rep_route'), async (req, res) => {
  const data = await masters.getTourPrintRows(req.params.id);
  if (!data) return res.status(404).send('الجولة غير موجودة');
  const { tour, rows } = data;
  const rowsHtml =
    rows
      .map(
        (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td><strong>${ui.esc(r.weekday_label || '')}</strong></td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.visit_date))}</td>
      <td>${ui.esc(r.customer_name || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.customer_code)}</td>
      <td>${dash(r.region_name)}</td>
      <td>${dash(r.address_name)}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(7, 'لا تفاصيل');

  const body = `
    <div class="si-stage si-report-page srr-print-page">
      ${ui.hero({
        mark: '🖨',
        kicker: KICKER,
        title: 'طباعة جولة مندوب',
        subtitle: `${ui.esc(tour.sales_rep_name || '')} · ${ui.esc(ui.isoToDmy(tour.date_from))} → ${ui.esc(
          ui.isoToDmy(tour.date_to)
        )} · ${statusLabel(tour.status)}`,
        actions: [
          ui.printAction(),
          ui.backAction('/sales-reps/route?id=' + tour.id, 'العودة للجولة'),
        ],
      })}
      <div class="si-print-area">
        <div class="srr-print-meta">
          <table class="si-table" style="margin-bottom:1rem">
            <tbody>
              <tr><th style="width:9rem">المندوب</th><td>${ui.esc(tour.sales_rep_name || '')}${
                tour.sales_rep_code ? ' (' + ui.esc(tour.sales_rep_code) + ')' : ''
              }</td></tr>
              <tr><th>تاريخ البداية</th><td dir="ltr">${ui.esc(ui.isoToDmy(tour.date_from))}</td></tr>
              <tr><th>تاريخ النهاية</th><td dir="ltr">${ui.esc(ui.isoToDmy(tour.date_to))}</td></tr>
              <tr><th>الحالة</th><td>${statusLabel(tour.status)}</td></tr>
              ${tour.notes ? `<tr><th>ملاحظات</th><td>${ui.esc(tour.notes)}</td></tr>` : ''}
            </tbody>
          </table>
        </div>
        ${ui.tableSurface(
          'تفاصيل الجولة',
          `${rows.length} صف`,
          ['#', 'اليوم', 'التاريخ', 'العميل', 'الرمز', 'المنطقة', 'العنوان'],
          rowsHtml
        )}
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'طباعة جولة — ' + (tour.sales_rep_name || ''),
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

/* ═══════════ Reports → تحت المبيعات ═══════════ */
function redirectSalesReport(basePath) {
  return (req, res) => {
    const qs = new URLSearchParams(req.query).toString();
    res.redirect(basePath + (qs ? '?' + qs : ''));
  };
}

router.get('/sales-reps/reports/by-rep', redirectSalesReport('/sales/reports/by-rep'));
router.get('/sales-reps/reports/by-region', redirectSalesReport('/sales/reports/by-region'));

/** اعتماد خروج يدوي — شاشة Node أصلية (بدون iframe إلى localhost) */
async function renderVisitCheckoutApprove(req, res, { flash = '', err = '' } = {}) {
  const status = ['pending', 'approved', 'rejected', 'all'].includes(String(req.query.status || ''))
    ? String(req.query.status)
    : 'pending';
  const focusId = Number(req.query.id || 0) || 0;
  const rows = await masters.listVisitCheckoutRequests(status, 200);
  const msg = flash || String(req.query.msg || '');
  const error = err || String(req.query.err || '');

  function stLbl(st) {
    if (st === 'pending') return ui.statusPill('wait', 'معلّق');
    if (st === 'approved') return ui.statusPill('ok', 'موافق عليه');
    if (st === 'rejected') return ui.statusPill('lock', 'مرفوض');
    return esc(st);
  }
  function distLabel(v) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    return Number.isFinite(n) ? `${Math.round(n)} م` : '—';
  }

  const tabs = [
    ['pending', 'معلّق'],
    ['approved', 'موافق عليه'],
    ['rejected', 'مرفوض'],
    ['all', 'الكل'],
  ]
    .map(
      ([k, lab]) =>
        `<a class="si-btn${status === k ? ' si-btn--primary' : ''}" href="/sales-reps/visit-checkout-approve?status=${k}">${esc(
          lab
        )}</a>`
    )
    .join(' ');

  const rowsHtml =
    rows
      .map((r) => {
        const id = Number(r.id) || 0;
        const st = String(r.status || '');
        const focus = focusId > 0 && focusId === id;
        const actions =
          st === 'pending'
            ? `<form method="post" action="/sales-reps/visit-checkout-approve" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="status" value="${esc(status)}">
                <input class="si-field" type="text" name="note" placeholder="ملاحظة" style="min-width:8rem">
                <button class="si-btn si-btn--primary" type="submit" name="action" value="approve">موافقة</button>
                <button class="si-btn" type="submit" name="action" value="reject">رفض</button>
              </form>`
            : `<span class="muted">${esc(r.decided_by_name || '')}</span>`;
        return `<tr${focus ? ' style="background:#fff7ed"' : ''}>
          <td class="si-num" dir="ltr">${id}</td>
          <td>${esc(r.sales_rep_name || '—')}</td>
          <td>${esc(r.customer_name || '—')}<div class="muted" dir="ltr">${esc(r.customer_code || '')}</div></td>
          <td>${esc(r.reason || '—')}</td>
          <td class="si-num" dir="ltr">${distLabel(r.request_distance_m)}</td>
          <td class="si-num" dir="ltr">${esc(r.created_at || '')}</td>
          <td>${stLbl(st)}</td>
          <td>${actions}</td>
        </tr>`;
      })
      .join('') || ui.emptyRow(8, status === 'pending' ? 'لا توجد طلبات معلّقة.' : 'لا توجد طلبات.');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '🚪',
        kicker: KICKER,
        title: 'اعتماد خروج يدوي من الزيارة',
        subtitle: 'طلبات المندوبين الذين دخلوا بـ GPS ونسوا الخروج من موقع العميل',
        actions: [
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits', primary: true },
        ],
      })}
      ${msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : ''}
      ${error ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(error)}</p>` : ''}
      <section class="si-surface no-print" style="padding:.85rem 1rem;margin-bottom:.75rem">
        <div class="si-meta" style="align-items:center;gap:.4rem">${tabs}</div>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem">${rows.length} طلب</p>
      </section>
      ${ui.tableSurface(
        'طلبات الخروج اليدوي',
        `${rows.length} صف`,
        ['#', 'المندوب', 'العميل', 'السبب', 'المسافة', 'وقت الطلب', 'الحالة', ''],
        rowsHtml
      )}
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'اعتماد خروج يدوي',
      bodyHtml: body,
    })
  );
}

router.get('/sales-reps/visit-checkout-approve', guard('sales_rep_visit_checkout_approve'), (req, res) => {
  return renderVisitCheckoutApprove(req, res);
});

router.post('/sales-reps/visit-checkout-approve', guard('sales_rep_visit_checkout_approve'), async (req, res) => {
  const body = req.body || {};
  const result = await masters.decideVisitCheckoutRequest({
    id: body.id,
    approve: String(body.action || '') === 'approve',
    userId: req.session.user && req.session.user.id,
    note: body.note || null,
  });
  const status = String(body.status || req.query.status || 'pending');
  const q = new URLSearchParams();
  q.set('status', status);
  if (result.ok) q.set('msg', result.message || 'تم');
  else q.set('err', result.message || 'تعذّر التنفيذ');
  res.redirect('/sales-reps/visit-checkout-approve?' + q.toString());
});

function fmtGpsCoord(lat, lng) {
  if (lat == null || lat === '' || lng == null || lng === '') return '—';
  const a = Number(lat);
  const b = Number(lng);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return '—';
  return `${a.toFixed(6)} ، ${b.toFixed(6)}`;
}

const REP_REPORT_CSS = ['/assets/css/report-rep-reports.css'];
const REP_REPORT_STYLE = (() => {
  try {
    const cssPath = path.join(__dirname, '../../public/css/report-rep-reports.css');
    return `<style>${fs.readFileSync(cssPath, 'utf8')}</style>`;
  } catch {
    return '';
  }
})();

function visitTsMethod(ts, method, fmtTsFn, methodLblFn) {
  const t = fmtTsFn(ts);
  const m = methodLblFn(method);
  if (t === '—' && m === '—') return '—';
  return `<span class="si-ts-method" dir="ltr">${t}${m !== '—' ? `<span class="muted"> · ${esc(m)}</span>` : ''}</span>`;
}

function fmtTimeOnly(v) {
  if (!v) return '';
  const s = String(v);
  if (s.length >= 16) return s.slice(11, 16);
  return s.trim();
}

function visitMethodPairLabel(cm, com) {
  if (cm === '—' && com === '—') return '—';
  if (cm !== '—' && com !== '—') return cm === com ? cm : `${cm} / ${com}`;
  return cm !== '—' ? cm : com;
}

function visitTimingCells(r, durationFn, methodLblFn) {
  const cin = fmtTimeOnly(r.visit_checkin_at) || '—';
  const cout = fmtTimeOnly(r.visit_checkout_at) || '—';
  const dur = durationFn(r.visit_checkin_at, r.visit_checkout_at);
  const checkinMethod = methodLblFn(r.checkin_method);
  return `<td class="si-col-checkin" dir="ltr">${cin}</td>
      <td class="si-col-checkout" dir="ltr">${cout}</td>
      <td class="si-col-duration">${dur}</td>
      <td class="si-col-method">${checkinMethod}</td>`;
}

function customerNameOnly(r) {
  return esc(r.customer_name || '—');
}

function visitRowClass(r) {
  const orderCount = Number(r.order_count || 0);
  const reasons = String(r.no_order_reasons || '').trim();
  if (orderCount <= 0 && reasons !== '' && reasons !== '—') return 'si-visits-no-order';
  return '';
}

function visitTotals(rows) {
  let mins = 0;
  let sales = 0;
  for (const r of rows) {
    if (r.visit_checkin_at && r.visit_checkout_at) {
      const t1 = Date.parse(String(r.visit_checkin_at).replace(' ', 'T'));
      const t2 = Date.parse(String(r.visit_checkout_at).replace(' ', 'T'));
      if (Number.isFinite(t1) && Number.isFinite(t2) && t2 >= t1) {
        mins += Math.floor((t2 - t1) / 60000);
      }
    }
    sales += Number(r.order_total || 0);
  }
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return {
    duration_label: mins > 0 ? `${h}:${String(m).padStart(2, '0')}` : '—',
    sales_total: sales,
  };
}

function visitMoney(n) {
  return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function customerInline(r) {
  const name = esc(r.customer_name || '—');
  const code = String(r.customer_code || '').trim();
  return code ? `${name} <span class="muted si-cust-code" dir="ltr">(${esc(code)})</span>` : name;
}

function locationInline(r) {
  const reg = String(r.region_name || '').trim();
  const addr = String(r.address_name || '').trim();
  if (reg && addr) return esc(`${reg} / ${addr}`);
  return esc(reg || addr || '—');
}

function repNameInline(name, code) {
  const n = esc(name || '—');
  const c = String(code || '').trim();
  return c ? `${n} <span class="muted" dir="ltr">(${esc(c)})</span>` : n;
}

async function renderGpsChangeApprove(req, res, { flash = '', err = '' } = {}) {
  const status = ['pending', 'approved', 'rejected', 'all'].includes(String(req.query.status || ''))
    ? String(req.query.status)
    : 'pending';
  const focusId = Number(req.query.id || 0) || 0;
  const rows = await masters.listGpsChangeRequests(status, 200);
  const msg = flash || String(req.query.msg || '');
  const error = err || String(req.query.err || '');

  function stLbl(st) {
    if (st === 'pending') return ui.statusPill('wait', 'معلّق');
    if (st === 'approved') return ui.statusPill('ok', 'موافق عليه');
    if (st === 'rejected') return ui.statusPill('lock', 'مرفوض');
    return esc(st);
  }

  const tabs = [
    ['pending', 'معلّق'],
    ['approved', 'موافق عليه'],
    ['rejected', 'مرفوض'],
    ['all', 'الكل'],
  ]
    .map(
      ([k, lab]) =>
        `<a class="si-btn${status === k ? ' si-btn--primary' : ''}" href="/sales-reps/customer-gps-approve?status=${k}">${esc(
          lab
        )}</a>`
    )
    .join(' ');

  const rowsHtml =
    rows
      .map((r) => {
        const id = Number(r.id) || 0;
        const st = String(r.status || '');
        const focus = focusId > 0 && focusId === id;
        const clear = Number(r.clear_gps) === 1;
        const actions =
          st === 'pending'
            ? `<form method="post" action="/sales-reps/customer-gps-approve" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="status" value="${esc(status)}">
                <input class="si-field" type="text" name="note" placeholder="ملاحظة" style="min-width:8rem">
                <button class="si-btn si-btn--primary" type="submit" name="action" value="approve">موافقة</button>
                <button class="si-btn" type="submit" name="action" value="reject">رفض</button>
              </form>`
            : `<span class="muted">${esc(r.decided_by_name || '')}</span>`;
        return `<tr${focus ? ' style="background:#fff7ed"' : ''}>
          <td class="si-num" dir="ltr">${id}</td>
          <td>${esc(r.sales_rep_name || '—')}</td>
          <td>${esc(r.customer_name || '—')}<div class="muted" dir="ltr">${esc(r.customer_code || '')}</div></td>
          <td class="si-num" dir="ltr">${esc(fmtGpsCoord(r.old_latitude, r.old_longitude))}</td>
          <td class="si-num" dir="ltr">${clear ? 'مسح الموقع' : esc(fmtGpsCoord(r.new_latitude, r.new_longitude))}</td>
          <td class="si-num" dir="ltr">${esc(r.created_at || '')}</td>
          <td>${stLbl(st)}</td>
          <td>${actions}</td>
        </tr>`;
      })
      .join('') || ui.emptyRow(8, status === 'pending' ? 'لا توجد طلبات معلّقة.' : 'لا توجد طلبات.');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'اعتماد تعديل موقع العميل',
        subtitle: 'الحفظ الأول يتم مباشرة. أي تعديل لاحق يحتاج موافقة مدير المبيعات قبل تطبيقه.',
        actions: [
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'اعتماد خروج يدوي', href: '/sales-reps/visit-checkout-approve' },
        ],
      })}
      ${msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : ''}
      ${error ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(error)}</p>` : ''}
      <section class="si-surface no-print" style="padding:.85rem 1rem;margin-bottom:.75rem">
        <div class="si-meta" style="align-items:center;gap:.4rem">${tabs}</div>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem">${rows.length} طلب</p>
      </section>
      ${ui.tableSurface(
        'طلبات تعديل موقع العميل',
        `${rows.length} صف`,
        ['#', 'المندوب', 'العميل', 'الموقع الحالي', 'الموقع المطلوب', 'وقت الطلب', 'الحالة', ''],
        rowsHtml
      )}
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'اعتماد موقع العميل',
      bodyHtml: body,
    })
  );
}

router.get('/sales-reps/customer-gps-approve', guard('crm_customer_gps_approve'), (req, res) => {
  return renderGpsChangeApprove(req, res);
});

router.post('/sales-reps/customer-gps-approve', guard('crm_customer_gps_approve'), async (req, res) => {
  const body = req.body || {};
  const result = await masters.decideGpsChangeRequest({
    id: body.id,
    approve: String(body.action || '') === 'approve',
    userId: req.session.user && req.session.user.id,
    note: body.note || null,
  });
  const status = String(body.status || req.query.status || 'pending');
  const q = new URLSearchParams();
  q.set('status', status);
  if (result.ok) q.set('msg', result.message || 'تم');
  else q.set('err', result.message || 'تعذّر التنفيذ');
  res.redirect('/sales-reps/customer-gps-approve?' + q.toString());
});

/* ── تقرير الجولات ── */
router.get('/sales-reps/reports/tours', async (req, res) => {
  if (
    !can(req.session.user, 'report_sales_rep_tours') &&
    !can(req.session.user, 'sales_rep_route') &&
    !can(req.session.user, 'sales_reps')
  ) {
    return res.status(403).send('ممنوع');
  }

  await masters.ensureTourSchema();
  const from =
    String(req.query.from || '').slice(0, 10) ||
    (() => {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
    })();
  const to = String(req.query.to || '').slice(0, 10) || todayIso();
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const status = String(req.query.status || '');
  const reps = await q.listRepsSimple();
  const rows = await masters.reportTours({ from, to, salesRepId, status });

  function fmtTs(v) {
    if (!v) return '—';
    const s = String(v);
    if (s.length >= 16) {
      const d = s.slice(0, 10);
      const t = s.slice(11, 19);
      return ui.isoToDmy(d) + ' ' + t;
    }
    return esc(s);
  }
  function methodLabel(v) {
    const m = String(v || '').trim().toUpperCase();
    if (!m) return '—';
    if (m === 'GPS') return 'GPS';
    if (m === 'MANUAL') return 'يدوي';
    return esc(v);
  }
  function statusLbl(st) {
    return String(st) === 'posted'
      ? ui.statusPill('ok', 'مرحّلة')
      : ui.statusPill('wait', 'مسودة');
  }
  function durationLabel(a, b) {
    if (!a || !b) return '—';
    const t1 = Date.parse(String(a).replace(' ', 'T'));
    const t2 = Date.parse(String(b).replace(' ', 'T'));
    if (!Number.isFinite(t1) || !Number.isFinite(t2) || t2 < t1) return '—';
    const mins = Math.floor((t2 - t1) / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}:${String(m).padStart(2, '0')}`;
  }

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${esc(
          r.name_ar || ''
        )}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  // ملخص جولات فريدة + تفصيل العملاء
  const tourIds = new Set();
  const regionSet = new Set();
  const addressSet = new Set();
  for (const r of rows) {
    tourIds.add(Number(r.tour_id));
    if (r.region_name) regionSet.add(String(r.region_name));
    if (r.address_name) addressSet.add(String(r.address_name));
  }

  const rowsHtml =
    rows
      .map(
        (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr">
        <a href="/sales-reps/route?id=${Number(r.tour_id)}">${Number(r.tour_id)}</a>
      </td>
      <td class="si-col-rep">${esc(r.sales_rep_name || '—')}</td>
      <td class="si-col-date" dir="ltr">${esc(ui.isoToDmy(r.date_from))} → ${esc(ui.isoToDmy(r.date_to))}</td>
      <td class="si-col-status">${statusLbl(r.status)}</td>
      <td class="si-col-customer">${customerInline(r)}</td>
      <td class="si-col-location">${locationInline(r)}</td>
      ${visitTimingCells(r, durationLabel, methodLabel)}
    </tr>`
      )
      .join('') || ui.emptyRow(11, 'لا جولات في الفترة المحددة');

  const body = `
    <div class="si-stage si-report-page si-report-tours" data-hx-print-landscape="1">
      ${REP_REPORT_STYLE}
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'تقرير الجولات',
        subtitle: 'الجولات المُنشأة: بداية/نهاية · المندوب · المناطق والعناوين — وأوقات الزيارة عند الربط مع الآيباد',
        actions: [
          ui.printAction(),
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits' },
          { label: 'الجولات', href: '/sales-reps/route', primary: true },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/tours" class="si-meta" style="align-items:end">
          <label>من تاريخ
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}" dir="ltr">
          </label>
          <label>إلى تاريخ
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}" dir="ltr">
          </label>
          <label>المندوب
            <select class="si-field" name="sales_rep_id">
              <option value="0">— الكل —</option>
              ${repOpts}
            </select>
          </label>
          <label>الحالة
            <select class="si-field" name="status">
              <option value="" ${status === '' ? 'selected' : ''}>— الكل —</option>
              <option value="draft" ${status === 'draft' ? 'selected' : ''}>مسودة</option>
              <option value="posted" ${status === 'posted' ? 'selected' : ''}>مرحّلة</option>
            </select>
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          ${ui.siPrintBtnHtml('طباعة')}
        </form>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${tourIds.size} جولة · ${rows.length} عميل في الخطط
          ${regionSet.size ? ` · ${regionSet.size} منطقة` : ''}
          ${addressSet.size ? ` · ${addressSet.size} عنوان` : ''}.
          أعمدة <b>وقت الدخول · وقت الخروج · المدة · النوع</b> تظهر
          <b>GPS</b> أو <b>يدوي</b> لاحقاً عندما يسجّل المندوب الدخول/الخروج من الآيباد.
        </p>
      </section>
      <div class="si-print-area si-report-page si-report-tours" data-hx-print-landscape="1">
      ${ui.tableSurface(
        'تفاصيل الجولات والعملاء',
        `عدد السجلات: ${rows.length}`,
        [
          '#',
          'الجولة',
          'المندوب',
          'الفترة',
          'الحالة',
          'العميل',
          'الموقع',
          'وقت الدخول',
          'وقت الخروج',
          'مجموع الساعات',
          'نوع الدخول/الخروج',
        ],
        rowsHtml
      )}
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير الجولات',
      bodyHtml: body,
      css: REP_REPORT_CSS,
      printTitle: 'تقرير الجولات',
    })
  );
});

/* ── تقرير زيارات العملاء ── */
router.get('/sales-reps/reports/visits/data', async (req, res) => {
  if (
    !can(req.session.user, 'report_sales_rep_visits') &&
    !can(req.session.user, 'report_sales_rep_tours') &&
    !can(req.session.user, 'sales_rep_route') &&
    !can(req.session.user, 'sales_reps')
  ) {
    return res.status(403).json({ ok: false, error: 'forbidden' });
  }
  await masters.ensureTourSchema();
  const today = todayIso();
  const from = String(req.query.from || '').slice(0, 10) || today;
  const to = String(req.query.to || '').slice(0, 10) || today;
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const customerId = Number(req.query.customer_id || 0) || 0;
  const method = String(req.query.method || '').toUpperCase();
  const status = String(req.query.status || '');
  const rows = await masters.reportVisits({ from, to, salesRepId, customerId, method, status });
  res.json({ ok: true, from, to, rows, totals: visitTotals(rows) });
});

router.post('/sales-reps/reports/visits/delete', async (req, res) => {
  if (!can(req.session.user, 'report_sales_rep_visits')) {
    return res.status(403).json({ ok: false, message: 'ممنوع' });
  }
  if (!can(req.session.user, 'action_delete_sales_rep_visit')) {
    return res.status(403).json({ ok: false, message: 'لا تملك صلاحية حذف الزيارات.' });
  }
  const lineIds = Array.isArray(req.body?.line_ids) ? req.body.line_ids : [];
  const result = await masters.deleteVisitLines(lineIds);
  res.json(result);
});

router.get('/sales-reps/reports/visits', async (req, res) => {
  if (
    !can(req.session.user, 'report_sales_rep_visits') &&
    !can(req.session.user, 'report_sales_rep_tours') &&
    !can(req.session.user, 'sales_rep_route') &&
    !can(req.session.user, 'sales_reps')
  ) {
    return res.status(403).send('ممنوع');
  }

  await masters.ensureTourSchema();
  const today = todayIso();
  const from = String(req.query.from || '').slice(0, 10) || today;
  const to = String(req.query.to || '').slice(0, 10) || today;
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const customerId = Number(req.query.customer_id || 0) || 0;
  const method = String(req.query.method || '').toUpperCase();
  const status = String(req.query.status || '');
  const reps = await q.listRepsSimple();
  const customers = await masters.listVisitReportCustomers({ from, to });
  const rows = await masters.reportVisits({ from, to, salesRepId, customerId, method, status });

  function fmtTs(v) {
    if (!v) return '—';
    const s = String(v);
    if (s.length >= 16) {
      return ui.isoToDmy(s.slice(0, 10)) + ' ' + s.slice(11, 16);
    }
    return esc(s);
  }
  function methodLabel(v) {
    const m = String(v || '').trim().toUpperCase();
    if (!m) return '—';
    if (m === 'GPS') return 'GPS';
    if (m === 'MANUAL') return 'يدوي';
    return esc(v);
  }
  function weekdayAr(iso) {
    const s = String(iso || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return '';
    const d = new Date(s + 'T12:00:00');
    if (Number.isNaN(d.getTime())) return '';
    return ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][d.getDay()] || '';
  }
  function dateWithWeekday(iso) {
    const dmy = ui.isoToDmy(iso);
    const day = weekdayAr(iso);
    return day ? `${day}\u00A0${dmy}` : dmy;
  }
  function durationLabel(a, b) {
    if (!a || !b) return '—';
    const t1 = Date.parse(String(a).replace(' ', 'T'));
    const t2 = Date.parse(String(b).replace(' ', 'T'));
    if (!Number.isFinite(t1) || !Number.isFinite(t2) || t2 < t1) return '—';
    const mins = Math.floor((t2 - t1) / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}:${String(m).padStart(2, '0')}`;
  }
  function statusLbl(r) {
    if (r.pending_request_id && !r.visit_checkout_at) return ui.statusPill('wait', 'بانتظار موافقة');
    if (r.visit_checkout_at) return ui.statusPill('ok', 'مكتملة');
    return ui.statusPill('info', 'داخل الزيارة');
  }

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${esc(
          r.name_ar || ''
        )}</option>`
    )
    .join('');

  const custOpts = customers
    .map(
      (c) =>
        `<option value="${c.id}" ${customerId === Number(c.id) ? 'selected' : ''}>${esc(
          c.name_ar || ''
        )}</option>`
    )
    .join('');

  const uniqueRepIds = [...new Set(rows.map((r) => Number(r.sales_rep_id || 0)).filter((id) => id > 0))];
  const groupByRep = salesRepId < 1 && uniqueRepIds.length > 1;
  const canDeleteVisits = can(req.session.user, 'action_delete_sales_rep_visit');
  const baseColCount = groupByRep ? 11 : 12;
  const colCount = baseColCount + (canDeleteVisits ? 1 : 0);
  const grandTotals = visitTotals(rows);

  function scopeLbl(r) {
    return Number(r.in_plan ?? 1) === 1
      ? ui.statusPill('ok', 'داخل الجولة')
      : ui.statusPill('wait', 'خارج الجولة');
  }

  function visitSelectCell(r) {
    if (!canDeleteVisits) return '';
    const lineId = Number(r.line_id || 0);
    const hasOrder = Number(r.order_count || 0) > 0;
    const disabled = hasOrder ? ' disabled title="مرتبطة بطلب شراء"' : '';
    return `<td class="no-print si-col-select"><input type="checkbox" class="hx-visit-pick" value="${lineId}"${disabled}></td>`;
  }

  function visitDataCells(r, seq, includeRep) {
    const reason = esc(r.no_order_reasons || '—');
    return `<td class="si-num" dir="ltr">${seq}</td>
      <td class="si-col-date">${esc(dateWithWeekday(r.route_date))}</td>
      ${includeRep ? `<td class="si-col-rep">${esc(r.sales_rep_name || '—')}</td>` : ''}
      <td class="si-col-customer">${customerNameOnly(r)}</td>
      <td class="si-col-scope">${scopeLbl(r)}</td>
      <td class="si-col-reason" title="${reason}">${reason}</td>
      <td class="si-col-location">${locationInline(r)}</td>
      ${visitTimingCells(r, durationLabel, methodLabel)}
      <td class="si-col-sales" dir="ltr">${visitMoney(r.order_total)}</td>`;
  }

  function totalsRow(label, t, cols) {
    const labelSpan = Math.max(1, cols - 3);
    return `<tr class="si-visits-totals-row">
      <td colspan="${labelSpan}" style="text-align:start"><strong>${esc(label)}</strong></td>
      <td class="si-col-duration"><strong>${esc(t.duration_label)}</strong></td>
      <td class="si-col-method"></td>
      <td class="si-col-sales" dir="ltr"><strong>${visitMoney(t.sales_total)}</strong></td>
    </tr>`;
  }

  let rowsHtml = '';
  if (!rows.length) {
    rowsHtml = ui.emptyRow(colCount, 'لا تسجيلات زيارة في الفترة المحددة');
  } else if (groupByRep) {
    const groups = new Map();
    for (const r of rows) {
      const id = Number(r.sales_rep_id || 0);
      const key = id > 0 ? String(id) : '0';
      if (!groups.has(key)) {
        groups.set(key, {
          name: r.sales_rep_name || 'مندوب',
          rows: [],
        });
      }
      groups.get(key).rows.push(r);
    }
    let seq = 0;
    for (const g of groups.values()) {
      rowsHtml += `<tr class="si-rep-group-row"><td colspan="${colCount}"><strong>المندوب: ${esc(
        g.name
      )}</strong> <span class="muted">(${g.rows.length} زيارة)</span></td></tr>`;
      for (const r of g.rows) {
        seq += 1;
        rowsHtml += `<tr class="${visitRowClass(r)}">${visitSelectCell(r)}${visitDataCells(r, seq, false)}</tr>`;
      }
      rowsHtml += totalsRow('مجموع المندوب', visitTotals(g.rows), colCount);
    }
    rowsHtml += totalsRow('الإجمالي النهائي', grandTotals, colCount);
  } else {
    rowsHtml = rows
      .map((r, i) => `<tr class="${visitRowClass(r)}">${visitSelectCell(r)}${visitDataCells(r, i + 1, true)}</tr>`)
      .join('');
    rowsHtml += totalsRow('الإجمالي', grandTotals, colCount);
  }

  const visitHeaders = (groupByRep
    ? [
        '#',
        'التاريخ',
        'العميل',
        'النطاق',
        'سبب عدم الطلب',
        'الموقع',
        'وقت الدخول',
        'وقت الخروج',
        'مجموع الساعات',
        'نوع الدخول',
        'المبيعات',
      ]
    : [
        '#',
        'التاريخ',
        'المندوب',
        'العميل',
        'النطاق',
        'سبب عدم الطلب',
        'الموقع',
        'وقت الدخول',
        'وقت الخروج',
        'مجموع الساعات',
        'نوع الدخول',
        'المبيعات',
      ]).map((h) => esc(h));
  if (canDeleteVisits) visitHeaders.unshift('');

  const deleteToolbar = canDeleteVisits
    ? `<div class="no-print" style="display:flex;gap:.5rem;align-items:center;margin:.65rem 0 0;flex-wrap:wrap">
        <button type="button" class="si-btn si-btn--danger" id="hx-visits-delete-btn" disabled>حذف المحدد</button>
        <span class="muted" style="font-size:.82rem">يمكن حذف الزيارات غير المربوطة بطلب شراء فقط</span>
      </div>`
    : '';

  const livePoll = from <= todayIso() && to >= todayIso();

  const body = `
    <div class="si-stage si-report-page si-report-visits" data-hx-print-landscape="1">
      ${REP_REPORT_STYLE}
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'تقرير زيارات العملاء',
        subtitle: 'تفاصيل دخول/خروج المندوب: وقت الدخول · وقت الخروج · المدة · نوع الدخول/الخروج',
        actions: [
          ui.printAction(),
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'اعتماد خروج يدوي', href: '/sales-reps/visit-checkout-approve' },
          { label: 'لوحة المندوبين', href: HUB, primary: true },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/visits" class="si-meta hx-visits-filters">
          <label class="hx-visits-f--date">من تاريخ
            <span class="hx-date-field">
              <input class="si-field si-field--mono" type="date" name="from" id="si-visits-from" value="${esc(from)}" dir="ltr">
              <span class="muted hx-date-weekday" id="si-weekday-from">${esc(weekdayAr(from))}</span>
            </span>
          </label>
          <label class="hx-visits-f--date">إلى تاريخ
            <span class="hx-date-field">
              <input class="si-field si-field--mono" type="date" name="to" id="si-visits-to" value="${esc(to)}" dir="ltr">
              <span class="muted hx-date-weekday" id="si-weekday-to">${esc(weekdayAr(to))}</span>
            </span>
          </label>
          <label>المندوب
            <select class="si-field" name="sales_rep_id">
              <option value="0">— الكل —</option>
              ${repOpts}
            </select>
          </label>
          <label class="hx-visits-f--customer">العميل
            <select class="si-field" name="customer_id">
              <option value="0">— الكل —</option>
              ${custOpts}
            </select>
          </label>
          <label>النوع
            <select class="si-field" name="method">
              <option value="" ${method === '' ? 'selected' : ''}>— الكل —</option>
              <option value="GPS" ${method === 'GPS' ? 'selected' : ''}>GPS</option>
              <option value="MANUAL" ${method === 'MANUAL' ? 'selected' : ''}>يدوي</option>
            </select>
          </label>
          <label>الحالة
            <select class="si-field" name="status">
              <option value="" ${status === '' ? 'selected' : ''}>— الكل —</option>
              <option value="open" ${status === 'open' ? 'selected' : ''}>داخل الزيارة</option>
              <option value="closed" ${status === 'closed' ? 'selected' : ''}>مكتملة</option>
              <option value="pending" ${status === 'pending' ? 'selected' : ''}>بانتظار موافقة</option>
            </select>
          </label>
          <div class="hx-visits-f-actions">
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
            ${ui.siPrintBtnHtml('طباعة')}
          </div>
        </form>
        <script>
        (function(){
          var days = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
          function weekday(iso){
            if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(iso||'')) return '';
            var d = new Date(iso + 'T12:00:00');
            return Number.isNaN(d.getTime()) ? '' : (days[d.getDay()]||'');
          }
          function bind(id, outId){
            var el = document.getElementById(id);
            var out = document.getElementById(outId);
            if (!el || !out) return;
            var sync = function(){ out.textContent = weekday(el.value); };
            el.addEventListener('change', sync);
            el.addEventListener('input', sync);
          }
          bind('si-visits-from','si-weekday-from');
          bind('si-visits-to','si-weekday-to');
        })();
        </script>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${rows.length} زيارة مسجّلة من تطبيق الهاتف في الفترة المحددة${
            groupByRep ? ' · مجمّعة حسب المندوب' : ''
          }.
        </p>
        ${deleteToolbar}
      </section>
      <div class="si-print-area si-report-page si-report-visits" data-hx-print-landscape="1">
      ${ui.tableSurface(
        'تفاصيل الزيارات',
        `عدد الزيارات: ${rows.length}`,
        visitHeaders,
        rowsHtml
      )}
      </div>
      ${
        canDeleteVisits
          ? `<script>
(function(){
  function picked(){
    return Array.prototype.slice.call(document.querySelectorAll('.hx-visit-pick:checked:not(:disabled)'))
      .map(function(el){ return Number(el.value)||0; })
      .filter(function(id){ return id > 0; });
  }
  function syncBtn(){
    var btn = document.getElementById('hx-visits-delete-btn');
    if (!btn) return;
    btn.disabled = picked().length < 1;
  }
  document.addEventListener('change', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('hx-visit-pick')) syncBtn();
  });
  var btn = document.getElementById('hx-visits-delete-btn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var ids = picked();
    if (!ids.length) return;
    var msg = 'حذف ' + ids.length + ' زيارة من التقرير؟';
    var go = function(){
      fetch('/sales-reps/reports/visits/delete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ line_ids: ids })
      })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (window.HypexUI && window.HypexUI.toast) {
            window.HypexUI.toast((d && d.message) || 'تم', d && d.ok ? 'ok' : 'error');
          }
          if (d && d.ok) window.location.reload();
        })
        .catch(function(){
          if (window.HypexUI && window.HypexUI.toast) window.HypexUI.toast('تعذر الحذف', 'error');
        });
    };
    if (window.HypexUI && window.HypexUI.confirm) {
      window.HypexUI.confirm(msg, { title: 'حذف الزيارات', okLabel: 'حذف', cancelLabel: 'إلغاء', danger: true })
        .then(function(ok){ if (ok) go(); });
    } else if (window.confirm(msg)) go();
  });
})();
</script>`
          : ''
      }
      ${
        livePoll
          ? `<script>
(function(){
  var q = new URLSearchParams(window.location.search);
  function poll(){
    fetch('/sales-reps/reports/visits/data?' + q.toString(), { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        var el = document.getElementById('hx-visits-live-count');
        if (el) el.textContent = (d.rows || []).length + ' زيارة';
      })
      .catch(function(){});
  }
  setInterval(poll, 30000);
})();
</script>
<p id="hx-visits-live-count" class="muted no-print" style="margin:.5rem 0;font-size:.82rem">تحديث تلقائي كل 30 ثانية — ${rows.length} زيارة</p>`
          : ''
      }
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير زيارات العملاء',
      bodyHtml: body,
      css: REP_REPORT_CSS,
      printTitle: 'تقرير زيارات العملاء',
    })
  );
});

/* ═══════════ Dynamic :id last ═══════════ */
router.get('/sales-reps/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return repForm(req, res, id);
});
router.post('/sales-reps/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.saveRep({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/sales-reps/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || 'تم'));
});
router.post('/sales-reps/:id/toggle', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.toggleRep(id);
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || result.error || ''));
});

module.exports = router;
