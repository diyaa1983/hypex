'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const auth = require('../auth');
const svc = require('./priceAdjustService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const { todayIso } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex Inventory · Node';
const HUB = '/inventory';
const SCREEN = 'item_sale_price_adjust';
const REPORT = 'report_item_price_adjustments';
const PA_JS_PATH = path.join(__dirname, '..', '..', 'public', 'js', 'price-adjust.js');

function priceAdjustJsSrc() {
  let v = String(Date.now());
  try {
    v = String(Math.floor(fs.statSync(PA_JS_PATH).mtimeMs));
  } catch {
    /* keep */
  }
  return '/assets/js/price-adjust.js?v=' + v;
}

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

function page(user, title, bodyHtml, opts) {
  opts = opts || {};
  return ui.salesPage({
    user,
    title,
    bodyHtml,
    css: ['/assets/css/sales-2027.css'],
    js: opts.js || [],
    bodyClass: opts.bodyClass || '',
    mainClass: opts.mainClass || '',
  });
}

function alertHtml(type, msg) {
  if (!msg) return '';
  const cls = type === 'err' || type === 'error' ? 'si-pill--lock' : 'si-pill--ok';
  return `<p class="si-pill ${cls}" style="display:inline-block;margin:.35rem 0">${esc(msg)}</p>`;
}

function guard(req, res, next) {
  if (!req.session || !req.session.user) return auth.requireAuth(req, res, next);
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) {
    return forbid(res);
  }
  return next();
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    !(
      p.startsWith('/inventory/price-adjust') ||
      p.startsWith('/inventory/reports/price-adjustments') ||
      p.startsWith('/api/inventory/price-adjust')
    )
  ) {
    return next('router');
  }
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return next();
  });
});

/* ── API: بحث مواد — أي مستخدم مسجّل (المرور عبر requireAuth أعلاه) ── */
router.get('/api/inventory/price-adjust/items', async (req, res) => {
  try {
    const inv = require('../sales/invoicesService');
    const rows = await inv.searchItems(String(req.query.q || ''), 50);
    res.json({ ok: true, rows: rows || [] });
  } catch (e) {
    console.error('price-adjust items', e);
    res.status(500).json({ ok: false, error: e.message || 'خطأ في البحث' });
  }
});

