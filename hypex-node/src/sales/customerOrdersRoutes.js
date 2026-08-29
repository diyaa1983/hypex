'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./customerOrdersService');
const invSvc = require('./invoicesService');
const { renderApp } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const { ensurePrintBrand, renderStandalonePrintPage, invoiceV1LinesTableHtml } = require('../lib/printBrand');
const { oracleStatementUrl, linesColgroup } = require('../lib/salesUi');

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
    canPostOracle: canAppr && hasId && locked && !(Number(order.oracle_v_num || 0) > 0),
    oracleVnum: Number(order.oracle_v_num || 0) || 0,
    oracleVyear: Number(order.oracle_vyear || 0) || 0,
  };
}

function toolbarHtml(caps, order) {
  const id = order && order.id ? Number(order.id) : 0;
  const locked = !!(order && order.is_approved);
  const b = (idAttr, label, cls, disabled, extra = '', key = '', keyDesc = '') => {
    const keyHtml = key
      ? `<span class="si-tb-keywrap" title="${esc(keyDesc || key)}"><kbd class="si-tb-key">${esc(key)}</kbd>${
          keyDesc ? `<span class="si-tb-keydesc">${esc(keyDesc)}</span>` : ''
        }</span>`
      : '';
    return `<button type="button" class="si-tb ${cls || ''}" id="${idAttr}" ${
      disabled ? 'disabled' : ''
    }${extra}><span class="si-tb-lbl">${esc(label)}</span>${keyHtml}</button>`;
  };

  return `
    <div class="si-cmd si-doc-toolbar" id="co-doc-bar" role="toolbar" aria-label="إجراءات طلب الشراء"
         data-order-id="${id}" data-approved="${locked ? '1' : '0'}">
      <div class="si-tb-group si-tb-group--core">
        ${b('co-save', 'حفظ', 'si-tb--save', !caps.canSave, ' data-hx-save="1" title="حفظ — F10"', 'F10', 'حفظ')}
        ${b('co-approve', 'اعتماد', 'si-tb--post', !caps.canApprove, ' title="اعتماد الطلب"', '', 'اعتماد')}
        ${b(
          'co-oracle',
          caps.oracleVnum > 0 ? 'Oracle #' + caps.oracleVnum : 'ترحيل إلى Oracle',
          'si-tb--post',
          !caps.canPostOracle && !(caps.oracleVnum > 0),
          caps.oracleVnum > 0
            ? ` title="فاتورة Oracle ${caps.oracleVnum} / ${caps.oracleVyear}"`
            : ' title="تحويل الطلب المعتمد إلى فاتورة بيع في فواتير المبيعات"'
        )}
      </div>
      <div class="si-tb-group">
        ${b('co-search', 'القائمة', 'si-tb--ghost', false, ' title="قائمة الطلبات"')}
        ${b('co-new', 'جديد', 'si-tb--ghost', false, ' title="طلب جديد"')}
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
          ' data-hx-delete="1" title="حذف الطلب"'
        )}
      </div>
      <div class="si-tb-group si-tb-group--status">
        <span class="si-msg" id="co-msg"></span>
      </div>
    </div>`;
}

