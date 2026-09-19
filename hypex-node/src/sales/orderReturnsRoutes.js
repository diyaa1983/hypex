'use strict';

const express = require('express');
const auth = require('../auth');
const db = require('../db');
const ui = require('../lib/salesUi');
const { esc, todayIso } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex Sales · Node';

function canView(user) {
  return (
    user.is_admin ||
    auth.userCan(user, 'sales_customer_order_returns') ||
    auth.userCan(user, 'sales_customer_orders') ||
    auth.userCan(user, 'report_customer_order_returns')
  );
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!p.startsWith('/sales/order-returns') && !p.startsWith('/sales/reports/order-returns')) {
    return next('router');
  }
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    if (!canView(req.session.user)) {
      return res.status(403).send('ممنوع');
    }
    next();
  });
});

async function listReturns({ from, to, customerId, status }) {
  const where = ['1=1'];
  const params = [];
  if (from) {
    where.push('r.return_date >= ?');
    params.push(from);
  }
  if (to) {
    where.push('r.return_date <= ?');
    params.push(to);
  }
  if (customerId) {
    where.push('r.customer_id = ?');
    params.push(customerId);
  }
  if (status === 'draft' || status === 'posted') {
    where.push('r.status = ?');
    params.push(status);
  }
  try {
    return await db.query(
      `SELECT r.id, r.return_no, r.return_date, r.status, r.total,
              c.name_ar AS customer_name, o.order_no
       FROM sal_customer_order_return r
       LEFT JOIN crm_customer c ON c.id = r.customer_id
       LEFT JOIN sal_customer_order o ON o.id = r.order_id
       WHERE ${where.join(' AND ')}
       ORDER BY r.return_date DESC, r.id DESC
       LIMIT 300`,
      params
    );
  } catch (e) {
    return [];
  }
}

router.get('/sales/order-returns', async (req, res) => {
  const from = String(req.query.from || todayIso()).slice(0, 10);
  const to = String(req.query.to || todayIso()).slice(0, 10);
  const status = String(req.query.status || '');
  const rows = await listReturns({ from, to, status: status || null });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${esc(r.return_no || '')}</td>
      <td>${esc(r.return_date || '')}</td>
      <td>${esc(r.customer_name || '')}</td>
      <td class="si-num" dir="ltr">${esc(r.order_no || '—')}</td>
      <td>${r.status === 'posted' ? ui.statusPill('ok', 'مرحّل') : ui.statusPill('wait', 'مسودة')}</td>
      <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '↩️',
        kicker: KICKER,
        title: 'مرتجع طلب شراء عميل',
        subtitle: 'يظهر بعد اعتماد وترحيل الطلب من مدير المبيعات · المندوب ينشئ المرتجع من الموبايل',
        actions: [
          { label: 'تقرير المرتجعات', href: '/sales/reports/order-returns' },
          { label: 'طلبات الشراء', href: '/sales/orders' },
        ],
      })}
      <form class="si-surface" method="get" action="/sales/order-returns" style="padding:1rem;display:flex;flex-wrap:wrap;gap:.6rem;align-items:end">
        <label>من <input class="si-field" type="date" name="from" value="${esc(from)}"></label>
        <label>إلى <input class="si-field" type="date" name="to" value="${esc(to)}"></label>
        <label>الحالة
          <select class="si-field" name="status">
            <option value="">الكل</option>
            <option value="draft"${status === 'draft' ? ' selected' : ''}>مسودة</option>
            <option value="posted"${status === 'posted' ? ' selected' : ''}>مرحّل</option>
          </select>
        </label>
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
      </form>
      ${ui.tableSurface('المرتجعات', `${rows.length} صف`, ['رقم المرتجع', 'التاريخ', 'العميل', 'طلب الشراء', 'الحالة', 'الإجمالي'], rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'مرتجع طلب شراء عميل', bodyHtml: body }));
});

router.get('/sales/reports/order-returns', async (req, res) => {
  const from = String(req.query.from || todayIso()).slice(0, 10);
  const to = String(req.query.to || todayIso()).slice(0, 10);
  const customerId = Number(req.query.customer_id || 0) || null;
  const rows = await listReturns({ from, to, customerId, status: 'posted' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${esc(r.return_no || '')}</td>
      <td>${esc(r.return_date || '')}</td>
      <td>${esc(r.customer_name || '')}</td>
      <td class="si-num" dir="ltr">${esc(r.order_no || '—')}</td>
      <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(5);
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '📉',
        kicker: KICKER,
        title: 'تقرير مرتجعات طلبات الشراء',
        subtitle: 'المرتجعات المرحّلة بين تاريخين',
        actions: [{ label: 'قائمة المرتجعات', href: '/sales/order-returns' }],
      })}
      <form class="si-surface" method="get" style="padding:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:end">
        <label>من <input class="si-field" type="date" name="from" value="${esc(from)}"></label>
        <label>إلى <input class="si-field" type="date" name="to" value="${esc(to)}"></label>
        <button class="si-btn si-btn--primary" type="submit">تحديث</button>
      </form>
      ${ui.tableSurface('التقرير', `${rows.length} صف`, ['رقم المرتجع', 'التاريخ', 'العميل', 'طلب الشراء', 'الإجمالي'], rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'تقرير مرتجعات طلبات الشراء', bodyHtml: body }));
});

module.exports = router;
