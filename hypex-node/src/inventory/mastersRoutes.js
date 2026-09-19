'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const auth = require('../auth');
const svc = require('./mastersService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const companyDecimals = require('../lib/companyDecimals');

const router = express.Router();
const KICKER = 'Hypex Inventory · Node';
const HUB = '/hub/inventory';
const ITEM_CARD_PICKER_JS = path.join(__dirname, '..', '..', 'public', 'js', 'item-card-picker.js');

function itemCardPickerJsSrc() {
  let v = String(Date.now());
  try {
    v = String(Math.floor(fs.statSync(ITEM_CARD_PICKER_JS).mtimeMs));
  } catch {
    /* keep */
  }
  return '/assets/js/item-card-picker.js?v=' + v;
}

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

function page(user, title, bodyHtml, opts) {
  opts = opts || {};
  return ui.salesPage({
    user,
    title,
    bodyHtml,
    activePath: '/hub/inventory',
    js: opts.js || [],
    css: opts.css || [],
  });
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
    const viewList = String(req.query.view || '') === 'list';

    // الشاشة تُفتح على بطاقة فارغة بكل الحقول — واختيار المادة من نافذة القائمة.
    if (!viewList) return itemForm(req, res, 0);

    const rows = await svc.listItems({ q: qv, activeOnly: false });
    const colCount = 11;
    const rowsHtml =
      rows
        .map((r) => {
          const sku = String(r.sku || '').trim() || '—';
          const barcode = String(r.barcode || '').trim() || '—';
          const pack = String(r.pack_label || '').trim();
          const nameAr = String(r.name_ar || '').trim();
          const nameEn = String(r.name_en || '').trim();
          const nameTitle = [nameAr, nameEn].filter(Boolean).join(' — ');
          return `<tr class="inv-item-row">
        <td class="si-num inv-item-sku" dir="ltr"><code>${esc(sku)}</code></td>
        <td class="si-num inv-item-barcode" dir="ltr"><code>${esc(barcode)}</code></td>
        <td class="inv-item-name" title="${esc(nameTitle)}"><span class="inv-item-name-ar">${esc(nameAr)}</span></td>
        <td class="inv-item-pack">${pack ? esc(pack) : '<span class="muted">—</span>'}</td>
        <td class="inv-item-cat">${esc(r.category_name || '—')}</td>
        <td>${esc(r.unit_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.default_sale))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.default_wholesale))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.default_cost))}</td>
        <td>${
          Number(r.is_active) === 1
            ? ui.statusPill('ok', 'نشط')
            : ui.statusPill('lock', 'موقوف')
        }</td>
        <td class="inv-item-actions">
          <a class="si-btn si-btn--primary" href="/inventory/items/${r.id}">بطاقة</a>
        </td>
      </tr>`;
        })
        .join('') || ui.emptyRow(colCount);

    const resultLabel = qv.trim()
      ? `${rows.length} نتيجة لـ «${qv.trim()}»`
      : `${rows.length} صف`;

    const body = `
      <style>
        .inv-items-table .si-table-wrap { overflow-x: auto; }
        .inv-items-table .si-table { table-layout: fixed; width: 100%; }
        .inv-items-table .inv-item-sku code,
        .inv-items-table .inv-item-barcode code {
          font-family: ui-monospace, Consolas, monospace;
          font-size: .8rem;
          background: #f1f5f9;
          border: 1px solid #e2e8f0;
          border-radius: 4px;
          padding: .1rem .3rem;
          white-space: nowrap;
        }
        .inv-items-table .inv-item-sku code { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; font-weight: 700; }
        .inv-items-table .inv-item-name {
          max-width: 0;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
          font-size: .9rem;
          font-weight: 700;
          color: #0f172a;
        }
        .inv-items-table .inv-item-name-ar { white-space: nowrap; }
        .inv-items-table .inv-item-pack,
        .inv-items-table .inv-item-cat { font-size: .8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .inv-items-table .inv-item-actions { white-space: nowrap; width: 4.5rem; }
        .inv-items-table .inv-item-actions .si-btn { display: inline-flex; }
        .inv-items-table .si-table th:nth-child(1),
        .inv-items-table .si-table td:nth-child(1) { width: 5.2rem; }
        .inv-items-table .si-table th:nth-child(2),
        .inv-items-table .si-table td:nth-child(2) { width: 8rem; }
        .inv-items-table .si-table th:nth-child(3),
        .inv-items-table .si-table td:nth-child(3) { width: 28%; }
        .inv-items-table .si-table th:nth-child(11),
        .inv-items-table .si-table td:nth-child(11) { width: 4.8rem; }
        .inv-items-table .si-search { display: flex; gap: .45rem; align-items: center; width: 100%; }
        .inv-items-table .si-search input[type="search"] { flex: 1; min-width: 0; }
      </style>
      <div class="si-stage">
        ${ui.hero({
          mark: 'It',
          kicker: KICKER,
          title: 'المواد والأصناف',
          subtitle: 'رقم المادة والباركود ظاهران · ابحث بالاسم أو رقم المادة أو الباركود أو الفئة',
          actions: [
            { label: 'بطاقة المادة', href: '/inventory/items', primary: true },
            { label: '＋ مادة جديدة', href: '/inventory/items/new' },
            { label: 'الفئات', href: '/inventory/categories' },
            { label: 'الوحدات', href: '/inventory/units' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${ui.railSearch('/inventory/items', qv, { view: 'list' })}
        <div class="inv-items-table">
        ${ui.tableSurface(
          'المواد',
          resultLabel,
          [
            'رقم المادة',
            'الباركود',
            'اسم المادة',
            'التعبئة',
            'الفئة',
            'الوحدة',
            'سعر البيع',
            'سعر الجملة',
            'سعر الكلفة',
            'الحالة',
            'إجراءات',
          ],
          rowsHtml
        )}
        </div>
      </div>
      <script>
      (function(){
        var form = document.querySelector('.si-rail .si-search');
        if (!form) return;
        var inp = form.querySelector('input[name="q"]');
        if (!inp) return;
        var t = null;
        inp.addEventListener('keydown', function(e){
          if (e.key === 'Enter') { e.preventDefault(); form.submit(); }
        });
        inp.focus();
        if (inp.value) {
          try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (e) {}
        }
      })();
      </script>`;
    res.send(page(req.session.user, 'المواد والأصناف', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function itemForm(req, res, id) {
  if (!canItems(req.session.user)) return res.status(403).send('ممنوع');
  const lookups = await svc.itemLookups();
  const decSnap = await companyDecimals.load(true);
  const unitDp = decSnap.unit;
  const amountDp = decSnap.amount;
  const unitStep = companyDecimals.unitStep();
  const fmtPrice = (v) => companyDecimals.formatInput(v, unitDp);
  const fmtTaxPct = (v) => {
    // نسب الضريبة: بلا أصفار زائدة (16 بدل 16.000)
    const n = Number(v);
    if (!Number.isFinite(n)) return '0';
    const fixed = n.toFixed(Math.min(6, amountDp));
    return fixed.replace(/\.?0+$/, '') || '0';
  };
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

  // packing rows (non-base) — allow *adding* first pack/carton even when base unit is locked after movements
  const packUnits = (item?.item_units || []).filter((u) => !Number(u.is_base));
  const packFieldsLocked = unitsLocked && packUnits.length > 0;
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
            return `<div class="inv-pack-row">
              <label>الوحدة
                <select class="si-field" name="pack_unit_id[]" ${packFieldsLocked ? 'disabled' : ''}>
                  <option value="">—</option>${opts}
                </select>
              </label>
              <label>العدد في الوحدة
                <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1"
                       value="${esc(facStr)}" dir="ltr" ${packFieldsLocked ? 'readonly' : ''} placeholder="مثال: 24">
              </label>
              ${packFieldsLocked ? '' : `<button type="button" class="si-btn js-pack-remove">حذف</button>`}
            </div>`;
          })
          .join('')
      : `<div class="inv-pack-row">
          <label>الوحدة
            <select class="si-field" name="pack_unit_id[]">
              <option value="">— إضافة وحدة إضافية —</option>${unitOptionsHtml}
            </select>
          </label>
          <label>العدد في الوحدة
            <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1" value="" dir="ltr"
                   placeholder="قطعة=1 · كرتون=24">
          </label>
          <button type="button" class="si-btn js-pack-remove">حذف</button>
        </div>`;

  let nav = { prev_id: 0, next_id: 0, first_id: 0, last_id: 0 };
  try {
    const { neighbors } = require('../lib/docBrowse');
    nav = await neighbors('inv_item', id || 0);
  } catch {
    /* ignore */
  }
  const navId = item ? Number(item.id) : 0;
  const prevHref = nav.prev_id ? '/inventory/items/' + nav.prev_id : '#';
  const nextHref = nav.next_id ? '/inventory/items/' + nav.next_id : '#';
  const firstHref = nav.first_id ? '/inventory/items/' + nav.first_id : '#';
  const lastHref = nav.last_id ? '/inventory/items/' + nav.last_id : '#';

  const catName =
    (lookups.categories || []).find((c) => Number(c.id) === Number(item?.category_id))?.name_ar || '—';
  const unitName =
    (lookups.units || []).find((u) => Number(u.id) === Number(item?.unit_id))?.name_ar || '—';
  const whName =
    (lookups.warehouses || []).find((w) => Number(w.id) === Number(item?.default_warehouse_id))?.name_ar ||
    '—';

  const lockNote = pricesLocked
    ? `<p class="ic-warn">الأسعار مقفلة بعد حركات على المادة — عدّلها من شاشات الأسعار المخصصة.</p>`
    : `<p class="muted" style="margin:0 0 4px;font-size:10px;line-height:1.35">سعر الحبة فقط؛ الكرتون = سعر الحبة × العدد.</p>`;

  const ro = pricesLocked ? 'readonly' : '';
  const expiryVal =
    item?.expiry_date != null ? String(item.expiry_date).slice(0, 10) : '';

  const movesHref = item
    ? '/inventory/reports/item-moves?item_id=' + encodeURIComponent(String(item.id))
    : '/inventory/reports/item-moves';

  const body = `
    <div class="si-stage co-ora-skin ic-card-stage">
      ${err ? `<div class="ic-flash">${alertHtml('err', err)}</div>` : ''}
      ${flash ? `<div class="ic-flash">${alertHtml('ok', flash)}</div>` : ''}

      <div class="si-cmd si-doc-toolbar" role="toolbar" aria-label="إجراءات بطاقة المادة">
        <div class="si-tb-group si-tb-group--core">
          <button type="submit" form="item-form" class="si-tb si-tb--save" data-hx-save="1">
            <span class="si-tb-lbl">حفظ</span>
            <span class="si-tb-keywrap"><kbd class="si-tb-key">F10</kbd><span class="si-tb-keydesc">حفظ</span></span>
          </button>
          <a class="si-tb" href="/inventory/items/new"><span class="si-tb-lbl">جديد</span></a>
          <button type="button" class="si-tb si-tb--accent" data-hx-item-picker="1">
            <span class="si-tb-lbl">تحديد المادة</span>
          </button>
          <a class="si-tb" href="${esc(movesHref)}" ${isNew ? 'tabindex="-1" style="pointer-events:none;opacity:.45"' : ''}>
            <span class="si-tb-lbl">كشف حركات</span>
          </a>
        </div>
        <div class="si-tb-group">
          <a class="si-tb" href="/inventory/categories"><span class="si-tb-lbl">الفئات</span></a>
          <a class="si-tb" href="/inventory/units"><span class="si-tb-lbl">الوحدات</span></a>
          <a class="si-tb si-tb--ghost" href="/inventory/items?view=list"><span class="si-tb-lbl">القائمة</span></a>
          <form method="post" action="/inventory/items/${item ? item.id : 0}/delete" style="display:inline"
                onsubmit="return confirm('حذف المادة نهائياً؟ لا يمكن التراجع.');">
            <button type="submit" class="si-tb si-tb--danger" ${isNew ? 'disabled' : ''}>
              <span class="si-tb-lbl">حذف</span>
            </button>
          </form>
        </div>
        <div class="si-tb-group si-tb-group--status">
          <span class="si-msg" id="ic-msg">${isNew ? 'اختر مادة من القائمة بالأسفل' : esc(item.name_ar || '')}</span>
        </div>
      </div>

      <section class="ic-list-dock" id="ic-pick" aria-label="قائمة المواد">
        <header class="ic-list-dock__head">
          <div>
            <h2 class="ic-list-dock__title">قائمة المواد</h2>
            <p class="ic-list-dock__sub muted">رقم المادة · الباركود · الاسم — مثل طلبات الشراء</p>
          </div>
          <div class="ic-list-dock__search">
            <input type="search" id="ic-pick-q" class="si-field" placeholder="بحث: رقم مادة / باركود / اسم…"
                   autocomplete="off">
          </div>
        </header>
        <div class="ic-list-dock__cols" aria-hidden="true">
          <span>رقم المادة</span>
          <span>الباركود</span>
          <span>اسم المادة</span>
        </div>
        <div class="ic-list-dock__hint muted" id="ic-pick-hint">جاري التحميل…</div>
        <div class="ic-list-dock__list" id="ic-pick-list" role="listbox" aria-label="نتائج المواد"
             data-current-id="${navId || 0}"></div>
        <footer class="ic-list-dock__foot">
          <span class="ic-card-nav" title="تنقّل بين المواد">
            <a href="${esc(firstHref)}" class="${nav.first_id ? '' : 'is-disabled'}" title="أول">«</a>
            <a href="${esc(prevHref)}" class="${nav.prev_id ? '' : 'is-disabled'}" title="السابق">‹</a>
            <input type="text" class="ic-card-nav-id" id="ic-card-nav-id" dir="ltr"
                   inputmode="numeric" autocomplete="off" spellcheck="false"
                   value="${navId || ''}" placeholder="—"
                   title="اكتب رقم البطاقة ثم Enter" aria-label="رقم البطاقة">
            <a href="${esc(nextHref)}" class="${nav.next_id ? '' : 'is-disabled'}" title="التالي">›</a>
            <a href="${esc(lastHref)}" class="${nav.last_id ? '' : 'is-disabled'}" title="آخر">»</a>
          </span>
          <span class="ic-list-dock__sel" id="ic-list-sel">${isNew ? '—' : esc(item.name_ar || '')}</span>
        </footer>
      </section>

      <form id="item-form" method="post" action="${isNew ? '/inventory/items/new' : '/inventory/items/' + id}">
        <input type="hidden" name="id" value="${item ? item.id : 0}">

        <div class="ic-panel">
          <div class="ic-panel-cap">بطاقة المادة ${isNew ? '— جديدة' : '— ' + esc(String(item.barcode || item.sku || item.id || ''))}</div>
          <div class="ic-top-grid">
            <div class="ic-col">
              <div class="ic-row">
                <span class="ic-lab">الاسم الرئيسي *</span>
                <input class="si-field" name="name_ar" required value="${esc(item?.name_ar || '')}" autocomplete="off">
              </div>
              <div class="ic-row">
                <span class="ic-lab">الاسم الثانوي</span>
                <input class="si-field" name="name_en" value="${esc(item?.name_en || '')}" dir="ltr" autocomplete="off">
              </div>
              <div class="ic-row">
                <span class="ic-lab">التصنيف الرئيسي</span>
                <select class="si-field" name="category_id">${catOpts}</select>
              </div>
              <div class="ic-row">
                <span class="ic-lab">المستودع</span>
                <select class="si-field" name="default_warehouse_id">${whOpts}</select>
              </div>
              <div class="ic-row">
                <span class="ic-lab">رقم المادة</span>
                <input class="si-field si-field--mono" name="sku" value="${esc(item?.sku || '')}" dir="ltr"
                       placeholder="${isNew ? 'تلقائي إن تُرك فارغاً' : ''}" autocomplete="off"
                       title="رقم داخلي — لا يظهر في الفواتير">
              </div>
            </div>

            <div class="ic-col">
              <div class="ic-row">
                <span class="ic-lab">الباركود *</span>
                <input class="si-field si-field--mono" name="barcode" value="${esc(item?.barcode || defaultBarcode)}"
                       dir="ltr" autocomplete="off" inputmode="numeric" maxlength="14" required>
              </div>
              <div class="ic-price-box" data-hx-price-fields="1" data-unit-dp="${unitDp}" data-amount-dp="${amountDp}">
                <div class="ic-row">
                  <span class="ic-lab">وحدة القياس *</span>
                  <select class="si-field" name="unit_id" id="inv-base-unit" ${lookups.units.length ? 'required' : ''} ${
                    unitsLocked ? 'disabled' : ''
                  }>${unitOpts}</select>
                </div>
                ${unitsLocked ? `<input type="hidden" name="unit_id" value="${esc(String(item?.unit_id || ''))}">` : ''}
                <div class="ic-row">
                  <span class="ic-lab">تكلفة أساسية</span>
                  <input class="si-field si-field--mono js-hx-unit-price" name="default_cost" type="text" inputmode="decimal"
                         step="${esc(unitStep)}" min="0" data-dp="${unitDp}"
                         value="${esc(fmtPrice(item?.default_cost != null ? item.default_cost : 0))}" dir="ltr" ${ro}
                         autocomplete="off">
                </div>
                <div class="ic-row">
                  <span class="ic-lab">سعر البيع</span>
                  <input class="si-field si-field--mono js-hx-unit-price" name="default_sale" type="text" inputmode="decimal"
                         step="${esc(unitStep)}" min="0" data-dp="${unitDp}"
                         value="${esc(fmtPrice(item?.default_sale != null ? item.default_sale : 0))}" dir="ltr" ${ro}
                         autocomplete="off">
                </div>
                <div class="ic-row">
                  <span class="ic-lab">سعر الجملة</span>
                  <input class="si-field si-field--mono js-hx-unit-price" name="default_wholesale" type="text" inputmode="decimal"
                         step="${esc(unitStep)}" min="0" data-dp="${unitDp}"
                         value="${esc(fmtPrice(item?.default_wholesale != null ? item.default_wholesale : 0))}" dir="ltr" ${ro}
                         autocomplete="off">
                </div>
                <div class="ic-row">
                  <span class="ic-lab">الضريبة</span>
                  <select class="si-field" name="tax_rate_id">${taxOpts}</select>
                </div>
              </div>
              ${lockNote}
            </div>

            <div class="ic-col ic-media">
              <div class="ic-photo" aria-hidden="true">صورة الصنف<br><span style="font-size:10px">(قريباً)</span></div>
              <div class="ic-flags">
                <label>
                  <input type="checkbox" name="is_active" value="1" ${isActive ? 'checked' : ''}>
                  <span>المادة نشطة / ظاهرة للبيع</span>
                </label>
                <label>
                  <input type="checkbox" name="notify_on_expiry" value="1" ${
                    item && Number(item.notify_on_expiry) === 1 ? 'checked' : ''
                  }>
                  <span>تنبيه الصلاحية</span>
                </label>
              </div>
              <div class="ic-row">
                <span class="ic-lab">تاريخ الانتهاء</span>
                <input class="si-field si-field--mono" type="date" name="expiry_date" value="${esc(expiryVal)}" dir="ltr">
              </div>
              ${
                isNew
                  ? `<div class="ic-row">
                <span class="ic-lab">رصيد افتتاحي</span>
                <input class="si-field si-field--mono" name="opening_qty" type="number" step="any" min="0" value="" dir="ltr" placeholder="0">
              </div>`
                  : ''
              }
              <p class="ic-warn">* تعديل التعبئة يؤثر في التقارير بعد وجود حركات.</p>
            </div>
          </div>
        </div>

        <div class="ic-tabs" role="tablist">
          <button type="button" class="ic-tab is-active" data-ic-tab="opts" role="tab">خيارات المادة</button>
          <button type="button" class="ic-tab" data-ic-tab="pack" role="tab">التعبئة والوحدات</button>
          <button type="button" class="ic-tab" data-ic-tab="info" role="tab">معلومات</button>
        </div>
        <div class="ic-tab-panels">
          <div class="ic-tab-panel" data-ic-panel="opts" role="tabpanel">
            <div class="ic-opt-grid">
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">الحالة</h3>
                <div class="ic-flags">
                  <label><input type="checkbox" ${isActive ? 'checked' : ''} disabled> يمكن بيعها</label>
                  <label><input type="checkbox" ${isActive ? 'checked' : ''} disabled> يمكن شراؤها</label>
                  <label><input type="checkbox" ${item && Number(item.notify_on_expiry) === 1 ? 'checked' : ''} disabled> تتبع الصلاحية</label>
                </div>
                <p class="muted" style="margin:.55rem 0 0;font-size:10px;line-height:1.4">
                  التفعيل/الإيقاف من خانة «المادة نشطة» أعلاه. تتبع الصلاحية من «تنبيه الصلاحية».
                </p>
              </div>
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">الوحدة الأساسية</h3>
                <div class="ic-row">
                  <span class="ic-lab">العدد الأساسي</span>
                  <input class="si-field si-field--mono" type="number" value="1" dir="ltr" readonly>
                </div>
                <p class="muted" style="margin:.45rem 0 0;font-size:10px;line-height:1.4">
                  الوحدة الأساسية دائماً 1. عبّئ الكرتون من تبويب التعبئة.
                </p>
              </div>
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">عرض الأسعار</h3>
                <div class="ic-row">
                  <span class="ic-lab">خانات سعر الوحدة</span>
                  <input class="si-field si-field--mono" value="${esc(String(unitDp))}" dir="ltr" readonly>
                </div>
                <div class="ic-row">
                  <span class="ic-lab">خانات النظام</span>
                  <input class="si-field si-field--mono" value="${esc(String(amountDp))}" dir="ltr" readonly>
                </div>
              </div>
            </div>
          </div>
          <div class="ic-tab-panel" data-ic-panel="pack" role="tabpanel" hidden>
            <h3 class="ic-opt-title">وحدات الصرف والتعبئة</h3>
            <p class="muted" style="margin:0 0 8px;font-size:11px;line-height:1.45">
              أضف وحدة دون تكرار (مثال: كرتون والعدد 24).
              ${
                packFieldsLocked
                  ? ' <b>الوحدات مقفلة بعد الحركات.</b>'
                  : unitsLocked
                    ? ' الوحدة الأساسية مقفلة — يمكنك إضافة وحدة التعبئة إن لم تُعرَّف.'
                    : ''
              }
            </p>
            <div id="inv-pack-list" class="ic-pack-list">${packRowsHtml}</div>
            ${
              packFieldsLocked
                ? ''
                : `<button type="button" class="si-btn" id="inv-pack-add" style="margin-top:4px">＋ إضافة وحدة أخرى</button>
                   <template id="inv-pack-tpl">
                     <div class="inv-pack-row">
                       <label>الوحدة
                         <select class="si-field" name="pack_unit_id[]">
                           <option value="">—</option>${unitOptionsHtml}
                         </select>
                       </label>
                       <label>العدد في الوحدة
                         <input class="si-field si-field--mono" name="pack_factor[]" type="number" step="1" min="1" value="" dir="ltr" placeholder="مثال: 24">
                       </label>
                       <button type="button" class="si-btn js-pack-remove">حذف</button>
                     </div>
                   </template>`
            }
          </div>
          <div class="ic-tab-panel" data-ic-panel="info" role="tabpanel" hidden>
            <div class="ic-opt-grid">
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">المعرّفات</h3>
                <div class="ic-row"><span class="ic-lab">المعرّف</span><input class="si-field si-field--mono" value="${esc(String(item?.id || '—'))}" dir="ltr" readonly></div>
                <div class="ic-row"><span class="ic-lab">الباركود</span><input class="si-field si-field--mono" value="${esc(item?.barcode || '')}" dir="ltr" readonly></div>
                <div class="ic-row"><span class="ic-lab">رقم المادة</span><input class="si-field si-field--mono" value="${esc(item?.sku || '')}" dir="ltr" readonly></div>
              </div>
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">التصنيف</h3>
                <div class="ic-row"><span class="ic-lab">الفئة</span><input class="si-field" value="${esc(catName)}" readonly></div>
                <div class="ic-row"><span class="ic-lab">الوحدة</span><input class="si-field" value="${esc(unitName)}" readonly></div>
                <div class="ic-row"><span class="ic-lab">المستودع</span><input class="si-field" value="${esc(whName)}" readonly></div>
              </div>
              <div class="ic-opt-col">
                <h3 class="ic-opt-title">تنبيه</h3>
                <p class="muted" style="margin:0;font-size:11px;line-height:1.5">
                  الباركود هو الظاهر في الفواتير والتقارير. رقم المادة داخلي للبطاقة فقط.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="ic-panel">
          <div class="ic-meta-bar">
            <span>البطاقة: <strong dir="ltr">${isNew ? 'جديد' : esc(String(item.id))}</strong></span>
            <span class="muted">${pricesLocked ? 'أسعار مقفلة · ' : ''}${unitsLocked ? 'وحدات أساسية مقفلة' : 'قابل للتعديل'}</span>
            <button class="si-btn si-btn--primary" type="submit">حفظ البطاقة</button>
          </div>
        </div>
      </form>
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
      document.querySelectorAll('.ic-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
          var id = tab.getAttribute('data-ic-tab');
          document.querySelectorAll('.ic-tab').forEach(function(t){ t.classList.toggle('is-active', t === tab); });
          document.querySelectorAll('.ic-tab-panel').forEach(function(p){
            p.hidden = p.getAttribute('data-ic-panel') !== id;
          });
        });
      });
    })();
    </script>
    <script>
    (function(){
      function unitDp() {
        if (window.HxDec && typeof window.HxDec.unitPlaces === 'function') return window.HxDec.unitPlaces();
        var c = window.__HYPEX_DECIMALS__ || {};
        var u = Number(c.unit);
        if (!Number.isFinite(u) || u < 0) u = 3;
        return Math.max(0, Math.min(6, Math.floor(u)));
      }
      function fmtPriceInput(v) {
        var d = unitDp();
        var n = Number(String(v == null ? '' : v).replace(/,/g, '').trim());
        if (!Number.isFinite(n)) n = 0;
        var f = Math.pow(10, d);
        n = Math.round((n + Number.EPSILON) * f) / f;
        return n.toFixed(d);
      }
      function applyPriceDp() {
        document.querySelectorAll('input.js-hx-unit-price, input[name="default_cost"], input[name="default_sale"], input[name="default_wholesale"]').forEach(function(el){
          el.value = fmtPriceInput(el.value);
        });
      }
      applyPriceDp();
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPriceDp);
      }
      window.addEventListener('load', applyPriceDp);
    })();
    </script>`;
  res.send(
    page(req.session.user, isNew ? 'مادة جديدة' : 'بطاقة المادة', body, {
      js: [itemCardPickerJsSrc()],
      css: ['/assets/css/item-card-ora.css'],
    })
  );
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