/* ── API item prices ── */
router.get('/api/inventory/price-adjust/item/:id', guard, async (req, res) => {
  try {
    const item = await svc.getItemPrices(req.params.id);
    if (!item) return res.json({ ok: false, error: 'مادة غير موجودة أو موقوفة.' });
    res.json({ ok: true, item });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

/* ── List docs ── */
router.get('/inventory/price-adjust', async (req, res) => {
  try {
    if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
    await svc.ensureSchema();
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const err = String(req.query.err || '');
    const rows = await svc.listDocs({ q: qv });
    const rowsHtml =
      rows
        .map((r) => {
          const st =
            String(r.status) === 'posted'
              ? ui.statusPill('ok', 'مرحّل')
              : ui.statusPill('wait', 'مسودة');
          return `<tr>
            <td class="si-num" dir="ltr">${esc(r.adj_no || '')}</td>
            <td class="si-num" dir="ltr">${esc(ui.isoToDmy(r.adj_date))}</td>
            <td>${st}</td>
            <td class="si-num" dir="ltr">${Number(r.line_count || 0)}</td>
            <td>${esc(r.created_by_name || '—')}</td>
            <td>${esc(r.notes || '—')}</td>
            <td><a class="si-btn" href="/inventory/price-adjust/${r.id}">فتح</a></td>
          </tr>`;
        })
        .join('') || ui.emptyRow(7, 'لا تعديلات بعد');

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '💰',
          kicker: KICKER,
          title: 'تعديل أسعار البيع',
          subtitle: 'أدخل الأسعار الجديدة للبيع والجملة — تُعتمد على بطاقة المادة عند الترحيل فقط',
          actions: [
            { label: '＋ تعديل جديد', href: '/inventory/price-adjust/new', primary: true },
            { label: 'تقرير المواد المعدّلة', href: '/inventory/reports/price-adjustments' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${err ? alertHtml('err', err) : ''}
        ${ui.railSearch('/inventory/price-adjust', qv)}
        ${ui.tableSurface(
          'سجل حركات التعديل',
          `${rows.length} صف`,
          ['رقم الحركة', 'التاريخ', 'الحالة', 'مواد', 'الموظف', 'ملاحظات', ''],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'تعديل أسعار البيع', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function renderForm(req, res, id) {
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
  await svc.ensureSchema();
  const isNew = !id;
  const doc = id ? await svc.getDoc(id) : null;
  if (id && !doc) return res.status(404).send('الحركة غير موجودة');
  const isPosted = !!(doc && doc.is_posted);
  const err = String(req.query.err || '');
  const flash = String(req.query.msg || '');
  const adjDate = doc ? doc.adj_date : svc.todayIso();
  const linesJson = JSON.stringify(doc?.lines || []).replace(/</g, '\\u003c');
  const hasId = !!(doc && doc.id);
  const adjNoClass = isPosted ? 'is-approved' : hasId ? 'is-saved' : '';

  const body = `
    <div class="si-stage" id="pa-root" data-posted="${isPosted ? '1' : '0'}" data-has-id="${hasId ? '1' : '0'}" data-pa-ux="co-v2">
      ${ui.hero({
        mark: '💰',
        kicker: KICKER,
        title: isNew ? 'تعديل أسعار بيع جديد' : `حركة ${esc(doc.adj_no || '')}`,
        subtitle: 'التاريخ تلقائي · السعر يُعتمد عند الترحيل فقط · أقل وحدة غير شامل الضريبة',
        actions: [
          { label: 'السجل', href: '/inventory/price-adjust' },
          { label: 'تقرير المواد المعدّلة', href: '/inventory/reports/price-adjustments' },
          { label: 'جديد', href: '/inventory/price-adjust/new' },
        ],
      })}
      ${err ? alertHtml('err', err) : ''}
      ${flash ? alertHtml('ok', flash) : ''}
      <p class="si-msg" id="pa-msg" ${err || flash ? '' : 'hidden'}></p>

      <div class="si-cmd si-doc-toolbar" id="pa-doc-bar" role="toolbar" aria-label="إجراءات تعديل الأسعار">
        <div class="si-tb-group si-tb-group--core">
          ${
            isPosted
              ? `<a class="si-tb si-tb--ghost" href="/inventory/price-adjust"><span class="si-tb-lbl">رجوع</span></a>`
              : `<button type="submit" form="pa-form" class="si-tb si-tb--save" name="action" value="save" data-hx-save="1" title="حفظ مسودة — F10">
                   <span class="si-tb-lbl">حفظ مسودة</span>
                   <span class="si-tb-keywrap"><kbd class="si-tb-key">F10</kbd><span class="si-tb-keydesc">حفظ</span></span>
                 </button>
                 ${
                   doc
                     ? `<button type="submit" form="pa-form" class="si-tb si-tb--post" name="action" value="post"
                          onclick="return confirm('ترحيل الأسعار إلى بطاقات المواد؟');">
                          <span class="si-tb-lbl">ترحيل</span>
                        </button>`
                     : `<button type="submit" form="pa-form" class="si-tb si-tb--post" name="action" value="save_post" title="حفظ ثم ترحيل">
                          <span class="si-tb-lbl">حفظ وترحيل</span>
                        </button>`
                 }`
          }
        </div>
        <div class="si-tb-group">
          <a class="si-tb si-tb--ghost" href="/inventory/price-adjust"><span class="si-tb-lbl">إلغاء</span></a>
          <a class="si-tb si-tb--ghost" href="/inventory/price-adjust/new"><span class="si-tb-lbl">جديد</span></a>
        </div>
      </div>

      <form method="post" action="/inventory/price-adjust/save" id="pa-form">
        <input type="hidden" name="id" id="pa-id" value="${doc ? doc.id : 0}">
        <input type="hidden" name="lines_json" id="pa-lines-json" value="">

        <section class="si-surface">
          <div class="si-surface-head">
            <h2>بيانات الحركة</h2>
            <span class="si-count">${isPosted ? 'مرحّلة' : 'مسودة'}</span>
          </div>
          <div class="si-meta si-meta--invoice si-meta--pa">
            <label class="si-f si-f--docno">
              <span class="si-f-head">رقم الحركة</span>
              <div class="si-docno-row" dir="ltr">
                <input class="si-field si-field--mono si-docno-input ${adjNoClass}" id="pa_no" type="text"
                       value="${esc(doc?.adj_no || '')}" readonly
                       placeholder="تلقائي عند الحفظ" dir="ltr">
              </div>
            </label>
            <label class="si-f si-f--date">
              <span class="si-f-head">تاريخ التعديل</span>
              <input class="si-field si-field--mono" type="date" name="adj_date" id="pa-date"
                     value="${esc(adjDate)}" dir="ltr" readonly title="تاريخ تلقائي — لا يُعدّل">
            </label>
            <label class="si-f">
              <span class="si-f-head">ملاحظات</span>
              <input class="si-field" name="notes" id="pa-notes" value="${esc(doc?.notes || '')}"
                     ${isPosted ? 'readonly' : ''} maxlength="500" placeholder="اختياري…">
            </label>
          </div>
        </section>

        <section class="si-surface si-surface--pa-lines">
          <div class="si-surface-head">
            <div class="pa-lines-head">
              <h2>المواد</h2>
              <p class="pa-lines-hint muted">السعر الحالي يُجلب تلقائياً · اكتب الجديد · الاعتماد بعد <b>الترحيل</b> فقط</p>
            </div>
            <span class="si-count si-count--keys">
              ${
                isPosted
                  ? ''
                  : `<span class="si-key-hint" title="سطر بند جديد"><kbd class="si-field-key">F2</kbd><span class="si-key-desc">سطر جديد</span></span>
                     <span class="si-key-hint" title="قائمة المواد"><kbd class="si-field-key">F3</kbd><span class="si-key-desc">قائمة مواد</span></span>
                     <span class="si-key-hint" title="حفظ"><kbd class="si-field-key">F10</kbd><span class="si-key-desc">حفظ</span></span>
                     <button type="button" class="si-btn" id="pa-add-line" style="margin-inline-start:.35rem">＋ إضافة مادة</button>`
              }
            </span>
          </div>
          <div class="si-lines-wrap pa-lines-wrap">
            <table class="si-lines si-lines--pa" id="pa-table">
              <colgroup>
                <col class="pa-c-idx">
                <col class="pa-c-code">
                <col class="pa-c-name">
                <col class="pa-c-sale-old">
                <col class="pa-c-sale-new">
                <col class="pa-c-wh-old">
                <col class="pa-c-wh-new">
                <col class="pa-c-act">
              </colgroup>
              <thead>
                <tr class="pa-th-group">
                  <th rowspan="2" class="pa-th-idx">#</th>
                  <th rowspan="2" class="pa-th-code">الباركود</th>
                  <th rowspan="2" class="pa-th-name">اسم المادة</th>
                  <th colspan="2" class="pa-th-grp pa-th-grp--sale">سعر البيع</th>
                  <th colspan="2" class="pa-th-grp pa-th-grp--wh">سعر الجملة</th>
                  <th rowspan="2" class="pa-th-act"></th>
                </tr>
                <tr class="pa-th-sub">
                  <th class="pa-th-sub--old pa-col-sale-old">الحالي</th>
                  <th class="pa-th-sub--new pa-col-sale-new">الجديد</th>
                  <th class="pa-th-sub--old pa-col-wh-old">الحالي</th>
                  <th class="pa-th-sub--new pa-col-wh-new">الجديد</th>
                </tr>
              </thead>
              <tbody id="pa-tbody"></tbody>
            </table>
          </div>
        </section>
      </form>
    </div>
    <div id="pa-global-suggest" class="pa-global-suggest" hidden aria-live="polite"></div>
    <script type="application/json" id="pa-initial">${linesJson}</script>
    <script src="${esc(priceAdjustJsSrc())}"></script>`;

  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
  res.setHeader('Pragma', 'no-cache');
  res.send(
    page(req.session.user, isNew ? 'تعديل أسعار جديد' : 'تعديل أسعار', body, {
      js: [],
    })
  );
}

router.get('/inventory/price-adjust/new', (req, res) => renderForm(req, res, 0));
router.get('/inventory/price-adjust/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/price-adjust');
  return renderForm(req, res, id);
});

router.post('/inventory/price-adjust/save', async (req, res) => {
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
  const body = req.body || {};
  let lines = [];
  try {
    lines = JSON.parse(String(body.lines_json || '[]'));
  } catch {
    lines = [];
  }
  const action = String(body.action || 'save');
  const result = await svc.saveDoc(
    { id: body.id, notes: body.notes, lines },
    req.session.user?.id
  );
  if (!result.ok) {
    return res.redirect(
      (body.id > 0 ? '/inventory/price-adjust/' + body.id : '/inventory/price-adjust/new') +
        '?err=' +
        encodeURIComponent(result.error || 'خطأ')
    );
  }
  if (action === 'post' || action === 'save_post') {
    const post = await svc.postDoc(result.id, req.session.user?.id);
    return res.redirect(
      '/inventory/price-adjust/' +
        result.id +
        (post.ok ? '?msg=' : '?err=') +
        encodeURIComponent(post.ok ? post.message : post.error || '')
    );
  }
  res.redirect(
    '/inventory/price-adjust/' +
      result.id +
      '?msg=' +
      encodeURIComponent(result.message || 'تم الحفظ')
  );
});

/* ── Report ── */
router.get('/inventory/reports/price-adjustments', async (req, res) => {
  try {
    if (
      !can(req.session.user, REPORT) &&
      !can(req.session.user, SCREEN) &&
      !can(req.session.user, 'items')
    ) {
      return forbid(res);
    }
    await svc.ensureSchema();
    const from = String(req.query.from || '').slice(0, 10) || svc.todayIso().slice(0, 8) + '01';
    const to = String(req.query.to || '').slice(0, 10) || svc.todayIso();
    const qv = String(req.query.q || '');
    const rows = await svc.reportAdjustments({ from, to, q: qv });

    /** تاريخ التعديل: يوم-شهر-سنة + ساعة:دقيقة */
    function fmtAdjustedAt(v) {
      if (!v) return '—';
      const s = String(v).replace('T', ' ').trim();
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2})(?::\d{2})?)?/);
      if (!m) return esc(s);
      const date = `${m[3]}-${m[2]}-${m[1]}`;
      if (m[4] != null && m[5] != null) return `${date} ${m[4]}:${m[5]}`;
      return date;
    }

    function employeeLabel(r) {
      const name = String(r.employee_name || '').trim();
      const user = String(r.employee_user || '').trim();
      if (name && user && name !== user) return `${name} (${user})`;
      return name || user || '—';
    }

    const rowsHtml =
      rows
        .map(
          (r, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td class="si-num" dir="ltr">${esc(r.item_code || '—')}</td>
        <td><strong>${esc(r.item_name || '')}</strong></td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.old_sale_price))}</td>
        <td class="si-num" dir="ltr"><strong>${esc(ui.fmtUnitPrice(r.new_sale_price))}</strong></td>
        <td class="si-num" dir="ltr">${esc(ui.fmtUnitPrice(r.old_wholesale))}</td>
        <td class="si-num" dir="ltr"><strong>${esc(ui.fmtUnitPrice(r.new_wholesale))}</strong></td>
        <td>${esc(employeeLabel(r))}</td>
        <td class="si-num" dir="ltr">${fmtAdjustedAt(r.adjusted_at || r.posted_at || r.doc_posted_at || r.created_at)}</td>
        <td class="si-num" dir="ltr">${esc(r.adj_no || '—')}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(10, 'لا توجد مواد معدّلة الأسعار في هذه الفترة');

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '📋',
          kicker: KICKER,
          title: 'تقرير المواد المعدّلة الأسعار',
          subtitle: 'السعر السابق والجديد · الموظف · تاريخ التعديل بالساعة والدقيقة (بعد الترحيل)',
          actions: [
            { label: 'تعديل أسعار جديد', href: '/inventory/price-adjust/new', primary: true },
            { label: 'سجل الحركات', href: '/inventory/price-adjust' },
            { label: 'لوحة المستودعات', href: HUB },
            ui.printAction(),
          ],
        })}
        ${ui.dateFilters('/inventory/reports/price-adjustments', from, to, `
          <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:700;color:#5c6578">بحث
            <input class="si-field" name="q" value="${esc(qv)}" placeholder="مادة / باركود / موظف / رقم حركة" style="min-height:2.1rem;min-width:12rem">
          </label>
        `)}
        ${ui.tableSurface(
          'المواد التي حدث عليها تعديل أسعار',
          `${rows.length} مادة`,
          [
            '#',
            'الباركود',
            'المادة',
            'سعر البيع السابق',
            'سعر البيع الجديد',
            'سعر الجملة السابق',
            'سعر الجملة الجديد',
            'الموظف',
            'تاريخ التعديل',
            'رقم الحركة',
          ],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'تقرير المواد المعدّلة الأسعار', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

module.exports = router;
