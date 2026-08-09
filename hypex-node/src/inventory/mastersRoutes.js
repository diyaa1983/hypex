'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./mastersService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex Inventory · Node';
const HUB = '/hub/inventory';

function canWh(user) {
  return user.is_admin || auth.userCan(user, 'warehouses');
}
function canItems(user) {
  return user.is_admin || auth.userCan(user, 'items');
}
function canUnits(user) {
  return user.is_admin || auth.userCan(user, 'item_units') || auth.userCan(user, 'items');
}
function canMoveTypes(user) {
  return (
    user.is_admin ||
    auth.userCan(user, 'inv_movement_types_settings') ||
    auth.userCan(user, 'warehouses') ||
    auth.userCan(user, 'warehouse_moves')
  );
}
function canCategories(user) {
  return user.is_admin || auth.userCan(user, 'item_categories') || auth.userCan(user, 'items');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    !p.startsWith('/inventory/warehouses') &&
    !p.startsWith('/inventory/items') &&
    !p.startsWith('/inventory/units') &&
    !p.startsWith('/inventory/categories') &&
    !p.startsWith('/inventory/movement-types') &&
    !p.startsWith('/api/inventory/')
  ) {
    return next('router');
  }
  return auth.requireAuth(req, res, next);
});

function page(user, title, bodyHtml) {
  return ui.salesPage({ user, title, bodyHtml, activePath: '/hub/inventory' });
}

function alertHtml(type, msg) {
  if (!msg) return '';
  const cls = type === 'ok' ? 'si-pill si-pill--ok' : 'si-pill si-pill--lock';
  return `<p class="${cls}" style="display:inline-block;margin:.5rem 0 0">${esc(msg)}</p>`;
}

