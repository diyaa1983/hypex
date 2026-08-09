'use strict';

/**
 * كتالوج قائمة النظام — مطابق nav_menu
 * open_sessions موجود مرتين في PHP؛ نعرضه مرة واحدة
 */
const systemCatalog = [
  {
    group: 'users',
    title: 'المستخدمون والصلاحيات',
    items: [
      { r: 'users', label: 'المستخدمون', icon: '👥', path: '/system/users', kind: 'list' },
      { r: 'groups', label: 'المجموعات', icon: '📁', path: '/system/groups', kind: 'list' },
      { r: 'permissions', label: 'الصلاحيات', icon: '🔐', path: '/system/permissions', kind: 'list' },
      { r: 'open_sessions', label: 'الجلسات المفتوحة', icon: '🔌', path: '/system/sessions', kind: 'list' },
    ],
  },
  {
    group: 'settings',
    title: 'إعدادات النظام',
    items: [
      { r: 'settings', label: 'الإعدادات', icon: '⚙', path: '/system/settings', kind: 'list' },
      {
        r: 'dashboard_accounts_settings',
        label: 'حسابات الشاشة الرئيسية',
        icon: '⌂',
        path: '/system/dashboard-accounts',
        kind: 'list',
      },
      { r: 'system_backup', label: 'النسخ الاحتياطي', icon: '💾', path: '/system/backup', kind: 'list' },
      {
        r: 'tax_rates_settings',
        label: 'معدّلات الضريبة',
        icon: '%',
        path: '/system/tax-rates',
        kind: 'list',
      },
      {
        r: 'einvoice_settings',
        label: 'إعدادات الفوترة',
        icon: '🧾',
        path: '/system/einvoice',
        kind: 'list',
      },
      {
        r: 'report_audit_log',
        label: 'حركات التعديل',
        icon: '📝',
        path: '/system/audit-log',
        kind: 'report',
      },
      {
        r: 'system_error_log',
        label: 'سجل أخطاء النظام',
        icon: '⚠',
        path: '/system/error-log',
        kind: 'report',
      },
      {
        r: 'sales_invoice_gps',
        label: 'مواقع فواتير البيع',
        icon: '📍',
        path: '/system/invoice-gps',
        kind: 'list',
      },
      {
        r: 'user_gps_locations',
        label: 'مواقع المستخدمين',
        icon: '🗺',
        path: '/system/user-locations',
        kind: 'list',
      },
      {
        r: 'user_gps_tracker',
        label: 'تتبّع المواقع الحية',
        icon: '📡',
        path: '/system/gps-tracker',
        kind: 'list',
      },
      {
        r: 'gps_tracking_settings',
        label: 'إعدادات تتبّع الهاتف',
        icon: '⚙',
        path: '/system/gps-settings',
        kind: 'list',
      },
    ],
  },
];

function flatSystemItems() {
  return systemCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { systemCatalog, flatSystemItems };
