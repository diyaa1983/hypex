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
  if (!req.path.startsWith('/sales-reps')) return next('router');
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
        subtitle: 'دليل المندوبين وخط السير وتقارير المبيعات — كلها على Node.',
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

/* ═══════════ خط السير ═══════════ */
router.get('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  const filterRep = Number(req.query.sales_rep_id || 0) || 0;
  const editId = Number(req.query.id || 0) || 0;
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const edit = editId ? await masters.getRoute(editId) : null;
  const reps = await q.listRepsSimple();
  const customers = await masters.listActiveCustomers();
  const routes = await masters.listRoutes({ salesRepId: filterRep });
  const selectedRep = edit ? Number(edit.sales_rep_id) : filterRep;
  const selectedCust = new Set((edit?.lines || []).map((l) => Number(l.customer_id)));
  const routeDate = edit ? String(edit.route_date).slice(0, 10) : todayIso();

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${selectedRep === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}${
          r.code ? ' (' + esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');

  const custChecks = customers
    .map((c) => {
      const linked = !selectedRep || !c.sales_rep_id || Number(c.sales_rep_id) === selectedRep;
      const dim = linked ? '' : ' is-dim';
      const name = String(c.name_ar || '');
      const code = String(c.code || '');
      return `<label class="srr-cust__row${dim}" data-name="${esc(name.toLowerCase())}" data-code="${esc(
        code.toLowerCase()
      )}">
        <input type="checkbox" name="customer_ids" value="${c.id}" ${
          selectedCust.has(Number(c.id)) ? 'checked' : ''
        }>
        <span class="srr-cust__name">${esc(name)}</span>
        <span class="srr-cust__code" dir="ltr">${esc(code)}</span>
      </label>`;
    })
    .join('');

  const listHtml =
    routes
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.route_date))}</td>
      <td>${ui.esc(r.sales_rep_name || '')}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${dash(r.notes)}</td>
      <td class="srr-table-actions">
        <a class="si-btn" href="/sales-reps/route?id=${r.id}">تعديل</a>
        <form method="post" action="/sales-reps/route/${r.id}/delete" style="display:inline" onsubmit="return confirm('حذف خط السير؟');">
          <button type="submit" class="si-btn si-btn--danger-text">حذف</button>
        </form>
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(5, 'لا خطوط سير بعد');

  const body = `
    <div class="si-stage srr-page">
      ${ui.hero({
        title: 'خط سير المندوب',
        subtitle: 'عيّن عملاء الزيارة ليوم محدد لظهورهم في تطبيق المندوب',
        actions: [
          { label: 'المندوبين', href: '/sales-reps/list' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}

      <section class="si-surface srr-card">
        <div class="si-surface-head">
          <h2>${edit ? 'تعديل خط السير' : 'تعيين خط سير جديد'}</h2>
          <span class="si-count">${edit ? 'تعديل #' + edit.id : 'جديد'}</span>
        </div>
        <form method="post" action="/sales-reps/route" class="srr-form" id="srr-form" data-hx-save="1">
          <input type="hidden" name="id" value="${edit ? edit.id : 0}">

          <div class="srr-form__fields">
            <div class="srr-form__row">
              <label class="srr-field">
                <span>المندوب <em>*</em></span>
                <select class="si-field" name="sales_rep_id" id="srr-rep" required>
                  <option value="">— اختر المندوب —</option>
                  ${repOpts}
                </select>
              </label>
              <label class="srr-field">
                <span>تاريخ خط السير <em>*</em></span>
                <input class="si-field si-field--mono" type="date" name="route_date" required value="${esc(
                  routeDate
                )}">
              </label>
            </div>
            <label class="srr-field srr-field--full">
              <span>ملاحظات</span>
              <textarea class="si-field srr-notes" name="notes" rows="3" placeholder="اختياري…">${esc(
                edit?.notes || ''
              )}</textarea>
            </label>
            <div class="srr-form__actions">
              <button class="si-btn si-btn--primary" type="submit" data-hx-save="1" title="F10">حفظ وترحيل</button>
              <a class="si-btn" href="/sales-reps/route">جديد</a>
              <span class="srr-selected muted" id="srr-selected-count">0 محدد</span>
            </div>
          </div>

          <div class="srr-form__cust">
            <div class="srr-cust__toolbar">
              <div class="srr-cust__title">
                <strong>عملاء الزيارة</strong>
                <span class="muted" id="srr-cust-total">${customers.length}</span>
              </div>
              <input type="search" class="si-field srr-cust__search" id="srr-cust-q"
                     placeholder="بحث بالاسم أو الرمز…" autocomplete="off">
              <div class="srr-cust__tools">
                <label class="srr-check-all">
                  <input type="checkbox" id="srr-check-all">
                  <span>تحديد الظاهر</span>
                </label>
                <button type="button" class="si-btn srr-btn-sm" id="srr-clear">مسح التحديد</button>
              </div>
            </div>
            <div class="srr-cust__list" id="srr-cust-list">
              ${custChecks || '<p class="srr-cust__empty">لا عملاء نشطون</p>'}
            </div>
          </div>
        </form>
      </section>

      <section class="si-surface srr-list-card">
        <div class="si-surface-head">
          <h2>خطوط السير المحفوظة</h2>
          <span class="si-count">${routes.length} صف</span>
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
                <th>التاريخ</th>
                <th>المندوب</th>
                <th>عملاء</th>
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
      var list=document.getElementById('srr-cust-list');
      var q=document.getElementById('srr-cust-q');
      var countEl=document.getElementById('srr-selected-count');
      var totalEl=document.getElementById('srr-cust-total');
      var all=document.getElementById('srr-check-all');
      var clearBtn=document.getElementById('srr-clear');
      if(!list)return;
      function rows(){return Array.prototype.slice.call(list.querySelectorAll('.srr-cust__row'));}
      function visibleRows(){return rows().filter(function(r){return r.style.display!=='none';});}
      function updateCount(){
        var n=rows().filter(function(r){var c=r.querySelector('input');return c&&c.checked;}).length;
        if(countEl)countEl.textContent=n+' محدد';
        var vis=visibleRows();
        if(totalEl)totalEl.textContent=vis.length+(vis.length!==rows().length?' / '+rows().length:'');
        if(all){
          var visChecked=vis.filter(function(r){var c=r.querySelector('input');return c&&c.checked;});
          all.checked=vis.length>0&&visChecked.length===vis.length;
          all.indeterminate=visChecked.length>0&&visChecked.length<vis.length;
        }
      }
      function filter(){
        var term=String(q&&q.value||'').trim().toLowerCase();
        rows().forEach(function(r){
          if(!term){r.style.display='';return;}
          var name=r.getAttribute('data-name')||'';
          var code=r.getAttribute('data-code')||'';
          r.style.display=(name.indexOf(term)!==-1||code.indexOf(term)!==-1)?'':'none';
        });
        updateCount();
      }
      if(q)q.addEventListener('input',filter);
      list.addEventListener('change',function(e){if(e.target&&e.target.type==='checkbox')updateCount();});
      if(all)all.addEventListener('change',function(){
        var on=all.checked;
        visibleRows().forEach(function(r){var c=r.querySelector('input');if(c)c.checked=on;});
        updateCount();
      });
      if(clearBtn)clearBtn.addEventListener('click',function(){
        rows().forEach(function(r){var c=r.querySelector('input');if(c)c.checked=false;});
        updateCount();
      });
      updateCount();
    })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'خط سير المندوب',
      bodyHtml: body,
      css: ['/assets/css/sales-rep-route.css'],
    })
  );
});

router.post('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  const body = req.body || {};
  body.customer_ids = [].concat(body.customer_ids || []).filter(Boolean);
  const result = await masters.saveRoute(body, req.session.user?.id);
  if (!result.ok) {
    return res.redirect(
      '/sales-reps/route?err=' +
        encodeURIComponent(result.error) +
        (body.sales_rep_id ? '&sales_rep_id=' + body.sales_rep_id : '')
    );
  }
  res.redirect(
    '/sales-reps/route?msg=' +
      encodeURIComponent(result.message || 'تم') +
      (body.sales_rep_id ? '&sales_rep_id=' + body.sales_rep_id : '')
  );
});

router.post('/sales-reps/route/:id/delete', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.deleteRoute(req.params.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect('/sales-reps/route?' + key + '=' + encodeURIComponent(result.message || result.error || ''));
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