/* ═══════════ Warehouses ═══════════ */
router.get('/inventory/warehouses', async (req, res) => {
  try {
    if (!canWh(req.session.user)) return res.status(403).send('ممنوع');
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const rows = await svc.listWarehouses({ q: qv });
    const rowsHtml =
      rows
        .map(
          (r, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td class="si-num" dir="ltr">${esc(r.code || '')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/warehouses/${r.id}">تعديل</a>
            <form method="post" action="/inventory/warehouses/${r.id}/delete" style="display:inline" onsubmit="return confirm('حذف هذا المستودع؟');">
              <button type="submit" class="si-btn" style="color:#b42318">حذف</button>
            </form>
          </div>
        </td>
      </tr>`
        )
        .join('') || ui.emptyRow(4);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'Wh',
          kicker: KICKER,
          title: 'المستودعات',
          subtitle: 'إدارة المستودعات — قائمة وإضافة وتعديل أصلية على Node',
          actions: [
            { label: '＋ إضافة مستودع', href: '/inventory/warehouses/new', primary: true },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${ui.railSearch('/inventory/warehouses', qv)}
        ${ui.tableSurface('المستودعات', `${rows.length} صف`, ['#', 'الرمز', 'الاسم', 'إجراءات'], rowsHtml)}
      </div>`;
    res.send(page(req.session.user, 'المستودعات', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function warehouseForm(req, res, id) {
  if (!canWh(req.session.user)) return res.status(403).send('ممنوع');
  const wh = id ? await svc.getWarehouse(id) : null;
  if (id && !wh) return res.status(404).send('غير موجود');
  const isNew = !wh;
  const err = String(req.query.err || '');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Wh',
        kicker: KICKER,
        title: isNew ? 'إضافة مستودع' : 'تعديل مستودع',
        subtitle: 'بيانات المستودع',
        actions: [{ label: 'رجوع للقائمة', href: '/inventory/warehouses' }],
      })}
      ${err ? alertHtml('err', err) : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'مستودع جديد' : 'تعديل'}</h2></div>
        <form method="post" action="${isNew ? '/inventory/warehouses/new' : '/inventory/warehouses/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${wh ? wh.id : 0}">
          <label>الرمز <span class="muted" style="font-weight:500">(فارغ = تلقائي)</span>
            <input class="si-field" name="code" value="${esc(wh?.code || '')}" dir="ltr" autocomplete="off">
          </label>
          <label class="si-span-2">اسم المستودع *
            <input class="si-field" name="name_ar" required value="${esc(wh?.name_ar || '')}" autocomplete="off">
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.35rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/inventory/warehouses">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(page(req.session.user, isNew ? 'إضافة مستودع' : 'تعديل مستودع', body));
}

router.get('/inventory/warehouses/new', (req, res) => warehouseForm(req, res, 0));
router.get('/inventory/warehouses/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/warehouses');
  return warehouseForm(req, res, id);
});

router.post('/inventory/warehouses/new', async (req, res) => {
  if (!canWh(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.saveWarehouse(req.body || {});
  if (!result.ok) return res.redirect('/inventory/warehouses/new?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/warehouses?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/warehouses/:id', async (req, res) => {
  if (!canWh(req.session.user)) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  const result = await svc.saveWarehouse({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/inventory/warehouses/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/warehouses?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/warehouses/:id/delete', async (req, res) => {
  if (!canWh(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.deleteWarehouse(req.params.id);
  if (!result.ok) return res.redirect('/inventory/warehouses?msg=' + encodeURIComponent(result.error));
  res.redirect('/inventory/warehouses?msg=' + encodeURIComponent(result.message || 'تم الحذف'));
});

/* ═══════════ Items ═══════════ */
router.get('/inventory/items', async (req, res) => {
  try {
    if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const rows = await svc.listItems({ q: qv });
    const rowsHtml =
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${esc(r.sku || '')}</td>
        <td class="si-num" dir="ltr">${esc(r.barcode || '—')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td>${esc(r.category_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.default_cost))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.default_sale))}</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/items/${r.id}">تعديل</a>
            <form method="post" action="/inventory/items/${r.id}/delete" style="display:inline" onsubmit="return confirm('حذف هذه المادة؟');">
              <button type="submit" class="si-btn" style="color:#b42318">حذف</button>
            </form>
          </div>
        </td>
      </tr>`
        )
        .join('') || ui.emptyRow(7);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'It',
          kicker: KICKER,
          title: 'المواد والأصناف',
          subtitle: 'قائمة المواد — إضافة وتعديل أصلي على Node',
          actions: [
            { label: '＋ مادة جديدة', href: '/inventory/items/new', primary: true },
            { label: 'الفئات', href: '/inventory/categories' },
            { label: 'الوحدات', href: '/inventory/units' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${ui.railSearch('/inventory/items', qv)}
        ${ui.tableSurface(
          'المواد',
          `${rows.length} صف`,
          ['SKU', 'Barcode', 'الاسم', 'الفئة', 'التكلفة', 'البيع', 'إجراءات'],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'المواد والأصناف', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function itemForm(req, res, id) {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const lookups = await svc.itemLookups();
  const item = id ? await svc.getItem(id) : null;
  if (id && !item) return res.status(404).send('غير موجود');
  const isNew = !item;
  const err = String(req.query.err || '');
  const flash = String(req.query.msg || '');

  let defaultBarcode = '';
  if (isNew) {
    try {
      defaultBarcode = await svc.nextBarcode();
    } catch {
      defaultBarcode = '';
    }
  }

  const catOpts =
    `<option value="">— بدون فئة —</option>` +
    (lookups.categories || [])
      .map(
        (c) =>
          `<option value="${c.id}"${Number(item?.category_id) === Number(c.id) ? ' selected' : ''}>${esc(c.name_ar)}</option>`
      )
      .join('');

  const unitOpts =
    `<option value="">— اختر —</option>` +
    (lookups.units || [])
      .map(
        (u) =>
          `<option value="${u.id}"${Number(item?.unit_id) === Number(u.id) ? ' selected' : ''}>${esc(u.name_ar)}</option>`
      )
      .join('');

  const whOpts =
    `<option value="">—</option>` +
    (lookups.warehouses || [])
      .map(
        (w) =>
          `<option value="${w.id}"${Number(item?.default_warehouse_id) === Number(w.id) ? ' selected' : ''}>${esc(w.name_ar)}</option>`
      )
      .join('');

  const issueUnitOpts =
    `<option value="">لا يوجد</option>` +
    (lookups.units || [])
      .map((u) => `<option value="${u.id}">${esc(u.name_ar)}</option>`)
      .join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'It',
        kicker: KICKER,
        title: isNew ? 'مادة جديدة' : `تعديل: ${esc(item.name_ar || '')}`,
        subtitle: 'بطاقة المادة — Node',
        actions: [{ label: 'رجوع للقائمة', href: '/inventory/items' }],
      })}
      ${err ? alertHtml('err', err) : ''}
      ${flash ? alertHtml('ok', flash) : ''}
      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات المادة</h2>
          <button class="si-btn si-btn--primary" type="submit" form="item-form">حفظ</button>
        </div>
        <form id="item-form" method="post" action="${isNew ? '/inventory/items/new' : '/inventory/items/' + id}" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${item ? item.id : 0}">
          <div class="si-meta">
            <label>Barcode
              <input class="si-field si-field--mono" name="barcode" value="${esc(item?.barcode || defaultBarcode)}" dir="ltr" autocomplete="off" inputmode="numeric">
            </label>
            <p class="si-span-2" style="margin:0;color:#5c6578;font-size:.8rem">أرقام فقط (حتى 14 رقماً) — فارغ عند الحفظ = تلقائي 6 أرقام</p>
            <label class="si-span-2">اسم المادة *
              <input class="si-field" name="name_ar" required value="${esc(item?.name_ar || '')}" autocomplete="off">
            </label>
            <label>SKU
              <input class="si-field si-field--mono" name="sku" value="${esc(item?.sku || '')}" dir="ltr" placeholder="${isNew ? 'تلقائي إن تُرك فارغاً' : ''}" autocomplete="off">
            </label>
            <label>الفئة
              <select class="si-field" name="category_id">${catOpts}</select>
            </label>
            <label>الوحدة الأساسية *
              <select class="si-field" name="unit_id" ${lookups.units.length ? 'required' : ''}>${unitOpts}</select>
            </label>
            <label>المستودع الافتراضي
              <select class="si-field" name="default_warehouse_id">${whOpts}</select>
            </label>
            <label>سعر التكلفة
              <input class="si-field si-field--mono" name="default_cost" type="number" step="0.001" min="0" value="${esc(item?.default_cost != null ? item.default_cost : 0)}" dir="ltr">
            </label>
            <label>سعر البيع
              <input class="si-field si-field--mono" name="default_sale" type="number" step="0.001" min="0" value="${esc(item?.default_sale != null ? item.default_sale : 0)}" dir="ltr">
            </label>
          </div>

          ${
            isNew
              ? `<div style="margin-top:1.1rem;padding-top:1rem;border-top:1px solid rgba(15,23,42,.08)">
            <h3 style="margin:0 0 .5rem;font-size:.95rem">وحدة الصرف والتعبئة (اختيارية)</h3>
            <p style="margin:0 0 .75rem;color:#5c6578;font-size:.82rem;line-height:1.5">
              اختيارية: إن كانت المادة تُباع بالكرتون ضع الوحدة وعدد القطع في الوحدة الأساسية (مثال 24).
            </p>
            <div class="si-meta">
              <label>وحدة الصرف
                <select class="si-field" name="issue_unit_id">${issueUnitOpts}</select>
              </label>
              <label>معامل التحويل (عدد الوحدات الأساسية)
                <input class="si-field si-field--mono" name="issue_factor" type="number" step="0.001" min="0" value="" dir="ltr" placeholder="مثال 24">
              </label>
            </div>
          </div>`
              : ''
          }

          <div style="display:flex;gap:.5rem;margin-top:1rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/inventory/items">رجوع للقائمة</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(page(req.session.user, isNew ? 'مادة جديدة' : 'تعديل مادة', body));
}

router.get('/inventory/items/new', (req, res) => itemForm(req, res, 0));
router.get('/inventory/items/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/items');
  return itemForm(req, res, id);
});

router.post('/inventory/items/new', async (req, res) => {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.saveItem(req.body || {});
  if (!result.ok) return res.redirect('/inventory/items/new?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/items/' + result.id + '?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/items/:id', async (req, res) => {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  const result = await svc.saveItem({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/inventory/items/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/items?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/items/:id/delete', async (req, res) => {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.deleteItem(req.params.id);
  const msg = result.ok ? result.message : result.error;
  res.redirect('/inventory/items?msg=' + encodeURIComponent(msg || ''));
});

/* ═══════════ Units ═══════════ */
router.get('/inventory/units', async (req, res) => {
  try {
    if (!canUnits(req.session.user)) return res.status(403).send('ممنوع');
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const rows = await svc.listUnits({ q: qv });
    const rowsHtml =
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${esc(r.code || '')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/units/${r.id}">تعديل</a>
            <form method="post" action="/inventory/units/${r.id}/toggle" style="display:inline">
              <button type="submit" class="si-btn">${Number(r.is_active) === 1 ? 'إيقاف' : 'تفعيل'}</button>
            </form>
          </div>
        </td>
      </tr>`
        )
        .join('') || ui.emptyRow(4);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'Un',
          kicker: KICKER,
          title: 'وحدات القياس',
          subtitle: 'إضافة وتعديل وحدات القياس المستخدمة في بطاقات المواد',
          actions: [
            { label: '＋ وحدة جديدة', href: '/inventory/units/new', primary: true },
            { label: 'المواد', href: '/inventory/items' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${ui.railSearch('/inventory/units', qv)}
        ${ui.tableSurface('وحدات القياس', `${rows.length} صف`, ['الرمز', 'الاسم', 'الحالة', 'إجراءات'], rowsHtml)}
      </div>`;
    res.send(page(req.session.user, 'وحدات القياس', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function unitForm(req, res, id) {
  if (!canUnits(req.session.user)) return res.status(403).send('ممنوع');
  const unit = id ? await svc.getUnit(id) : null;
  if (id && !unit) return res.status(404).send('غير موجود');
  const isNew = !unit;
  const err = String(req.query.err || '');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Un',
        kicker: KICKER,
        title: isNew ? 'إضافة وحدة قياس' : 'تعديل وحدة قياس',
        subtitle: 'رمز واسم الوحدة',
        actions: [{ label: 'رجوع للقائمة', href: '/inventory/units' }],
      })}
      ${err ? alertHtml('err', err) : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'وحدة جديدة' : 'تعديل'}</h2></div>
        <form method="post" action="${isNew ? '/inventory/units/new' : '/inventory/units/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${unit ? unit.id : 0}">
          <label>الرمز <span style="font-weight:500;color:#5c6578">(فارغ = تلقائي)</span>
            <input class="si-field si-field--mono" name="code" value="${esc(unit?.code || '')}" dir="ltr" placeholder="مثال: BOX" autocomplete="off">
          </label>
          <label class="si-span-2">اسم الوحدة *
            <input class="si-field" name="name_ar" required value="${esc(unit?.name_ar || '')}" placeholder="مثال: كرتون" autocomplete="off">
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.35rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/inventory/units">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(page(req.session.user, isNew ? 'إضافة وحدة' : 'تعديل وحدة', body));
}

router.get('/inventory/units/new', (req, res) => unitForm(req, res, 0));
router.get('/inventory/units/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/units');
  return unitForm(req, res, id);
});

router.post('/inventory/units/new', async (req, res) => {
  if (!canUnits(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.saveUnit(req.body || {});
  if (!result.ok) return res.redirect('/inventory/units/new?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/units?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/units/:id', async (req, res) => {
  if (!canUnits(req.session.user)) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  const result = await svc.saveUnit({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/inventory/units/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/units?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/units/:id/toggle', async (req, res) => {
  if (!canUnits(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.toggleUnit(req.params.id);
  res.redirect('/inventory/units?msg=' + encodeURIComponent(result.message || result.error || ''));
});

/* ═══════════ Categories ═══════════ */
router.get('/inventory/categories', async (req, res) => {
  try {
    if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const err = String(req.query.err || '');
    const rows = await svc.listCategories({ q: qv });
    const rowsHtml =
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${esc(r.code || '')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td class="si-num" dir="ltr">${Number(r.item_count || 0)}</td>
        <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/categories/${r.id}">تعديل</a>
            <form method="post" action="/inventory/categories/${r.id}/toggle" style="display:inline">
              <button type="submit" class="si-btn">${Number(r.is_active) === 1 ? 'إيقاف' : 'تفعيل'}</button>
            </form>
            <form method="post" action="/inventory/categories/${r.id}/delete" style="display:inline" onsubmit="return confirm('حذف هذه الفئة؟');">
              <button type="submit" class="si-btn" style="color:#b42318">حذف</button>
            </form>
          </div>
        </td>
      </tr>`
        )
        .join('') || ui.emptyRow(5);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'Ct',
          kicker: KICKER,
          title: 'فئات المواد',
          subtitle: 'إضافة وتعديل فئات تصنيف المواد — تظهر في بطاقة المادة',
          actions: [
            { label: '＋ فئة جديدة', href: '/inventory/categories/new', primary: true },
            { label: 'المواد', href: '/inventory/items' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${err ? alertHtml('err', err) : ''}
        ${ui.railSearch('/inventory/categories', qv)}
        ${ui.tableSurface('فئات المواد', `${rows.length} صف`, ['الرمز', 'الاسم', 'مواد', 'الحالة', 'إجراءات'], rowsHtml)}
      </div>`;
    res.send(page(req.session.user, 'فئات المواد', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function categoryForm(req, res, id) {
  if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
  const cat = id ? await svc.getCategory(id) : null;
  if (id && !cat) return res.status(404).send('غير موجود');
  const isNew = !cat;
  const err = String(req.query.err || '');
  const nextCode = isNew ? await svc.nextCategoryCode() : '';

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Ct',
        kicker: KICKER,
        title: isNew ? 'إضافة فئة مواد' : 'تعديل فئة مواد',
        subtitle: isNew ? `الرمز التالي المقترح: ${esc(nextCode)}` : 'تعديل اسم ورمز الفئة',
        actions: [{ label: 'رجوع للقائمة', href: '/inventory/categories' }],
      })}
      ${err ? alertHtml('err', err) : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'فئة جديدة' : 'تعديل'}</h2></div>
        <form method="post" action="${isNew ? '/inventory/categories/new' : '/inventory/categories/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${cat ? cat.id : 0}">
          <label>الرمز <span style="font-weight:500;color:#5c6578">${isNew ? '(فارغ = تلقائي)' : ''}</span>
            <input class="si-field si-field--mono" name="code" value="${esc(cat?.code || '')}" dir="ltr" placeholder="${esc(nextCode || '')}" autocomplete="off" ${isNew ? '' : ''}>
          </label>
          <label class="si-span-2">اسم الفئة *
            <input class="si-field" name="name_ar" required value="${esc(cat?.name_ar || '')}" placeholder="مثال: مشروبات" autocomplete="off">
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.35rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/inventory/categories">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(page(req.session.user, isNew ? 'إضافة فئة' : 'تعديل فئة', body));
}

router.get('/inventory/categories/new', (req, res) => categoryForm(req, res, 0));
router.get('/inventory/categories/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/categories');
  return categoryForm(req, res, id);
});

router.post('/inventory/categories/new', async (req, res) => {
  if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.saveCategory(req.body || {});
  if (!result.ok) return res.redirect('/inventory/categories/new?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/categories?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/categories/:id', async (req, res) => {
  if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/categories');
  const result = await svc.saveCategory({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/inventory/categories/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/categories?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/categories/:id/toggle', async (req, res) => {
  if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.toggleCategory(req.params.id);
  res.redirect('/inventory/categories?msg=' + encodeURIComponent(result.message || result.error || ''));
});

router.post('/inventory/categories/:id/delete', async (req, res) => {
  if (!canCategories(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.deleteCategory(req.params.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect('/inventory/categories?' + key + '=' + encodeURIComponent(result.message || result.error || ''));
});

/* ═══════════ Movement types ═══════════ */
function flagCell(on) {
  return ui.statusPill(on ? 'ok' : 'lock', on ? 'نعم' : 'لا');
}

router.get('/inventory/movement-types', async (req, res) => {
  try {
    if (!canMoveTypes(req.session.user)) return res.status(403).send('ممنوع');
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const rows = await svc.listMovementTypes({ q: qv });
    const rowsHtml =
      rows
        .map((r) => {
          const auto = Number(r.post_auto) === 1;
          const manual = Number(r.post_manual) === 1;
          const active = Number(r.is_active) === 1;
          const gl = r.affects_gl == null ? null : Number(r.affects_gl) === 1;
          return `<tr>
        <td class="si-num" dir="ltr">${esc(r.code || '')}</td>
        <td>${esc(r.name_ar || '')}</td>
        <td class="muted" style="font-size:.85rem">${esc(r.hint_ar || '—')}</td>
        <td>${flagCell(auto)}</td>
        <td>${flagCell(manual)}</td>
        <td>${flagCell(active)}</td>
        <td>${gl == null ? '—' : flagCell(gl)}</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/movement-types/${r.id}">تعديل</a>
          </div>
        </td>
      </tr>`;
        })
        .join('') || ui.emptyRow(8);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'Mt',
          kicker: KICKER,
          title: 'إعداد أنواع الحركات',
          subtitle: 'إضافة وتعديل أنواع حركات المستودع — الترحيل التلقائي/اليدوي والتفعيل والتأثير المحاسبي',
          actions: [
            { label: '＋ نوع حركة جديد', href: '/inventory/movement-types/new', primary: true },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${ui.railSearch('/inventory/movement-types', qv)}
        ${ui.tableSurface(
          'أنواع الحركات',
          `${rows.length} صف`,
          ['الرمز', 'الاسم', 'الوصف', 'ترحيل تلقائي', 'ترحيل يدوي', 'مفعّل', 'محاسبي', 'إجراءات'],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'أنواع الحركات', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function moveTypeForm(req, res, id) {
  if (!canMoveTypes(req.session.user)) return res.status(403).send('ممنوع');
  const row = id ? await svc.getMovementType(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');

  let postAuto = isNew ? 0 : Number(row.post_auto) === 1 ? 1 : 0;
  let postManual = isNew ? 1 : Number(row.post_manual) === 1 ? 1 : 0;
  if (postAuto === 1 && postManual === 1) postManual = 0;
  const isActive = isNew ? 1 : Number(row.is_active) === 1 ? 1 : 0;
  const affectsGl = isNew ? 1 : row.affects_gl == null ? 1 : Number(row.affects_gl) === 1 ? 1 : 0;
  const sortOrder = isNew ? 0 : Number(row.sort_order || 0);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Mt',
        kicker: KICKER,
        title: isNew ? 'إضافة نوع حركة' : 'تعديل نوع حركة',
        subtitle: 'اضبط الاسم، الترحيل، التفعيل والتأثير المحاسبي',
        actions: [{ label: 'رجوع للقائمة', href: '/inventory/movement-types' }],
      })}
      ${err ? alertHtml('err', err) : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>${isNew ? 'نوع جديد' : esc(row.name_ar || '')}</h2></div>
        <form method="post" action="${isNew ? '/inventory/movement-types/new' : '/inventory/movement-types/' + id}" class="si-meta" style="padding:1rem 1.1rem 1.25rem">
          <input type="hidden" name="id" value="${row ? row.id : 0}">
          <label>الرمز <span style="font-weight:500;color:#5c6578">(لاتيني، فارغ = تلقائي)</span>
            <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="مثال: adjust_extra" autocomplete="off">
          </label>
          <label>ترتيب العرض
            <input class="si-field si-field--mono" type="number" name="sort_order" value="${sortOrder}" dir="ltr">
          </label>
          <label class="si-span-2">اسم النوع *
            <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" placeholder="مثال: إرجاع داخلي" autocomplete="off">
          </label>
          <label class="si-span-2">وصف / تلميح
            <input class="si-field" name="hint_ar" value="${esc(row?.hint_ar || '')}" placeholder="يظهر كتلميح في شاشة الحركة" autocomplete="off">
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;flex-direction:row;margin-top:.25rem">
            <input type="checkbox" name="post_auto" value="1" ${postAuto ? 'checked' : ''}>
            <span>ترحيل تلقائي عند الحفظ</span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;flex-direction:row;margin-top:.25rem">
            <input type="checkbox" name="post_manual" value="1" ${postManual ? 'checked' : ''}>
            <span>ترحيل يدوي</span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;flex-direction:row;margin-top:.25rem">
            <input type="checkbox" name="is_active" value="1" ${isActive ? 'checked' : ''}>
            <span>مفعّل (يظهر في شاشة الحركات)</span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;flex-direction:row;margin-top:.25rem">
            <input type="checkbox" name="affects_gl" value="1" ${affectsGl ? 'checked' : ''}>
            <span>تأثير محاسبي عند الترحيل</span>
          </label>
          <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.5rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/inventory/movement-types">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(page(req.session.user, isNew ? 'إضافة نوع حركة' : 'تعديل نوع حركة', body));
}

router.get('/inventory/movement-types/new', (req, res) => moveTypeForm(req, res, 0));
router.get('/inventory/movement-types/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/movement-types');
  return moveTypeForm(req, res, id);
});

router.post('/inventory/movement-types/new', async (req, res) => {
  if (!canMoveTypes(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.saveMovementType(req.body || {});
  if (!result.ok) return res.redirect('/inventory/movement-types/new?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/movement-types?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/movement-types/:id', async (req, res) => {
  if (!canMoveTypes(req.session.user)) return res.status(403).send('ممنوع');
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/movement-types');
  const result = await svc.saveMovementType({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/inventory/movement-types/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/movement-types?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

module.exports = router;
