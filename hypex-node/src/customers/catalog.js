'use strict';

/**
 * كتالوج شاشات/تقارير قسم العملاء (مطابق nav_menu)
 */
const customersCatalog = [
  {
    group: 'master',
    title: 'العملاء',
    items: [
      { r: 'customers', label: 'العملاء', icon: '👤', path: '/customers/list', kind: 'list' },
      { r: 'customer_regions', label: 'مناطق العملاء', icon: '🗺️', path: '/customers/regions', kind: 'list' },
      { r: 'oracle_customers_sync', label: 'تكامل Oracle — العملاء', icon: '🔗', path: '/customers/oracle-sync', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير العملاء',
    items: [
      { r: 'report_customers', label: 'تقرير العملاء', icon: '👥', path: '/customers/reports/list', kind: 'report' },
      { r: 'report_customers_by_rep', label: 'تقرير العملاء حسب المندوب', icon: '👤', path: '/customers/reports/by-rep', kind: 'report' },
    ],
  },
];

function flatCustomersItems() {
  return customersCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group, groupTitle: g.title })));
}

module.exports = { customersCatalog, flatCustomersItems };
