'use strict';

const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const masters = require('./mastersService');
const ui = require('../lib/salesUi');
const { customersCatalog } = require('./catalog');
const { esc } = require('../lib/html');

const router = express.Router();
const HUB = '/customers';
const KICKER = 'Hypex Customers · Node';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function requireAnyCustomers(req, res, next) {
  const u = req.session.user;
  const any = customersCatalog.some((g) => g.items.some((it) => can(u, it.r)));
  if (!any && !u.is_admin) {
    return res.status(403).send(
      ui.salesPage({
        user: u,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({ kicker: KICKER, title: 'لا صلاحية', subtitle: 'ليس لديك شاشات عملاء' })}</div>`,
      })
    );
  }
  next();
}

function guard(code) {
  return (req, res, next) => {
    if (!can(req.session.user, code)) {
      return res.status(403).send(
        ui.salesPage({
          user: req.session.user,
          title: 'ممنوع',
          bodyHtml: `<div class="si-stage">${ui.hero({ kicker: KICKER, title: 'ممنوع', subtitle: 'لا صلاحية لهذه الشاشة' })}</div>`,
        })
      );
    }
    next();
  };
}

router.use((req, res, next) => {
  if (!req.path.startsWith('/customers')) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAnyCustomers(req, res, next);
  });
});

router.get('/customers', async (req, res) => {
  const user = req.session.user;
  const linked = await q.oracleLinkedCount();
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Cu',
        kicker: KICKER,
        title: 'العملاء',
        subtitle: 'قائمة العملاء والمناطق والتقارير — إضافة وتعديل أصلية على Node.',
        actions: [
          { label: 'قائمة العملاء', href: '/customers/list', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      <p style="margin:.5rem 0 0;color:#5c6578;font-size:.88rem">عملاء مربوطون بـ Oracle: <strong dir="ltr">${linked}</strong></p>
      ${ui.hubTiles(can, user, customersCatalog)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'العملاء', bodyHtml: body }));
});

function listPage(res, user, opts) {
  const {
    title,
    mark,
    subtitle,
    headers,
    rowsHtml,
    count,
    searchPath,
    qVal,
    extraActions = [],
    phpRoute,
    filtersHtml = '',
  } = opts;
  const actions = [...extraActions, { label: 'لوحة العملاء', href: HUB }];
  

  const body = `
    <div class="si-stage">
      ${ui.hero({ mark, kicker: KICKER, title, subtitle, actions })}
      ${filtersHtml || (searchPath ? ui.railSearch(searchPath, qVal) : '')}
      ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user, title, bodyHtml: body }));
}

function bridge(req, res, conf) {
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: conf.mark,
        kicker: KICKER,
        title: conf.title,
        subtitle: conf.subtitle,
        actions: [{ label: 'لوحة العملاء', href: HUB }],
      })}
      ${ui.bridgeCard(conf.cardTitle, conf.phpRoute, conf.desc, HUB, 'عودة لوحة العملاء')}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: conf.title, bodyHtml: body }));
}

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

/* ── Customers list ── */
router.get('/customers/list', guard('customers'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const regionId = Number(req.query.region_id || 0) || 0;
  const regions = await q.regionOptions();
  const rows = await q.listCustomers({ q: qv, activeOnly: !showAll, regionId });

  const regionOpts = regions
    .map(
      (r) =>
        `<option value="${r.id}" ${regionId === Number(r.id) ? 'selected' : ''}>${ui.esc(r.name_ar)}</option>`
    )
    .join('');

  const filtersHtml = `
    <div class="si-rail">
      <form class="si-search" method="get" action="/customers/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
        <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث بالرمز / الاسم / الهاتف…" autocomplete="off" style="flex:1;min-width:10rem">
        <select name="region_id" class="si-field" style="min-height:2.1rem;width:auto;min-width:9rem">
          <option value="0">كل المناطق</option>
          ${regionOpts}
        </select>
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفين
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
      </form>
    </div>`;

  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.code || '')}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td>${dash(r.region_name)}</td>
      <td>${dash(r.sales_rep_name)}</td>
      <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/customers/${r.id}">تعديل</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(7);

  listPage(res, req.session.user, {
    title: 'العملاء',
    mark: 'Cl',
    subtitle: 'دليل العملاء — إضافة وتعديل على Node',
    headers: ['الرمز', 'الاسم', 'الهاتف', 'المنطقة', 'المندوب', 'الحالة', ''],
    rowsHtml,
    count: rows.length,
    phpRoute: 'customers',
    filtersHtml,
    extraActions: [
      {
        label: '＋ عميل جديد',
        href: '/customers/new',
        primary: true,
      },
    ],
  });
});

