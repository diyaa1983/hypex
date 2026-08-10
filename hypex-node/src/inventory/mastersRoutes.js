'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./mastersService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const companyDecimals = require('../lib/companyDecimals');

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
    const rows = await svc.listItems({ q: qv, activeOnly: false });
    const rowsHtml =
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${esc(r.barcode || '—')}</td>
        <td class="si-num" dir="ltr" style="opacity:.65">${esc(r.sku || '—')}</td>
        <td>
          <strong>${esc(r.name_ar || '')}</strong>
          ${r.name_en ? `<div class="muted" style="font-size:.78rem;font-weight:500" dir="ltr">${esc(r.name_en)}</div>` : ''}
        </td>
        <td>${esc(r.category_name || '—')}</td>
        <td>${esc(r.unit_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.default_sale))}</td>
        <td>${
          Number(r.is_active) === 1
            ? ui.statusPill('ok', 'نشط')
            : ui.statusPill('lock', 'موقوف')
        }</td>
        <td>
          <div class="si-act">
            <a class="si-btn" href="/inventory/items/${r.id}">بطاقة</a>
            <form method="post" action="/inventory/items/${r.id}/toggle" style="display:inline">
              <button type="submit" class="si-btn">${Number(r.is_active) === 1 ? 'إيقاف' : 'تفعيل'}</button>
            </form>
          </div>
        </td>
      </tr>`
        )
        .join('') || ui.emptyRow(8);

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'It',
          kicker: KICKER,
          title: 'المواد والأصناف',
          subtitle: 'بطاقة المادة — الباركود هو المعرّف الظاهر في الفواتير والتقارير',
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
          ['الباركود', 'رقم المادة', 'الاسم', 'الفئة', 'الوحدة', 'سعر البيع', 'الحالة', 'إجراءات'],
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
  await companyDecimals.load();
  const unitDp = companyDecimals.unitPlaces();
  const unitStep = companyDecimals.unitStep();
  const fmtPrice = (v) => companyDecimals.formatUnitInput(v);
  const fmtTaxPct = (v) => companyDecimals.formatInput(v, Math.min(unitDp, companyDecimals.amountPlaces()));
  const item = id ? await svc.getItem(id) : null;
  if (id && !item) return res.status(404).send('غير موجود');
  const isNew = !item;
  const err = String(req.query.err || '');
  const flash = String(req.query.msg || '');
  const pricesLocked = !!(item && item.prices_locked);
  const unitsLocked = !!(item && item.units_locked);
  const isActive = item ? Number(item.is_active) === 1 : true;

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
    `<option value="">— بدون مستودع —</option>` +
    (lookups.warehouses || [])
      .map(
        (w) =>
          `<option value="${w.id}"${Number(item?.default_warehouse_id) === Number(w.id) ? ' selected' : ''}>${esc(w.name_ar)}</option>`
      )
      .join('');

  const taxOpts =
    `<option value="">— بدون ضريبة مخصصة —</option>` +
    (lookups.taxRates || [])
      .map(
        (t) =>
          `<option value="${t.id}"${Number(item?.tax_rate_id) === Number(t.id) ? ' selected' : ''}>${esc(t.name_ar)} (${esc(fmtTaxPct(t.rate_percent))}%)</option>`
      )
      .join('');

  const unitOptionsHtml = (lookups.units || [])
    .map((u) => `<option value="${u.id}">${esc(u.name_ar)}</option>`)
    .join('');

  // packing rows (non-base)
  const packUnits = (item?.item_units || []).filter((u) => !Number(u.is_base));
  const packRowsHtml =
    packUnits.length > 0
      ? packUnits
          .map((pu) => {
            const fac = Number(pu.factor_to_base || 1);
            const facStr = Number.isFinite(fac) ? String(fac).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1') : '1';
            const opts = (lookups.units || [])
              .map(
                (u) =>
                  `<option value="${u.id}"${Number(pu.unit_id) === Number(u.id) ? ' selected' : ''}>${esc(u.name_ar)}</option>`
              )
              .join('');
            return `<div class="inv-pack-row" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:.5rem">
              <label style="flex:1.2;min-width:9rem">الوحدة
                <select class="si-field" name="pack_unit_id[]" ${unitsLocked ? 'disabled' : ''}>
                  <option value="">—</option>${opts}
                </select>
              </label>
              <label style="flex:1;min-width:7rem">العدد في الوحدة
                <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1"
                       value="${esc(facStr)}" dir="ltr" ${unitsLocked ? 'readonly' : ''} placeholder="مثال: 24">
              </label>
              ${
                unitsLocked
                  ? ''
                  : `<button type="button" class="si-btn js-pack-remove" style="margin-bottom:.1rem">حذف</button>`
              }
            </div>`;
          })
          .join('')
      : `<div class="inv-pack-row" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:.5rem">
          <label style="flex:1.2;min-width:9rem">الوحدة
            <select class="si-field" name="pack_unit_id[]" ${unitsLocked ? 'disabled' : ''}>
              <option value="">— إضافة وحدة إضافية —</option>${unitOptionsHtml}
            </select>
          </label>
          <label style="flex:1;min-width:7rem">العدد في الوحدة
            <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1" value="" dir="ltr"
                   ${unitsLocked ? 'readonly' : ''} placeholder="قطعة=1 · كرتون=24">
          </label>
          ${unitsLocked ? '' : `<button type="button" class="si-btn js-pack-remove" style="margin-bottom:.1rem">حذف</button>`}
        </div>`;

  const lockNote = pricesLocked
    ? `<p class="si-pill si-pill--lock" style="display:inline-block;margin:0 0 .75rem">
         الأسعار مقفلة بعد حركات على المادة. عدّل سعر الكلفة/البيع/الجملة من الشاشات الخاصة لاحقاً.
       </p>`
    : `<p class="muted" style="margin:0 0 .75rem;font-size:.82rem;line-height:1.5">
         عند أول تعريف تُدخل الأسعار هنا. بعد أي حركة مخزون/فاتورة تُقفل ولا تُعدَّل من هذه البطاقة.
       </p>`;

  const ro = pricesLocked ? 'readonly' : '';
  const expiryVal =
    item?.expiry_date != null ? String(item.expiry_date).slice(0, 10) : '';

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'It',
        kicker: KICKER,
        title: isNew ? 'بطاقة مادة جديدة' : `بطاقة المادة: ${esc(item.name_ar || '')}`,
        subtitle: 'رقم المادة للأغراض الداخلية · الباركود هو الظاهر في الفواتير والتقارير',
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
            <label>رقم المادة
              <input class="si-field si-field--mono" name="sku" value="${esc(item?.sku || '')}" dir="ltr"
                     placeholder="${isNew ? 'تلقائي إن تُرك فارغاً' : ''}" autocomplete="off"
                     title="رقم داخلي — لا يظهر في الفواتير">
            </label>
            <label>باركود المادة *
              <input class="si-field si-field--mono" name="barcode" value="${esc(item?.barcode || defaultBarcode)}"
                     dir="ltr" autocomplete="off" inputmode="numeric" maxlength="14" required
                     title="المعرّف الظاهر في الشاشات والفواتير والتقارير">
            </label>
            <p class="si-span-2 muted" style="margin:0;font-size:.8rem">
              <strong>الباركود</strong> هو ما يظهر في الفواتير والتقارير. <strong>رقم المادة</strong> داخلي فقط.
            </p>

            <label class="si-span-2">اسم المادة بالعربي *
              <input class="si-field" name="name_ar" required value="${esc(item?.name_ar || '')}" autocomplete="off">
            </label>
            <label class="si-span-2">اسم المادة بالإنجليزي
              <input class="si-field" name="name_en" value="${esc(item?.name_en || '')}" dir="ltr" autocomplete="off">
            </label>

            <label>فئة المادة
              <select class="si-field" name="category_id">${catOpts}</select>
            </label>
            <label>المستودع
              <select class="si-field" name="default_warehouse_id">${whOpts}</select>
            </label>
            <label>ضريبة المادة
              <select class="si-field" name="tax_rate_id">${taxOpts}</select>
            </label>
            <label>تاريخ الانتهاء
              <input class="si-field si-field--mono" type="date" name="expiry_date" value="${esc(expiryVal)}" dir="ltr">
            </label>
          </div>

          <div style="margin-top:1.15rem;padding-top:1rem;border-top:1px solid rgba(15,23,42,.08)">
            <h3 style="margin:0 0 .45rem;font-size:.95rem">الأسعار (لأقل وحدة — غير شامل الضريبة)</h3>
            ${lockNote}
            <p class="muted" style="margin:0 0 .65rem;font-size:.8rem;line-height:1.45">
              أدخل <b>سعر الحبة / أقل وحدة</b> فقط. عند البيع بالكرتون (مثلاً 12) يحسب النظام السعر تلقائياً =
              سعر الحبة × 12 في الفاتورة وطلب العميل.
            </p>
            <div class="si-meta">
              <label>سعر الكلفة
                <input class="si-field si-field--mono" name="default_cost" type="number" step="${esc(unitStep)}" min="0"
                       value="${esc(fmtPrice(item?.default_cost != null ? item.default_cost : 0))}" dir="ltr" ${ro}>
              </label>
              <label>سعر البيع
                <input class="si-field si-field--mono" name="default_sale" type="number" step="${esc(unitStep)}" min="0"
                       value="${esc(fmtPrice(item?.default_sale != null ? item.default_sale : 0))}" dir="ltr" ${ro}>
              </label>
              <label>سعر الجملة
                <input class="si-field si-field--mono" name="default_wholesale" type="number" step="${esc(unitStep)}" min="0"
                       value="${esc(fmtPrice(item?.default_wholesale != null ? item.default_wholesale : 0))}" dir="ltr" ${ro}>
              </label>
            </div>
          </div>

          <div style="margin-top:1.15rem;padding-top:1rem;border-top:1px solid rgba(15,23,42,.08)">
            <h3 style="margin:0 0 .45rem;font-size:.95rem">وحدات الصرف والتعبئة</h3>
            <p class="muted" style="margin:0 0 .75rem;font-size:.82rem;line-height:1.5">
              الوحدة الأساسية (مثل <b>قطعة</b>) عددها دائماً 1. أضف وحدة أخرى دون تكرار (مثال: <b>كرتون</b> والعدد 24).
              في الفواتير وطلبات الشراء/المبيعات تُستخدم هذه الوحدات فقط.
              ${unitsLocked ? ' <b>الوحدات مقفلة بعد الحركات.</b>' : ''}
            </p>
            <div class="si-meta" style="margin-bottom:.65rem">
              <label>الوحدة الأساسية *
                <select class="si-field" name="unit_id" id="inv-base-unit" ${lookups.units.length ? 'required' : ''} ${
                  unitsLocked ? 'disabled' : ''
                }>${unitOpts}</select>
              </label>
              <label>العدد بالوحدة الأساسية
                <input class="si-field si-field--mono" type="number" value="1" dir="ltr" readonly>
              </label>
            </div>
            ${unitsLocked ? `<input type="hidden" name="unit_id" value="${esc(String(item?.unit_id || ''))}">` : ''}
            <div id="inv-pack-list">${packRowsHtml}</div>
            ${
              unitsLocked
                ? ''
                : `<button type="button" class="si-btn" id="inv-pack-add" style="margin-top:.25rem">＋ إضافة وحدة أخرى</button>
                   <template id="inv-pack-tpl">
                     <div class="inv-pack-row" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:.5rem">
                       <label style="flex:1.2;min-width:9rem">الوحدة
                         <select class="si-field" name="pack_unit_id[]">
                           <option value="">—</option>${unitOptionsHtml}
                         </select>
                       </label>
                       <label style="flex:1;min-width:7rem">العدد في الوحدة
                         <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1" value="" dir="ltr" placeholder="مثال: 24">
                       </label>
                       <button type="button" class="si-btn js-pack-remove" style="margin-bottom:.1rem">حذف</button>
                     </div>
                   </template>`
            }
          </div>

          <div style="margin-top:1.15rem;padding-top:1rem;border-top:1px solid rgba(15,23,42,.08)">
            <label style="display:flex;align-items:center;gap:.5rem;font-weight:700;cursor:pointer">
              <input type="checkbox" name="is_active" value="1" ${isActive ? 'checked' : ''}>
              <span>المادة نشطة (إلغاء التفعيل يوقف المادة عن البيع والشراء)</span>
            </label>
            <label style="display:flex;align-items:center;gap:.5rem;font-weight:600;margin-top:.65rem;cursor:pointer">
              <input type="checkbox" name="notify_on_expiry" value="1" ${
                item && Number(item.notify_on_expiry) === 1 ? 'checked' : ''
              }>
              <span>تنبيه عند اقتراب/انتهاء الصلاحية</span>
            </label>
            ${
              isNew
                ? `<div class="si-meta" style="margin-top:.85rem">
              <label>رصيد افتتاحي (اختياري)
                <input class="si-field si-field--mono" name="opening_qty" type="number" step="any" min="0" value="" dir="ltr" placeholder="0">
              </label>
            </div>`
                : ''
            }
          </div>

          <div style="display:flex;gap:.5rem;margin-top:1.1rem;flex-wrap:wrap">
            <button class="si-btn si-btn--primary" type="submit">حفظ البطاقة</button>
            <a class="si-btn" href="/inventory/items">القائمة</a>
          </div>
        </form>
      </section>
    </div>
    <script>
    (function(){
      var list = document.getElementById('inv-pack-list');
      var addBtn = document.getElementById('inv-pack-add');
      var tpl = document.getElementById('inv-pack-tpl');
      if (addBtn && tpl && list) {
        addBtn.addEventListener('click', function(){
          var node = tpl.content.cloneNode(true);
          list.appendChild(node);
        });
      }
      document.addEventListener('click', function(e){
        var btn = e.target && e.target.closest && e.target.closest('.js-pack-remove');
        if (!btn) return;
        var row = btn.closest('.inv-pack-row');
        if (!row || !list) return;
        if (list.querySelectorAll('.inv-pack-row').length <= 1) {
          var sel = row.querySelector('select');
          var inp = row.querySelector('input[name="pack_factor[]"]');
          if (sel) sel.value = '';
          if (inp) inp.value = '';
          return;
        }
        row.remove();
      });
    })();
    </script>`;
  res.send(page(req.session.user, isNew ? 'مادة جديدة' : 'بطاقة المادة', body));
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
  const body = { ...(req.body || {}), id };
  // unchecked checkbox = inactive
  if (body.is_active === undefined) body.is_active = '0';
  const result = await svc.saveItem(body);
  if (!result.ok) return res.redirect('/inventory/items/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/inventory/items/' + id + '?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.post('/inventory/items/:id/delete', async (req, res) => {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.deleteItem(req.params.id);
  const msg = result.ok ? result.message : result.error;
  res.redirect('/inventory/items?msg=' + encodeURIComponent(msg || ''));
});

