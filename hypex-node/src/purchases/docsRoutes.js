'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./docsService');
const invSvc = require('../sales/invoicesService');
const { renderApp } = require('../lib/layout');
const { esc, todayIso } = require('../lib/html');
const ui = require('../lib/salesUi');

const router = express.Router();

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}

router.use((req, res, next) => {
  const p = req.path || '';
  const ok =
    p === '/purchases/orders/entry' ||
    p === '/purchases/orders/new' ||
    /^\/purchases\/orders\/\d+(\/|$)/.test(p) ||
    p === '/purchases/invoices/entry' ||
    p === '/purchases/invoices/new' ||
    /^\/purchases\/invoices\/\d+(\/|$)/.test(p) ||
    p.startsWith('/api/purchases/');
  if (!ok) return next('router');
  return auth.requireAuth(req, res, next);
});

function isoDate(v) {
  if (!v) return todayIso();
  const s = String(v);
  return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : todayIso();
}

async function renderDocForm(req, res, conf) {
  const user = req.session.user;
  if (!can(user, conf.perm)) {
    return res.status(403).send(
      ui.salesPage({
        user,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({ title: 'ممنوع', subtitle: 'لا صلاحية' })}</div>`,
      })
    );
  }

  const lookups = await svc.lookups();
  const doc = conf.id ? await conf.load(conf.id) : null;
  if (conf.id && !doc) {
    return res.status(404).send(ui.salesPage({ user, title: 'غير موجود', bodyHtml: '<div class="si-stage">المستند غير موجود</div>' }));
  }

  const locked = !!(doc && doc.is_locked);
  const initial = {
    kind: conf.kind,
    apiSave: conf.apiSave,
    listHref: conf.listHref,
    id: doc ? doc.id : 0,
    doc_no: doc ? doc.doc_no : '',
    doc_date: isoDate(doc ? doc.doc_date : todayIso()),
    expected_date: doc && doc.expected_date ? isoDate(doc.expected_date) : '',
    party_id: doc ? doc.supplier_id : 0,
    party_label: doc
      ? `${doc.supplier_code || ''} — ${doc.supplier_name || ''}`.replace(/^ — /, '')
      : '',
    warehouse_id: doc ? doc.warehouse_id : lookups.warehouses[0]?.id || '',
    payment_type: doc ? doc.payment_type : 'credit',
    reference_no: doc ? doc.reference_no || '' : '',
    notes: doc ? doc.notes || '' : '',
    invoice_discount: doc ? doc.invoice_discount_input || '' : '',
    is_locked: locked,
    status_label: doc ? doc.status_label : 'جديد',
    show_expected: !!conf.showExpected,
    party_role: 'supplier',
    party_placeholder: 'ابحث عن المورد…',
    lines:
      doc && doc.lines.length
        ? doc.lines
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
    defaults: { tax: lookups.default_tax },
  };

  const whOpts = (lookups.warehouses || [])
    .map(
      (w) =>
        `<option value="${w.id}"${Number(initial.warehouse_id) === Number(w.id) ? ' selected' : ''}>${esc(w.name_ar)}</option>`
    )
    .join('');

  const badge = locked
    ? '<span class="si-pill si-pill--lock">مقفل</span>'
    : '<span class="si-pill si-pill--wait">قابل للتعديل</span>';

  const titleLine = initial.doc_no ? `${esc(conf.title)} ${esc(initial.doc_no)}` : conf.titleNew;

  const bodyHtml = `
    <div class="si-stage">
      <header class="si-hero">
        <div class="si-brand-lockup">
          <div class="si-brand-mark" aria-hidden="true">${esc(conf.mark)}</div>
          <div class="si-brand-text">
            <p class="si-kicker">Hypex Purchases · Node</p>
            <h1>${titleLine}</h1>
            <p>مستند ${esc(conf.title)} أصلي على Node. ${badge}</p>
          </div>
        </div>
        <div class="si-hero-actions">
          <a class="si-btn" href="${esc(conf.listHref)}">القائمة</a>
          <a class="si-btn si-btn--primary" href="${esc(conf.newHref)}">جديد</a>
          <a class="si-btn" href="/hub/purchases">لوحة المشتريات</a>
        </div>
      </header>

      <div class="si-cmd">
        <button type="button" class="si-btn si-btn--primary" id="df-save" ${locked ? 'disabled' : ''}>حفظ</button>
        <button type="button" class="si-btn" id="df-add-line" ${locked ? 'disabled' : ''}>＋ سطر</button>
        <span class="si-msg" id="df-msg"></span>
      </div>

      <section class="si-surface">
        <div class="si-surface-head"><h2>بيانات المستند</h2><span class="si-count">${esc(initial.status_label)}</span></div>
        <div class="si-meta">
          <label>الرقم
            <input class="si-field si-field--mono" id="df_no" type="text" value="${esc(initial.doc_no)}" readonly dir="ltr" placeholder="—">
          </label>
          <label>التاريخ
            <input class="si-field si-field--mono" id="df_date" type="date" value="${esc(initial.doc_date)}" ${locked ? 'readonly' : ''}>
          </label>
          ${
            conf.showExpected
              ? `<label>تاريخ متوقع
            <input class="si-field si-field--mono" id="df_expected" type="date" value="${esc(initial.expected_date)}" ${locked ? 'readonly' : ''}>
          </label>`
              : ''
          }
          <label>النوع
            <select class="si-field" id="df_pay" ${locked ? 'disabled' : ''}>
              <option value="credit"${initial.payment_type === 'credit' ? ' selected' : ''}>ذمم</option>
              <option value="cash"${initial.payment_type === 'cash' ? ' selected' : ''}>نقدي</option>
            </select>
          </label>
          <label class="si-span-2">المورد
            <div class="si-cust-wrap">
              <input type="hidden" id="df_party_id" value="${initial.party_id || ''}">
              <input class="si-field" id="df_party" type="search" placeholder="ابحث عن المورد…" value="${esc(initial.party_label)}" autocomplete="off" ${locked ? 'readonly' : ''}>
              <div class="si-suggest" id="party_suggest" hidden></div>
            </div>
          </label>
          <label>المستودع
            <select class="si-field" id="df_wh" ${locked ? 'disabled' : ''}>
              <option value="">—</option>${whOpts}
            </select>
          </label>
          <label>مرجع
            <input class="si-field" id="df_ref" type="text" value="${esc(initial.reference_no)}" ${locked ? 'readonly' : ''} placeholder="اختياري">
          </label>
        </div>
      </section>

      <section class="si-surface">
        <div class="si-surface-head"><h2>تفاصيل المواد</h2></div>
        <div class="si-lines-wrap">
          <table class="si-lines">
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
            <tbody id="df-lines-body"></tbody>
          </table>
        </div>
        <div class="si-doc-foot">
          <label class="si-notes">ملاحظات
            <textarea id="df_notes" rows="3" ${locked ? 'readonly' : ''}>${esc(initial.notes)}</textarea>
          </label>
          <div class="si-totals">
            <label>خصم المستند
              <input class="si-field" id="df_discount" type="text" value="${esc(initial.invoice_discount)}" placeholder="10 أو 10%" ${locked ? 'readonly' : ''}>
            </label>
            <div class="si-tot-row"><span>بدون ضريبة</span><strong id="sum_sub" dir="ltr">0.000</strong></div>
            <div class="si-tot-row"><span>الضريبة</span><strong id="sum_tax" dir="ltr">0.000</strong></div>
            <div class="si-tot-row si-tot-grand"><span>الإجمالي</span><strong id="sum_grand" dir="ltr">0.000</strong></div>
          </div>
        </div>
      </section>
    </div>
    <script type="application/json" id="df-initial">${JSON.stringify(initial).replace(/</g, '\\u003c')}</script>
  `;

  res.send(
    renderApp({
      user,
      title: doc ? `${conf.title} ${initial.doc_no}` : conf.titleNew,
      bodyHtml,
      bodyClass: 'si-2027',
      mainClass: 'main si-main',
      css: ['/assets/css/sales-2027.css'],
      js: ['/assets/js/doc-form.js'],
      activePath: conf.listHref,
    })
  );
}

router.get('/purchases/orders/entry', (req, res) => {
  const id = Number(req.query.id || 0);
  if (id) return res.redirect('/purchases/orders/' + id);
  return res.redirect('/purchases/orders/new');
});

router.get('/purchases/orders/new', async (req, res) => {
  try {
    await renderDocForm(req, res, {
      kind: 'pur_order',
      perm: 'purchase_orders',
      mark: 'PO',
      title: 'طلب شراء',
      titleNew: 'طلب شراء جديد',
      apiSave: '/api/purchases/orders',
      listHref: '/purchases/orders',
      newHref: '/purchases/orders/new',
      showExpected: true,
      id: 0,
      load: svc.getOrder,
    });
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/purchases/orders/:id', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (!id) return res.redirect('/purchases/orders');
    await renderDocForm(req, res, {
      kind: 'pur_order',
      perm: 'purchase_orders',
      mark: 'PO',
      title: 'طلب شراء',
      titleNew: 'طلب شراء جديد',
      apiSave: '/api/purchases/orders',
      listHref: '/purchases/orders',
      newHref: '/purchases/orders/new',
      showExpected: true,
      id,
      load: svc.getOrder,
    });
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/purchases/invoices/entry', (req, res) => {
  const id = Number(req.query.id || 0);
  if (id) return res.redirect('/purchases/invoices/' + id);
  return res.redirect('/purchases/invoices/new');
});

router.get('/purchases/invoices/new', async (req, res) => {
  try {
    await renderDocForm(req, res, {
      kind: 'pur_invoice',
      perm: 'purchase_invoices',
      mark: 'PI',
      title: 'فاتورة شراء',
      titleNew: 'فاتورة شراء جديدة',
      apiSave: '/api/purchases/invoices',
      listHref: '/purchases/invoices',
      newHref: '/purchases/invoices/new',
      showExpected: false,
      id: 0,
      load: svc.getInvoice,
    });
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/purchases/invoices/:id', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (!id) return res.redirect('/purchases/invoices');
    await renderDocForm(req, res, {
      kind: 'pur_invoice',
      perm: 'purchase_invoices',
      mark: 'PI',
      title: 'فاتورة شراء',
      titleNew: 'فاتورة شراء جديدة',
      apiSave: '/api/purchases/invoices',
      listHref: '/purchases/invoices',
      newHref: '/purchases/invoices/new',
      showExpected: false,
      id,
      load: svc.getInvoice,
    });
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

/* APIs */
router.get('/api/purchases/suppliers', async (req, res) => {
  try {
    const rows = await svc.searchSuppliers(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/purchases/items', async (req, res) => {
  try {
    const rows = await invSvc.searchItems(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/purchases/orders', async (req, res) => {
  try {
    if (!can(req.session.user, 'purchase_orders')) {
      return res.status(403).json({ ok: false, error: 'ممنوع' });
    }
    const result = await svc.saveOrder(req.body || {}, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/purchases/invoices', async (req, res) => {
  try {
    if (!can(req.session.user, 'purchase_invoices')) {
      return res.status(403).json({ ok: false, error: 'ممنوع' });
    }
    const result = await svc.saveInvoice(req.body || {}, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

module.exports = router;
