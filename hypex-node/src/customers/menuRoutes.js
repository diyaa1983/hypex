'use strict';

const express = require('express');
const path = require('path');
const fs = require('fs');
const multer = require('multer');
const auth = require('../auth');
const q = require('./domainQueries');
const masters = require('./mastersService');
const ui = require('../lib/salesUi');
const { customersCatalog } = require('./catalog');
const { esc } = require('../lib/html');

const router = express.Router();
const HUB = '/customers';
const KICKER = 'Hypex Customers · Node';

const importUploadDir = path.join(masters.hypexRoot(), 'uploads');
try {
  fs.mkdirSync(importUploadDir, { recursive: true });
} catch {
  /* */
}
const excelUpload = multer({
  storage: multer.diskStorage({
    destination: (_req, _file, cb) => cb(null, importUploadDir),
    filename: (_req, file, cb) => {
      const safe = String(file.originalname || 'import.xlsx')
        .replace(/[^\w.\-\u0600-\u06FF]+/g, '_')
        .slice(0, 80);
      cb(null, 'region_import_' + Date.now() + '_' + safe);
    },
  }),
  limits: { fileSize: 20 * 1024 * 1024 },
  fileFilter: (_req, file, cb) => {
    const name = String(file.originalname || '').toLowerCase();
    if (name.endsWith('.xlsx')) return cb(null, true);
    cb(new Error('يُقبل ملف Excel بصيغة .xlsx فقط.'));
  },
});

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

/** CSV بترميز UTF-8 مع BOM ليفتحه Excel بالعربية */
function sendExcelCsv(res, filename, tableRows) {
  const csvCell = (v) => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
  const body =
    '\uFEFF' +
    tableRows.map((row) => row.map(csvCell).join(',')).join('\r\n') +
    '\r\n';
  const safe = String(filename || 'export')
    .replace(/[^\w.\-]+/g, '_')
    .slice(0, 80);
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${safe}.csv"`);
  res.setHeader('Cache-Control', 'no-store');
  return res.send(body);
}

