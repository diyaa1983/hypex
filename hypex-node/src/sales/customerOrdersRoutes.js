'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./customerOrdersService');
const invSvc = require('./invoicesService');
const { renderApp } = require('../lib/layout');
const { esc, todayIso } = require('../lib/html');

const router = express.Router();

function canEdit(user) {
  return (
    user.is_admin ||
    auth.userCan(user, 'sales_customer_order_entry') ||
    auth.userCan(user, 'sales_customer_orders') ||
    auth.userCan(user, 'sales_customer_orders_approve') ||
    auth.userCan(user, 'm_customer_orders')
  );
}

function canApprove(user) {
  return user.is_admin || auth.userCan(user, 'sales_customer_orders_approve');
}

function canView(user) {
  return (
    canEdit(user) ||
    auth.userCan(user, 'sales_customer_orders_approved') ||
    auth.userCan(user, 'sales_customer_orders')
  );
}

router.use((req, res, next) => {
  const p = req.path || '';
  const ok =
    p.startsWith('/sales/orders/entry') ||
    p.startsWith('/sales/orders/new') ||
    p === '/sales/orders/new' ||
    /^\/sales\/orders\/\d+/.test(p) ||
    p.startsWith('/api/sales/customer-orders');
  if (!ok) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    if (!canView(req.session.user)) {
      return res.status(403).send(
        renderApp({
          user: req.session.user,
          title: 'ممنوع',
          bodyHtml:
            '<div class="panel"><div class="panel-head"><h2>ليس لديك صلاحية طلبات شراء العملاء</h2></div></div>',
        })
      );
    }
    next();
  });
});

function isoDate(v) {
  if (!v) return todayIso();
  const s = String(v);
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
  return todayIso();
}

