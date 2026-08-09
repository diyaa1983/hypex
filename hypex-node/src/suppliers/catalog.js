'use strict';

const suppliersCatalog = [
  {
    group: 'ops',
    title: 'الموردين',
    items: [
      { r: 'suppliers', label: 'الموردين', icon: '🏭', path: '/suppliers/list', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير الموردين',
    items: [
      {
        r: 'report_suppliers',
        label: 'تقرير الموردين',
        icon: '🏭',
        path: '/suppliers/reports/list',
        kind: 'report',
      },
    ],
  },
];

function flatSuppliersItems() {
  return suppliersCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { suppliersCatalog, flatSuppliersItems };
