'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./customerOrdersService');
const invSvc = require('./invoicesService');
const { renderApp } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const { ensurePrintBrand, renderStandalonePrintPage } = require('../lib/printBrand');

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

function toolbarCaps(user, order) {
  const locked = !!(order && order.is_approved);
  const hasId = !!(order && order.id);
  const canAppr = canApprove(user);
  const edit = canEdit(user);
  return {
    canSave: edit && !locked,
    canApprove: canAppr && hasId && !locked,
    canUnapprove: canAppr && locked,
    canDelete: hasId && !locked && (canAppr || user.is_admin),
    canPrint: hasId,
    canPdf: hasId,
    canExcel: hasId,
  };
}

function toolbarHtml(caps, order) {
  const id = order && order.id ? Number(order.id) : 0;
  const locked = !!(order && order.is_approved);
  const b = (idAttr, label, cls, disabled, extra = '', key = '') => {
    const keyHtml = key
      ? ` <span class="si-tb-key" aria-hidden="true">${esc(key)}</span>`
      : '';
    return `<button type="button" class="si-tb ${cls || ''}" id="${idAttr}" ${
      disabled ? 'disabled' : ''
    }${extra}>${esc(label)}${keyHtml}</button>`;
  };

  return `
    <div class="si-cmd si-doc-toolbar" id="co-doc-bar" role="toolbar" aria-label="إجراءات طلب الشراء"
         data-order-id="${id}" data-approved="${locked ? '1' : '0'}">
      <div class="si-tb-group si-tb-group--core">
        ${b('co-save', 'حفظ', 'si-tb--save', !caps.canSave, ' data-hx-save="1" title="حفظ — F10"', 'F10')}
        ${b('co-approve', 'اعتماد', 'si-tb--post', !caps.canApprove, ' title="اعتماد"')}
      </div>
      <div class="si-tb-group">
        ${b('co-search', 'بحث', 'si-tb--ghost', false)}
        ${b('co-pdf', 'PDF', '', !caps.canPdf)}
        ${b('co-print', 'طباعة', '', !caps.canPrint)}
        ${b('co-excel', 'Excel', '', !caps.canExcel)}
      </div>
      <div class="si-tb-group si-tb-group--risk">
        ${b('co-unapprove', 'فك الاعتماد', '', !caps.canUnapprove)}
        ${b(
          'co-delete',
          'حذف',
          'si-tb--danger',
          !caps.canDelete,
          ' data-hx-delete="1" title="حذف الطلب — F4"',
          'F4'
        )}
      </div>
      <div class="si-tb-group si-tb-group--status">
        <span class="si-msg" id="co-msg"></span>
      </div>
    </div>
    <p class="si-keys" dir="rtl" aria-label="اختصارات لوحة المفاتيح">
      <span><kbd>F2</kbd> سطر مادة</span>
      <span><kbd>F3</kbd> قائمة المواد</span>
      <span><kbd>F4</kbd> حذف</span>
      <span><kbd>F7</kbd> العملاء</span>
      <span><kbd>F10</kbd> حفظ</span>
      <span><kbd>Esc</kbd> إغلاق</span>
    </p>`;
}

