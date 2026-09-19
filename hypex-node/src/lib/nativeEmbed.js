'use strict';

/**
 * تحويل رموز الشاشات القديمة (PHP /embed) إلى مسارات Node الأصلية.
 * لا يُفتح iframe PHP من هنا.
 */

const { resolveScreen } = require('./screenMap');

/** شاشات المستندات: ?id= → /path/:id وبدون id → /path/new أو القائمة */
const DOC_ID_ROUTES = {
  sales_invoices: { byId: (id) => `/sales/invoices/${id}`, fresh: '/sales/invoices/new', list: '/sales/documents' },
  sales_documents_list: { list: '/sales/documents', fresh: '/sales/invoices/new' },
  sales_invoices_list: { list: '/sales/posting' },
  sales_returns: { byId: (id) => `/sales/returns/${id}`, fresh: '/sales/returns/new', list: '/sales/returns' },
  sales_returns_list: { list: '/sales/returns' },
  sales_returns_documents_list: { list: '/sales/returns' },
  sales_customer_orders: { byId: (id) => `/sales/orders/${id}`, fresh: '/sales/orders/new', list: '/sales/orders' },
  sales_customer_order_entry: { byId: (id) => `/sales/orders/${id}`, fresh: '/sales/orders/new', list: '/sales/orders' },
  sales_delivery: { byId: (id) => `/sales/delivery/${id}`, fresh: '/sales/delivery/new', list: '/sales/delivery' },
  sales_offers: { byId: (id) => `/sales/offers/${id}`, fresh: '/sales/offers/new', list: '/sales/offers' },
  purchase_invoices: { byId: (id) => `/purchases/invoices/${id}`, fresh: '/purchases/invoices/new', list: '/purchases/invoices' },
  purchase_invoices_list: { list: '/purchases/posting' },
  purchase_documents_list: { list: '/purchases/invoices' },
  purchase_orders: { byId: (id) => `/purchases/orders/${id}`, fresh: '/purchases/orders/new', list: '/purchases/orders' },
  purchase_orders_list: { list: '/purchases/orders/approve' },
  purchase_orders_documents_list: { list: '/purchases/orders' },
  purchase_returns: { byId: (id) => `/purchases/returns/${id}`, fresh: '/purchases/returns/new', list: '/purchases/returns' },
  purchase_returns_list: { list: '/purchases/returns/posting' },
  warehouse_moves: { byId: (id) => `/inventory/moves?id=${id}`, fresh: '/inventory/moves', list: '/inventory/moves' },
  inventory_stocktake: { byId: (id) => `/inventory/stocktake?id=${id}`, fresh: '/inventory/stocktake', list: '/inventory/stocktake' },
  journal_voucher: { byId: (id) => `/accounting/journal-voucher?id=${id}`, fresh: '/accounting/journal-voucher?new=1', list: '/accounting/journals' },
  journal_entries: { list: '/accounting/journals' },
  cash_receipt: { byId: (id) => `/accounting/receipts/${id}`, fresh: '/accounting/receipts/new', list: '/accounting/receipts' },
  cash_payment: { byId: (id) => `/accounting/payments/${id}`, fresh: '/accounting/payments/new', list: '/accounting/payments' },
  debit_notes: { byId: (id) => `/accounting/debit-notes?id=${id}`, fresh: '/accounting/debit-notes', list: '/accounting/debit-notes' },
  credit_notes: { byId: (id) => `/accounting/credit-notes?id=${id}`, fresh: '/accounting/credit-notes', list: '/accounting/credit-notes' },
  fin_checks: { byId: (id) => `/accounting/checks?id=${id}`, list: '/accounting/checks' },
  fin_outgoing_checks: { list: '/accounting/outgoing-checks' },
  customers: { byId: (id) => `/customers/${id}`, fresh: '/customers/new', list: '/customers' },
  suppliers: { byId: (id) => `/suppliers/${id}`, fresh: '/suppliers/new', list: '/suppliers' },
  sales_reps: { byId: (id) => `/sales-reps/${id}`, fresh: '/sales-reps/new', list: '/sales-reps' },
  items: { byId: (id) => `/inventory/items?id=${id}`, fresh: '/inventory/items', list: '/inventory/items' },
  oracle_customers_sync: { list: '/customers/oracle-sync' },
  menu_hub: { list: '/app' },
  dashboard: { list: '/app' },
};

function embedQueryString(query, omitKeys = ['id', 'new', 'r']) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query || {})) {
    if (omitKeys.includes(k)) continue;
    if (v == null) continue;
    if (Array.isArray(v)) v.forEach((x) => qs.append(k, String(x)));
    else qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

/**
 * @returns {{ ok:true, url:string } | { ok:false, screen:object|null }}
 */
function resolveNativeEmbedTarget(code, query = {}) {
  const c = String(code || '').trim();
  if (!c) return { ok: false, screen: null };

  const sc = resolveScreen(c);
  const q = query || {};
  const id = Number(q.id || 0) || 0;
  const isNew = String(q.new || '') === '1';

  const doc = DOC_ID_ROUTES[c];
  if (doc) {
    if (id > 0 && doc.byId) return { ok: true, url: doc.byId(id) };
    if (isNew && doc.fresh) return { ok: true, url: doc.fresh };
    if (doc.list) return { ok: true, url: doc.list };
    if (doc.fresh) return { ok: true, url: doc.fresh };
  }

  if (sc && sc.path) {
    // doc مع id: حاول إلحاق /:id إن كان المسار ينتهي بـ /new
    let path = sc.path;
    if (id > 0 && /\/new$/.test(path)) {
      path = path.replace(/\/new$/, `/${id}`);
      return { ok: true, url: path };
    }
    if (id > 0 && (sc.kind === 'doc' || sc.kind === 'list')) {
      // لا نضيف ?id= لمسارات قائمة عامة إن لم تُعرَّف في DOC_ID_ROUTES
      const qs = embedQueryString(q);
      return { ok: true, url: path + qs };
    }
    return { ok: true, url: path + embedQueryString(q) };
  }

  return { ok: false, screen: sc };
}

module.exports = {
  DOC_ID_ROUTES,
  resolveNativeEmbedTarget,
  embedQueryString,
};
