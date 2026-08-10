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
    (can(u, 'report_sales_by_rep') || can(u, 'report_sales_by_region'));
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
          { label: 'خط السير', href: '/sales-reps/route' },
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
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const edit = editId ? await masters.getTour(editId) : null;
  const reps = await q.listRepsSimple();
  const regions = await masters.listRegionsSimple();
  const tours = await masters.listTours({ salesRepId: filterRep });
  const selectedRep = edit ? Number(edit.sales_rep_id) : filterRep;
  const isPosted = edit && String(edit.status) === 'posted';
  const dateFrom = edit ? String(edit.date_from).slice(0, 10) : todayIso();
  const dateTo = edit ? String(edit.date_to).slice(0, 10) : todayIso();
  const editLines = edit?.lines || [];

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

  const selectedJson = JSON.stringify(
    editLines.map((l) => ({
      customer_id: Number(l.customer_id),
      code: l.customer_code || '',
      name: l.customer_name || '',
      region_id: Number(l.region_id || 0) || null,
      region_address_id: Number(l.region_address_id || 0) || null,
      region_name: l.region_name || '',
      address_name: l.address_name || '',
      date_from: String(l.date_from || dateFrom).slice(0, 10),
      date_to: String(l.date_to || dateTo).slice(0, 10),
    }))
  ).replace(/</g, '\\u003c');

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
            ? `<form method="post" action="/sales-reps/route/${r.id}/unpost" style="display:inline">
                <button type="submit" class="si-btn">فك ترحيل</button>
              </form>`
            : `<form method="post" action="/sales-reps/route/${r.id}/post" style="display:inline">
                <button type="submit" class="si-btn si-btn--primary">ترحيل</button>
              </form>
              <form method="post" action="/sales-reps/route/${r.id}/delete" style="display:inline" onsubmit="return confirm('حذف الجولة؟');">
                <button type="submit" class="si-btn si-btn--danger-text">حذف</button>
              </form>`
        }
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(7, 'لا جولات بعد');

  const body = `
    <div class="si-stage srr-page srr-tour-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'جولات المندوبين',
        subtitle: 'اختر المندوب ثم المنطقة والعنوان والعملاء، وحدد خطة بين تاريخين — احفظ ثم رحّل لظهورها في تطبيق المندوب',
        actions: [
          { label: 'المندوبين', href: '/sales-reps/list' },
          { label: 'لوحة المندوبين', href: HUB },
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
              <label class="srr-field">
                <span>العنوان</span>
                <select class="si-field" id="srr-address" ${isPosted ? 'disabled' : ''}>
                  <option value="0">— اختر المنطقة أولاً —</option>
                </select>
              </label>
              <div class="srr-form__row srr-form__row--inline">
                <label class="srr-field">
                  <span>من تاريخ <em>*</em></span>
                  <input class="si-field si-field--mono" type="date" name="date_from" id="srr-from" required value="${esc(
                    dateFrom
                  )}" ${isPosted ? 'readonly' : ''}>
                </label>
                <label class="srr-field">
                  <span>إلى تاريخ <em>*</em></span>
                  <input class="si-field si-field--mono" type="date" name="date_to" id="srr-to" required value="${esc(
                    dateTo
                  )}" ${isPosted ? 'readonly' : ''}>
                </label>
              </div>
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
                <span class="muted" id="srr-selected-count">0 عميل</span>
              </div>
              <div class="srr-selected-table-wrap">
                <table class="si-table srr-selected-table" id="srr-selected-table">
                  <thead>
                    <tr>
                      <th>العميل</th>
                      <th>المنطقة</th>
                      <th>العنوان</th>
                      <th>من</th>
                      <th>إلى</th>
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
                  ? `<button class="si-btn" type="submit" formaction="/sales-reps/route/${edit.id}/unpost" formmethod="post">فك ترحيل</button>
                    <a class="si-btn si-btn--primary" href="/sales-reps/route/${edit.id}/print" target="_blank">طباعة الجولة</a>`
                  : `<button class="si-btn si-btn--primary" type="submit" data-hx-save="1" title="F10">حفظ</button>
                    <button class="si-btn" type="submit" name="and_post" value="1">حفظ وترحيل</button>
                    ${
                      edit
                        ? `<button class="si-btn" type="submit" formaction="/sales-reps/route/${edit.id}/post" formmethod="post">ترحيل</button>`
                        : ''
                    }`
              }
              <a class="si-btn" href="/sales-reps/route">جديد</a>
            </div>
          </div>

          <div class="srr-form__cust">
            <div class="srr-cust__toolbar">
              <div class="srr-cust__title">
                <strong>اختيار العملاء</strong>
                <span class="muted" id="srr-cust-total">0</span>
              </div>
              <input type="search" class="si-field srr-cust__search" id="srr-cust-q"
                     placeholder="بحث بالاسم أو الرمز…" autocomplete="off" ${isPosted ? 'disabled' : ''}>
              <div class="srr-cust__tools">
                <button type="button" class="si-btn srr-btn-sm" id="srr-add-visible" ${
                  isPosted ? 'disabled' : ''
                }>إضافة الظاهر</button>
              </div>
            </div>
            <div class="srr-cust__list" id="srr-cust-list">
              <p class="srr-cust__empty">اختر المندوب ثم المنطقة/العنوان لعرض العملاء</p>
            </div>
          </div>
        </form>
      </section>

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
    </div>
    <script>
    (function(){
      var posted = ${isPosted ? 'true' : 'false'};
      var selected = ${selectedJson};
      var repEl = document.getElementById('srr-rep');
      var regionEl = document.getElementById('srr-region');
      var addressEl = document.getElementById('srr-address');
      var fromEl = document.getElementById('srr-from');
      var toEl = document.getElementById('srr-to');
      var list = document.getElementById('srr-cust-list');
      var qEl = document.getElementById('srr-cust-q');
      var totalEl = document.getElementById('srr-cust-total');
      var bodySel = document.getElementById('srr-selected-body');
      var countEl = document.getElementById('srr-selected-count');
      var linesInput = document.getElementById('srr-lines-json');
      var form = document.getElementById('srr-form');
      var addVisibleBtn = document.getElementById('srr-add-visible');
      var custCache = [];
      var loadTimer = null;

      function escHtml(s){
        return String(s==null?'':s)
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }
      function defaultFrom(){ return fromEl && fromEl.value ? fromEl.value : ''; }
      function defaultTo(){ return toEl && toEl.value ? toEl.value : ''; }

      function syncHidden(){
        if(linesInput) linesInput.value = JSON.stringify(selected.map(function(r){
          return {
            customer_id: r.customer_id,
            date_from: r.date_from || defaultFrom(),
            date_to: r.date_to || defaultTo(),
            region_id: r.region_id || null,
            region_address_id: r.region_address_id || null
          };
        }));
        if(countEl) countEl.textContent = selected.length + ' عميل';
      }

      function renderSelected(){
        if(!bodySel) return;
        if(!selected.length){
          bodySel.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center;padding:.8rem">لم يُختر عملاء بعد</td></tr>';
          syncHidden();
          return;
        }
        bodySel.innerHTML = selected.map(function(r, idx){
          return '<tr data-idx="'+idx+'">'
            + '<td><strong>'+escHtml(r.name)+'</strong> <span class="muted" dir="ltr">'+escHtml(r.code)+'</span></td>'
            + '<td>'+escHtml(r.region_name||'—')+'</td>'
            + '<td>'+escHtml(r.address_name||'—')+'</td>'
            + '<td><input type="date" class="si-field srr-line-from" data-idx="'+idx+'" value="'+escHtml(r.date_from||defaultFrom())+'" '+(posted?'readonly':'')+'></td>'
            + '<td><input type="date" class="si-field srr-line-to" data-idx="'+idx+'" value="'+escHtml(r.date_to||defaultTo())+'" '+(posted?'readonly':'')+'></td>'
            + '<td>'+(posted?'':('<button type="button" class="si-btn srr-btn-sm srr-remove" data-idx="'+idx+'">حذف</button>'))+'</td>'
            + '</tr>';
        }).join('');
        syncHidden();
      }

      function selectedIds(){
        var m = {};
        selected.forEach(function(r){ m[r.customer_id]=1; });
        return m;
      }

      function addCustomer(c){
        if(posted) return;
        var id = Number(c.id);
        if(!id || selectedIds()[id]) return;
        selected.push({
          customer_id: id,
          code: c.code || '',
          name: c.name_ar || c.name || '',
          region_id: c.region_id || null,
          region_address_id: c.region_address_id || null,
          region_name: c.region_name || '',
          address_name: c.address_name || '',
          date_from: defaultFrom(),
          date_to: defaultTo()
        });
        renderSelected();
        renderCustList();
      }

      function renderCustList(){
        if(!list) return;
        var ids = selectedIds();
        if(!custCache.length){
          list.innerHTML = '<p class="srr-cust__empty">لا عملاء مطابقون للفلتر</p>';
          if(totalEl) totalEl.textContent = '0';
          return;
        }
        list.innerHTML = custCache.map(function(c){
          var on = !!ids[Number(c.id)];
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
          list.innerHTML = '<p class="srr-cust__empty">اختر المندوب أولاً</p>';
          if(totalEl) totalEl.textContent = '0';
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

      if(list){
        list.addEventListener('change', function(e){
          var t = e.target;
          if(!t || t.type !== 'checkbox') return;
          var id = Number(t.getAttribute('data-id')||0);
          if(!id) return;
          if(t.checked){
            var c = custCache.find(function(x){ return Number(x.id)===id; });
            if(c) addCustomer(c);
          } else {
            selected = selected.filter(function(r){ return Number(r.customer_id)!==id; });
            renderSelected();
            renderCustList();
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
        bodySel.addEventListener('change', function(e){
          var t = e.target;
          if(!t) return;
          var idx = Number(t.getAttribute('data-idx')|| -1);
          if(idx<0 || !selected[idx]) return;
          if(t.classList.contains('srr-line-from')) selected[idx].date_from = t.value;
          if(t.classList.contains('srr-line-to')) selected[idx].date_to = t.value;
          syncHidden();
        });
      }

      if(repEl) repEl.addEventListener('change', scheduleLoad);
      if(regionEl) regionEl.addEventListener('change', function(){
        loadAddresses().then(scheduleLoad);
      });
      if(addressEl) addressEl.addEventListener('change', scheduleLoad);
      if(qEl) qEl.addEventListener('input', scheduleLoad);

      if(fromEl) fromEl.addEventListener('change', function(){
        selected.forEach(function(r){ if(!r.date_from) r.date_from = defaultFrom(); });
        renderSelected();
      });
      if(toEl) toEl.addEventListener('change', function(){
        selected.forEach(function(r){ if(!r.date_to) r.date_to = defaultTo(); });
        renderSelected();
      });

      if(addVisibleBtn) addVisibleBtn.addEventListener('click', function(){
        custCache.forEach(addCustomer);
      });

      if(form) form.addEventListener('submit', function(){
        syncHidden();
      });

      renderSelected();
      if(Number(repEl && repEl.value || 0) > 0) scheduleLoad();
    })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'جولات المندوبين',
      bodyHtml: body,
      css: ['/assets/css/sales-rep-route.css'],
      js: ['/assets/js/sales-print.js'],
    })
  );
});

router.post('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  const body = req.body || {};
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
    return res.redirect(
      '/sales-reps/route?err=' +
        encodeURIComponent(result.error) +
        (body.sales_rep_id ? '&sales_rep_id=' + body.sales_rep_id : '')
    );
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