router.post('/inventory/items/:id/toggle', async (req, res) => {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const result = await svc.toggleItemActive(req.params.id);
  res.redirect('/inventory/items?msg=' + encodeURIComponent(result.message || result.error || ''));
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
      <section class="si-surface" style="max-width:32rem">
        <div class="si-surface-head"><h2>${isNew ? 'وحدة جديدة' : 'تعديل'}</h2></div>
        <form method="post" action="${isNew ? '/inventory/units/new' : '/inventory/units/' + id}"
              class="inv-simple-form" style="padding:1rem 1.15rem 1.25rem;display:grid;gap:0.9rem">
          <input type="hidden" name="id" value="${unit ? unit.id : 0}">
          <label style="display:grid;gap:.35rem;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--si-muted,#5c6578)">
            الرمز <span style="font-weight:500;text-transform:none;letter-spacing:0">(فارغ = تلقائي)</span>
            <input class="si-field si-field--mono" name="code" value="${esc(unit?.code || '')}" dir="ltr"
                   placeholder="مثال: BOX" autocomplete="off" style="max-width:12rem">
          </label>
          <label style="display:grid;gap:.35rem;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--si-muted,#5c6578)">
            اسم الوحدة *
            <input class="si-field" name="name_ar" required value="${esc(unit?.name_ar || '')}"
                   placeholder="مثال: كرتونة" autocomplete="off">
          </label>
          <div class="si-form-actions" style="margin:0;padding-top:.75rem">
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
