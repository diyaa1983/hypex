'use strict';

/**
 * كتالوج شاشات/تقارير قائمة المبيعات (مطابق nav_menu).
 * path: مسار Node — الكل بنفس تصميم 2027
 */
const salesCatalog = [
  {
    group: 'operations',
    title: 'المبيعات',
    items: [
      { r: 'sales_invoices', label: 'فاتورة مبيعات', icon: '🧾', path: '/sales/invoices', kind: 'doc' },
      { r: 'sales_documents_list', label: 'قائمة فواتير المبيعات', icon: '📑', path: '/sales/documents', kind: 'list' },
      { r: 'sales_unpaid_invoices', label: 'فواتير البيع غير المسددة', icon: '🔴', path: '/sales/unpaid', kind: 'list' },
      { r: 'sales_invoices_list', label: 'ترحيل فواتير المبيعات', icon: '📋', path: '/sales/posting', kind: 'list' },
    ],
  },
  {
    group: 'customer_orders',
    title: 'طلبات شراء العملاء',
    items: [
      { r: 'sales_customer_orders', label: 'طلبات شراء العملاء', icon: '📝', path: '/sales/orders', kind: 'list' },
      { r: 'sales_customer_order_entry', label: 'طلب شراء عميل جديد', icon: '➕', path: '/sales/orders/new', kind: 'doc' },
      { r: 'sales_customer_orders_approve', label: 'اعتماد طلبات الشراء', icon: '✅', path: '/sales/orders/approve', kind: 'list' },
      { r: 'sales_customer_orders_approved', label: 'الطلبات المعتمدة', icon: '📦', path: '/sales/orders/approved', kind: 'list' },
      { r: 'report_customer_orders', label: 'تقرير طلبات الشراء', icon: '📊', path: '/sales/reports/customer-orders', kind: 'report' },
      { r: 'report_customer_orders_by_item', label: 'طلبات الشراء للعميل حسب مادة معينة', icon: '📦', path: '/sales/reports/customer-orders-by-item', kind: 'report' },
      { r: 'sales_customer_order_returns', label: 'مرتجع طلب شراء عميل', icon: '↩️', path: '/sales/order-returns', kind: 'list' },
      { r: 'report_customer_order_returns', label: 'تقرير مرتجعات طلبات الشراء', icon: '📉', path: '/sales/reports/order-returns', kind: 'report' },
    ],
  },
  {
    group: 'delivery',
    title: 'سند التسليم',
    items: [
      { r: 'sales_delivery', label: 'سند تسليم بضاعة', icon: '📦', path: '/sales/delivery', kind: 'list' },
      { r: 'report_sales_delivery', label: 'تقرير سندات البضاعة', icon: '📦', path: '/sales/reports/delivery', kind: 'report' },
    ],
  },
  {
    group: 'returns',
    title: 'مرتجعات المبيعات',
    items: [
      { r: 'sales_returns', label: 'مرتجع مبيعات', icon: '↩', path: '/sales/returns', kind: 'doc' },
      { r: 'sales_returns_documents_list', label: 'قائمة المرتجعات', icon: '↩️', path: '/sales/returns/documents', kind: 'list' },
      { r: 'sales_returns_list', label: 'ترحيل مرتجعات المبيعات', icon: '📋', path: '/sales/returns/posting', kind: 'list' },
      { r: 'report_sales_returns', label: 'تقرير المرتجعات', icon: '↩️', path: '/sales/reports/returns', kind: 'report' },
      { r: 'report_sales_returns_totals', label: 'إجمالي المرتجعات', icon: '∑', path: '/sales/reports/returns-totals', kind: 'report' },
    ],
  },
  {
    group: 'offers',
    title: 'العروض',
    items: [
      { r: 'sales_offers', label: 'شاشة العرض', icon: '🎁', path: '/sales/offers', kind: 'list' },
      { r: 'report_sales_offers', label: 'تقرير العروض', icon: '📋', path: '/sales/reports/offers', kind: 'report' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المبيعات',
    items: [
      { r: 'report_sales', label: 'تقرير المبيعات الشهري حسب العميل', icon: '📈', path: '/sales/reports/monthly', kind: 'report' },
      { r: 'report_sales_between_dates', label: 'تقرير المبيعات بين تاريخين', icon: '📆', path: '/sales/reports/between-dates', kind: 'report' },
      { r: 'report_sales_by_item', label: 'تقرير المبيعات حسب المادة', icon: '📦', path: '/sales/reports/by-item', kind: 'report' },
      { r: 'report_sales_by_region', label: 'تقرير المبيعات حسب المنطقة', icon: '🗺️', path: '/sales/reports/by-region', kind: 'report' },
      { r: 'report_sales_by_rep', label: 'تقرير المبيعات حسب المندوب', icon: '📊', path: '/sales/reports/by-rep', kind: 'report' },
      { r: 'report_sales_qty_extra', label: 'تقرير الكميات الإضافية', icon: '➕', path: '/sales/reports/qty-extra', kind: 'report' },
      { r: 'report_sales_invoice_discount', label: 'الخصم على الفواتير', icon: '🏷', path: '/sales/reports/discount', kind: 'report' },
    ],
  },
];

function flatSalesItems() {
  return salesCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group, groupTitle: g.title })));
}

function findByPath(pathname) {
  return flatSalesItems().find((it) => it.path === pathname) || null;
}

module.exports = { salesCatalog, flatSalesItems, findByPath };
