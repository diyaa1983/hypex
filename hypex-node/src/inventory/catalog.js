'use strict';

const inventoryCatalog = [
  {
    group: 'ops',
    title: 'المستودعات',
    items: [
      { r: 'warehouses', label: 'المستودعات', icon: '📦', path: '/inventory/warehouses', kind: 'list' },
      { r: 'inv_movement_types_settings', label: 'إعداد أنواع الحركات', icon: '⚙', path: '/inventory/movement-types', kind: 'list' },
      { r: 'items', label: 'بطاقة المادة', icon: '🏷', path: '/inventory/items', kind: 'list' },
      { r: 'item_categories', label: 'فئات المواد', icon: '📦', path: '/inventory/categories', kind: 'list' },
      { r: 'item_units', label: 'وحدات القياس', icon: '📐', path: '/inventory/units', kind: 'list' },
      { r: 'warehouse_moves', label: 'حركات المستودع', icon: '🔄', path: '/inventory/moves', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المستودعات',
    items: [
      { r: 'item_stock_movements', label: 'كشف حركات مادة', icon: '📋', path: '/inventory/reports/item-moves', kind: 'report' },
      { r: 'report_warehouse_items', label: 'تقرير المواد', icon: '📋', path: '/inventory/reports/items', kind: 'report' },
      { r: 'report_customer_purchases_by_item', label: 'تقرير مشتريات العميل حسب المادة', icon: '🛒', path: '/inventory/reports/customer-purchases', kind: 'report' },
      { r: 'report_warehouse_zero_qty', label: 'المواد التي رصيدها صفر', icon: '0️⃣', path: '/inventory/reports/zero-qty', kind: 'report' },
      { r: 'report_warehouse_negative_qty', label: 'تقرير المواد السالبة', icon: '➖', path: '/inventory/reports/negative-qty', kind: 'report' },
      { r: 'report_warehouse_financial', label: 'أرصدة المستودع المالية', icon: '💰', path: '/inventory/reports/financial', kind: 'bridge' },
      { r: 'report_warehouse_moves', label: 'تقرير حركات المستودعات', icon: '🔄', path: '/inventory/reports/moves', kind: 'report' },
    ],
  },
  {
    group: 'price',
    title: 'تعديل الأسعار',
    items: [
      {
        r: 'item_sale_price_adjust',
        label: 'تعديل أسعار البيع',
        icon: '💰',
        path: '/inventory/price-adjust',
        kind: 'list',
      },
      {
        r: 'report_item_price_adjustments',
        label: 'تقرير المواد المعدّلة الأسعار',
        icon: '📋',
        path: '/inventory/reports/price-adjustments',
        kind: 'report',
      },
    ],
  },
  {
    group: 'stocktake',
    title: 'الجرد',
    items: [
      { r: 'inventory_stocktake', label: 'سند جرد المواد', icon: '🧮', path: '/inventory/stocktake', kind: 'list' },
      { r: 'report_stocktake_list', label: 'قوائم الجرد', icon: '📑', path: '/inventory/reports/stocktake', kind: 'list' },
    ],
  },
];

function flatInventoryItems() {
  return inventoryCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { inventoryCatalog, flatInventoryItems };
