<?php

declare(strict_types=1);



/**

 * مسارات تطبيق الهاتف فقط — نفس قاعدة البيانات، واجهة منفصلة.

 * الصلاحيات عبر مجموعة MOBILE في sys_group.

 * لا تُحمَّل مع index.php؛ أي CSS/JS للموبايل تحت assets/mobile/ و m/ فقط.

 *

 * icon: مفتاح أيقونة SVG — includes/mobile_icons.php

 */

return [

    'm_home' => [

        'file' => 'modules/mobile/home.php',

        'permission' => 'm_home',

        'title' => 'الرئيسية',

    ],

    'm_sales_invoices' => [

        'file' => 'modules/mobile/sales_invoice.php',

        'permission' => 'm_sales_invoices',

        'title' => 'فواتير المبيعات',

        'icon' => 'invoice',

        'tile_kind' => 'doc',

        'home_tile' => true,

    ],

    'm_sales_invoice_list' => [

        'file' => 'modules/mobile/sales_invoice_list.php',

        'permission' => 'm_sales_invoices',

        'title' => 'قائمة فواتير المبيعات',

        'icon' => 'list',

        'tile_kind' => 'list',

        'home_tile' => true,

    ],

    'm_sales_invoice_view' => [

        'file' => 'modules/mobile/sales_invoice_view.php',

        'permission' => 'm_sales_invoices',

        'title' => 'عرض الفاتورة',

        'home_tile' => false,

    ],

    'm_customer_orders' => [
        'file' => 'modules/mobile/customer_orders.php',
        'permission' => 'm_customer_orders',
        'title' => 'طلبات شراء العملاء',
        'icon' => 'list',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],

    'm_customer_list' => [
        'file' => 'modules/mobile/customer_list.php',
        'permission' => 'm_customer_list',
        'title' => 'قائمة العملاء',
        'home_label' => 'قائمة العملاء',
        'icon' => 'person',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_customer_orders_pending' => [
        'file' => 'modules/mobile/customer_orders.php',
        'permission' => 'm_customer_orders_pending',
        'title' => 'طلبات غير مرسلة',
        'home_label' => 'طلبات غير مرسلة',
        'icon' => 'list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_customer_orders_sent' => [
        'file' => 'modules/mobile/customer_orders.php',
        'permission' => 'm_customer_orders_sent',
        'title' => 'الطلبات المرسلة',
        'home_label' => 'الطلبات المرسلة',
        'icon' => 'list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_customer_orders_query' => [
        'file' => 'modules/mobile/customer_orders.php',
        'permission' => 'm_customer_orders_query',
        'title' => 'طلبات عملاء',
        'home_label' => 'طلبات عملاء',
        'icon' => 'list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_customer_order_returns' => [
        'file' => 'modules/mobile/customer_order_returns.php',
        'permission' => 'm_customer_order_returns',
        'title' => 'مرتجع طلب شراء عميل',
        'home_label' => 'مرتجع طلب شراء عميل',
        'icon' => 'return',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],

    'm_rep_route_today' => [
        'file' => 'modules/mobile/rep_route_today.php',
        'permission' => 'm_rep_route_today',
        'title' => 'جولات المندوبين',
        'home_label' => 'جولات المندوبين',
        'icon' => 'map-pin',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_rep_visits' => [
        'file' => 'modules/mobile/rep_visits.php',
        'permission' => 'm_rep_visits',
        'title' => 'جولات المندوبين',
        'home_label' => 'جولات المندوبين',
        'icon' => 'map-pin',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],

    'm_rep_visit_report' => [
        'file' => 'modules/mobile/rep_visit_report.php',
        'permission' => 'm_rep_visit_report',
        'title' => 'تقرير الزيارات',
        'home_label' => 'تقرير الزيارات',
        'icon' => 'list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_sales_invoice_gps' => [

        'file' => 'modules/mobile/sales_invoice_gps_list.php',

        'permission' => 'm_sales_invoices',

        'title' => 'إحداثيات مواقع فواتير البيع',

        'icon' => 'map-pin',

        'tile_kind' => 'list',

        'home_tile' => true,

    ],

    'm_user_gps_locations' => [

        'file' => 'modules/mobile/user_gps_locations_list.php',

        'permission' => 'm_user_gps_locations',

        'title' => 'مواقع المستخدمين',

        'icon' => 'map-pin',

        'tile_kind' => 'list',

        'home_tile' => true,

    ],

    'm_user_gps_tracker' => [

        'file' => 'modules/mobile/user_gps_tracker.php',

        'permission' => 'm_user_gps_tracker',

        'title' => 'تتبّع المواقع الحية',

        'icon' => 'map-pin',

        'tile_kind' => 'list',

        'home_tile' => true,

    ],

    'm_customer_add' => [
        'file' => 'modules/mobile/customer_add.php',
        'permission' => 'm_customer_add',
        'title' => 'إضافة عميل',
        'home_label' => 'إضافة عميل',
        'icon' => 'person',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],

    'm_sales_movement' => [
        'file' => 'modules/mobile/sales_movement.php',
        'permission' => 'm_sales_movement',
        'title' => 'كشف حركات المبيعات',
        'home_label' => 'كشف حركات المبيعات',
        'icon' => 'ledger',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],

    'm_party_statement' => [

        'file' => 'modules/mobile/party_statement.php',

        'permission' => 'm_party_statement',

        'title' => 'كشف حساب',

        'home_label' => 'كشف حساب',

        'icon' => 'ledger',

        'home_tile' => true,

    ],

    'm_receipt' => [
        'file' => 'modules/mobile/receipt.php',
        'permission' => 'm_receipt',
        'title' => 'سند قبض',
        'home_label' => 'سندات القبض',
        'icon' => 'receipt',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],
    'm_receipt_list' => [
        'file' => 'modules/mobile/receipt_list.php',
        'permission' => 'm_receipt',
        'title' => 'قائمة سندات القبض',
        'icon' => 'receipt-list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],
    'm_sales_returns' => [
        'file' => 'modules/mobile/sales_return.php',
        'permission' => 'm_sales_returns',
        'title' => 'مرتجع مبيعات',
        'home_label' => 'مرتجع جديد',
        'icon' => 'return',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],
    'm_sales_returns_list' => [
        'file' => 'modules/mobile/sales_return_list.php',
        'permission' => 'm_sales_returns',
        'title' => 'قائمة مرتجعات المبيعات',
        'icon' => 'return-list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],
    'm_rep_load' => [
        'file' => 'modules/mobile/rep_load.php',
        'permission' => 'm_rep_load',
        'title' => 'تحميل عهدة',
        'home_label' => 'تحميل عهدة',
        'icon' => 'load',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],
    'm_rep_custody_list' => [
        'file' => 'modules/mobile/rep_custody_list.php',
        'permission' => 'm_rep_custody_list',
        'title' => 'قائمة العهدة المستلمة',
        'home_label' => 'قائمة العهدة المستلمة',
        'icon' => 'list',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],
    'm_rep_return' => [
        'file' => 'modules/mobile/rep_return.php',
        'permission' => 'm_rep_return',
        'title' => 'إرجاع عهدة',
        'home_label' => 'إرجاع عهدة',
        'icon' => 'return',
        'tile_kind' => 'doc',
        'home_tile' => true,
    ],
    'm_rep_stock' => [
        'file' => 'modules/mobile/rep_stock.php',
        'permission' => 'm_rep_stock',
        'title' => 'رصيد المستودع',
        'home_label' => 'رصيد المستودع',
        'icon' => 'stock',
        'tile_kind' => 'list',
        'home_tile' => true,
    ],
];

