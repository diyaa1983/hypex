'use strict';

const salesRepsCatalog = [
  {
    group: 'ops',
    title: 'المندوبين',
    items: [
      { r: 'sales_reps', label: 'المندوبين', icon: '🧑‍💼', path: '/sales-reps/list', kind: 'list' },
      { r: 'sales_rep_route', label: 'خط سير المندوب', icon: '🗺️', path: '/sales-reps/route', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المندوبين',
    items: [
      {
        r: 'report_sales_by_rep',
        label: 'تقرير المبيعات حسب المندوب',
        icon: '📊',
        path: '/sales-reps/reports/by-rep',
        kind: 'report',
      },
      {
        r: 'report_sales_by_region',
        label: 'تقرير المبيعات حسب المنطقة',
        icon: '🗺️',
        path: '/sales-reps/reports/by-region',
        kind: 'report',
      },
    ],
  },
];

function flatSalesRepsItems() {
  return salesRepsCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { salesRepsCatalog, flatSalesRepsItems };
