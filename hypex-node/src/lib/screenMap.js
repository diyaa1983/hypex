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
const { mobileCatalog } = require('../mobile/catalog');
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
  { id: 'mobile', title: 'تطبيق الهاتف', icon: '📱', hub: '/hub/mobile', catalog: mobileCatalog },
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

function resolvePath(pathname) {
  return byPath.get(pathname) || null;
}

module.exports = {
  DOMAIN_CATALOGS,
  byCode,
  resolveScreen,
  resolvePath,
};
