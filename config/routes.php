<?php
declare(strict_types=1);

/**
 * مسارات التطبيق: مفتاح r = كود الشاشة في sys_screen وصلاحيات المجموعات.
 *
 * عند إضافة شاشة/تقرير/قائمة جديدة:
 * 1) أنشئ جدول/جداول MySQL مستقلة (رأس + بنود إن لزم) — راجع includes/db_tables.php
 * 2) أضف ترحيلًا في database/migrations/ وحدّث database/schema.sql
 * 3) أضف سطرًا هنا (file, permission = نفس المفتاح r, title)
 * 4) أضف الرابط في config/nav_menu.php تحت المجال المناسب
 * 5) تُزامَن الشاشة تلقائيًا مع «صلاحيات الشاشات والتقارير» عند فتح النظام
 *
 * صلاحيات الأزرار الحساسة (فك ترحيل، حذف، فوترة…): config/action_permissions.php
 */
return [
    'dashboard' => [
        'file' => 'modules/dashboard/home.php',
        'permission' => 'dashboard',
        'title' => 'لوحة التحكم',
    ],
    'menu_hub' => [
        'file' => 'modules/menu/hub.php',
        'permission' => 'dashboard',
        'title' => 'القائمة',
        'hide_screen_title' => true,
    ],
    'favorites_empty' => [
        'file' => 'modules/menu/favorites_empty.php',
        'permission' => 'dashboard',
        'title' => 'المفضلة',
    ],
    'sales_invoices' => [
        'file' => 'modules/sales/invoices.php',
        'permission' => 'sales_invoices',
        'title' => 'فاتورة مبيعات',
        'hide_screen_title' => true,
    ],
    'sales_invoices_list' => [
        'file' => 'modules/sales/invoices_list.php',
        'permission' => 'sales_invoices_list',
        'title' => 'ترحيل فواتير المبيعات',
        'hide_screen_title' => true,
    ],
    'sales_documents_list' => [
        'file' => 'modules/sales/documents_list.php',
        'permission' => 'sales_documents_list',
        'title' => 'قائمة فواتير المبيعات',
        'hide_screen_title' => true,
    ],
    'sales_invoice_gps' => [
        'file' => 'modules/sales/invoice_gps_list.php',
        'permission' => 'sales_invoice_gps',
        'title' => 'إحداثيات مواقع فواتير البيع',
        'hide_screen_title' => true,
    ],
    'sales_returns_documents_list' => [
        'file' => 'modules/sales/returns_documents_list.php',
        'permission' => 'sales_returns_documents_list',
        'title' => 'قائمة المرتجعات',
        'hide_screen_title' => true,
    ],
    'sales_delivery' => [
        'file' => 'modules/sales/delivery.php',
        'permission' => 'sales_delivery',
        'title' => 'سند تسليم بضاعة',
        'hide_screen_title' => true,
    ],
    'sales_returns' => [
        'file' => 'modules/sales/returns.php',
        'permission' => 'sales_returns',
        'title' => 'مرتجع مبيعات',
        'hide_screen_title' => true,
    ],
    'sales_returns_list' => [
        'file' => 'modules/sales/returns_list.php',
        'permission' => 'sales_returns_list',
        'title' => 'ترحيل مرتجعات المبيعات',
        'hide_screen_title' => true,
    ],
    'purchase_invoices' => [
        'file' => 'modules/purchases/invoices.php',
        'permission' => 'purchase_invoices',
        'title' => 'فاتورة شراء',
        'hide_screen_title' => true,
    ],
    'purchase_documents_list' => [
        'file' => 'modules/purchases/documents_list.php',
        'permission' => 'purchase_documents_list',
        'title' => 'قائمة فواتير الشراء',
        'hide_screen_title' => true,
    ],
    'purchase_returns_documents_list' => [
        'file' => 'modules/purchases/returns_documents_list.php',
        'permission' => 'purchase_returns_documents_list',
        'title' => 'قائمة مردودات المشتريات',
        'hide_screen_title' => true,
    ],
    'purchase_invoices_list' => [
        'file' => 'modules/purchases/invoices_list.php',
        'permission' => 'purchase_invoices_list',
        'title' => 'ترحيل فواتير الشراء',
        'hide_screen_title' => true,
    ],
    'purchase_returns' => [
        'file' => 'modules/purchases/returns.php',
        'permission' => 'purchase_returns',
        'title' => 'مردود مشتريات',
        'hide_screen_title' => true,
    ],
    'purchase_returns_list' => [
        'file' => 'modules/purchases/returns_list.php',
        'permission' => 'purchase_returns_list',
        'title' => 'ترحيل مردودات المشتريات',
        'hide_screen_title' => true,
    ],
    'customers' => [
        'file' => 'modules/master/customers.php',
        'permission' => 'customers',
        'title' => 'العملاء',
        'hide_screen_title' => true,
    ],
    'sales_reps' => [
        'file' => 'modules/sales/sales_reps.php',
        'permission' => 'sales_reps',
        'title' => 'المندوبين',
        'hide_screen_title' => true,
    ],
    'suppliers' => [
        'file' => 'modules/master/suppliers.php',
        'permission' => 'suppliers',
        'title' => 'الموردون',
        'hide_screen_title' => true,
    ],
    'user_gps_locations' => [
        'file' => 'modules/system/user_gps_locations.php',
        'permission' => 'user_gps_locations',
        'title' => 'مواقع المستخدمين (GPS)',
        'hide_screen_title' => true,
    ],
    'users' => [
        'file' => 'modules/users/list.php',
        'permission' => 'users',
        'title' => 'المستخدمون',
        'hide_screen_title' => true,
    ],
    'groups' => [
        'file' => 'modules/users/groups.php',
        'permission' => 'groups',
        'title' => 'مجموعات المستخدمين',
        'hide_screen_title' => true,
    ],
    'permissions' => [
        'file' => 'modules/users/permissions.php',
        'permission' => 'permissions',
        'title' => 'صلاحيات الشاشات والتقارير',
        'hide_screen_title' => true,
    ],
    'cash_receipt' => [
        'file' => 'modules/finance/receipt.php',
        'permission' => 'cash_receipt',
        'title' => 'سند قبض',
        'hide_screen_title' => true,
    ],
    'cash_receipts_list' => [
        'file' => 'modules/finance/receipts_list.php',
        'permission' => 'cash_receipt',
        'title' => 'ترحيل سندات القبض',
        'hide_screen_title' => true,
    ],
    'cash_payment' => [
        'file' => 'modules/finance/payment.php',
        'permission' => 'cash_payment',
        'title' => 'سند صرف',
        'hide_screen_title' => true,
    ],
    'cash_payments_list' => [
        'file' => 'modules/finance/payments_list.php',
        'permission' => 'cash_payment',
        'title' => 'ترحيل سندات الصرف',
        'hide_screen_title' => true,
    ],
    'debit_notes' => [
        'file' => 'modules/finance/debit_notes.php',
        'permission' => 'debit_notes',
        'title' => 'إشعارات مدينة',
        'hide_screen_title' => true,
    ],
    'credit_notes' => [
        'file' => 'modules/finance/credit_notes.php',
        'permission' => 'credit_notes',
        'title' => 'إشعارات دائنة',
        'hide_screen_title' => true,
    ],
    'journal_voucher' => [
        'file' => 'modules/finance/journal_voucher.php',
        'permission' => 'journal_voucher',
        'title' => 'سند قيد',
        'hide_screen_title' => true,
    ],
    'chart_of_accounts' => [
        'file' => 'modules/finance/chart_of_accounts.php',
        'permission' => 'chart_of_accounts',
        'title' => 'شجرة الحسابات',
        'hide_screen_title' => true,
    ],
    'report_chart_of_accounts' => [
        'file' => 'modules/reports/chart_of_accounts_print.php',
        'permission' => 'report_chart_of_accounts',
        'title' => 'طباعة شجرة الحسابات',
    ],
    'account_mapping' => [
        'file' => 'modules/accounting/account_mapping.php',
        'permission' => 'account_mapping',
        'title' => 'ربط الحسابات المحاسبية',
        'hide_screen_title' => true,
    ],
    'acc_period_close' => [
        'file' => 'modules/accounting/period_close.php',
        'permission' => 'acc_period_close',
        'title' => 'إغلاق الأشهر المحاسبية',
        'hide_screen_title' => true,
    ],
    'inventory_align_warehouse' => [
        'file' => 'modules/accounting/inventory_align_warehouse.php',
        'permission' => 'inventory_align_warehouse',
        'title' => 'مواءمة المخزون مع المستودع',
        'hide_screen_title' => true,
    ],
    'vat_returns_repost' => [
        'file' => 'modules/accounting/vat_returns_repost.php',
        'permission' => 'account_mapping',
        'title' => 'تحديث ضريبة المردودات',
        'hide_screen_title' => true,
    ],
    'warehouses' => [
        'file' => 'modules/inventory/warehouses.php',
        'permission' => 'warehouses',
        'title' => 'المستودعات',
        'hide_screen_title' => true,
    ],
    'inv_movement_types_settings' => [
        'file' => 'modules/inventory/movement_types_settings.php',
        'permission' => 'inv_movement_types_settings',
        'title' => 'إعداد أنواع حركات المستودع',
        'hide_screen_title' => true,
    ],
    'warehouse_moves' => [
        'file' => 'modules/inventory/warehouse_moves.php',
        'permission' => 'warehouse_moves',
        'title' => 'حركات المستودع',
        'hide_screen_title' => true,
    ],
    'inventory_stocktake' => [
        'file' => 'modules/inventory/stocktake.php',
        'permission' => 'inventory_stocktake',
        'title' => 'سند جرد المواد',
        'hide_screen_title' => true,
    ],
    'report_stocktake_list' => [
        'file' => 'modules/reports/stocktake_list.php',
        'permission' => 'inventory_stocktake',
        'title' => 'قوائم الجرد',
    ],
    'items' => [
        'file' => 'modules/inventory/items.php',
        'permission' => 'items',
        'title' => 'المواد والأصناف',
        'hide_screen_title' => true,
    ],
    'item_categories' => [
        'file' => 'modules/inventory/item_categories.php',
        'permission' => 'item_categories',
        'title' => 'فئات المواد',
        'hide_screen_title' => true,
    ],
    'item_units' => [
        'file' => 'modules/inventory/item_units.php',
        'permission' => 'item_units',
        'title' => 'وحدات القياس',
        'hide_screen_title' => true,
    ],
    'journal_entries' => [
        'file' => 'modules/accounting/journal_entries.php',
        'permission' => 'journal_entries',
        'title' => 'القيود المحاسبية',
        'hide_screen_title' => true,
    ],
    'settings' => [
        'file' => 'modules/settings/general.php',
        'permission' => 'settings',
        'title' => 'الإعدادات',
        'hide_screen_title' => true,
    ],
    'system_backup' => [
        'file' => 'modules/system/backup.php',
        'permission' => 'system_backup',
        'title' => 'النسخ الاحتياطي',
        'hide_screen_title' => true,
    ],
    'tax_rates_settings' => [
        'file' => 'modules/settings/tax_rates.php',
        'permission' => 'tax_rates_settings',
        'title' => 'معدّلات الضريبة',
    ],
    'einvoice_settings' => [
        'file' => 'modules/settings/einvoice.php',
        'permission' => 'einvoice_settings',
        'title' => 'إعدادات الفوترة الإلكترونية',
        'hide_screen_title' => true,
    ],
    'report_audit_log' => [
        'file' => 'modules/reports/audit_log.php',
        'permission' => 'report_audit_log',
        'title' => 'حركات التعديل',
    ],
    'sales_send_einvoice' => [
        'file' => 'modules/system/permission_stub.php',
        'permission' => 'sales_send_einvoice',
        'title' => 'إرسال فاتورة للفوترة',
    ],
    'report_sales' => [
        'file' => 'modules/reports/sales_monthly.php',
        'permission' => 'report_sales',
        'title' => 'تقرير المبيعات الشهري حسب العميل',
    ],
    'report_sales_between_dates' => [
        'file' => 'modules/reports/sales.php',
        'permission' => 'report_sales_between_dates',
        'title' => 'تقرير المبيعات بين تاريخين',
    ],
    'report_sales_by_rep' => [
        'file' => 'modules/reports/sales_by_rep.php',
        'permission' => 'report_sales_by_rep',
        'title' => 'تقرير المبيعات حسب المندوب',
    ],
    'report_sales_by_item' => [
        'file' => 'modules/reports/sales_by_item.php',
        'permission' => 'report_sales_by_item',
        'title' => 'تقرير المبيعات حسب المادة',
    ],
    'report_sales_returns' => [
        'file' => 'modules/reports/sales_returns.php',
        'permission' => 'report_sales_returns',
        'title' => 'تقرير المرتجعات',
    ],
    'report_sales_returns_totals' => [
        'file' => 'modules/reports/sales_returns_totals.php',
        'permission' => 'report_sales_returns_totals',
        'title' => 'إجمالي المرتجعات',
    ],
    'report_sales_qty_extra' => [
        'file' => 'modules/reports/sales_qty_extra.php',
        'permission' => 'report_sales_qty_extra',
        'title' => 'تقرير الكميات الإضافية على الفواتير',
    ],
    'report_customers' => [
        'file' => 'modules/reports/customers.php',
        'permission' => 'report_customers',
        'title' => 'تقرير العملاء',
    ],
    'report_hr_employees' => [
        'file' => 'modules/reports/hr_employees.php',
        'permission' => 'hr_employees',
        'title' => 'تقرير الموظفين',
    ],
    'report_purchases' => [
        'file' => 'modules/reports/purchases.php',
        'permission' => 'report_purchases',
        'title' => 'تقرير المشتريات بين تاريخين',
    ],
    'report_purchases_by_item' => [
        'file' => 'modules/reports/purchases_by_item.php',
        'permission' => 'report_purchases_by_item',
        'title' => 'تقرير المشتريات حسب المادة',
    ],
    'report_purchase_returns' => [
        'file' => 'modules/reports/purchase_returns.php',
        'permission' => 'report_purchase_returns',
        'title' => 'تقرير مرتجعات المشتريات',
    ],
    'item_stock_movements' => [
        'file' => 'modules/inventory/item_movements.php',
        'permission' => 'item_stock_movements',
        'title' => 'كشف حركات مادة',
    ],
    'item_sale_price_adjust' => [
        'file' => 'modules/inventory/item_sale_price_adjust.php',
        'permission' => 'item_sale_price_adjust',
        'title' => 'تعديل أسعار المواد',
    ],
    'report_inventory' => [
        'file' => 'modules/reports/inventory.php',
        'permission' => 'item_stock_movements',
        'title' => 'كشف حركات مادة',
    ],
    'report_warehouse_items' => [
        'file' => 'modules/reports/warehouse_items.php',
        'permission' => 'report_warehouse_items',
        'title' => 'تقرير المواد',
    ],
    'report_warehouse_financial' => [
        'file' => 'modules/reports/warehouse_financial.php',
        'permission' => 'report_warehouse_financial',
        'title' => 'أرصدة المستودع المالية',
    ],
    'report_warehouse_moves' => [
        'file' => 'modules/reports/warehouse_moves.php',
        'permission' => 'report_warehouse_moves',
        'title' => 'تقرير حركات المستودعات',
    ],
    'report_warehouse_zero_qty' => [
        'file' => 'modules/reports/warehouse_zero_qty.php',
        'permission' => 'report_warehouse_zero_qty',
        'title' => 'المواد التي رصيدها صفر',
    ],
    'report_warehouse_negative_qty' => [
        'file' => 'modules/reports/warehouse_negative_qty.php',
        'permission' => 'report_warehouse_negative_qty',
        'title' => 'تقرير المواد السالبة',
    ],
    'report_trial_balance' => [
        'file' => 'modules/reports/trial_balance.php',
        'permission' => 'report_trial_balance',
        'title' => 'ميزان المراجعة',
    ],
    'report_trial_balance_detailed' => [
        'file' => 'modules/reports/trial_balance_detailed.php',
        'permission' => 'report_trial_balance_detailed',
        'title' => 'ميزان مراجعة تفصيلي',
    ],
    'report_journal' => [
        'file' => 'modules/reports/journal.php',
        'permission' => 'report_journal',
        'title' => 'تقرير القيود',
    ],
    'report_general_ledger' => [
        'file' => 'modules/reports/general_ledger.php',
        'permission' => 'report_general_ledger',
        'title' => 'دفتر الأستاذ العام',
    ],
    'report_account_statement' => [
        'file' => 'modules/reports/account_statement.php',
        'permission' => 'report_account_statement',
        'title' => 'كشف حساب',
    ],
    'report_income_statement' => [
        'file' => 'modules/reports/income_statement.php',
        'permission' => 'report_income_statement',
        'title' => 'قائمة الدخل',
    ],
    'report_income_statement_comprehensive' => [
        'file' => 'modules/reports/income_statement_comprehensive.php',
        'permission' => 'report_income_statement_comprehensive',
        'title' => 'تقرير الأرباح والخسائر',
    ],
    'report_balance_sheet' => [
        'file' => 'modules/reports/balance_sheet.php',
        'permission' => 'report_balance_sheet',
        'title' => 'الميزانية العمومية',
    ],
    'report_invoice_tax' => [
        'file' => 'modules/reports/vat_invoice_tax.php',
        'permission' => 'report_invoice_tax',
        'title' => 'الضريبة المستحقة على فواتير البيع',
        'vat_kind' => 'sale',
    ],
    'report_invoice_tax_purchase' => [
        'file' => 'modules/reports/vat_invoice_tax.php',
        'permission' => 'report_invoice_tax',
        'title' => 'الضريبة المستحقة على فواتير الشراء',
        'vat_kind' => 'purchase',
    ],
    'report_vat_return_tax' => [
        'file' => 'modules/reports/vat_return_tax.php',
        'permission' => 'report_vat_return_tax',
        'title' => 'الضريبة المستحقة على مردود البيع',
        'vat_kind' => 'sale',
    ],
    'report_vat_return_tax_purchase' => [
        'file' => 'modules/reports/vat_return_tax.php',
        'permission' => 'report_vat_return_tax',
        'title' => 'الضريبة المستحقة على مردود الشراء',
        'vat_kind' => 'purchase',
    ],
    'report_vat_net_payable' => [
        'file' => 'modules/reports/vat_net_payable.php',
        'permission' => 'report_vat_net_payable',
        'title' => 'صافي الضريبة المستحقة على المبيعات والمشتريات',
    ],
    'report_tax_declaration' => [
        'file' => 'modules/reports/tax_declaration.php',
        'permission' => 'report_tax_declaration',
        'title' => 'الإقرار الضريبي',
    ],
    'report_tax_ar3' => [
        'file' => 'modules/reports/tax_ar3.php',
        'permission' => 'report_tax_ar3',
        'title' => 'تقرير الضريبة (أر/3)',
    ],
    'report_receivables' => [
        'file' => 'modules/reports/receivables.php',
        'permission' => 'report_receivables',
        'title' => 'كشف ذمم العملاء',
    ],
    'report_receivables_aging' => [
        'file' => 'modules/reports/receivables_aging.php',
        'permission' => 'report_receivables_aging',
        'title' => 'أعمار الذمم',
    ],
    'report_supplier_payables' => [
        'file' => 'modules/reports/supplier_payables.php',
        'permission' => 'report_supplier_payables',
        'title' => 'كشف ذمم الموردين',
    ],
    'report_party_statement' => [
        'file' => 'modules/reports/party_statement.php',
        'permission' => 'report_party_statement',
        'title' => 'كشف حساب مورد - عميل',
    ],
    'report_customer_statement' => [
        'file' => 'modules/reports/customer_statement.php',
        'permission' => 'report_customer_statement',
        'title' => 'كشف حساب مورد - عميل',
    ],
    'report_supplier_statement' => [
        'file' => 'modules/reports/supplier_statement.php',
        'permission' => 'report_supplier_statement',
        'title' => 'كشف حساب مورد - عميل',
    ],
    'report_vouchers' => [
        'file' => 'modules/reports/vouchers.php',
        'permission' => 'report_vouchers',
        'title' => 'تقرير سندات القبض / الصرف',
    ],
    'report_incoming_checks' => [
        'file' => 'modules/reports/incoming_checks.php',
        'permission' => 'report_incoming_checks',
        'title' => 'تقرير الشيكات الواردة',
    ],
    'report_outgoing_checks' => [
        'file' => 'modules/reports/outgoing_checks.php',
        'permission' => 'report_outgoing_checks',
        'title' => 'تقرير الشيكات الصادرة',
    ],
    'hr_employees' => [
        'file' => 'modules/hr/employees.php',
        'permission' => 'hr_employees',
        'title' => 'بيانات الموظف الاساسية',
        'hide_screen_title' => true,
    ],
    'hr_employee_print' => [
        'file' => 'modules/hr/employee_print.php',
        'permission' => 'hr_employees',
        'title' => 'طباعة بطاقة موظف',
    ],
    'hr_departments' => [
        'file' => 'modules/hr/departments.php',
        'permission' => 'hr_departments',
        'title' => 'الأقسام',
        'hide_screen_title' => true,
    ],
    'hr_nationalities' => [
        'file' => 'modules/hr/nationalities.php',
        'permission' => 'hr_nationalities',
        'title' => 'الجنسيات',
        'hide_screen_title' => true,
    ],
    'hr_job_titles' => [
        'file' => 'modules/hr/job_titles.php',
        'permission' => 'hr_job_titles',
        'title' => 'المسميات الوظيفية',
        'hide_screen_title' => true,
    ],
    'hr_salaries' => [
        'file' => 'modules/hr/salaries.php',
        'permission' => 'hr_salaries',
        'title' => 'رواتب الموظفين',
        'hide_screen_title' => true,
    ],
    'hr_employee_advances' => [
        'file' => 'modules/hr/employee_advances.php',
        'permission' => 'hr_salaries',
        'title' => 'سلف الموظفين',
        'hide_screen_title' => true,
    ],
    'hr_monthly_payroll_adjustments' => [
        'file' => 'modules/hr/monthly_payroll_adjustments.php',
        'permission' => 'hr_salaries',
        'title' => 'علاوات واقتطاعات شهرية',
        'hide_screen_title' => true,
    ],
    'hr_payroll_posting' => [
        'file' => 'modules/hr/payroll_posting.php',
        'permission' => 'hr_salaries',
        'title' => 'قيد الرواتب',
        'hide_screen_title' => true,
    ],
    'hr_payroll_slip' => [
        'file' => 'modules/hr/payroll_slip_print.php',
        'permission' => 'hr_salaries',
        'title' => 'قسيمة الراتب',
    ],
    'hr_payroll_dept_report' => [
        'file' => 'modules/hr/payroll_dept_report.php',
        'permission' => 'hr_salaries',
        'title' => 'كشف الرواتب للأقسام',
        'hide_screen_title' => true,
    ],
    'hr_payroll_month_report' => [
        'file' => 'modules/hr/payroll_month_report.php',
        'permission' => 'hr_salaries',
        'title' => 'تقرير قيود الرواتب حسب الشهر',
        'hide_screen_title' => true,
    ],
    'hr_payroll_ss_report' => [
        'file' => 'modules/hr/payroll_ss_report.php',
        'permission' => 'hr_salaries',
        'title' => 'كشف الضمان الاجتماعي',
        'hide_screen_title' => true,
    ],
    'hr_payroll_slip_report' => [
        'file' => 'modules/hr/payroll_slip_report.php',
        'permission' => 'hr_salaries',
        'title' => 'قسيمة الراتب',
        'hide_screen_title' => true,
    ],
    'hr_salary_slip' => [
        'file' => 'modules/hr/salary_slip_print.php',
        'permission' => 'hr_salaries',
        'title' => 'قسيمة راتب',
    ],
    'hr_salary_banks' => [
        'file' => 'modules/hr/salary_banks.php',
        'permission' => 'hr_salary_banks',
        'title' => 'البنوك',
        'hide_screen_title' => true,
    ],
    'hr_employee_bank_link' => [
        'file' => 'modules/hr/employee_bank_link.php',
        'permission' => 'hr_employee_bank_link',
        'title' => 'ربط إعدادات البنك',
        'hide_screen_title' => true,
    ],
    'hr_payroll_components' => [
        'file' => 'modules/hr/payroll_components.php',
        'permission' => 'hr_payroll_components',
        'title' => 'إعداد العلاوات والاقتطاعات',
        'hide_screen_title' => true,
    ],
    'hr_social_security_rates' => [
        'file' => 'modules/hr/social_security_rates.php',
        'permission' => 'hr_social_security_rates',
        'title' => 'نسب الضمان الاجتماعي',
        'hide_screen_title' => true,
    ],
    'hr_social_security' => [
        'file' => 'modules/hr/social_security.php',
        'permission' => 'hr_social_security',
        'title' => 'قيود الضمان الاجتماعي',
        'hide_screen_title' => true,
    ],
    'hr_income_tax_settings' => [
        'file' => 'modules/hr/income_tax_settings.php',
        'permission' => 'hr_income_tax_settings',
        'title' => 'إعدادات ضريبة الدخل',
        'hide_screen_title' => true,
    ],
];