/** طباعة طلب شراء عميل — نفس شكل فاتورة المبيعات مع محتوى الطلب */
async function renderOrderPrint(req, res, orderId) {
  await ensurePrintBrand();
  const order = await svc.getOrder(orderId);
  if (!order) return res.status(404).send('الطلب غير موجود');

  const custLabel = String(order.customer_name || '').trim() || '—';
  const lines = Array.isArray(order.lines) ? order.lines : [];
  const showExtra = lines.some((ln) => (Number(ln.qty_extra) || 0) > 0.000001);
  const discLinesTotal = lines.reduce((a, ln) => a + (Number(ln.discount_amount) || 0), 0);
  const hasLineDisc = lines.some(
    (ln) =>
      (Number(ln.discount_pct) || 0) > 0.000001 || (Number(ln.discount_amount) || 0) > 0.000001
  );
  const invDiscRaw = String(order.invoice_discount_input || '').trim();
  let hasInvDisc = false;
  if (invDiscRaw) {
    const stripped = invDiscRaw.replace(/%/g, '').replace(/,/g, '').trim();
    const n = Number(stripped);
    hasInvDisc = Number.isFinite(n) ? Math.abs(n) > 0.000001 : invDiscRaw !== '0' && invDiscRaw !== '0.000';
  }
  const showDisc = hasLineDisc || hasInvDisc || discLinesTotal > 0.000001;
  const invDiscLabel = invDiscRaw || fmtAmt(0);
  const colCount = 11 + (showExtra ? 1 : 0) + (showDisc ? 1 : 0);
  const statusLabel = order.is_approved ? 'معتمد' : 'مسودة';

  const bodyRows =
    lines
      .map((ln, i) => {
        const qty = Number(ln.qty) || 0;
        const qtyExtra = Number(ln.qty_extra) || 0;
        const discPct = Number(ln.discount_pct) || 0;
        const discAmt = Number(ln.discount_amount) || 0;
        const taxPct = Number(ln.tax_rate_percent) || 0;
        const unitNet = Number(ln.unit_price) || 0;
        const unitGross = unitNet * (1 + taxPct / 100);
        const discCell = discPct > 0.000001 ? `${fmtAmt(discPct)}%` : fmtAmt(discAmt);
        return `<tr>
            <td class="c-idx" dir="ltr">${i + 1}</td>
            <td class="c-code" dir="ltr">${esc(ln.item_code || '')}</td>
            <td class="c-name">${esc(ln.name_ar || '')}</td>
            <td class="c-unit">${esc(ln.unit_name || 'قطعة')}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(qty))}</td>
            ${
              showExtra
                ? `<td class="c-num" dir="ltr">${esc(fmtAmt(qtyExtra))}</td>`
                : ''
            }
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitNet))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitGross))}</td>
            ${
              showDisc
                ? `<td class="c-num c-disc" dir="ltr">${esc(discCell)}</td>`
                : ''
            }
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.line_total))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.tax_amount))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(taxPct))}%</td>
            <td class="c-num c-gross" dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
          </tr>`;
      })
      .join('') ||
    `<tr><td colspan="${colCount}" class="empty">لا بنود</td></tr>`;

  const discSumRows = showDisc
    ? `<tr>
                <td class="lbl">خصم الطلب</td>
                <td class="val" dir="ltr">${esc(invDiscLabel)}</td>
              </tr>
              <tr>
                <td class="lbl">مجموع الخصم</td>
                <td class="val" dir="ltr">${esc(fmtAmt(discLinesTotal))}</td>
              </tr>`
    : '';

  const sumsBlock = `<div class="inv-v1-sumwrap">
            <table class="inv-v1-sum">
              ${discSumRows}
              <tr>
                <td class="lbl">المجموع بدون ضريبة</td>
                <td class="val" dir="ltr">${esc(fmtAmt(order.subtotal))}</td>
              </tr>
              <tr>
                <td class="lbl">مجموع الضريبة</td>
                <td class="val" dir="ltr">${esc(fmtAmt(order.tax_amount))}</td>
              </tr>
              <tr class="grand">
                <td class="lbl">الإجمالي</td>
                <td class="val" dir="ltr">${esc(fmtAmt(order.total))}</td>
              </tr>
            </table>
            ${
              order.notes
                ? `<div class="inv-v1-notes"><span>ملاحظات:</span> ${esc(order.notes)}</div>`
                : ''
            }
          </div>`;

  const contentHtml = `
      <div class="inv-v1 inv-v1--draft" dir="rtl">
        <div class="inv-v1-top">
          <div class="inv-v1-meta">
            <div><span>رقم الطلب:</span> <strong dir="ltr">${esc(order.order_no || '—')}</strong></div>
            <div><span>التاريخ:</span> <strong dir="ltr">${esc(isoToDmy(order.order_date))}</strong></div>
            <div><span>العميل:</span> <strong>${esc(custLabel)}</strong></div>
            <div><span>الحالة:</span> <strong>${esc(statusLabel)}</strong></div>
            <div><span>المندوب:</span> <strong>${esc(order.sales_rep_name || '—')}</strong></div>
            <div><span>المستودع:</span> <strong>${esc(order.warehouse_name || '—')}</strong></div>
          </div>
          <div class="inv-v1-title-block">
            <h1 class="inv-v1-title">طلب شراء عميل</h1>
          </div>
        </div>

        <table class="inv-v1-table">
          <thead>
            <tr>
              <th>تسلسل</th>
              <th>رقم المادة</th>
              <th>اسم المادة</th>
              <th>الوحدة</th>
              <th>الكمية</th>
              ${showExtra ? '<th>الكمية الإضافية</th>' : ''}
              <th>الافرادي غ.ش</th>
              <th>الافرادي ش.</th>
              ${showDisc ? '<th>الخصم</th>' : ''}
              <th>السعر الإجمالي</th>
              <th>مبلغ الضريبة</th>
              <th>نسبة الضريبة</th>
              <th>الإجمالي مع الضريبة</th>
            </tr>
          </thead>
          <tbody>${bodyRows}</tbody>
        </table>

        <div class="inv-v1-foot">
          ${sumsBlock}
          <div class="inv-v1-sign">
            <div class="inv-v1-sign-label">توقيع المستلم</div>
            <div class="inv-v1-sign-line"></div>
          </div>
        </div>
      </div>`;

  res.send(
    await renderStandalonePrintPage({
      user: req.session.user,
      documentTitle: 'طلب شراء عميل',
      backHref: `/sales/orders/${order.id}`,
      contentHtml,
      autoPrint: false,
      printMode: 'sheet',
      theme: 'invoice-v1',
    })
  );
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
  const caps = toolbarCaps(user, order || { id: 0, is_approved: false });

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
    caps,
    can_approve: canApprove(user),
    can_delete: caps.canDelete,
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
          <a class="si-btn" href="/sales/orders/new">جديد</a>
        </div>
      </header>

      ${toolbarHtml(caps, initial)}

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات المستند</h2>
          <span class="si-count">${esc(initial.status_label)}</span>
        </div>
        <div class="si-meta">
          <label>رقم الطلب
            <input class="si-field si-field--mono" id="co_no" type="text" value="${esc(initial.order_no)}" readonly placeholder="—" dir="ltr">
          </label>
          <label>التاريخ
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
          <h2>بنود الطلب</h2>
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
            <label>خصم مستوى الطلب
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

router.get('/sales/orders/:id/print', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (!id) return res.status(404).send('الطلب غير موجود');
    await renderOrderPrint(req, res, id);
  } catch (e) {
    console.error(e);
    res.status(500).send(e.message || 'خطأ');
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