function plainDash(v) {
  const s = v == null || v === '' ? '' : String(v).trim();
  return s === '' ? '' : s;
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
  const showImport = String(req.query.import || '') === '1' || !!err && String(req.query.focus || '') === 'import';
  const qsKeep = `${qv ? '&q=' + encodeURIComponent(qv) : ''}${showAll ? '&all=1' : ''}`;
  let importWarningsHtml = '';
  try {
    const rawWarn = String(req.query.warn || '');
    if (rawWarn) {
      const list = JSON.parse(Buffer.from(rawWarn, 'base64url').toString('utf8'));
      if (Array.isArray(list) && list.length) {
        importWarningsHtml = `<div class="rg-import-warn"><strong>تنبيهات (${list.length}):</strong><ul>${list
          .slice(0, 15)
          .map((w) => `<li>${ui.esc(String(w))}</li>`)
          .join('')}${list.length > 15 ? `<li>… و ${list.length - 15} أخرى</li>` : ''}</ul></div>`;
      }
    }
  } catch {
    /* */
  }

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
      .rg-import{margin:.15rem 0 .85rem;padding:1rem 1.1rem;border:1px solid #dbe4f0;border-radius:14px;background:linear-gradient(180deg,#f8fbff,#fff)}
      .rg-import h3{margin:0 0 .35rem;font-size:.98rem;font-weight:800}
      .rg-import p{margin:0 0 .65rem;font-size:.84rem;color:#64748b;line-height:1.5}
      .rg-import-form{display:flex;flex-wrap:wrap;gap:.55rem;align-items:end}
      .rg-import-form label{display:grid;gap:.3rem;font-size:.75rem;font-weight:700;color:#64748b;flex:1;min-width:12rem}
      .rg-import-cols{margin:.65rem 0 0;padding:.55rem .7rem;border-radius:10px;background:#f1f5f9;font-size:.8rem;color:#475569;line-height:1.55}
      .rg-import-cols code{font-size:.78rem;background:#fff;padding:.05rem .3rem;border-radius:4px}
      .rg-import-warn{margin:.65rem 0 0;padding:.55rem .75rem;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;font-size:.82rem;color:#9a3412}
      .rg-import-warn ul{margin:.35rem 0 0;padding-inline-start:1.1rem}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rg',
        kicker: KICKER,
        title: 'تعريف المناطق',
        subtitle: 'عرّف المنطقة ثم أضف بداخلها العناوين — أو استورد ربط العملاء من Excel',
        actions: [
          { label: '＋ منطقة جديدة', href: '/customers/regions?new=1' + qsKeep, primary: true },
          { label: 'استيراد Excel', href: '/customers/regions?import=1' + qsKeep },
          { label: 'العملاء', href: '/customers/list' },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block;max-width:100%;white-space:normal;line-height:1.45">${ui.esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block;max-width:100%;white-space:normal;line-height:1.45">${ui.esc(err)}</p>` : ''}
      ${importWarningsHtml}
      <section class="rg-import" id="rg-import" ${showImport ? '' : 'hidden'}>
        <h3>استيراد Excel — ربط العميل · المنطقة · العنوان · المندوب</h3>
        <p>
          ارفع ملف <strong>.xlsx</strong> بالأعمدة التالية (يمكن إعادة ترتيبها، المهم عناوين الأعمدة):
        </p>
        <div class="rg-import-cols" dir="rtl">
          <code>رقم العميل</code> ·
          <code>اسم العميل</code> ·
          <code>العنوان</code> ·
          <code>المنطقة</code> ·
          <code>اسم المندوب</code>
          <br>مثال: 11200612 · مركز حبيبه / الرابيه · الرابيه · عمان الغربية · إبراهيم
        </div>
        <form class="rg-import-form" method="post" action="/customers/regions/import" enctype="multipart/form-data" style="margin-top:.85rem">
          <label>ملف Excel
            <input class="si-field" type="file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          </label>
          <label style="flex:0;min-width:auto;flex-direction:row;align-items:center;gap:.4rem;padding-bottom:.45rem">
            <input type="checkbox" name="replace_reps" value="1" checked>
            استبدال مندوب العميل بالمندوب من الملف
          </label>
          <button class="si-btn si-btn--primary" type="submit">تنفيذ الاستيراد</button>
          <a class="si-btn" href="/customers/regions${qsKeep ? '?' + qsKeep.slice(1) : ''}">إخفاء</a>
        </form>
        <p class="rg-hint" style="margin-top:.65rem">
          يُنشئ المناطق والعناوين والمندوبين تلقائياً إن لم يكونوا موجودين، ويربط العميل الموجود برمزه في النظام (مثل 11200612).
          العملاء غير الموجودين في قاعدة البيانات يُذكرون كتنبيه دون إيقاف الاستيراد.
        </p>
      </section>
      <div class="si-rail">
        <form class="si-search rg-filters" method="get" action="/customers/regions" style="max-width:100%;margin:0">
          ${focusId && !wantsNew ? `<input type="hidden" name="id" value="${focusId}">` : ''}
          <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث منطقة أو عنوان…" autocomplete="off" style="flex:1;min-width:10rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفة
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          <a class="si-btn" href="/customers/regions?import=1${qsKeep}">استيراد Excel</a>
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

router.post('/customers/regions/import', guard('customer_regions'), (req, res) => {
  excelUpload.single('excel_file')(req, res, async (uploadErr) => {
    if (uploadErr) {
      return res.redirect(
        '/customers/regions?import=1&err=' + encodeURIComponent(uploadErr.message || 'فشل رفع الملف')
      );
    }
    const file = req.file;
    if (!file || !file.path) {
      return res.redirect('/customers/regions?import=1&err=' + encodeURIComponent('اختر ملف Excel (.xlsx).'));
    }
    const replaceReps =
      !req.body || req.body.replace_reps === undefined
        ? true
        : req.body.replace_reps === '1' ||
          req.body.replace_reps === 'on' ||
          req.body.replace_reps === true ||
          req.body.replace_reps === 'true';
    try {
      const result = await masters.importRegionCustomerExcel(req.session.user.id, file.path, {
        replaceReps,
      });
      try {
        fs.unlinkSync(file.path);
      } catch {
        /* keep for debug */
      }
      if (!result.ok) {
        return res.redirect(
          '/customers/regions?import=1&err=' +
            encodeURIComponent(result.error || result.message || 'فشل الاستيراد')
        );
      }
      let qs =
        '/customers/regions?msg=' + encodeURIComponent(result.message || 'تم الاستيراد');
      const warns = Array.isArray(result.warnings) ? result.warnings : [];
      if (warns.length) {
        try {
          qs +=
            '&warn=' +
            Buffer.from(JSON.stringify(warns.slice(0, 40)), 'utf8').toString('base64url');
        } catch {
          /* */
        }
      }
      return res.redirect(qs);
    } catch (e) {
      try {
        fs.unlinkSync(file.path);
      } catch {
        /* */
      }
      return res.redirect(
        '/customers/regions?import=1&err=' + encodeURIComponent(e.message || 'فشل الاستيراد')
      );
    }
  });
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

/* ── مزامنة / ربط عملاء Oracle (واجهة Node كاملة) ── */
const { registerOracleSyncRoutes } = require('./oracleSyncRoutes');
registerOracleSyncRoutes(router, { guard, ui, q, HUB, KICKER });

/* ── Reports ── */
router.get('/customers/reports/list', guard('report_customers'), async (req, res) => {
  const activeOnly = String(req.query.active_only || '') === '1';
  const wantExcel = String(req.query.excel || '') === '1';
  const rows = await q.reportCustomers({ activeOnly });
  const active = rows.filter((r) => Number(r.is_active) === 1).length;
  const inactive = rows.length - active;

  if (wantExcel) {
    const table = [
      ['#', 'الرمز', 'الاسم', 'الهاتف', 'البريد', 'ضريبي', 'المنطقة', 'العنوان', 'المندوب', 'الحالة'],
    ];
    rows.forEach((r, i) => {
      table.push([
        i + 1,
        plainDash(r.customer_code),
        plainDash(r.customer_name),
        plainDash(r.phone),
        plainDash(r.email),
        plainDash(r.tax_number),
        plainDash(r.region_name),
        plainDash(r.region_address_name),
        plainDash(r.sales_rep_name),
        Number(r.is_active) === 1 ? 'نشط' : 'موقوف',
      ]);
    });
    return sendExcelCsv(res, 'customers_report', table);
  }

  const excelQs = new URLSearchParams();
  if (activeOnly) excelQs.set('active_only', '1');
  excelQs.set('excel', '1');
  const excelHref = '/customers/reports/list?' + excelQs.toString();

  const filtersHtml = `
    <div class="si-rail no-print">
      <form class="si-search" method="get" action="/customers/reports/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="active_only" value="1" ${activeOnly ? 'checked' : ''}> العملاء النشطون فقط
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
        <a class="si-btn" href="${ui.esc(excelHref)}">Excel</a>
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
      <td>${dash(r.region_address_name)}</td>
      <td>${dash(r.sales_rep_name)}</td>
      <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(10);

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: 'R1',
        kicker: KICKER,
        title: 'تقرير العملاء',
        subtitle: `${rows.length} عميل · نشط ${active} · موقوف ${inactive}`,
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'Excel', href: excelHref },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${filtersHtml}
      <div class="si-print-area">
        ${ui.tableSurface(
          'تقرير العملاء',
          `${rows.length} عميل`,
          ['#', 'الرمز', 'الاسم', 'الهاتف', 'البريد', 'ضريبي', 'المنطقة', 'العنوان', 'المندوب', 'الحالة'],
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
  const wantExcel = String(req.query.excel || '') === '1';
  const reps = await q.salesRepOptions();
  const rows = await q.reportCustomersByRep({ activeOnly, salesRepId });

  if (wantExcel) {
    const table = [
      ['#', 'رمز المندوب', 'المندوب', 'رمز العميل', 'اسم العميل', 'المنطقة', 'العنوان', 'الهاتف', 'البريد', 'الحالة'],
    ];
    rows.forEach((r, i) => {
      table.push([
        i + 1,
        plainDash(r.rep_code),
        plainDash(r.rep_name),
        plainDash(r.customer_code),
        plainDash(r.customer_name),
        plainDash(r.region_name),
        plainDash(r.region_address_name),
        plainDash(r.phone),
        plainDash(r.email),
        Number(r.is_active) === 1 ? 'نشط' : 'موقوف',
      ]);
    });
    const fname =
      salesRepId > 0 ? 'customers_by_rep_' + salesRepId : 'customers_by_rep';
    return sendExcelCsv(res, fname, table);
  }

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

  const excelQs = new URLSearchParams();
  if (salesRepId > 0) excelQs.set('sales_rep_id', String(salesRepId));
  if (activeOnly) excelQs.set('active_only', '1');
  excelQs.set('excel', '1');
  const excelHref = '/customers/reports/by-rep?' + excelQs.toString();

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
        <a class="si-btn" href="${ui.esc(excelHref)}">Excel</a>
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
          <td>${dash(r.region_name)}</td>
          <td>${dash(r.region_address_name)}</td>
          <td>${dash(r.rep_name)}</td>
          <td class="si-num" dir="ltr">${dash(r.phone)}</td>
          <td>${dash(r.email)}</td>
          <td>${Number(r.is_active) === 1 ? 'نشط' : 'موقوف'}</td>
        </tr>`
        )
        .join('');
      const title = `المندوب: ${g.rep_name}${g.rep_code ? ' (' + g.rep_code + ')' : ''}`;
      blocks += `<div style="margin-top:.75rem">${ui.tableSurface(
        title,
        `${g.rows.length} عميل · نشط ${g.active}`,
        ['#', 'الرمز', 'الاسم', 'المنطقة', 'العنوان', 'المندوب', 'الهاتف', 'البريد', 'الحالة'],
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
          { label: 'Excel', href: excelHref },
          { label: 'لوحة العملاء', href: HUB },
          { label: 'تقرير كامل', href: '/customers/reports/by-rep' },
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

router.get('/customers/reports/region-addresses', guard('report_customers_region_addresses'), async (req, res) => {
  const activeOnly = String(req.query.active_only || '') === '1';
  const regionId = Number(req.query.region_id || 0) || 0;
  const wantExcel = String(req.query.excel || '') === '1';
  const regions = await q.regionOptions();
  const rows = await q.reportRegionAddresses({ activeOnly, regionId });

  if (wantExcel) {
    const table = [['#', 'رمز المنطقة', 'المنطقة', 'العنوان', 'المندوب', 'عدد العملاء', 'حالة المنطقة', 'حالة العنوان']];
    rows.forEach((r, i) => {
      table.push([
        i + 1,
        plainDash(r.region_code),
        plainDash(r.region_name),
        plainDash(r.address_name) || '— بدون عنوان —',
        plainDash(r.sales_rep_name),
        Number(r.customer_count || 0),
        Number(r.region_active) === 1 ? 'نشط' : 'موقوف',
        Number(r.address_id) > 0 ? (Number(r.address_active) === 1 ? 'نشط' : 'موقوف') : '',
      ]);
    });
    const fname = regionId > 0 ? 'region_addresses_' + regionId : 'region_addresses';
    return sendExcelCsv(res, fname, table);
  }

  const groups = new Map();
  for (const r of rows) {
    const key = String(r.region_id || 0);
    if (!groups.has(key)) {
      groups.set(key, {
        region_id: Number(r.region_id || 0),
        region_name: r.region_name || '—',
        region_code: r.region_code || '',
        region_active: Number(r.region_active) === 1,
        rows: [],
        withRep: 0,
      });
    }
    const g = groups.get(key);
    g.rows.push(r);
    if (String(r.sales_rep_name || '').trim()) g.withRep += 1;
  }

  const regionOpts = regions
    .map(
      (r) =>
        `<option value="${r.id}" ${regionId === Number(r.id) ? 'selected' : ''}>${ui.esc(r.name_ar)}${
          r.code ? ' (' + ui.esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');

  const excelQs = new URLSearchParams();
  if (regionId > 0) excelQs.set('region_id', String(regionId));
  if (activeOnly) excelQs.set('active_only', '1');
  excelQs.set('excel', '1');
  const excelHref = '/customers/reports/region-addresses?' + excelQs.toString();

  const filtersHtml = `
    <div class="si-rail no-print">
      <form class="si-search" method="get" action="/customers/reports/region-addresses" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.35rem">المنطقة
          <select name="region_id" class="si-field" style="min-height:2.1rem;width:auto;min-width:12rem">
            <option value="0">كل المناطق</option>
            ${regionOpts}
          </select>
        </label>
        <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
          <input type="checkbox" name="active_only" value="1" ${activeOnly ? 'checked' : ''}> النشطة فقط
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
        <a class="si-btn" href="${ui.esc(excelHref)}">Excel</a>
      </form>
    </div>
    <div class="si-print-meta print-only">
      <strong>تقرير العناوين والمنطقة</strong>
      · ${groups.size} منطقة · ${rows.length} عنوان/صف
      · طُبع: <span class="si-print-when" dir="ltr"></span>
    </div>`;

  let blocks = '';
  if (groups.size === 0) {
    blocks = ui.tableSurface('النتيجة', '0', ['—'], ui.emptyRow(1, 'لا توجد مناطق مطابقة'));
  } else {
    for (const g of groups.values()) {
      const html = g.rows
        .map(
          (r, i) => `<tr>
          <td class="si-num" dir="ltr">${i + 1}</td>
          <td>${dash(r.address_name) === '—' ? '<span class="muted">— بدون عنوان —</span>' : dash(r.address_name)}</td>
          <td>${dash(r.sales_rep_name)}</td>
          <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
          <td>${
            Number(r.address_id) > 0
              ? Number(r.address_active) === 1
                ? 'نشط'
                : 'موقوف'
              : '—'
          }</td>
        </tr>`
        )
        .join('');
      const codePart = g.region_code ? ` (${g.region_code})` : '';
      const statusPart = g.region_active ? '' : ' · موقوف';
      const title = `المنطقة: ${g.region_name}${codePart}${statusPart}`;
      blocks += `<div style="margin-top:.75rem">${ui.tableSurface(
        title,
        `${g.rows.length} عنوان · بمندوب ${g.withRep}`,
        ['#', 'العنوان', 'المندوب', 'عملاء', 'حالة العنوان'],
        html
      )}</div>`;
    }
  }

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'تقرير العناوين والمنطقة',
        subtitle: `${groups.size} منطقة · ${rows.length} صف · يعرض العناوين والمندوبين المربوطين`,
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'Excel', href: excelHref },
          { label: 'تعريف المناطق', href: '/customers/regions' },
          { label: 'لوحة العملاء', href: HUB },
        ],
      })}
      ${filtersHtml}
      <div class="si-print-area">${blocks}</div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير العناوين والمنطقة',
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
  let regions = await q.regionOptions();
  const reps = await q.salesRepOptions();
  const regionId = Number(row?.region_id || 0);
  const regionAddressId = Number(row?.region_address_id || 0);
  let addresses = regionId ? await masters.listAddressesForRegion(regionId, { activeOnly: false }) : [];
  // إن كانت المنطقة الحالية موقوفة أضفها للقائمة حتى تظهر مختارة
  if (regionId > 0 && !regions.some((r) => Number(r.id) === regionId)) {
    const curReg = await masters.getRegion(regionId);
    if (curReg) {
      regions = [{ id: curReg.id, code: curReg.code, name_ar: curReg.name_ar }, ...regions];
    }
  }
  if (
    regionAddressId > 0 &&
    !addresses.some((a) => Number(a.id) === regionAddressId)
  ) {
    try {
      const one = await q.listRegionAddresses(regionId);
      const hit = one.find((a) => Number(a.id) === regionAddressId);
      if (hit) addresses = [hit, ...addresses];
    } catch {
      /* */
    }
  }
  const selectedReps = new Set((row?.rep_ids || []).map(Number));
  if (row?.sales_rep_id) selectedReps.add(Number(row.sales_rep_id));
  const primaryRepId = [...selectedReps][0] || Number(row?.sales_rep_id || 0) || 0;
  const oracleLocked = !isNew && String(row.oracle_key || '').trim() !== '';
  const latVal =
    row?.latitude != null && String(row.latitude).trim() !== '' ? String(row.latitude) : '';
  const lngVal =
    row?.longitude != null && String(row.longitude).trim() !== '' ? String(row.longitude) : '';
  const accVal =
    row?.gps_accuracy != null && String(row.gps_accuracy).trim() !== ''
      ? String(row.gps_accuracy)
      : '';
  const hasGps = latVal !== '' && lngVal !== '';
  const mapsHref = hasGps
    ? `https://www.google.com/maps?q=${encodeURIComponent(latVal + ',' + lngVal)}`
    : '';

  const regionOpts = regions
    .map(
      (r) =>
        `<option value="${r.id}" ${regionId === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}</option>`
    )
    .join('');
  const addrOpts = addresses
    .filter((a) => Number(a.is_active) === 1 || Number(a.id) === regionAddressId)
    .map(
      (a) =>
        `<option value="${a.id}" ${regionAddressId === Number(a.id) ? 'selected' : ''}>${esc(
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
      .cf-gps{display:grid;gap:.55rem;grid-column:1/-1;padding:.75rem .85rem;border-radius:12px;
        border:1px dashed #cbd5e1;background:linear-gradient(180deg,#fff,#f8fafc)}
      .cf-gps-status{margin:0;font-size:.86rem;font-weight:700;color:#0f172a}
      .cf-gps-status.is-empty{color:#94a3b8;font-weight:600}
      .cf-gps-coords{display:grid;grid-template-columns:1fr 1fr auto;gap:.55rem;align-items:end}
      @media (max-width:640px){.cf-gps-coords{grid-template-columns:1fr}}
      .cf-gps-coords label{margin:0}
      .cf-gps-actions{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center}
      .cf-gps-actions .si-btn{min-height:2rem;padding:.25rem .75rem;font-size:.8rem}
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

            <div class="cf-sec" style="border-color:#86efac;background:linear-gradient(180deg,#f0fdf4,#fff)">
              <div class="cf-sec-h" style="background:#ecfdf5;border-color:#bbf7d0">
                <h3>سعر البيع / الجملة</h3>
                <span>أي سعر يُستخدم عند البيع لهذا العميل</span>
              </div>
              <div class="cf-sec-b">
                <label class="cf-span-3" style="display:flex;align-items:flex-start;gap:.65rem;font-weight:700;color:#0f172a;cursor:pointer;padding:.35rem 0">
                  <input type="checkbox" name="use_wholesale_price" value="1"
                         style="margin-top:.25rem;width:1.15rem;height:1.15rem;accent-color:#16a34a"
                    ${Number(row?.use_wholesale_price) === 1 ? 'checked' : ''}>
                  <span>
                    تسعير بسعر الجملة (بدل سعر البيع)
                    <span class="cf-field-note" style="display:block;font-weight:600;margin-top:.25rem;color:#166534">
                      ✓ مفعّل: فاتورة البيع وطلب العميل يأخذان <b>سعر الجملة</b> من بطاقة المادة.
                      بدون التفعيل: يُستخدم <b>سعر البيع</b>.
                    </span>
                  </span>
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
                <div class="cf-gps" id="cust-gps-box">
                  <div class="cf-sec-h" style="padding:0 0 .35rem;border:0;background:transparent">
                    <h3 style="font-size:.88rem">موقع العميل (Location)</h3>
                    <span>GPS على الخريطة</span>
                  </div>
                  <input type="hidden" name="latitude" id="cust-latitude" value="${esc(latVal)}">
                  <input type="hidden" name="longitude" id="cust-longitude" value="${esc(lngVal)}">
                  <input type="hidden" name="gps_accuracy" id="cust-gps-accuracy" value="${esc(accVal)}">
                  <input type="hidden" name="clear_gps" id="cust-clear-gps" value="0">
                  <p id="cust-gps-status" class="cf-gps-status${hasGps ? '' : ' is-empty'}">
                    ${
                      hasGps
                        ? 'الموقع الحالي: <span dir="ltr">' +
                          esc(latVal) +
                          ' ، ' +
                          esc(lngVal) +
                          '</span>'
                        : 'لم يُحدَّد موقع بعد — حدّد على الخريطة أو استخدم GPS الجهاز'
                    }
                  </p>
                  <div class="cf-gps-coords">
                    <label>خط العرض (Latitude)
                      <input class="si-field si-field--mono" id="cust-lat-view" type="text" dir="ltr" readonly
                             value="${esc(latVal)}" placeholder="—">
                    </label>
                    <label>خط الطول (Longitude)
                      <input class="si-field si-field--mono" id="cust-lng-view" type="text" dir="ltr" readonly
                             value="${esc(lngVal)}" placeholder="—">
                    </label>
                    <label>
                      <span style="visibility:hidden">.</span>
                      <a class="si-btn" id="cust-gps-maps" href="${mapsHref || '#'}" target="_blank" rel="noopener"
                         style="justify-content:center;width:100%;${hasGps ? '' : 'pointer-events:none;opacity:.45'}">فتح في الخرائط</a>
                    </label>
                  </div>
                  <div class="cf-gps-actions">
                    <button type="button" class="si-btn si-btn--primary" id="cust-gps-pick-map">تحديد على الخريطة</button>
                    <button type="button" class="si-btn" id="cust-gps-my-loc">موقعي الآن (GPS)</button>
                    <button type="button" class="si-btn" id="cust-gps-clear" ${hasGps ? '' : 'disabled'}>مسح الموقع</button>
                  </div>
                  <p class="cf-field-note">يُستخدم الموقع في تطبيق المندوب وتحديد نطاق الزيارة حول العميل عند الترحيل.</p>
                </div>
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

      (function () {
        var latEl = document.getElementById('cust-latitude');
        var lngEl = document.getElementById('cust-longitude');
        var accEl = document.getElementById('cust-gps-accuracy');
        var clearFlag = document.getElementById('cust-clear-gps');
        var statusEl = document.getElementById('cust-gps-status');
        var latView = document.getElementById('cust-lat-view');
        var lngView = document.getElementById('cust-lng-view');
        var mapsLink = document.getElementById('cust-gps-maps');
        var clearBtn = document.getElementById('cust-gps-clear');
        var mapBtn = document.getElementById('cust-gps-pick-map');
        var gpsBtn = document.getElementById('cust-gps-my-loc');
        if (!latEl || !lngEl) return;

        window.APP_GPS_ENABLED = true;

        function fmt(n) {
          var x = parseFloat(n);
          if (!isFinite(x)) return '';
          return String(Math.round(x * 1e7) / 1e7);
        }
        function syncViews() {
          var lat = latEl.value || '';
          var lng = lngEl.value || '';
          var has = lat !== '' && lng !== '';
          if (latView) latView.value = lat;
          if (lngView) lngView.value = lng;
          if (statusEl) {
            if (has) {
              statusEl.className = 'cf-gps-status';
              statusEl.innerHTML = 'الموقع الحالي: <span dir="ltr">' + lat + ' ، ' + lng + '</span>';
            } else {
              statusEl.className = 'cf-gps-status is-empty';
              statusEl.textContent = 'لم يُحدَّد موقع بعد — حدّد على الخريطة أو استخدم GPS الجهاز';
            }
          }
          if (clearBtn) clearBtn.disabled = !has;
          if (mapsLink) {
            if (has) {
              mapsLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng);
              mapsLink.style.pointerEvents = '';
              mapsLink.style.opacity = '';
            } else {
              mapsLink.href = '#';
              mapsLink.style.pointerEvents = 'none';
              mapsLink.style.opacity = '0.45';
            }
          }
          if (clearFlag) clearFlag.value = has ? '0' : '1';
        }
        function setGps(gps) {
          if (!gps) return;
          var lat = gps.latitude != null ? gps.latitude : gps.lat;
          var lng = gps.longitude != null ? gps.longitude : gps.lng;
          if (!isFinite(lat) || !isFinite(lng)) return;
          latEl.value = fmt(lat);
          lngEl.value = fmt(lng);
          if (accEl) {
            accEl.value =
              gps.accuracy != null && isFinite(gps.accuracy) ? String(gps.accuracy) : '';
          }
          if (clearFlag) clearFlag.value = '0';
          syncViews();
        }
        function clearGps() {
          latEl.value = '';
          lngEl.value = '';
          if (accEl) accEl.value = '';
          if (clearFlag) clearFlag.value = '1';
          syncViews();
        }

        if (clearBtn) clearBtn.addEventListener('click', clearGps);

        if (mapBtn) {
          mapBtn.addEventListener('click', function () {
            if (!window.AppGeoMapPick || typeof AppGeoMapPick.pickLocationOnMap !== 'function') {
              alert('خريطة تحديد الموقع غير متاحة. حدّث الصفحة أو تأكد من تحميل ملفات الموقع.');
              return;
            }
              var opts = { forPost: false, preferCurrentGps: true };
              if (latEl.value && lngEl.value) {
                opts.latitude = parseFloat(latEl.value);
                opts.longitude = parseFloat(lngEl.value);
              }
              AppGeoMapPick.pickLocationOnMap(opts).then(setGps).catch(function () {});
          });
        }

        if (gpsBtn) {
          gpsBtn.addEventListener('click', function () {
            if (!window.AppGeo || typeof AppGeo.withGpsForPost !== 'function') {
              if (mapBtn) mapBtn.click();
              return;
            }
            gpsBtn.disabled = true;
            AppGeo.withGpsForPost('desktop', function (gps) {
              gpsBtn.disabled = false;
              if (gps === undefined) return;
              if (!gps) {
                alert('لم يُحدَّد موقع. اسمح بالوصول للموقع أو اختر على الخريطة.');
                return;
              }
              setGps(gps);
            });
          });
        }

        syncViews();
      })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: isNew ? 'إضافة عميل' : 'تعريف العميل',
      bodyHtml: body,
      css: ['/assets/css/geo-map-pick.css'],
      js: ['/assets/js/geo.js', '/assets/js/geo-map-pick.js'],
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