async function renderForm(req, res, orderId) {
  const user = req.session.user;
  if (!canEdit(user) && (!orderId || !canView(user))) {
    return res.status(403).send('ممنوع');
  }

  const lookups = await svc.lookups();
  const order = orderId ? await svc.getOrder(orderId) : null;
  if (orderId && !order) {
    return res.status(404).send(
      renderApp({
        user,
        title: 'غير موجود',
        bodyHtml: `<div class="si-stage">${esc('الطلب غير موجود')}</div>`,
        bodyClass: 'si-2027',
        mainClass: 'main si-main',
        css: ['/assets/css/sales-2027.css'],
      })
    );
  }

  const isNew = !order;
  const locked = !!(order && order.is_approved);
  const canAppr = canApprove(user);
  const canDel = canAppr || user.is_admin;

  const initial = {
    id: order ? order.id : 0,
    order_no: order ? order.order_no : '',
    order_date: isoDate(order ? order.order_date : todayIso()),
    customer_id: order ? order.customer_id : 0,
    customer_label: order
      ? `${order.customer_code || ''} — ${order.customer_name || ''}`.replace(/^ — /, '')
      : '',
    sales_rep_id: order ? order.sales_rep_id : null,
    warehouse_id: order ? order.warehouse_id : lookups.warehouses[0]?.id || '',
    notes: order ? order.notes : '',
    invoice_discount: order ? order.invoice_discount_input : '',
    is_approved: locked,
    status_label: order ? order.status_label : 'مسودة',
    lines:
      order && order.lines.length
        ? order.lines
        : [
            {
              item_id: 0,
              item_code: '',
              name_ar: '',
              qty: 1,
              qty_extra: 0,
              unit_price: 0,
              discount_pct: 0,
              tax_rate_percent: lookups.default_tax,
            },
          ],
    defaults: {
      tax: lookups.default_tax,
      warehouses: lookups.warehouses,
      sales_reps: lookups.sales_reps,
    },
    can_approve: canAppr,
    can_delete: canDel && !locked && !isNew,
  };

  const whOpts = (lookups.warehouses || [])
    .map(
      (w) =>
        `<option value="${w.id}"${Number(initial.warehouse_id) === Number(w.id) ? ' selected' : ''}>${esc(w.name_ar)}</option>`
    )
    .join('');

  const repOpts =
    `<option value="">—</option>` +
    (lookups.sales_reps || [])
      .map(
        (r) =>
          `<option value="${r.id}"${Number(initial.sales_rep_id) === Number(r.id) ? ' selected' : ''}>${esc(r.name_ar)}</option>`
      )
      .join('');

  const badge = locked
    ? '<span class="si-pill si-pill--lock">معتمد — قراءة فقط</span>'
    : '<span class="si-pill si-pill--wait">مسودة</span>';

  const titleLine = initial.order_no
    ? `طلب ${esc(initial.order_no)}`
    : 'طلب شراء عميل جديد';

  const approveBtn =
    canAppr && initial.id && !locked
      ? `<button type="button" class="si-btn si-btn--primary" id="co-approve">اعتماد</button>`
      : '';
  const unapproveBtn =
    canAppr && locked
      ? `<button type="button" class="si-btn" id="co-unapprove">فك الاعتماد</button>`
      : '';
  const deleteBtn =
    initial.can_delete
      ? `<button type="button" class="si-btn" id="co-delete" style="color:#b42318">حذف</button>`
      : '';

  const bodyHtml = `
    <div class="si-stage">
      <header class="si-hero">
        <div class="si-brand-lockup">
          <div class="si-brand-text">
            <h1>${titleLine}</h1>
            ${badge ? `<div class="si-hero-badge">${badge}</div>` : ''}
          </div>
        </div>
        <div class="si-hero-actions">
          <a class="si-btn" href="/sales/orders">القائمة</a>
          <a class="si-btn si-btn--primary" href="/sales/orders/new">طلب جديد</a>
          <a class="si-btn" href="/hub/sales">لوحة المبيعات</a>
        </div>
      </header>

      <div class="si-cmd" id="co-doc-bar">
        <button type="button" class="si-btn si-btn--primary" id="co-save" ${locked || !canEdit(user) ? 'disabled' : ''}>حفظ</button>
        <button type="button" class="si-btn" id="co-add-line" ${locked || !canEdit(user) ? 'disabled' : ''}>＋ سطر</button>
        ${approveBtn}
        ${unapproveBtn}
        ${deleteBtn}
        <span class="si-msg" id="co-msg"></span>
      </div>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات السند</h2>
          <span class="si-count">${esc(initial.status_label)}</span>
        </div>
        <div class="si-meta">
          <label>رقم السند
            <input class="si-field si-field--mono" id="co_no" type="text" value="${esc(initial.order_no)}" readonly placeholder="—" dir="ltr">
          </label>
          <label>تاريخ السند
            <input class="si-field si-field--mono" id="co_date" type="date" value="${esc(initial.order_date)}" ${locked ? 'readonly' : ''}>
          </label>
          <label class="si-span-2">العميل
            <div class="si-cust-wrap">
              <input type="hidden" id="co_customer_id" value="${initial.customer_id || ''}">
              <input class="si-field" id="co_customer" type="search" placeholder="ابحث بالاسم أو الرمز…"
                     value="${esc(initial.customer_label)}" autocomplete="off" ${locked ? 'readonly' : ''}>
              <div class="si-suggest" id="cust_suggest" hidden></div>
            </div>
          </label>
          <label>المندوب
            <select class="si-field" id="co_rep" ${locked ? 'disabled' : ''}>${repOpts}</select>
          </label>
          <label>المستودع
            <select class="si-field" id="co_wh" ${locked ? 'disabled' : ''}>
              <option value="">—</option>
              ${whOpts}
            </select>
          </label>
        </div>
      </section>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>تفاصيل المواد</h2>
          <span class="si-count">line items</span>
        </div>
        <div class="si-lines-wrap">
          <table class="si-lines" id="co-lines">
            <thead>
              <tr>
                <th style="width:2.2rem">#</th>
                <th>المادة</th>
                <th style="width:6.2rem">الكمية</th>
                <th style="width:6.2rem">إضافية</th>
                <th style="width:7rem">السعر</th>
                <th style="width:5.2rem">خصم %</th>
                <th style="width:5.2rem">ضريبة %</th>
                <th style="width:7rem">الصافي</th>
                <th style="width:7rem">الإجمالي</th>
                <th style="width:2.6rem"></th>
              </tr>
            </thead>
            <tbody id="co-lines-body"></tbody>
          </table>
        </div>
        <div class="si-doc-foot">
          <label class="si-notes">ملاحظات
            <textarea id="co_notes" rows="3" ${locked ? 'readonly' : ''} placeholder="اختياري…">${esc(initial.notes)}</textarea>
          </label>
          <div class="si-totals">
            <label>خصم الطلب
              <input class="si-field" id="co_discount" type="text" value="${esc(initial.invoice_discount)}"
                     placeholder="10 أو 10% أو 1.000" ${locked ? 'readonly' : ''}>
            </label>
            <div class="si-tot-row"><span>بدون ضريبة</span><strong id="sum_sub" dir="ltr">0.000</strong></div>
            <div class="si-tot-row"><span>الضريبة</span><strong id="sum_tax" dir="ltr">0.000</strong></div>
            <div class="si-tot-row si-tot-grand"><span>الإجمالي</span><strong id="sum_grand" dir="ltr">0.000</strong></div>
          </div>
        </div>
      </section>
    </div>
    <script type="application/json" id="co-initial">${JSON.stringify(initial).replace(/</g, '\\u003c')}</script>
  `;

  res.send(
    renderApp({
      user,
      title: isNew ? 'طلب شراء عميل جديد' : `طلب ${initial.order_no}`,
      bodyHtml,
      bodyClass: 'si-2027',
      mainClass: 'main si-main',
      css: ['/assets/css/sales-2027.css'],
      js: ['/assets/js/customer-order.js'],
    })
  );
}

