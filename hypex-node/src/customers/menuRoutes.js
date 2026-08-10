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
  const p = req.path || '';
  const ok =
    p.startsWith('/customers') ||
    p === '/api/customers/region-addresses' ||
    p.startsWith('/api/customers/region-addresses');
  if (!ok) return next('router');
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

/* ── تعريف المناطق: منطقة + عناوين متعددة بداخلها ── */
router.get('/customers/regions', guard('customer_regions'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const wantsNew = String(req.query.new || '') === '1';
  const rows = await q.listRegions({ q: qv, activeOnly: !showAll });
  let focusId = Number(req.query.id || 0) || 0;
  if (!wantsNew && !focusId && rows[0]) focusId = Number(rows[0].id);
  const focus = focusId ? rows.find((r) => Number(r.id) === focusId) || (await masters.getRegion(focusId)) : null;
  if (focusId && !focus) focusId = 0;
  let addressRows = [];
  if (focusId > 0) addressRows = await q.listRegionAddresses(focusId);

  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const qsKeep = `${qv ? '&q=' + encodeURIComponent(qv) : ''}${showAll ? '&all=1' : ''}`;

  const regionNav =
    rows
      .map((r) => {
        const active = !wantsNew && Number(r.id) === focusId;
        return `<a class="rg-nav-item${active ? ' is-active' : ''}${Number(r.is_active) !== 1 ? ' is-off' : ''}"
          href="/customers/regions?id=${r.id}${qsKeep}">
          <span class="rg-nav-name">${ui.esc(r.name_ar || '')}</span>
          <span class="rg-nav-meta" dir="ltr">${Number(r.address_count || 0)} عنوان · ${Number(r.customer_count || 0)} عميل</span>
        </a>`;
      })
      .join('') || `<p class="rg-empty">لا مناطق بعد — أضف منطقة من الزر أعلاه.</p>`;

  let detailHtml = '';
  if (wantsNew) {
    detailHtml = `
      <section class="si-surface rg-detail">
        <div class="si-surface-head"><h2>تعريف منطقة جديدة</h2></div>
        <form method="post" action="/customers/regions/new" class="si-meta" style="padding:1rem">
          <label>الرمز
            <input class="si-field si-field--mono" name="code" dir="ltr" placeholder="تلقائي إن فارغ">
          </label>
          <label>الترتيب
            <input class="si-field" type="number" name="sort_order" value="0" dir="ltr">
          </label>
          <label class="si-span-2">اسم المنطقة *
            <input class="si-field" name="name_ar" required autofocus placeholder="مثال: عمان الغربية">
          </label>
          <div class="si-form-actions si-span-2">
            <button class="si-btn si-btn--primary" type="submit">حفظ المنطقة</button>
            <a class="si-btn" href="/customers/regions${qsKeep ? '?' + qsKeep.slice(1) : ''}">إلغاء</a>
          </div>
        </form>
        <p class="rg-hint">بعد الحفظ يمكنك إضافة أكثر من عنوان داخل المنطقة.</p>
      </section>`;
  } else if (focus) {
    const aRows =
      addressRows
        .map(
          (a) => `<tr>
        <td>
          <form method="post" action="/customers/regions/${focusId}/addresses/${a.id}/edit" class="rg-addr-edit">
            <input type="hidden" name="sort_order" value="${Number(a.sort_order || 0)}">
            <input class="si-field" name="name_ar" required value="${ui.esc(a.name_ar || '')}" aria-label="اسم العنوان">
            <label class="rg-check">
              <input type="checkbox" name="is_active" value="1" ${Number(a.is_active) === 1 ? 'checked' : ''}>
              نشط
            </label>
            <button class="si-btn si-btn--primary" type="submit" style="min-height:1.85rem;padding:.25rem .65rem;font-size:.78rem">حفظ</button>
          </form>
        </td>
        <td class="si-num" dir="ltr">${Number(a.customer_count || 0)}</td>
        <td>${ui.statusPill(Number(a.is_active) === 1 ? 'ok' : 'lock', Number(a.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(3, 'لا عناوين بعد — أضف عنواناً أو أكثر أدناه');

    detailHtml = `
      <section class="si-surface rg-detail">
        <div class="si-surface-head">
          <h2>تعريف المنطقة</h2>
          <span class="si-count" dir="ltr">${ui.esc(focus.code || '')}</span>
        </div>
        <form method="post" action="/customers/regions/${focusId}/edit" class="si-meta" style="padding:1rem">
          <input type="hidden" name="id" value="${focusId}">
          <label>الرمز
            <input class="si-field si-field--mono" name="code" value="${ui.esc(focus.code || '')}" dir="ltr">
          </label>
          <label>الترتيب
            <input class="si-field" type="number" name="sort_order" value="${Number(focus.sort_order || 0)}" dir="ltr">
          </label>
          <label class="si-span-2">اسم المنطقة *
            <input class="si-field" name="name_ar" required value="${ui.esc(focus.name_ar || '')}">
          </label>
          <label class="rg-check-row si-span-2">
            <input type="checkbox" name="is_active" value="1" ${Number(focus.is_active) === 1 ? 'checked' : ''}>
            المنطقة مفعّلة
          </label>
          <div class="si-form-actions si-span-2">
            <button class="si-btn si-btn--primary" type="submit">حفظ تعريف المنطقة</button>
          </div>
        </form>
      </section>

      <section class="si-surface rg-detail" style="margin-top:.75rem">
        <div class="si-surface-head">
          <h2>عناوين داخل «${ui.esc(focus.name_ar || '')}»</h2>
          <span class="si-count">${addressRows.length} عنوان</span>
        </div>
        <div class="si-lines-wrap" style="padding:0 .85rem .85rem">
          <table class="si-table" style="width:100%;margin:0">
            <thead>
              <tr>
                <th>العنوان (حي / شارع)</th>
                <th style="width:5rem">عملاء</th>
                <th style="width:5.5rem">الحالة</th>
              </tr>
            </thead>
            <tbody>${aRows}</tbody>
          </table>
        </div>
        <form method="post" action="/customers/regions/${focusId}/addresses" class="si-meta" style="padding:0 1rem 1rem;border-top:1px solid #e8edf5">
          <div class="si-surface-head" style="padding:.75rem 0 .35rem"><h2 style="font-size:.95rem">＋ إضافة عنوان آخر</h2></div>
          <label class="si-span-2">اسم العنوان *
            <input class="si-field" name="name_ar" required placeholder="مثال: الدوار السابع · خلدا · الجبيهة" autocomplete="off">
          </label>
          <label>الترتيب
            <input class="si-field" type="number" name="sort_order" value="${addressRows.length * 10}" dir="ltr">
          </label>
          <div class="si-form-actions">
            <button class="si-btn si-btn--primary" type="submit">إضافة العنوان للمنطقة</button>
          </div>
          <p class="rg-hint si-span-2">يمكن إضافة أكثر من عنوان لنفس المنطقة — كل عنوان على حدة.</p>
        </form>
      </section>`;
  } else {
    detailHtml = `
      <section class="si-surface rg-detail">
        <div class="si-surface-head"><h2>تعريف المنطقة</h2></div>
        <p class="rg-empty" style="padding:1.25rem">اختر منطقة من القائمة أو أضف منطقة جديدة.</p>
      </section>`;
  }

  const body = `
    <style>
      .rg-layout{display:grid;grid-template-columns:minmax(14rem,18rem) minmax(0,1fr);gap:.85rem;align-items:start}
      @media (max-width:900px){.rg-layout{grid-template-columns:1fr}}
      .rg-nav{display:flex;flex-direction:column;gap:.35rem;padding:.55rem;max-height:min(70vh,36rem);overflow:auto}
      .rg-nav-item{display:flex;flex-direction:column;gap:.15rem;padding:.55rem .65rem;border-radius:10px;border:1px solid transparent;text-decoration:none;color:inherit;background:#f8fafc}
      .rg-nav-item:hover{border-color:#cbd5e1;background:#fff}
      .rg-nav-item.is-active{border-color:#0b6bcb;background:#eff6ff;box-shadow:0 0 0 1px rgba(11,107,203,.12)}
      .rg-nav-item.is-off{opacity:.72}
      .rg-nav-name{font-weight:700;font-size:.92rem}
      .rg-nav-meta{font-size:.72rem;color:#64748b;font-weight:600}
      .rg-empty{margin:0;padding:.75rem;color:#64748b;font-size:.88rem}
      .rg-hint{margin:.35rem 0 0;font-size:.8rem;color:#64748b;line-height:1.45}
      .rg-addr-edit{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
      .rg-addr-edit .si-field{flex:1;min-width:10rem;min-height:2rem}
      .rg-check,.rg-check-row{display:flex;align-items:center;gap:.35rem;font-size:.82rem;font-weight:700;color:#475569;flex-direction:row}
      .rg-filters{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rg',
        kicker: KICKER,
        title: 'تعريف المناطق',
        subtitle: 'عرّف المنطقة ثم أضف بداخلها العناوين (حي / شارع) — ويمكن أكثر من عنوان لكل منطقة',
        actions: [
          { label: '＋ منطقة جديدة', href: '/customers/regions?new=1' + qsKeep, primary: true },
          { label: 'العملاء', href: '/customers/list' },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${ui.esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
      <div class="si-rail">
        <form class="si-search rg-filters" method="get" action="/customers/regions" style="max-width:100%;margin:0">
          ${focusId && !wantsNew ? `<input type="hidden" name="id" value="${focusId}">` : ''}
          <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث منطقة أو عنوان…" autocomplete="off" style="flex:1;min-width:10rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفة
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="rg-layout">
        <section class="si-surface">
          <div class="si-surface-head"><h2>المناطق</h2><span class="si-count">${rows.length}</span></div>
          <nav class="rg-nav" aria-label="قائمة المناطق">${regionNav}</nav>
        </section>
        <div class="rg-detail-col">${detailHtml}</div>
      </div>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'تعريف المناطق', bodyHtml: body }));
});

async function regionForm(req, res, id) {
  /* نموذج منفصل لم يعد مستخدماً كشاشة رئيسية — إعادة توجيه للواجهة الموحدة */
  const isNew = !id;
  if (isNew) return res.redirect('/customers/regions?new=1');
  return res.redirect('/customers/regions?id=' + Number(id));
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
  if (!result.ok) return res.redirect('/customers/regions?new=1&err=' + encodeURIComponent(result.error));
  res.redirect(
    '/customers/regions?msg=' +
      encodeURIComponent(result.message || 'تم') +
      (result.id ? '&id=' + result.id : '')
  );
});
router.post('/customers/regions/:id/edit', async (req, res) => {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  const result = await masters.saveRegion({ ...(req.body || {}), id });
  if (!result.ok) {
    return res.redirect('/customers/regions?id=' + id + '&err=' + encodeURIComponent(result.error));
  }
  res.redirect('/customers/regions?id=' + id + '&msg=' + encodeURIComponent(result.message || 'تم'));
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
router.post('/customers/regions/:regionId/addresses/:addrId/edit', async (req, res) => {
  if (!can(req.session.user, 'customer_regions')) return res.status(403).send('ممنوع');
  const regionId = Number(req.params.regionId);
  const addrId = Number(req.params.addrId);
  const body = req.body || {};
  const result = await masters.saveRegionAddress({
    ...body,
    id: addrId,
    region_id: regionId,
    is_active: body.is_active ? 1 : 0,
  });
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
  const primaryRepId = [...selectedReps][0] || Number(row?.sales_rep_id || 0) || 0;
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
  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${primaryRepId === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}${
          r.code ? ' — ' + esc(r.code) : ''
        }</option>`
    )
    .join('');

  const body = `
    <style>
      .cf-form{padding:0!important;display:block!important}
      .cf-body{padding:1rem 1.1rem 1.15rem;display:grid;gap:1rem}
      .cf-sec{border:1px solid #e6ebf2;border-radius:14px;background:#fbfcfe;overflow:hidden}
      .cf-sec-h{display:flex;align-items:center;justify-content:space-between;gap:.75rem;
        padding:.7rem 1rem;background:#fff;border-bottom:1px solid #eef1f6}
      .cf-sec-h h3{margin:0;font-size:.95rem;font-weight:800;color:#0f172a}
      .cf-sec-h span{font-size:.75rem;font-weight:700;color:#64748b}
      .cf-sec-b{padding:.9rem 1rem 1rem;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem .85rem}
      @media (max-width:900px){.cf-sec-b{grid-template-columns:1fr 1fr}}
      @media (max-width:560px){.cf-sec-b{grid-template-columns:1fr}}
      .cf-sec-b label{display:grid;gap:.32rem;font-size:.72rem;font-weight:700;letter-spacing:.03em;color:#64748b}
      .cf-sec-b .cf-span-2{grid-column:span 2}
      .cf-sec-b .cf-span-3{grid-column:1/-1}
      @media (max-width:560px){.cf-sec-b .cf-span-2{grid-column:1/-1}}
      .cf-chain{display:grid;grid-template-columns:1fr auto 1fr;gap:.55rem;align-items:end;grid-column:1/-1}
      @media (max-width:720px){.cf-chain{grid-template-columns:1fr}}
      .cf-chain-arrow{display:flex;align-items:center;justify-content:center;padding-bottom: .55rem;
        color:#94a3b8;font-weight:800;font-size:1.1rem;user-select:none}
      @media (max-width:720px){.cf-chain-arrow{display:none}}
      .cf-field-note{margin:.15rem 0 0;font-size:.75rem;font-weight:600;color:#94a3b8;line-height:1.4}
      .cf-foot{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.15rem 0 .25rem}
      .cf-hint-line{margin:0;font-size:.8rem;color:#64748b;flex:1;min-width:10rem;line-height:1.45}
      select#cust-region-addr:disabled{opacity:.65;cursor:not-allowed;background:#f1f5f9}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Cl',
        kicker: KICKER,
        title: isNew ? 'إضافة عميل' : 'تعريف العميل',
        subtitle: isNew
          ? 'بيانات العميل ← المنطقة ← العنوان ← المندوب'
          : oracleLocked
            ? 'عميل مربوط بـ Oracle — الاسم مقفل · ' + esc(row.code || '')
            : esc(row.code || '') + ' — اختر المنطقة ثم العنوان ثم المندوب',
        actions: [
          { label: 'القائمة', href: '/customers/list' },
          { label: 'تعريف المناطق', href: '/customers/regions' },
        ],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface">
        <div class="si-surface-head">
          <h2>${isNew ? 'بيانات العميل' : esc(row.name_ar || 'تعديل عميل')}</h2>
          ${!isNew && Number(row.is_active) === 1 ? '<span class="si-pill si-pill--ok">نشط</span>' : ''}
        </div>
        <form method="post" action="${isNew ? '/customers/new' : '/customers/' + id}" class="si-meta cf-form">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <div class="cf-body">

            <div class="cf-sec">
              <div class="cf-sec-h">
                <h3>1 · بيانات العميل</h3>
                <span>التعريف والاتصال</span>
              </div>
              <div class="cf-sec-b">
                <label>رمز العميل
                  <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" readonly placeholder="يُولَّد تلقائياً عند الحفظ">
                </label>
                <label class="cf-span-2">اسم العميل *
                  <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" ${
                    oracleLocked ? 'readonly' : ''
                  } autocomplete="off" placeholder="الاسم كما يظهر في الفواتير">
                </label>
                <label>الهاتف
                  <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr" autocomplete="off" placeholder="07xxxxxxxx">
                </label>
                <label>البريد الإلكتروني
                  <input class="si-field" name="email" type="email" value="${esc(row?.email || '')}" dir="ltr" autocomplete="off" placeholder="name@example.com">
                </label>
                <label>الرقم الضريبي
                  <input class="si-field" name="tax_number" value="${esc(row?.tax_number || '')}" dir="ltr" autocomplete="off">
                </label>
              </div>
            </div>

            <div class="cf-sec">
              <div class="cf-sec-h">
                <h3>2 · المنطقة والعنوان</h3>
                <span>المنطقة أولاً ثم العنوان داخلها</span>
              </div>
              <div class="cf-sec-b">
                <div class="cf-chain">
                  <label>المنطقة
                    <select class="si-field" name="region_id" id="cust-region">
                      <option value="0">— اختر المنطقة —</option>
                      ${regionOpts}
                    </select>
                  </label>
                  <div class="cf-chain-arrow" aria-hidden="true">←</div>
                  <label>العنوان ضمن المنطقة
                    <select class="si-field" name="region_address_id" id="cust-region-addr" ${
                      regionId < 1 ? 'disabled' : ''
                    }>
                      <option value="0">${regionId < 1 ? '— اختر المنطقة أولاً —' : '— اختر العنوان —'}</option>
                      ${addrOpts}
                    </select>
                  </label>
                </div>
                <label class="cf-span-3">تفاصيل العنوان (اختياري)
                  <textarea class="si-field" name="address_ar" rows="2" style="min-height:3.4rem" placeholder="شارع، مبنى، ملاحظات توصيل…">${esc(
                    row?.address_ar || ''
                  )}</textarea>
                  <p class="cf-field-note">تُعرَّف المناطق وعناوينها من شاشة «تعريف المناطق». يتحدّث عنوان القائمة تلقائياً عند تغيير المنطقة.</p>
                </label>
              </div>
            </div>

            <div class="cf-sec">
              <div class="cf-sec-h">
                <h3>3 · المندوب</h3>
                <span>مندوب المبيعات المسؤول عن العميل</span>
              </div>
              <div class="cf-sec-b">
                <label class="cf-span-2">المندوب
                  <select class="si-field" name="sales_rep_id" id="cust-sales-rep">
                    <option value="0">— بدون مندوب —</option>
                    ${repOpts}
                  </select>
                </label>
                <label>
                  <span style="visibility:hidden">.</span>
                  <a class="si-btn" href="/sales-reps/list" style="justify-content:center;width:100%">إدارة المندوبين</a>
                </label>
                <p class="cf-field-note cf-span-3">يُحفظ المندوب المختار مع العميل ويظهر في القوائم والتقارير.</p>
              </div>
            </div>

            <div class="cf-foot">
              <button class="si-btn si-btn--primary" type="submit">حفظ العميل</button>
              <a class="si-btn" href="/customers/list">إلغاء</a>
              <p class="cf-hint-line">التسلسل: العميل → المنطقة → العنوان → المندوب ثم الحفظ.</p>
            </div>
          </div>
        </form>
      </section>
    </div>
    <script>
      (function () {
        var reg = document.getElementById('cust-region');
        var addr = document.getElementById('cust-region-addr');
        if (!reg || !addr) return;

        function setAddrBusy(busy, text) {
          addr.disabled = !!busy || !reg.value || reg.value === '0' || reg.value === '';
          if (text != null) addr.innerHTML = '<option value="0">' + text + '</option>';
        }

        function loadAddresses(regionId, keepId) {
          if (!regionId || regionId === '0') {
            setAddrBusy(true, '— اختر المنطقة أولاً —');
            return;
          }
          setAddrBusy(true, 'جاري التحميل…');
          fetch('/api/customers/region-addresses?region_id=' + encodeURIComponent(regionId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              var rows = data.rows || [];
              var opts = '<option value="0">— اختر العنوان —</option>';
              rows.forEach(function (a) {
                var id = String(a.id);
                var name = String(a.name_ar || '')
                  .replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/"/g, '&quot;');
                var sel = keepId && String(keepId) === id ? ' selected' : '';
                opts += '<option value="' + id + '"' + sel + '>' + name + '</option>';
              });
              if (!rows.length) {
                opts = '<option value="0">— لا عناوين لهذه المنطقة —</option>';
              }
              addr.innerHTML = opts;
              addr.disabled = false;
            })
            .catch(function () {
              setAddrBusy(false, '— تعذر التحميل —');
              addr.disabled = false;
            });
        }

        reg.addEventListener('change', function () {
          loadAddresses(reg.value, null);
        });
      })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: isNew ? 'إضافة عميل' : 'تعريف العميل',
      bodyHtml: body,
    })
  );
}

router.get('/customers/new', (req, res) => customerForm(req, res, 0));
router.post('/customers/new', async (req, res) => {
  if (!can(req.session.user, 'customers')) return res.status(403).send('ممنوع');
  const body = req.body || {};
  const repId = Number(body.sales_rep_id || 0);
  body.rep_ids = repId > 0 ? [repId] : [].concat(body.rep_ids || []).filter(Boolean);
  body.sales_rep_id = repId > 0 ? repId : null;
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
  const repId = Number(body.sales_rep_id || 0);
  body.rep_ids = repId > 0 ? [repId] : [].concat(body.rep_ids || []).filter(Boolean);
  body.sales_rep_id = repId > 0 ? repId : null;
  const result = await masters.saveCustomer(body);
  if (!result.ok) return res.redirect('/customers/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/customers/list?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

module.exports = router;
