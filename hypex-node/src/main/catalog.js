'use strict';

const mainCatalog = [
  {
    group: 'general',
    title: 'عام',
    items: [{ r: 'dashboard', label: 'لوحة التحكم', icon: '⌂', path: '/app', kind: 'list' }],
  },
  {
    group: 'widgets',
    title: 'مؤشرات الشاشة الرئيسية',
    items: [
      {
        r: 'dashboard_kpi_sales',
        label: 'مؤشرات المبيعات',
        icon: '📈',
        path: '/main/kpi/sales',
        kind: 'list',
      },
      {
        r: 'dashboard_kpi_journal_daily',
        label: 'مؤشر القيود اليومية',
        icon: '📒',
        path: '/main/kpi/journal',
        kind: 'list',
      },
      {
        r: 'dashboard_kpi_purchases',
        label: 'مؤشر المشتريات',
        icon: '🛒',
        path: '/main/kpi/purchases',
        kind: 'list',
      },
      {
        r: 'dashboard_kpi_cashflow',
        label: 'مؤشرات المقبوضات',
        icon: '💵',
        path: '/main/kpi/cashflow',
        kind: 'list',
      },
      {
        r: 'dashboard_kpi_receivables',
        label: 'فواتير البيع غير المسددة',
        icon: '🔴',
        path: '/main/kpi/receivables',
        kind: 'list',
      },
      {
        r: 'dashboard_kpi_payables',
        label: 'فواتير الشراء غير المدفوعة',
        icon: '🔴',
        path: '/main/kpi/payables',
        kind: 'list',
      },
      {
        r: 'dashboard_panel_treasury',
        label: 'لوحة الصندوق والحسابات',
        icon: '🏦',
        path: '/main/panel/treasury',
        kind: 'list',
      },
      {
        r: 'dashboard_panel_liabilities',
        label: 'لوحة المستحقات',
        icon: '📋',
        path: '/main/panel/liabilities',
        kind: 'list',
      },
      {
        r: 'dashboard_panel_checks',
        label: 'مؤشرات الشيكات',
        icon: '📝',
        path: '/main/panel/checks',
        kind: 'list',
      },
      {
        r: 'dashboard_panel_recent_sales',
        label: 'آخر فواتير المبيعات',
        icon: '🧾',
        path: '/main/panel/recent-sales',
        kind: 'list',
      },
    ],
  },
];

function flatMainItems() {
  return mainCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { mainCatalog, flatMainItems };
