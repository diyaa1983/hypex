'use strict';

/** شاشات تطبيق الهاتف — عرض Node + جسر للنسخة المحمولة/PHP */
const mobileCatalog = [
  {
    group: 'mobile',
    title: 'تطبيق الهاتف',
    items: [
      { r: 'm_home', label: 'الرئيسية', icon: '📱', path: '/mobile/home', kind: 'list' },
      { r: 'm_sales_invoices', label: 'فواتير المبيعات', icon: '🧾', path: '/mobile/sales-invoices', kind: 'list' },
      { r: 'm_customer_add', label: 'إضافة عميل', icon: '👤', path: '/mobile/customer-add', kind: 'bridge' },
      { r: 'm_party_statement', label: 'كشف حساب', icon: '📋', path: '/mobile/party-statement', kind: 'bridge' },
      { r: 'm_receipt', label: 'سند قبض', icon: '⬆', path: '/mobile/receipt', kind: 'bridge' },
      { r: 'm_sales_returns', label: 'مرتجع مبيعات', icon: '↩', path: '/mobile/sales-returns', kind: 'bridge' },
      { r: 'm_user_gps_locations', label: 'مواقع المستخدمين', icon: '📍', path: '/mobile/user-locations', kind: 'list' },
      { r: 'm_user_gps_tracker', label: 'تتبّع المواقع الحية', icon: '📡', path: '/mobile/gps-tracker', kind: 'list' },
      { r: 'm_rep_visits', label: 'تسجيل زيارة العميل', icon: '🗺', path: '/mobile/rep-visits', kind: 'bridge' },
      { r: 'm_rep_load', label: 'تحميل عهدة', icon: '📦', path: '/mobile/rep-load', kind: 'bridge' },
      { r: 'm_rep_return', label: 'إرجاع عهدة', icon: '↩', path: '/mobile/rep-return', kind: 'bridge' },
      { r: 'm_rep_stock', label: 'رصيد المستودع', icon: '📊', path: '/mobile/rep-stock', kind: 'list' },
      { r: 'm_rep_custody_list', label: 'قائمة عهدة المندوب', icon: '📋', path: '/mobile/rep-custody', kind: 'list' },
    ],
  },
];

function flatMobileItems() {
  return mobileCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { mobileCatalog, flatMobileItems };