/* ── Regions ── */
router.get('/customers/regions', guard('customer_regions'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const rows = await q.listRegions({ q: qv, activeOnly: !showAll });
  const focusId = Number(req.query.id || 0) || 0;
  let addressRows = [];
  if (focusId > 0) addressRows = await q.listRegionAddresses(focusId);

  const filtersHtml = `
    <div class="si-rail">
      <form class="si-search" method="get" action="/customers/regions" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
        <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث في المناطق…" autocomplete="off" style="flex:1;min-width:10rem">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفة
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
      </form>
    </div>`;

  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${dash(r.code)}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${Number(r.address_count || 0)}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      <td>
        <a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/customers/regions?id=${r.id}${qv ? '&q=' + encodeURIComponent(qv) : ''}${showAll ? '&all=1' : ''}">عناوين</a>
        <a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/customers/regions/${r.id}/edit">تعديل</a>
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);

  let addressesBlock = '';
  if (focusId > 0) {
    const focus = rows.find((r) => Number(r.id) === focusId);
    const aHtml =
      addressRows
        .map(
          (a) => `<tr>
        <td>${ui.esc(a.name_ar || '')}</td>
        <td class="si-num" dir="ltr">${Number(a.customer_count || 0)}</td>
        <td>${ui.statusPill(Number(a.is_active) === 1 ? 'ok' : 'lock', Number(a.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(3, 'لا عناوين لهذه المنطقة');
    addressesBlock = `
      <div style="margin-top:.85rem">
        ${ui.tableSurface(
          `عناوين: ${focus ? focus.name_ar : '#' + focusId}`,
          `${addressRows.length} عنوان`,
          ['العنوان', 'العملاء', 'الحالة'],
          aHtml
        )}
        <section class="si-surface" style="margin-top:.65rem">
          <div class="si-surface-head"><h2>＋ عنوان جديد</h2></div>
          <form method="post" action="/customers/regions/${focusId}/addresses" class="si-meta" style="padding:1rem">
            <label class="si-span-2">اسم العنوان (حي / شارع)
              <input class="si-field" name="name_ar" required placeholder="مثال: الدوار السابع" autocomplete="off">
            </label>
            <div class="si-form-actions"><button class="si-btn si-btn--primary" type="submit">إضافة العنوان</button></div>
          </form>
        </section>
      </div>`;
  }

  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rg',
        kicker: KICKER,
        title: 'مناطق العملاء',
        subtitle: 'إضافة مناطق وعناوين مرتبطة بالعملاء — أصلية على Node',
        actions: [
          { label: '＋ منطقة', href: '/customers/regions/new', primary: true },
          { label: 'العملاء', href: '/customers/list' },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${ui.esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
      ${filtersHtml}
      ${ui.tableSurface('المناطق', `${rows.length} منطقة`, ['الرمز', 'الاسم', 'عناوين', 'عملاء', 'الحالة', ''], rowsHtml)}
      ${addressesBlock}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'مناطق العملاء', bodyHtml: body }));
});

async function regionForm(req, res, id) {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const row = id ? await masters.getRegion(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rg',
        kicker: KICKER,
        title: isNew ? 'إضافة منطقة' : 'تعديل منطقة',
        subtitle: 'crm_region',
        actions: [{ label: 'رجوع', href: '/customers/regions' }],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface">
        <form method="post" action="${isNew ? '/customers/regions/new' : '/customers/regions/' + id + '/edit'}" class="si-meta" style="padding:1rem">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <label>الرمز
            <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="تلقائي إن فارغ">
          </label>
          <label>الترتيب
            <input class="si-field" type="number" name="sort_order" value="${Number(row?.sort_order || 0)}" dir="ltr">
          </label>
          <label class="si-span-2">اسم المنطقة *
            <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" placeholder="مثال: عمان الغربية">
          </label>
          ${
            isNew
              ? ''
              : `<label style="display:flex;align-items:center;gap:.4rem;flex-direction:row">
            <input type="checkbox" name="is_active" value="1" ${Number(row.is_active) === 1 ? 'checked' : ''}>
            مفعّلة
          </label>`
          }
          <div class="si-form-actions">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/customers/regions">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: isNew ? 'منطقة جديدة' : 'تعديل منطقة', bodyHtml: body }));
}

router.get('/customers/regions/new', (req, res) => regionForm(req, res, 0));
router.get('/customers/regions/:id/edit', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/customers/regions');
  return regionForm(req, res, id);
});
router.post('/customers/regions/new', async (req, res) => {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const result = await masters.saveRegion({ ...(req.body || {}), is_active: 1 });
  if (!result.ok) return res.redirect('/customers/regions/new?err=' + encodeURIComponent(result.error));
  res.redirect('/customers/regions?msg=' + encodeURIComponent(result.message || 'تم') + (result.id ? '&id=' + result.id : ''));
});
router.post('/customers/regions/:id/edit', async (req, res) => {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  const result = await masters.saveRegion({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/customers/regions/' + id + '/edit?err=' + encodeURIComponent(result.error));
  res.redirect('/customers/regions?msg=' + encodeURIComponent(result.message || 'تم') + '&id=' + id);
});
router.post('/customers/regions/:id/addresses', async (req, res) => {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const regionId = Number(req.params.id);
  const result = await masters.saveRegionAddress({ ...(req.body || {}), region_id: regionId });
  const key = result.ok ? 'msg' : 'err';
  res.redirect(
    '/customers/regions?id=' + regionId + '&' + key + '=' + encodeURIComponent(result.message || result.error || '')
  );
});

/* ── Oracle bridge (حالة + فتح PHP للمزامنة) ── */
router.get('/customers/oracle-sync', guard('oracle_customers_sync'), async (req, res) => {
  const linked = await q.oracleLinkedCount();
  const total = (await q.listCustomers({ activeOnly: false, limit: 1 }))
  // count all
  let allCount = linked;
  try {
    const r = await q.reportCustomers({ activeOnly: false });
    allCount = r.length;
  } catch {
    /* */
  }
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Or',
        kicker: KICKER,
        title: 'مزامنة عملاء Oracle',
        subtitle: 'عرض حالة الربط في Hypex. اتصال Oracle والمزامنة الفعلية عبر شاشة PHP (تحتاج Instant Client).',
        actions: [{ label: 'لوحة العملاء', href: HUB }],
      })}
      <section class="si-surface">
        <div class="si-surface-head"><h2>حالة في MySQL</h2></div>
        <div style="padding:1rem 1.1rem">
          <p style="margin:0 0 .5rem">إجمالي العملاء (في التقرير): <strong dir="ltr">${allCount}</strong></p>
          <p style="margin:0 0 .75rem">مربوطون بـ Oracle (رمز 112* + مفتاح): <strong dir="ltr">${linked}</strong></p>
          <p class="muted" style="font-size:.88rem;margin:0 0 1rem">المزامنة والخرائط واختبار الاتصال ما زالت تحتاج وحدة PHP مع pdo_oci / oci8. من هنا تفتح الشاشة داخل Node.</p>
          <a class="si-btn si-btn--primary" href="${ui.esc(ui.embedUrl('oracle_customers_sync'))}">فتح مزامنة Oracle</a>
        </div>
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'مزامنة عملاء Oracle', bodyHtml: body }));
});

/* ── Reports ── */
router.get('/customers/reports/list', guard('report_customers'), async (req, res) => {
  const activeOnly = String(req.query.active_only || '') === '1';
  const rows = await q.reportCustomers({ activeOnly });
  const active = rows.filter((r) => Number(r.is_active) === 1).length;
  const inactive = rows.length - active;

  const filtersHtml = `
    <div class="si-rail no-print">
      <form class="si-search" method="get" action="/customers/reports/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="active_only" value="1" ${activeOnly ? 'checked' : ''}> العملاء النشطون فقط
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
      </form>
    </div>
    <div class="si-print-meta print-only">
      <strong>تقرير العملاء</strong>
      · نشط: ${active} · موقوف: ${inactive}
      · طُبع: <span class="si-print-when" dir="ltr"></span>
    </div>`;

  const rowsHtml =
    rows
      .map(
        (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr">${ui.esc(r.customer_code || '')}</td>
      <td>${ui.esc(r.customer_name || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td>${dash(r.email)}</td>
      <td class="si-num" dir="ltr">${dash(r.tax_number)}</td>
      <td>${dash(r.region_name)}</td>
      <td>${dash(r.sales_rep_name)}</td>
      <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(9);

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: 'R1',
        kicker: KICKER,
        title: 'تقرير العملاء',
        subtitle: `${rows.length} عميل · نشط ${active} · موقوف ${inactive}`,
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${filtersHtml}
      <div class="si-print-area">
        ${ui.tableSurface(
          'تقرير العملاء',
          `${rows.length} عميل`,
          ['#', 'الرمز', 'الاسم', 'الهاتف', 'البريد', 'ضريبي', 'المنطقة', 'المندوب', 'الحالة'],
          rowsHtml
        )}
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير العملاء',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

router.get('/customers/reports/by-rep', guard('report_customers_by_rep'), async (req, res) => {
  const activeOnly = String(req.query.active_only || '') === '1';
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const reps = await q.salesRepOptions();
  const rows = await q.reportCustomersByRep({ activeOnly, salesRepId });

  const groups = new Map();
  for (const r of rows) {
    const key = String(r.rep_id || 0);
    if (!groups.has(key)) {
      groups.set(key, {
        rep_id: Number(r.rep_id || 0),
        rep_name: r.rep_name || '—',
        rep_code: r.rep_code || '',
        rows: [],
        active: 0,
      });
    }
    const g = groups.get(key);
    g.rows.push(r);
    if (Number(r.is_active) === 1) g.active += 1;
  }

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${ui.esc(r.name_ar)}${r.code ? ' (' + ui.esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  const filtersHtml = `
    <div class="si-rail no-print">
      <form class="si-search" method="get" action="/customers/reports/by-rep" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.35rem">المندوب
          <select name="sales_rep_id" class="si-field" style="min-height:2.1rem;width:auto;min-width:11rem">
            <option value="0">جميع المندوبين</option>
            ${repOpts}
          </select>
        </label>
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="active_only" value="1" ${activeOnly ? 'checked' : ''}> النشطون فقط
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
      </form>
    </div>`;

  let blocks = '';
  if (groups.size === 0) {
    blocks = ui.tableSurface('النتيجة', '0', ['—'], ui.emptyRow(1, 'لا يوجد عملاء مطابقون'));
  } else {
    for (const g of groups.values()) {
      const html = g.rows
        .map(
          (r, i) => `<tr>
          <td class="si-num" dir="ltr">${i + 1}</td>
          <td class="si-num" dir="ltr">${ui.esc(r.customer_code || '')}</td>
          <td>${ui.esc(r.customer_name || '')}</td>
          <td class="si-num" dir="ltr">${dash(r.phone)}</td>
          <td>${dash(r.email)}</td>
          <td class="si-num" dir="ltr">${dash(r.tax_number)}</td>
          <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
        </tr>`
        )
        .join('');
      const title = `المندوب: ${g.rep_name}${g.rep_code ? ' (' + g.rep_code + ')' : ''}`;
      blocks += `<div style="margin-top:.75rem">${ui.tableSurface(
        title,
        `${g.rows.length} عميل · نشط ${g.active}`,
        ['#', 'الرمز', 'الاسم', 'الهاتف', 'البريد', 'ضريبي', 'الحالة'],
        html
      )}</div>`;
    }
  }

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: 'R2',
        kicker: KICKER,
        title: 'تقرير العملاء حسب المندوب',
        subtitle: `${groups.size} مجموعة · ${rows.length} صف عميل`,
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'لوحة العملاء', href: HUB },
          { label: 'تقرير كامل', href: ui.embedUrl('report_customers_by_rep') },
        ],
      })}
      ${filtersHtml}
      <div class="si-print-area">${blocks}</div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير العملاء حسب المندوب',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

/* ── Customer form (بعد المسارات الثابتة) ── */
async function customerForm(req, res, id) {
  if (!can(req.session.user, 'customers')) return res.status(403).send('ممنوع');
  const row = id ? await masters.getCustomer(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');
  const regions = await q.regionOptions();
  const reps = await q.salesRepOptions();
  const regionId = Number(row?.region_id || 0);
  const addresses = regionId ? await masters.listAddressesForRegion(regionId) : [];
  const selectedReps = new Set((row?.rep_ids || []).map(Number));
  if (row?.sales_rep_id) selectedReps.add(Number(row.sales_rep_id));
  const oracleLocked = !isNew && String(row.oracle_key || '').trim() !== '';

  const regionOpts = regions
    .map(
      (r) =>
        `<option value="${r.id}" ${regionId === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}</option>`
    )
    .join('');
  const addrOpts = addresses
    .map(
      (a) =>
        `<option value="${a.id}" ${Number(row?.region_address_id) === Number(a.id) ? 'selected' : ''}>${esc(
          a.name_ar
        )}</option>`
    )
    .join('');
  const repChecks =
    reps
      .map(
        (r) => `<label style="display:flex;align-items:center;gap:.4rem;font-weight:600;font-size:.88rem">
          <input type="checkbox" name="rep_ids" value="${r.id}" ${selectedReps.has(Number(r.id)) ? 'checked' : ''}>
          ${esc(r.name_ar)}${r.code ? ` <span class="muted" dir="ltr">(${esc(r.code)})</span>` : ''}
        </label>`
      )
      .join('') ||
    `<p class="muted" style="margin:0">لا يوجد مندوبون نشطون — أضف من شاشة المندوبين.</p>`;

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Cl',
        kicker: KICKER,
        title: isNew ? 'إضافة عميل' : 'تعديل عميل',
        subtitle: isNew
          ? 'الرمز يُولَّد تلقائياً عند الحفظ'
          : oracleLocked
            ? 'عميل مربوط بـ Oracle — الاسم مقفل'
            : esc(row.code || ''),
        actions: [{ label: 'رجوع للقائمة', href: '/customers/list' }],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'بيانات العميل' : esc(row.name_ar || '')}</h2></div>
        <form method="post" action="${isNew ? '/customers/new' : '/customers/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <label>رمز العميل
            <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" readonly placeholder="يولد تلقائياً عند الحفظ">
          </label>
          <label>اسم العميل *
            <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" ${
              oracleLocked ? 'readonly' : ''
            } autocomplete="off">
          </label>
          <label>الهاتف
            <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr" autocomplete="off">
          </label>
          <label>البريد
            <input class="si-field" name="email" type="email" value="${esc(row?.email || '')}" dir="ltr" autocomplete="off">
          </label>
          <label>الرقم الضريبي
            <input class="si-field" name="tax_number" value="${esc(row?.tax_number || '')}" dir="ltr" autocomplete="off">
          </label>
          <label>المنطقة
            <select class="si-field" name="region_id" id="cust-region">
              <option value="0">— بدون منطقة —</option>
              ${regionOpts}
            </select>
          </label>
          <label>العنوان ضمن المنطقة
            <select class="si-field" name="region_address_id" id="cust-region-addr">
              <option value="0">— اختر —</option>
              ${addrOpts}
            </select>
          </label>
          <label class="si-span-2">العنوان
            <textarea class="si-field" name="address_ar" rows="2" style="min-height:4rem">${esc(
              row?.address_ar || ''
            )}</textarea>
          </label>
          <div class="si-span-full" style="border:1px solid #e4e8f0;border-radius:12px;padding:.75rem 1rem;margin-top:.25rem">
            <div style="font-weight:800;font-size:.9rem;margin-bottom:.45rem">المندوب / مندوبو المبيعات</div>
            <div style="display:flex;flex-direction:column;gap:.35rem">${repChecks}</div>
          </div>
          <div class="si-form-actions">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/customers/list">إلغاء</a>
          </div>
        </form>
      </section>
    </div>
    <script>
      (function(){
        var reg = document.getElementById('cust-region');
        var addr = document.getElementById('cust-region-addr');
        if (!reg || !addr) return;
        reg.addEventListener('change', function(){
          var id = reg.value;
          addr.innerHTML = '<option value="0">…</option>';
          if (!id || id === '0') { addr.innerHTML = '<option value="0">— اختر المنطقة أولاً —</option>'; return; }
          fetch('/api/customers/region-addresses?region_id=' + encodeURIComponent(id), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(data){
              var opts = '<option value="0">— اختر —</option>';
              (data.rows || []).forEach(function(a){
                opts += '<option value="'+a.id+'">'+String(a.name_ar||'').replace(/</g,'&lt;')+'</option>';
              });
              addr.innerHTML = opts;
            }).catch(function(){ addr.innerHTML = '<option value="0">—</option>'; });
        });
      })();
    </script>`;
  res.send(ui.salesPage({ user: req.session.user, title: isNew ? 'إضافة عميل' : 'تعديل عميل', bodyHtml: body }));
}

router.get('/customers/new', (req, res) => customerForm(req, res, 0));
router.post('/customers/new', async (req, res) => {
  if (!can(req.session.user, 'customers')) return res.status(403).send('ممنوع');
  const body = req.body || {};
  body.rep_ids = [].concat(body.rep_ids || []).filter(Boolean);
  const result = await masters.saveCustomer(body);
  if (!result.ok) return res.redirect('/customers/new?err=' + encodeURIComponent(result.error));
  res.redirect('/customers/list?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});
router.get('/api/customers/region-addresses', async (req, res) => {
  if (!can(req.session.user, 'customers') && !can(req.session.user, 'customer_regions')) {
    return res.status(403).json({ rows: [] });
  }
  const regionId = Number(req.query.region_id || 0);
  const rows = await masters.listAddressesForRegion(regionId);
  res.json({ rows });
});
router.get('/customers/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return customerForm(req, res, id);
});
router.post('/customers/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'customers')) return res.status(403).send('ممنوع');
  const body = { ...(req.body || {}), id };
  body.rep_ids = [].concat(body.rep_ids || []).filter(Boolean);
  const result = await masters.saveCustomer(body);
  if (!result.ok) return res.redirect('/customers/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/customers/list?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

module.exports = router;
