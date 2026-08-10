'use strict';

/** شاشات المندوبين فقط — تقارير المبيعات حسب المندوب/المنطقة ضمن قائمة المبيعات. */
const salesRepsCatalog = [
  {
    group: 'ops',
    title: 'المندوبين',
    items: [
      { r: 'sales_reps', label: 'المندوبين', icon: '🧑‍💼', path: '/sales-reps/list', kind: 'list' },
      { r: 'sales_rep_route', label: 'خط سير المندوب', icon: '🗺️', path: '/sales-reps/route', kind: 'list' },
    ],
  },
];

function flatSalesRepsItems() {
  return salesRepsCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { salesRepsCatalog, flatSalesRepsItems };
