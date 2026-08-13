'use strict';

const express = require('express');
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
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'مندوب جديد' : esc(row.name_ar || '')}</h2></div>
        <form method="post" action="${isNew ? '/sales-reps/new' : '/sales-reps/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <label>الرمز <span style="font-weight:500;color:#5c6578">(فارغ = تلقائي)</span>
            <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="REP-0001" autocomplete="off">
          </label>
          <label>اسم المندوب *
            <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" autocomplete="off">
          </label>
          <label>الهاتف
            <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr">
          </label>
          <label>مستودع العهدة
            <select class="si-field" name="warehouse_id">
              <option value="0">— بدون —</option>
              ${whOpts}
            </select>
          </label>
          <label class="si-span-2">العنوان
            <textarea class="si-field" name="address_ar" rows="2" style="min-height:3.5rem">${esc(
              row?.address_ar || ''
            )}</textarea>
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.35rem">
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
        <a class="si-btn" href="/sales-reps/route/${r.id}/print" target="_blank">طباعة</a>
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
  const dateFrom = edit ? String(edit.date_from).slice(0, 10) : month.from;
  const dateTo = edit ? String(edit.date_to).slice(0, 10) : month.to;
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
        `<button type="button" class="srr-day-chip" data-weekday="${wd}" ${isPosted ? 'disabled' : ''}>${esc(
          dayLabels[wd]
        )}</button>`
    )
    .join('');

  const selectedJson = JSON.stringify(
    editLines.map((l) => ({
      customer_id: Number(l.customer_id),
      code: l.customer_code || '',
      name: l.customer_name || '',
      weekday: l.weekday == null || l.weekday === '' ? 0 : Number(l.weekday),
      region_id: Number(l.region_id || 0) || null,
      region_address_id: Number(l.region_address_id || 0) || null,
      region_name: l.region_name || '',
      address_name: l.address_name || '',
    }))
  ).replace(/</g, '\\u003c');

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
          { label: 'قائمة الجولات', href: '/sales-reps/route', primary: true },
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
        <form method="post" action="/sales-reps/route" class="srr-form srr-tour-form" id="srr-form" data-hx-save="1">
          <input type="hidden" name="id" value="${edit ? edit.id : 0}">
          <input type="hidden" name="lines_json" id="srr-lines-json" value="">

          <div class="srr-form__fields">
            <div class="srr-form__row">
              <label class="srr-field">
                <span>المندوب <em>*</em></span>
                <select class="si-field" name="sales_rep_id" id="srr-rep" required ${isPosted ? 'disabled' : ''}>
                  <option value="">— اختر المندوب —</option>
                  ${repOpts}
                </select>
                ${isPosted ? `<input type="hidden" name="sales_rep_id" value="${selectedRep}">` : ''}
              </label>
              <label class="srr-field">
                <span>المنطقة</span>
                <select class="si-field" id="srr-region" ${isPosted ? 'disabled' : ''}>
                  <option value="0">— كل المناطق —</option>
                  ${regionOpts}
                </select>
              </label>
            </div>
            <div class="srr-form__row">
              <label class="srr-field srr-field--full">
                <span>العنوان</span>
                <select class="si-field" id="srr-address" ${isPosted ? 'disabled' : ''}>
                  <option value="0">— كل العناوين —</option>
                </select>
              </label>
            </div>
            <div class="srr-form__row srr-form__row--dates">
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

            <div class="srr-weekdays" id="srr-weekdays">
              <div class="srr-weekdays__label">أيام الأسبوع <em>*</em>
                <span class="muted" style="font-weight:600;font-size:.75rem">اختر يوماً ثم أضف عملاءه</span>
              </div>
              <div class="srr-weekdays__chips" id="srr-day-chips">${weekdayChips}</div>
              <p class="srr-weekdays__hint muted" id="srr-day-hint">حدد يوماً من أيام الأسبوع لبدء إضافة العملاء.</p>
            </div>

            <label class="srr-field srr-field--full">
              <span>ملاحظات</span>
              <textarea class="si-field srr-notes" name="notes" rows="2" placeholder="اختياري…" ${
                isPosted ? 'readonly' : ''
              }>${esc(edit?.notes || '')}</textarea>
            </label>

            <div class="srr-selected-panel">
              <div class="srr-selected-panel__head">
                <strong>عملاء الخطة</strong>
                <span class="muted" id="srr-selected-count">0 تعيين</span>
              </div>
              <p class="muted" style="margin:0;padding:.35rem .75rem 0;font-size:.78rem;font-weight:600">
                كل عميل مرتبط بيوم أسبوع ضمن فترة الجولة (من/إلى) — عند الترحيل يظهر في خط السير في ذلك اليوم فقط.
              </p>
              <div class="srr-selected-table-wrap">
                <table class="si-table srr-selected-table" id="srr-selected-table">
                  <thead>
                    <tr>
                      <th>اليوم</th>
                      <th>العميل</th>
                      <th>المنطقة</th>
                      <th>العنوان</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="srr-selected-body"></tbody>
                </table>
              </div>
            </div>

            <div class="srr-form__actions">
              ${
                isPosted
                  ? `<button class="si-btn" type="submit" name="tour_action" value="unpost">فك ترحيل</button>
                    <a class="si-btn si-btn--primary" href="/sales-reps/route/${edit.id}/print" target="_blank">طباعة الجولة</a>`
                  : `<button class="si-btn si-btn--primary" type="submit" data-hx-save="1" title="F10">حفظ</button>
                    <button class="si-btn" type="submit" name="and_post" value="1">حفظ وترحيل</button>
                    ${
                      edit
                        ? `<button class="si-btn" type="submit" name="tour_action" value="post">ترحيل</button>`
                        : ''
                    }`
              }
              <a class="si-btn" href="/sales-reps/route">رجوع للقائمة</a>
              <a class="si-btn" href="/sales-reps/route?new=1">جديد</a>
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
      var bodySel = document.getElementById('srr-selected-body');
      var countEl = document.getElementById('srr-selected-count');
      var linesInput = document.getElementById('srr-lines-json');
      var form = document.getElementById('srr-form');
      var addVisibleBtn = document.getElementById('srr-add-visible');
      var chips = document.getElementById('srr-day-chips');
      var custCache = [];
      var loadTimer = null;

      function escHtml(s){
        return String(s==null?'':s)
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
        renderCustList();
      }

      function renderSelected(){
        if(!bodySel) return;
        if(!selected.length){
          bodySel.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center;padding:.8rem">لم يُختر عملاء بعد</td></tr>';
          syncHidden();
          return;
        }
        var sorted = selected.slice().sort(function(a,b){
          if(Number(a.weekday)!==Number(b.weekday)) return Number(a.weekday)-Number(b.weekday);
          return String(a.name||'').localeCompare(String(b.name||''),'ar');
        });
        bodySel.innerHTML = sorted.map(function(r){
          var idx = selected.indexOf(r);
          return '<tr data-idx="'+idx+'">'
            + '<td><span class="srr-day-badge">'+escHtml(dayLabels[Number(r.weekday)]||'—')+'</span></td>'
            + '<td><strong>'+escHtml(r.name)+'</strong> <span class="muted" dir="ltr">'+escHtml(r.code)+'</span></td>'
            + '<td>'+escHtml(r.region_name||'—')+'</td>'
            + '<td>'+escHtml(r.address_name||'—')+'</td>'
            + '<td>'+(posted?'':('<button type="button" class="si-btn srr-btn-sm srr-remove" data-idx="'+idx+'">حذف</button>'))+'</td>'
            + '</tr>';
        }).join('');
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

      if(bodySel){
        bodySel.addEventListener('click', function(e){
          var btn = e.target.closest('.srr-remove');
          if(!btn) return;
          var idx = Number(btn.getAttribute('data-idx')|| -1);
          if(idx>=0){ selected.splice(idx,1); renderSelected(); renderCustList(); }
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

      if(form) form.addEventListener('submit', function(){
        syncHidden();
        if(!posted && !selected.length){
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
    const base =
      tourId > 0
        ? '/sales-reps/route?id=' + tourId
        : '/sales-reps/route?new=1' +
          (body.sales_rep_id ? '&sales_rep_id=' + body.sales_rep_id : '');
    return res.redirect(base + (base.includes('?') ? '&' : '?') + 'err=' + encodeURIComponent(result.error));
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
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.visit_date))}</td>
      <td>${ui.esc(r.customer_name || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.customer_code)}</td>
      <td>${dash(r.region_name)}</td>
      <td>${dash(r.address_name)}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا تفاصيل');

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
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'العودة للجولة', href: '/sales-reps/route?id=' + tour.id },
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
          ['#', 'اليوم', 'العميل', 'الرمز', 'المنطقة', 'العنوان'],
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
      <td>${esc(r.sales_rep_name || '—')}${
          r.sales_rep_code ? ` <span class="muted" dir="ltr">(${esc(r.sales_rep_code)})</span>` : ''
        }</td>
      <td class="si-num" dir="ltr">${esc(ui.isoToDmy(r.date_from))}</td>
      <td class="si-num" dir="ltr">${esc(ui.isoToDmy(r.date_to))}</td>
      <td>${statusLbl(r.status)}</td>
      <td>${esc(r.customer_name || '—')}</td>
      <td class="si-num" dir="ltr">${esc(r.customer_code || '')}</td>
      <td>${esc(r.region_name || '—')}</td>
      <td>${esc(r.address_name || '—')}</td>
      <td class="si-num muted" dir="ltr" title="يُسجَّل من الآيباد عند دخول العميل">${fmtTs(
        r.visit_checkin_at
      )}</td>
      <td class="si-num muted" dir="ltr" title="يُسجَّل من الآيباد عند الخروج">${fmtTs(
        r.visit_checkout_at
      )}</td>
      <td class="muted" title="GPS إذا كان المندوب ضمن حدود منطقة العميل">${methodLabel(
        r.checkin_method
      )}</td>
      <td class="muted" title="GPS إذا كان المندوب ضمن حدود منطقة العميل عند الخروج">${methodLabel(
        r.checkout_method
      )}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(14, 'لا جولات في الفترة المحددة');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'تقرير الجولات',
        subtitle: 'الجولات المُنشأة: بداية/نهاية · المندوب · المناطق والعناوين — وأوقات الزيارة عند الربط مع الآيباد',
        actions: [
          { label: '🖨 طباعة', print: true },
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
          <button type="button" class="si-btn si-btn--print no-print" data-print="1">🖨 طباعة</button>
        </form>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${tourIds.size} جولة · ${rows.length} عميل في الخطط
          ${regionSet.size ? ` · ${regionSet.size} منطقة` : ''}
          ${addressSet.size ? ` · ${addressSet.size} عنوان` : ''}.
          أعمدة <b>وقت الدخول / الخروج</b> و<b>طريقة الدخول / الخروج</b> تظهر
          <b>GPS</b> لاحقاً عندما يسجّل المندوب الدخول/الخروج من الآيباد وهو ضمن حدود العميل.
        </p>
      </section>
      <div class="si-print-area">
      ${ui.tableSurface(
        'تفاصيل الجولات والعملاء',
        `${rows.length} صف`,
        [
          '#',
          'رقم الجولة',
          'المندوب',
          'تاريخ البداية',
          'تاريخ النهاية',
          'الحالة',
          'العميل',
          'الرمز',
          'المنطقة',
          'العنوان',
          'وقت الدخول',
          'وقت الخروج',
          'طريقة الدخول',
          'طريقة الخروج',
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
      printTitle: 'تقرير الجولات',
    })
  );
});

/* ── تقرير زيارات العملاء ── */
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
  const from =
    String(req.query.from || '').slice(0, 10) ||
    (() => {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
    })();
  const to = String(req.query.to || '').slice(0, 10) || todayIso();
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const method = String(req.query.method || '').toUpperCase();
  const status = String(req.query.status || '');
  const reps = await q.listRepsSimple();
  const rows = await masters.reportVisits({ from, to, salesRepId, method, status });

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
  function distLabel(v) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    if (!Number.isFinite(n)) return '—';
    return `${Math.round(n)} م`;
  }
  function durationLabel(a, b) {
    if (!a || !b) return '—';
    const t1 = Date.parse(String(a).replace(' ', 'T'));
    const t2 = Date.parse(String(b).replace(' ', 'T'));
    if (!Number.isFinite(t1) || !Number.isFinite(t2) || t2 < t1) return '—';
    const mins = Math.floor((t2 - t1) / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}س ${m}د` : `${m} د`;
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
        )}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  const rowsHtml =
    rows
      .map(
        (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr">${esc(ui.isoToDmy(r.route_date))}</td>
      <td>${esc(r.sales_rep_name || '—')}${
          r.sales_rep_code ? ` <span class="muted" dir="ltr">(${esc(r.sales_rep_code)})</span>` : ''
        }</td>
      <td>${esc(r.customer_name || '—')}</td>
      <td class="si-num" dir="ltr">${esc(r.customer_code || '')}</td>
      <td>${esc(r.region_name || '—')}</td>
      <td>${esc(r.address_name || '—')}</td>
      <td class="si-num" dir="ltr">${fmtTs(r.visit_checkin_at)}</td>
      <td>${methodLabel(r.checkin_method)}</td>
      <td class="si-num" dir="ltr">${distLabel(r.checkin_distance_m)}</td>
      <td class="si-num" dir="ltr">${fmtTs(r.visit_checkout_at)}</td>
      <td>${methodLabel(r.checkout_method)}</td>
      <td class="si-num" dir="ltr">${distLabel(r.checkout_distance_m)}</td>
      <td class="si-num" dir="ltr">${durationLabel(r.visit_checkin_at, r.visit_checkout_at)}</td>
      <td>${statusLbl(r)}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(15, 'لا تسجيلات زيارة في الفترة المحددة');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'تقرير زيارات العملاء',
        subtitle: 'تفاصيل دخول/خروج المندوب عند العميل: الوقت · GPS أو يدوي · المسافة · مدة الزيارة',
        actions: [
          { label: '🖨 طباعة', print: true },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'اعتماد خروج يدوي', href: '/sales-reps/visit-checkout-approve' },
          { label: 'لوحة المندوبين', href: HUB, primary: true },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/visits" class="si-meta" style="align-items:end">
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
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          <button type="button" class="si-btn si-btn--print no-print" data-print="1">🖨 طباعة</button>
        </form>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${rows.length} زيارة مسجّلة من تطبيق الهاتف في الفترة المحددة.
        </p>
      </section>
      <div class="si-print-area">
      ${ui.tableSurface(
        'تفاصيل الزيارات',
        `${rows.length} صف`,
        [
          '#',
          'التاريخ',
          'المندوب',
          'العميل',
          'الرمز',
          'المنطقة',
          'العنوان',
          'وقت الدخول',
          'نوع الدخول',
          'مسافة الدخول',
          'وقت الخروج',
          'نوع الخروج',
          'مسافة الخروج',
          'المدة',
          'الحالة',
        ],
        rowsHtml
      )}
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير زيارات العملاء',
      bodyHtml: body,
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
