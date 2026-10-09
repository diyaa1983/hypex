'use strict';

/**
 * خريطة كل شاشات النظام: code → { path, label, icon, kind, domain }
 */
const { salesCatalog } = require('../sales/catalog');
const { purchasesCatalog } = require('../purchases/catalog');
const { customersCatalog } = require('../customers/catalog');
const { salesRepsCatalog } = require('../sales-reps/catalog');
const { suppliersCatalog } = require('../suppliers/catalog');
const { accountingCatalog } = require('../accounting/catalog');
const { inventoryCatalog } = require('../inventory/catalog');
const { hrCatalog } = require('../hr/catalog');
const { systemCatalog } = require('../system/catalog');
const { mainCatalog } = require('../main/catalog');

const DOMAIN_CATALOGS = [
  { id: 'main', title: 'رئيسي', icon: '⌂', hub: '/app', catalog: mainCatalog },
  { id: 'sales', title: 'المبيعات', icon: '🧾', hub: '/hub/sales', catalog: salesCatalog },
  { id: 'customers', title: 'العملاء', icon: '👤', hub: '/hub/customers', catalog: customersCatalog },
  { id: 'suppliers', title: 'الموردين', icon: '🏭', hub: '/hub/suppliers', catalog: suppliersCatalog },
  { id: 'sales_reps', title: 'المندوبين', icon: '🧑‍💼', hub: '/hub/sales-reps', catalog: salesRepsCatalog },
  { id: 'purchases', title: 'المشتريات', icon: '🛒', hub: '/hub/purchases', catalog: purchasesCatalog },
  { id: 'inventory', title: 'المستودعات', icon: '📦', hub: '/hub/inventory', catalog: inventoryCatalog },
  { id: 'accounting', title: 'المحاسبة', icon: '⚖', hub: '/hub/accounting', catalog: accountingCatalog },
  { id: 'hr', title: 'شؤون الموظفين', icon: '👥', hub: '/hub/hr', catalog: hrCatalog },
  { id: 'system', title: 'النظام', icon: '⚙', hub: '/hub/system', catalog: systemCatalog },
];

/** @type {Map<string, {r:string,path:string,label:string,icon:string,kind:string,domain:string,groupTitle:string}>} */
const byCode = new Map();
const byPath = new Map();

for (const dom of DOMAIN_CATALOGS) {
  for (const g of dom.catalog) {
    for (const it of g.items) {
      const entry = {
        r: it.r,
        path: it.path,
        label: it.label,
        icon: it.icon || '·',
        kind: it.kind || 'screen',
        domain: dom.id,
        domainTitle: dom.title,
        groupTitle: g.title,
      };
      byCode.set(it.r, entry);
      if (it.path) byPath.set(it.path, entry);
    }
  }
}

// مسار لوحة التحكم
byCode.set('dashboard', {
  r: 'dashboard',
  path: '/app',
  label: 'لوحة التحكم',
  icon: '⌂',
  kind: 'list',
  domain: 'main',
  domainTitle: 'رئيسي',
  groupTitle: 'عام',
});

function resolveScreen(code) {
  return byCode.get(code) || null;
}

function normalizePath(pathname) {
  let p = String(pathname || '').trim();
  if (!p) return '';
  if (!p.startsWith('/')) p = '/' + p;
  if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
  return p;
}

/** مطابقة مسار الشاشة — بما فيها /sales/orders/123 → /sales/orders/new */
function resolvePath(pathname) {
  const p = normalizePath(pathname);
  if (!p) return null;
  if (byPath.has(p)) return byPath.get(p);

  // مسارات مستندات بمعرّف: /sales/orders/12 → شاشة الإدخال
  const docPrefixes = [
    ['/sales/orders/', 'sales_customer_orders'],
    ['/sales/invoices/', 'sales_invoices'],
    ['/sales/returns/', 'sales_returns'],
    ['/sales/offers/', 'sales_offers'],
    ['/sales/delivery/', 'sales_delivery'],
    ['/sales/order-returns/', 'sales_customer_order_returns'],
    ['/purchases/invoices/', 'purchase_invoices'],
    ['/purchases/orders/', 'purchase_orders'],
    ['/purchases/returns/', 'purchase_returns'],
  ];
  for (const [prefix, code] of docPrefixes) {
    if (p.startsWith(prefix) && p.length > prefix.length) {
      const rest = p.slice(prefix.length);
      // تجاهل مسارات فرعية مثل print إن وُجدت في الخريطة لاحقاً
      if (/^\d+(\/|$)/.test(rest) || rest === 'new' || rest === 'entry') {
        return byCode.get(code) || null;
      }
    }
  }

  // أطول بادئة معروفة في الكتالوج
  let best = null;
  let bestLen = 0;
  for (const [path, entry] of byPath.entries()) {
    if (p === path || p.startsWith(path + '/')) {
      if (path.length > bestLen) {
        best = entry;
        bestLen = path.length;
      }
    }
  }
  return best;
}

module.exports = {
  DOMAIN_CATALOGS,
  byCode,
  resolveScreen,
  resolvePath,
};