/** طباعة طلب شراء عميل — نفس شكل فاتورة المبيعات مع محتوى الطلب */
async function renderOrderPrint(req, res, orderId) {
  await ensurePrintBrand();
  const order = await svc.getOrder(orderId);
  if (!order) return res.status(404).send('الطلب غير موجود');

  const custLabel = String(order.customer_name || '').trim() || '—';
  const lines = Array.isArray(order.lines) ? order.lines : [];
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
  const statusLabel = order.is_approved ? 'معتمد' : 'مسودة';
  const linesTableHtml = invoiceV1LinesTableHtml(lines, fmtAmt, esc);

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
            <div><span>النوع:</span> <strong>${esc(order.payment_type === 'cash' ? 'نقدي' : 'ذمم')}</strong></div>
            <div><span>المندوب:</span> <strong>${esc(order.sales_rep_name || '—')}</strong></div>
            <div><span>المستودع:</span> <strong>${esc(order.warehouse_name || '—')}</strong></div>
          </div>
          <div class="inv-v1-title-block">
            <h1 class="inv-v1-title">طلب شراء عميل</h1>
          </div>
        </div>

        ${linesTableHtml}

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
  const nav = order ? await svc.browseNeighbors(order.id) : { prev_id: 0, next_id: 0 };

  const initial = {
    id: order ? order.id : 0,
    order_no: order ? order.order_no : '',
    order_date: isoDate(order ? order.order_date : todayIso()),
    customer_id: order ? order.customer_id : 0,
    customer_label: order
      ? `${order.customer_code || ''} — ${order.customer_name || ''}`.replace(/^ — /, '')
      : '',
    use_wholesale_price: order ? (Number(order.use_wholesale_price) === 1 ? 1 : 0) : 0,
    sales_rep_id: order ? order.sales_rep_id : null,
    warehouse_id: order ? order.warehouse_id : lookups.warehouses[0]?.id || '',
    payment_type: order && order.payment_type === 'cash' ? 'cash' : 'credit',
    notes: order ? order.notes : '',
    invoice_discount: order ? order.invoice_discount_input : '',
    is_approved: locked,
    status_label: order ? order.status_label : 'مسودة',
    oracle_v_num: order ? Number(order.oracle_v_num || 0) : 0,
    oracle_vyear: order ? Number(order.oracle_vyear || 0) : 0,
    prev_id: nav.prev_id || 0,
    next_id: nav.next_id || 0,
    lines:
      order && order.lines.length
        ? order.lines
        : [
            {
              item_id: 0,
              item_code: '',
              item_sku: '',
              item_barcode: '',
              name_ar: '',
              qty: '',
              qty_extra: '',
              unit_price: 0,
              discount_pct: '',
              tax_rate_percent: lookups.default_tax,
            },
          ],
    defaults: {
      tax: lookups.default_tax,
      tax_rates: lookups.tax_rates || [],
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
    <div class="si-stage si-stage--toolbar-first">
      ${toolbarHtml(caps, initial)}
      <div class="si-keys-bar" role="group" aria-label="اختصارات لوحة المفاتيح">
        <span class="si-count si-count--keys">
          <span class="si-key-hint" title="سطر بند جديد"><kbd class="si-field-key">F2</kbd><span class="si-key-desc">سطر جديد</span></span>
          <span class="si-key-hint" title="قائمة المواد"><kbd class="si-field-key">F3</kbd><span class="si-key-desc">قائمة مواد</span></span>
          <span class="si-key-hint" title="حذف بند المادة"><kbd class="si-field-key">F4</kbd><span class="si-key-desc">حذف بند</span></span>
          <span class="si-key-hint" title="حفظ"><kbd class="si-field-key">F10</kbd><span class="si-key-desc">حفظ</span></span>
        </span>
      </div>
      <div class="si-doc-screen-head">
        <h1 class="si-doc-screen-title" id="co-screen-title">${titleLine}</h1>
        <div class="si-doc-screen-badge">${badge}</div>
      </div>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات المستند</h2>
        </div>
        <div class="si-meta si-meta--invoice si-meta--order">
          <label class="si-f si-f--docno">
            <span class="si-f-head">رقم الطلب</span>
            <div class="si-docno-row" dir="ltr">
              <button type="button" class="si-btn si-docno-btn" id="co_prev" title="السابق — ↑ / ←">‹</button>
              <input class="si-field si-field--mono si-docno-input ${
                locked ? 'is-approved' : initial.order_no || initial.id ? 'is-saved' : ''
              }" id="co_no" type="text" value="${esc(initial.order_no)}" readonly placeholder="2026-1 — Enter بحث" dir="ltr"
                     title="↑/← سابق · ↓/→ تالٍ · اكتب الرقم ثم Enter للبحث">
              <button type="button" class="si-btn si-docno-btn" id="co_next" title="التالي — ↓ / →">›</button>
            </div>
          </label>
          <label class="si-f si-f--date">
            <span class="si-f-head">التاريخ</span>
            <input class="si-field si-field--mono" id="co_date" type="date" value="${esc(initial.order_date)}" ${locked ? 'readonly' : ''} data-nav="1">
          </label>
          <label class="si-f si-f--pay">
            <span class="si-f-head">النوع</span>
            <select class="si-field" id="co_pay" ${locked ? 'disabled' : ''} data-nav="1">
              <option value="credit"${initial.payment_type === 'credit' ? ' selected' : ''}>ذمم</option>
              <option value="cash"${initial.payment_type === 'cash' ? ' selected' : ''}>نقدي</option>
            </select>
          </label>
          <label class="si-f si-f--rep">
            <span class="si-f-head">المندوب</span>
            <select class="si-field" id="co_rep" ${locked ? 'disabled' : ''} data-nav="1">${repOpts}</select>
          </label>
          <label class="si-f si-f--wh">
            <span class="si-f-head">المستودع</span>
            <select class="si-field" id="co_wh" ${locked ? 'disabled' : ''} data-nav="1">
              <option value="">—</option>
              ${whOpts}
            </select>
          </label>
          <label class="si-f si-f--cat-filter">
            <span class="si-f-head">فئة Oracle</span>
            <select class="si-field" id="co-oracle-cat-filter" ${locked ? 'disabled' : ''} title="تصفية قائمة المواد حسب فئة Oracle" data-nav="1">
              <option value="">— كل الفئات —</option>
            </select>
          </label>
          <label class="si-f si-f--cust">
            <span class="si-f-head">
              العميل
              <span class="si-key-hint" title="اختيار عميل"><kbd class="si-field-key">F7</kbd><span class="si-key-desc">بحث عميل</span></span>
              <span id="co_price_mode_hint" class="si-price-mode" hidden></span>
            </span>
            <div class="si-cust-wrap">
              <input type="hidden" id="co_customer_id" value="${initial.customer_id || ''}">
              <input class="si-field" id="co_customer" type="search" placeholder="ابحث بالاسم أو الرمز…"
                     value="${esc(initial.customer_label)}" autocomplete="off" ${locked ? 'readonly' : ''} data-nav="1">
              <div class="si-suggest" id="cust_suggest" hidden></div>
            </div>
          </label>
        </div>
      </section>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بنود الطلب</h2>
        </div>
        <div class="si-lines-wrap">
          <table class="si-lines si-lines--co" id="co-lines">
            ${linesColgroup()}
            <thead>
              <tr>
                <th>#</th>
                <th>رقم المادة</th>
                <th>الباركود</th>
                <th>اسم المادة</th>
                <th>الوحدة</th>
                <th>الكمية</th>
                <th>إضافية</th>
                <th>السعر</th>
                <th>خصم %</th>
                <th>ضريبة %</th>
                <th>الصافي</th>
                <th>الإجمالي</th>
                <th class="si-col-del" title="حذف">حذف</th>
              </tr>
            </thead>
            <tbody id="co-lines-body"></tbody>
          </table>
        </div>
        <div class="si-doc-foot">
          <div class="si-totals">
            <label>خصم مستوى الطلب
              <input class="si-field" id="co_discount" type="text" value="${esc(initial.invoice_discount)}"
                     placeholder="10 أو 10% أو 1.000" ${locked ? 'readonly' : ''}>
            </label>
            <div class="si-tot-row"><span>بدون ضريبة</span><strong id="sum_sub" dir="ltr">0.000</strong></div>
            <div class="si-tot-row"><span>الضريبة</span><strong id="sum_tax" dir="ltr">0.000</strong></div>
            <div class="si-tot-row si-tot-grand"><span>الإجمالي</span><strong id="sum_grand" dir="ltr">0.000</strong></div>
          </div>
          <label class="si-notes">ملاحظات
            <textarea id="co_notes" rows="3" ${locked ? 'readonly' : ''} placeholder="اختياري…">${esc(initial.notes)}</textarea>
          </label>
        </div>
      </section>

      <section class="si-surface co-ora-ar-panel" id="co-ora-ar-panel" ${
        initial.customer_id ? '' : 'hidden'
      }>
        <div class="si-surface-head">
          <h2>رصيد العميل والشيكات</h2>
          <span class="si-count" id="co-ora-ar-name">—</span>
        </div>
        <p class="co-ora-ar-status muted" id="co-ora-ar-status">
          اختر عميلاً لعرض الرصيد (مدين / دائن) والشيكات قيد التحصيل.
        </p>
        <div class="co-ora-ar-summary" id="co-ora-ar-summary" hidden>
          <div class="co-ora-ar-kpis">
            <div class="co-ora-ar-kpi co-ora-ar-kpi--debit">
              <span>مجموع المدين</span>
              <strong id="co-ora-ar-debit" dir="ltr">0</strong>
            </div>
            <div class="co-ora-ar-kpi co-ora-ar-kpi--credit">
              <span>مجموع الدائن</span>
              <strong id="co-ora-ar-credit" dir="ltr">0</strong>
            </div>
            <div class="co-ora-ar-kpi co-ora-ar-kpi--due">
              <span>الرصيد المستحق</span>
              <strong id="co-ora-ar-balance" dir="ltr">0</strong>
            </div>
          </div>
          <p class="co-ora-ar-meta muted" id="co-ora-ar-meta"></p>
          <div class="co-ora-ar-chq-wrap">
            <h3 class="co-ora-ar-chq-title">الشيكات قيد التحصيل</h3>
            <div class="co-ora-ar-table-wrap">
              <table class="co-ora-ar-table">
                <thead>
                  <tr>
                    <th>الشيك</th>
                    <th>التاريخ</th>
                    <th>قيمة الشيك</th>
                    <th>تاريخ القبض</th>
                  </tr>
                </thead>
                <tbody id="co-ora-ar-chq-body">
                  <tr><td colspan="4" class="muted">—</td></tr>
                </tbody>
              </table>
            </div>
            <div class="co-ora-ar-chq-total">
              <span>مجموع الشيكات قيد التحصيل</span>
              <strong id="co-ora-ar-chq-total" dir="ltr">0</strong>
            </div>
          </div>
          <p class="co-ora-ar-actions">
            <a class="si-btn" id="co-ora-ar-full-link" href="#" target="_blank" rel="noopener" hidden>فتح الكشف التفصيلي</a>
            <button type="button" class="si-btn" id="co-ora-ar-refresh">تحديث</button>
          </p>
        </div>
      </section>

      ${
        caps.canPostOracle
          ? `
      <div id="co-batch-modal" class="co-batch-modal" hidden aria-hidden="true">
        <div class="co-batch-panel" role="dialog" aria-labelledby="co-batch-title">
          <div class="co-batch-head">
            <h3 id="co-batch-title">ترحيل الكميات</h3>
            <p id="co-batch-sub" class="muted" hidden></p>
          </div>
          <div class="co-batch-body-wrap">
            <table class="co-batch-table co-batch-table--alloc">
              <thead>
                <tr>
                  <th>#</th>
                  <th>المادة</th>
                  <th>الفئة</th>
                  <th>الكمية</th>
                  <th>إضافي</th>
                  <th>من التشغيلة</th>
                  <th>التشغيلة</th>
                  <th>الرصيد</th>
                </tr>
              </thead>
              <tbody id="co-batch-rows"></tbody>
            </table>
          </div>
          <div class="co-batch-foot">
            <span id="co-batch-status" class="co-batch-status" aria-live="polite"></span>
            <button type="button" id="co-batch-cancel" class="si-btn">إلغاء</button>
            <button type="button" id="co-batch-confirm" class="si-btn si-tb--post">ترحيل الكميات</button>
          </div>
        </div>
      </div>`
          : ''
      }
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
      css: ['/assets/css/sales-2027.css', '/assets/css/customer-order-doc.css'],
      js: ['/assets/js/doc-nav.js', '/assets/js/hx-offers-client.js', '/assets/js/customer-order.js'],
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
router.get('/api/sales/customer-orders/by-no', async (req, res) => {
  try {
    const id = await svc.findOrderIdByNo(req.query.no);
    if (!id) return res.status(404).json({ ok: false, error: 'لم يُعثر على طلب بهذا الرقم' });
    res.json({ ok: true, id });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

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
    const rows = await invSvc.searchItems(String(req.query.q || ''), 50, {
      cat: String(req.query.cat || req.query.category || ''),
    });
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/customer-orders/item-categories', async (req, res) => {
  try {
    const rows = await invSvc.listItemCategoriesOracle();
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

/** رصيد المدين/الدائن + الشيكات قيد التحصيل (Oracle) عند اختيار العميل */
router.get('/api/sales/customer-orders/customer-ar', async (req, res) => {
  try {
    const customerId = Number(req.query.customer_id || req.query.id || 0);
    const data = await svc.getCustomerArSummary(customerId);
    data.statement_url = oracleStatementUrl(req.session.user, customerId, data);
    res.json(data);
  } catch (e) {
    res.status(500).json({ ok: false, message: e.message || 'خطأ' });
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

router.post('/api/sales/customer-orders/:id/oracle-batches', async (req, res) => {
  try {
    if (!canApprove(req.session.user)) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية.' });
    }
    const catPicks = Array.isArray(req.body?.cat_picks) ? req.body.cat_picks : [];
    const needOverrides = Array.isArray(req.body?.qty_overrides)
      ? req.body.qty_overrides
      : Array.isArray(req.body?.need_overrides)
        ? req.body.need_overrides
        : [];
    const result = await svc.fetchOracleBatches(req.params.id, {
      cat_picks: catPicks,
      need_overrides: needOverrides,
      qty_overrides: needOverrides,
    });
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/customer-orders/:id/post-oracle', async (req, res) => {
  try {
    if (!canApprove(req.session.user)) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية ترحيل إلى Oracle.' });
    }
    const dry = String(req.query.dry || req.body?.dry || '') === '1';
    const batchPicks = Array.isArray(req.body?.batch_picks) ? req.body.batch_picks : [];
    const needOverrides = Array.isArray(req.body?.qty_overrides)
      ? req.body.qty_overrides
      : Array.isArray(req.body?.need_overrides)
        ? req.body.need_overrides
        : [];
    const result = await svc.postOrderToOracle(
      req.params.id,
      req.session.user.id,
      dry,
      batchPicks,
      needOverrides
    );
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
