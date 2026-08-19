'use strict';

const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const masters = require('./mastersService');
const ui = require('../lib/salesUi');
const { suppliersCatalog } = require('./catalog');
const { esc } = require('../lib/html');

const router = express.Router();
const HUB = '/suppliers';
const KICKER = 'Hypex Suppliers · Node';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function requireAny(req, res, next) {
  const u = req.session.user;
  const flat = suppliersCatalog.flatMap((g) => g.items);
  const any = flat.some((it) => can(u, it.r));
  if (!any && !u.is_admin) {
    return res.status(403).send('ممنوع');
  }
  next();
}

function guard(code) {
  return (req, res, next) => {
    if (!can(req.session.user, code)) return res.status(403).send('ممنوع');
    next();
  };
}

router.use((req, res, next) => {
  if (!req.path.startsWith('/suppliers')) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAny(req, res, next);
  });
});

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

router.get('/suppliers', (req, res) => {
  const user = req.session.user;
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Sp',
        kicker: KICKER,
        title: 'الموردين',
        subtitle: 'دليل الموردين وتقاريرهم — إضافة وتعديل على Node.',
        actions: [
          { label: 'قائمة الموردين', href: '/suppliers/list', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      ${ui.hubTiles(can, user, suppliersCatalog)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'الموردين', bodyHtml: body }));
});

router.get('/suppliers/list', guard('suppliers'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const flash = String(req.query.msg || '');
  const rows = await q.listSuppliers({ q: qv, activeOnly: !showAll });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${dash(r.code)}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td class="si-num" dir="ltr">${dash(r.tax_number)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.balance))}</td>
      <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/suppliers/${r.id}">تعديل</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(7);

  const filtersHtml = `
    <div class="si-rail">
      <form class="si-search" method="get" action="/suppliers/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
        <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث…" style="flex:1">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;gap:.3rem;align-items:center">
          <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفين
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
      </form>
    </div>`;

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Sp',
        kicker: KICKER,
        title: 'الموردين',
        subtitle: 'إضافة وتعديل الموردين على Node',
        actions: [
          { label: '＋ مورد جديد', href: '/suppliers/new', primary: true },
          { label: 'لوحة الموردين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${ui.esc(flash)}</p>` : ''}
      ${filtersHtml}
      ${ui.tableSurface('الموردين', `${rows.length} صف`, ['الرمز', 'الاسم', 'الهاتف', 'الضريبي', 'الرصيد', 'الحالة', ''], rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'الموردين', bodyHtml: body }));
});

async function supplierForm(req, res, id) {
  if (!can(req.session.user, 'suppliers')) return res.status(403).send('ممنوع');
  const row = id ? await masters.getSupplier(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Sp',
        kicker: KICKER,
        title: isNew ? 'إضافة مورد' : 'تعديل مورد',
        subtitle: isNew ? 'الرمز فارغ = تلقائي' : esc(row.code || ''),
        actions: [{ label: 'رجوع للقائمة', href: '/suppliers/list' }],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'مورد جديد' : 'تعديل'}</h2></div>
        <form method="post" action="${isNew ? '/suppliers/new' : '/suppliers/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <label>الرمز <span style="font-weight:500;color:#5c6578">(فارغ = تلقائي)</span>
            <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="مثال: S-00001" autocomplete="off">
          </label>
          <label>اسم المورد *
            <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" autocomplete="off">
          </label>
          <label>الهاتف
            <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr">
          </label>
          <label>البريد
            <input class="si-field" name="email" type="email" value="${esc(row?.email || '')}" dir="ltr">
          </label>
          <label>الرقم الضريبي
            <input class="si-field" name="tax_number" value="${esc(row?.tax_number || '')}" dir="ltr">
          </label>
          <label class="si-span-2">العنوان
            <textarea class="si-field" name="address_ar" rows="2" style="min-height:4rem">${esc(
              row?.address_ar || ''
            )}</textarea>
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.35rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/suppliers/list">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: isNew ? 'إضافة مورد' : 'تعديل مورد', bodyHtml: body }));
}

router.get('/suppliers/new', (req, res) => supplierForm(req, res, 0));
router.post('/suppliers/new', async (req, res) => {
  if (!can(req.session.user, 'suppliers')) return res.status(403).send('ممنوع');
  const result = await masters.saveSupplier(req.body || {});
  if (!result.ok) return res.redirect('/suppliers/new?err=' + encodeURIComponent(result.error));
  res.redirect('/suppliers/list?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.get('/suppliers/reports/list', guard('report_suppliers'), async (req, res) => {
  const activeOnly = String(req.query.active_only || '') !== '0';
  const rows = await q.listSuppliers({ activeOnly });
  const filtersHtml = `
    <div class="si-rail no-print">
      <form method="get" action="/suppliers/reports/list" class="si-search" style="display:flex;gap:.5rem;align-items:center">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;gap:.3rem;align-items:center">
          <input type="hidden" name="active_only" value="0">
          <input type="checkbox" name="active_only" value="1" ${activeOnly ? 'checked' : ''}> النشطون فقط
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        ${ui.siPrintBtnHtml('طباعة')}
      </form>
    </div>`;
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${dash(r.code)}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td>${dash(r.email)}</td>
      <td class="si-num" dir="ltr">${dash(r.tax_number)}</td>
      <td>${dash(r.address_ar)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.balance))}</td>
      <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(8);

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: 'Sp',
        kicker: KICKER,
        title: 'تقرير الموردين',
        subtitle: `${rows.length} مورد`,
        actions: [
          ui.printAction(),
          { label: 'لوحة الموردين', href: HUB },
        ],
      })}
      ${filtersHtml}
      <div class="si-print-area">
        ${ui.tableSurface(
          'تقرير الموردين',
          `${rows.length} صف`,
          ['الرمز', 'الاسم', 'الهاتف', 'البريد', 'الضريبي', 'العنوان', 'الرصيد', 'الحالة'],
          rowsHtml
        )}
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير الموردين',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

router.get('/suppliers/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return supplierForm(req, res, id);
});
router.post('/suppliers/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'suppliers')) return res.status(403).send('ممنوع');
  const result = await masters.saveSupplier({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/suppliers/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/suppliers/list?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

module.exports = router;