router.get('/sales/orders/entry', (req, res) => {
  const id = Number(req.query.id || 0);
  if (id > 0) return res.redirect('/sales/orders/' + id);
  return res.redirect('/sales/orders/new');
});

router.get('/sales/orders/new', async (req, res) => {
  try {
    if (!canEdit(req.session.user)) {
      return res.status(403).send('ممنوع');
    }
    await renderForm(req, res, 0);
  } catch (e) {
    console.error(e);
    res.status(500).send('Error: ' + e.message);
  }
});

router.get('/sales/orders/:id', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (!id) return res.redirect('/sales/orders');
    await renderForm(req, res, id);
  } catch (e) {
    console.error(e);
    res.status(500).send('Error: ' + e.message);
  }
});

/* ── APIs ── */
router.get('/api/sales/customer-orders/customers', async (req, res) => {
  try {
    const rows = await invSvc.searchCustomers(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/customer-orders/items', async (req, res) => {
  try {
    const rows = await invSvc.searchItems(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/customer-orders/lookups', async (req, res) => {
  try {
    const data = await svc.lookups();
    res.json({ ok: true, ...data });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/customer-orders/:id', async (req, res) => {
  try {
    const order = await svc.getOrder(req.params.id);
    if (!order) return res.status(404).json({ ok: false, error: 'not_found' });
    res.json({ ok: true, order });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/customer-orders', async (req, res) => {
  try {
    if (!canEdit(req.session.user)) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية حفظ.' });
    }
    const result = await svc.saveOrder(req.body || {}, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/customer-orders/:id/approve', async (req, res) => {
  try {
    if (!canApprove(req.session.user)) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية اعتماد.' });
    }
    const result = await svc.setApproved(req.params.id, true, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/customer-orders/:id/unapprove', async (req, res) => {
  try {
    if (!canApprove(req.session.user)) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية فك الاعتماد.' });
    }
    const result = await svc.setApproved(req.params.id, false, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/customer-orders/:id/delete', async (req, res) => {
  try {
    if (!canApprove(req.session.user) && !req.session.user.is_admin) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية حذف.' });
    }
    const result = await svc.deleteOrder(req.params.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

module.exports = router;
