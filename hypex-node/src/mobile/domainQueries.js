'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('mobile query', e.message);
    return [];
  }
}

async function recentSales(limit = 30) {
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS customer_name
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status = 'confirmed'
     ORDER BY i.id DESC LIMIT ${Math.min(100, limit)}`
  );
}

async function listLocations() {
  return safeQuery(
    `SELECT l.user_id, l.latitude, l.longitude, l.captured_at, u.username, u.full_name_ar
     FROM sys_user_location l
     LEFT JOIN sys_user u ON u.id = l.user_id
     ORDER BY l.captured_at DESC LIMIT 100`
  );
}

async function listTracks() {
  const from = monthStart();
  const to = todayIso();
  return safeQuery(
    `SELECT t.id, t.latitude, t.longitude, t.captured_at, u.username, u.full_name_ar
     FROM sys_user_location_track t
     LEFT JOIN sys_user u ON u.id = t.user_id
     WHERE DATE(t.captured_at) BETWEEN ? AND ?
     ORDER BY t.id DESC LIMIT 150`,
    [from, to]
  );
}

async function repStockSummary() {
  return safeQuery(
    `SELECT w.id, w.code, w.name_ar,
            COALESCE((SELECT SUM(m.qty_delta) FROM inv_stock_move m WHERE m.warehouse_id = w.id),0) AS qty
     FROM inv_warehouse w
     WHERE w.is_active = 1
     ORDER BY w.name_ar LIMIT 50`
  );
}

async function repCustodyMoves() {
  return safeQuery(
    `SELECT m.id, m.move_no, m.move_date, m.movement_type_code, m.status, w.name_ar AS warehouse_name
     FROM inv_wh_move m
     LEFT JOIN inv_warehouse w ON w.id = m.warehouse_id
     WHERE m.movement_type_code LIKE '%rep%' OR m.movement_type_code LIKE '%custody%' OR m.movement_type_code LIKE '%عهد%'
        OR m.movement_type_code LIKE '%load%' OR m.notes LIKE '%عهد%'
     ORDER BY m.id DESC LIMIT 80`
  );
}

async function homeKpis() {
  const sales = await safeQuery(
    `SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS s FROM sal_invoice
     WHERE status='confirmed' AND invoice_date >= ?`,
    [monthStart()]
  );
  const cust = await safeQuery(`SELECT COUNT(*) AS c FROM crm_customer WHERE is_active=1`);
  return {
    month_invoices: Number(sales[0]?.c || 0),
    month_sales: Number(sales[0]?.s || 0),
    customers: Number(cust[0]?.c || 0),
  };
}

module.exports = {
  recentSales,
  listLocations,
  listTracks,
  repStockSummary,
  repCustodyMoves,
  homeKpis,
};
