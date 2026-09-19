'use strict';

/**
 * كتالوج شاشات/تقارير قائمة المشتريات (مطابق nav_menu)
 */
const purchasesCatalog = [
  {
    group: 'orders',
    title: 'طلبات الشراء',
    items: [
      { r: 'purchase_orders', label: 'طلب شراء', icon: '📝', path: '/purchases/orders/new', kind: 'doc' },
      { r: 'purchase_orders_documents_list', label: 'قائمة طلبات الشراء', icon: '📋', path: '/purchases/orders', kind: 'list' },
      { r: 'purchase_orders_list', label: 'اعتماد طلبات الشراء', icon: '✅', path: '/purchases/orders/approve', kind: 'list' },
      { r: 'report_purchase_orders', label: 'تقرير طلبات الشراء', icon: '📊', path: '/purchases/reports/orders', kind: 'report' },
      { r: 'report_purchase_orders_by_item', label: 'تقرير طلبات الشراء حسب المادة', icon: '📦', path: '/purchases/reports/orders-by-item', kind: 'report' },
      { r: 'report_purchase_orders_open', label: 'تقرير الطلبات المفتوحة', icon: '📂', path: '/purchases/reports/orders-open', kind: 'report' },
    ],
  },
  {
    group: 'operations',
    title: 'المشتريات',
    items: [
      { r: 'purchase_invoices', label: 'فاتورة شراء', icon: '📥', path: '/purchases/invoices/new', kind: 'doc' },
      { r: 'purchase_documents_list', label: 'قائمة فواتير الشراء', icon: '📑', path: '/purchases/invoices', kind: 'list' },
      { r: 'purchase_unpaid_invoices', label: 'فواتير الشراء غير المدفوعة', icon: '🔴', path: '/purchases/unpaid', kind: 'list' },
      { r: 'purchase_invoices_list', label: 'ترحيل فواتير الشراء', icon: '📋', path: '/purchases/posting', kind: 'list' },
      { r: 'purchase_returns', label: 'مردود مشتريات', icon: '↩', path: '/purchases/returns/new', kind: 'doc' },
      { r: 'purchase_returns_documents_list', label: 'قائمة مردودات المشتريات', icon: '↩️', path: '/purchases/returns', kind: 'list' },
      { r: 'purchase_returns_list', label: 'ترحيل مردودات المشتريات', icon: '📋', path: '/purchases/returns/posting', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المشتريات',
    items: [
      { r: 'report_purchases', label: 'تقرير المشتريات بين تاريخين', icon: '📈', path: '/purchases/reports/between-dates', kind: 'report' },
      { r: 'report_purchases_by_item', label: 'تقرير المشتريات حسب المادة', icon: '📦', path: '/purchases/reports/by-item', kind: 'report' },
      { r: 'report_purchase_returns', label: 'تقرير مرتجعات المشتريات', icon: '↩️', path: '/purchases/reports/returns', kind: 'report' },
    ],
  },
];

function flatPurchasesItems() {
  return purchasesCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group, groupTitle: g.title })));
}

module.exports = { purchasesCatalog, flatPurchasesItems };
