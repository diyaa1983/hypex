'use strict';

/** شاشات المندوبين فقط — تقارير المبيعات حسب المندوب/المنطقة ضمن قائمة المبيعات. */
const salesRepsCatalog = [
  {
    group: 'ops',
    title: 'المندوبين',
    items: [
      { r: 'sales_reps', label: 'المندوبين', icon: '🧑‍💼', path: '/sales-reps/list', kind: 'list' },
      { r: 'sales_rep_route', label: 'الجولات', icon: '🗺️', path: '/sales-reps/route', kind: 'list' },
      {
        r: 'sales_rep_tour_map',
        label: 'خريطة جولة المندوب',
        icon: '🧭',
        path: '/sales-reps/tour-map',
        kind: 'list',
      },
      {
        r: 'user_gps_tracker',
        label: 'تتبع مواقع المندوبين',
        icon: '📡',
        path: '/sales-reps/gps-tracker',
        kind: 'list',
      },
      {
        r: 'sales_rep_visit_checkout_approve',
        label: 'اعتماد خروج يدوي',
        icon: '🚪',
        path: '/sales-reps/visit-checkout-approve',
        kind: 'list',
      },
      {
        r: 'crm_customer_gps_approve',
        label: 'اعتماد موقع العميل',
        icon: '📍',
        path: '/sales-reps/customer-gps-approve',
        kind: 'list',
      },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المندوبين',
    items: [
      {
        r: 'report_sales_rep_customers',
        label: 'تقرير المندوبين حسب العملاء',
        icon: '👥',
        path: '/sales-reps/reports/by-customers',
        kind: 'report',
      },
      {
        r: 'report_sales_rep_tours',
        label: 'تقرير الجولات',
        icon: '📋',
        path: '/sales-reps/reports/tours',
        kind: 'report',
      },
      {
        r: 'report_sales_rep_visits',
        label: 'تقرير زيارات العملاء',
        icon: '📍',
        path: '/sales-reps/reports/visits',
        kind: 'report',
      },
      {
        r: 'report_oracle_rep_collections',
        label: 'كشف تحصيلات المندوب خلال فترة Oracle',
        icon: '💵',
        path: '/sales-reps/reports/oracle-collections',
        kind: 'report',
      },
    ],
  },
];

function flatSalesRepsItems() {
  return salesRepsCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { salesRepsCatalog, flatSalesRepsItems };
