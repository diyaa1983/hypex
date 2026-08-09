'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./deliveryService');
const invSvc = require('./invoicesService');
const { renderApp } = require('../lib/layout');
const { esc, todayIso } = require('../lib/html');
const ui = require('../lib/salesUi');

const router = express.Router();

function can(user) {
  return user.is_admin || auth.userCan(user, 'sales_delivery');
}

router.use((req, res, next) => {
  const p = req.path || '';
  const ok =
    p === '/sales/delivery/new' ||
    p === '/sales/delivery/entry' ||
    /^\/sales\/delivery\/\d+(\/|$)/.test(p) ||
    p.startsWith('/api/sales/delivery');
  if (!ok) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    if (!can(req.session.user)) {
      return res.status(403).send(
        ui.salesPage({
          user: req.session.user,
          title: 'ممنوع',
          bodyHtml: `<div class="si-stage">${ui.hero({ title: 'ممنوع', subtitle: 'لا صلاحية سند التسليم' })}</div>`,
        })
      );
    }
    next();
  });
});

function isoDate(v) {
  if (!v) return todayIso();
  const s = String(v);
  return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : todayIso();
}

async function renderForm(req, res, deliveryId) {
  const user = req.session.user;
  const lookups = await svc.lookups();
  const doc = deliveryId ? await svc.getDelivery(deliveryId) : null;
  if (deliveryId && !doc) {
    return res.status(404).send(
      ui.salesPage({
        user,
        title: 'غير موجود',
        bodyHtml: '<div class="si-stage">السند غير موجود</div>',
      })
    );
  }

  const locked = !!(doc && doc.is_locked);
  const initial = {
    id: doc ? doc.id : 0,
    delivery_no: doc ? doc.delivery_no : '',
    delivery_date: isoDate(doc ? doc.delivery_date : todayIso()),
    customer_id: doc ? doc.customer_id : 0,
    customer_label: doc
      ? `${doc.customer_code || ''} — ${doc.customer_name || ''}`.replace(/^ — /, '')
      : '',
    warehouse_id: doc ? doc.warehouse_id : lookups.warehouses[0]?.id || '',
    notes: doc ? doc.notes : '',
    is_locked: locked,
    is_posted: !!(doc && doc.is_posted),
    status_label: doc ? doc.status_label : 'جديد',
    lines:
      doc && doc.lines.length
        ? doc.lines
        : [{ item_id: 0, item_code: '', name_ar: '', qty: 1 }],
    defaults: { tax: lookups.default_tax, warehouses: lookups.warehouses },
  };

  const whOpts = (lookups.warehouses || [])
    .map(
      (w) =>
        `<option value="${w.id}"${Number(initial.warehouse_id) === Number(w.id) ? ' selected' : ''}>${esc(w.name_ar)}</option>`
    )
    .join('');

  const badge = locked
    ? '<span class="si-pill si-pill--lock">مرحّل — قراءة فقط</span>'
    : '<span class="si-pill si-pill--wait">قابل للتعديل</span>';

  const titleLine = initial.delivery_no
    ? `سند ${esc(initial.delivery_no)}`
    : 'سند تسليم جديد';

  const bodyHtml = `
    <div class="si-stage">
      <header class="si-hero">
        <div class="si-brand-lockup">
          <div class="si-brand-mark" aria-hidden="true">DL</div>
          <div class="si-brand-text">
            <p class="si-kicker">Hypex Sales · Node</p>
            <h1>${titleLine}</h1>
            <p>سند تسليم بضاعة — إدخال وحفظ أصلي على Node. ${badge}</p>
          </div>
        </div>
        <div class="si-hero-actions">
          <a class="si-btn" href="/sales/delivery">القائمة</a>
          <a class="si-btn si-btn--primary" href="/sales/delivery/new">سند جديد</a>
          <a class="si-btn" href="/hub/sales">لوحة المبيعات</a>
        </div>
      </header>

      <div class="si-cmd">
        <button type="button" class="si-btn si-btn--primary" id="dl-save" ${locked ? 'disabled' : ''}>حفظ</button>
        <button type="button" class="si-btn" id="dl-add-line" ${locked ? 'disabled' : ''}>＋ سطر</button>
        <span class="si-msg" id="dl-msg"></span>
      </div>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات السند</h2>
          <span class="si-count">${esc(initial.status_label)}</span>
        </div>
        <div class="si-meta">
          <label>رقم السند
            <input class="si-field si-field--mono" id="dl_no" type="text" value="${esc(initial.delivery_no)}" readonly placeholder="—" dir="ltr">
          </label>
          <label>التاريخ
            <input class="si-field si-field--mono" id="dl_date" type="date" value="${esc(initial.delivery_date)}" ${locked ? 'readonly' : ''}>
          </label>
          <label class="si-span-2">العميل
            <div class="si-cust-wrap">
              <input type="hidden" id="dl_customer_id" value="${initial.customer_id || ''}">
              <input class="si-field" id="dl_customer" type="search" placeholder="ابحث بالاسم أو الرمز…"
                     value="${esc(initial.customer_label)}" autocomplete="off" ${locked ? 'readonly' : ''}>
              <div class="si-suggest" id="cust_suggest" hidden></div>
            </div>
          </label>
          <label>المستودع
            <select class="si-field" id="dl_wh" ${locked ? 'disabled' : ''}>
              <option value="">—</option>
              ${whOpts}
            </select>
          </label>
        </div>
      </section>

      <section class="si-surface">
        <div class="si-surface-head"><h2>تفاصيل المواد</h2></div>
        <div class="si-lines-wrap">
          <table class="si-lines" id="dl-lines">
            <thead>
              <tr>
                <th style="width:2.2rem">#</th>
                <th>المادة</th>
                <th style="width:8rem">الكمية</th>
                <th style="width:2.6rem"></th>
              </tr>
            </thead>
            <tbody id="dl-lines-body"></tbody>
          </table>
        </div>
        <div class="si-doc-foot">
          <label class="si-notes">ملاحظات
            <textarea id="dl_notes" rows="3" ${locked ? 'readonly' : ''} placeholder="اختياري…">${esc(initial.notes)}</textarea>
          </label>
        </div>
      </section>
    </div>
    <script type="application/json" id="dl-initial">${JSON.stringify(initial).replace(/</g, '\\u003c')}</script>
  `;

  res.send(
    renderApp({
      user,
      title: deliveryId ? `سند ${initial.delivery_no}` : 'سند تسليم جديد',
      bodyHtml,
      bodyClass: 'si-2027',
      mainClass: 'main si-main',
      css: ['/assets/css/sales-2027.css'],
      js: ['/assets/js/sales-delivery.js'],
      activePath: '/sales/delivery',
    })
  );
}

router.get('/sales/delivery/entry', (req, res) => {
  const id = Number(req.query.id || 0);
  if (id) return res.redirect('/sales/delivery/' + id);
  return res.redirect('/sales/delivery/new');
});

router.get('/sales/delivery/new', async (req, res) => {
  try {
    await renderForm(req, res, 0);
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/sales/delivery/:id', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (!id) return res.redirect('/sales/delivery');
    await renderForm(req, res, id);
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/api/sales/delivery/customers', async (req, res) => {
  try {
    const rows = await invSvc.searchCustomers(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/delivery/items', async (req, res) => {
  try {
    const rows = await invSvc.searchItems(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/delivery', async (req, res) => {
  try {
    const result = await svc.saveDelivery(req.body || {}, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

module.exports = router;
