<?php
declare(strict_types=1);

/**
 * صلاحيات الإجراءات الحساسة (أزرار الشريط العلوي وواجهات API).
 * لا تظهر في القائمة الجانبية — تُعرض في «صلاحيات الشاشات والتقارير» تحت أقسام منفصلة.
 *
 * inherit_from: مرجع توثيقي للشاشات المرتبطة (لا يُستخدم للمنح التلقائي).
 * المنح الأولي عند إضافة كود جديد: مجموعة ADMINS فقط — باقي المجموعات من شاشة الصلاحيات.
 */
return [
    'groups' => [
        [
            'title' => 'فك الترحيل',
            'items' => [
                [
                    'code' => 'action_unpost_sales_invoice',
                    'name_ar' => 'فك ترحيل فاتورة مبيعات',
                    'inherit_from' => ['sales_invoices', 'sales_invoices_list'],
                ],
                [
                    'code' => 'action_unpost_sales_return',
                    'name_ar' => 'فك ترحيل مرتجع مبيعات',
                    'inherit_from' => ['sales_returns', 'sales_returns_list'],
                ],
                [
                    'code' => 'action_unpost_sales_delivery',
                    'name_ar' => 'فك ترحيل سند تسليم بضاعة',
                    'inherit_from' => ['sales_delivery'],
                ],
                [
                    'code' => 'action_unpost_purchase_invoice',
                    'name_ar' => 'فك ترحيل فاتورة شراء',
                    'inherit_from' => ['purchase_invoices', 'purchase_invoices_list'],
                ],
                [
                    'code' => 'action_unpost_purchase_return',
                    'name_ar' => 'فك ترحيل مردود مشتريات',
                    'inherit_from' => ['purchase_returns', 'purchase_returns_list'],
                ],
                [
                    'code' => 'action_unapprove_purchase_order',
                    'name_ar' => 'فك اعتماد طلب شراء',
                    'inherit_from' => ['purchase_orders', 'purchase_orders_list'],
                ],
                [
                    'code' => 'action_unpost_cash_receipt',
                    'name_ar' => 'فك ترحيل سند قبض',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_unpost_cash_payment',
                    'name_ar' => 'فك ترحيل سند صرف',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_edit_cash_receipt',
                    'name_ar' => 'تعديل سند قبض مرحّل',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_edit_cash_payment',
                    'name_ar' => 'تعديل سند صرف مرحّل',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_unpost_journal_voucher',
                    'name_ar' => 'فك ترحيل سند قيد',
                    'inherit_from' => ['journal_voucher'],
                ],
                [
                    'code' => 'action_edit_journal_voucher',
                    'name_ar' => 'تعديل سند قيد مرحّل',
                    'inherit_from' => ['journal_voucher'],
                ],
                [
                    'code' => 'action_unpost_warehouse_move',
                    'name_ar' => 'فك ترحيل حركة مستودع',
                    'inherit_from' => ['warehouse_moves'],
                ],
                [
                    'code' => 'action_unpost_inventory_stocktake',
                    'name_ar' => 'فك ترحيل سند جرد',
                    'inherit_from' => ['inventory_stocktake'],
                ],
                [
                    'code' => 'action_unpost_payroll',
                    'name_ar' => 'فك ترحيل رواتب الشهر (قيد الرواتب)',
                    'inherit_from' => ['hr_salaries', 'hr_payroll_posting'],
                ],
                [
                    'code' => 'action_unpost_employee_advance',
                    'name_ar' => 'فك ترحيل سلفة موظف',
                    'inherit_from' => ['hr_salaries', 'hr_employee_advances'],
                ],
                [
                    'code' => 'action_unpost_employee_departure',
                    'name_ar' => 'فك ترحيل مغادرة موظف',
                    'inherit_from' => ['hr_employee_departures'],
                ],
                [
                    'code' => 'action_unpost_employee_leave',
                    'name_ar' => 'فك ترحيل إجازة موظف',
                    'inherit_from' => ['hr_employee_leaves'],
                ],
                [
                    'code' => 'action_undo_outgoing_check',
                    'name_ar' => 'إلغاء صرف شيك صادر',
                    'inherit_from' => ['fin_outgoing_checks', 'cash_payment'],
                ],
            ],
        ],
        [
            'title' => 'إلغاء السندات',
            'items' => [
                [
                    'code' => 'action_cancel_cash_receipt',
                    'name_ar' => 'إلغاء سند قبض',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_cancel_cash_payment',
                    'name_ar' => 'إلغاء سند صرف',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_cancel_journal_voucher',
                    'name_ar' => 'إلغاء سند قيد',
                    'inherit_from' => ['journal_voucher'],
                ],
            ],
        ],
        [
            'title' => 'ترحيل المستندات',
            'items' => [
                [
                    'code' => 'action_post_sales_invoice',
                    'name_ar' => 'ترحيل فاتورة مبيعات',
                    'inherit_from' => ['sales_invoices', 'sales_invoices_list'],
                ],
                [
                    'code' => 'action_post_sales_return',
                    'name_ar' => 'ترحيل مرتجع مبيعات',
                    'inherit_from' => ['sales_returns', 'sales_returns_list'],
                ],
                [
                    'code' => 'action_post_sales_delivery',
                    'name_ar' => 'ترحيل سند تسليم بضاعة',
                    'inherit_from' => ['sales_delivery'],
                ],
                [
                    'code' => 'action_post_purchase_invoice',
                    'name_ar' => 'ترحيل فاتورة شراء',
                    'inherit_from' => ['purchase_invoices', 'purchase_invoices_list'],
                ],
                [
                    'code' => 'action_post_purchase_return',
                    'name_ar' => 'ترحيل مردود مشتريات',
                    'inherit_from' => ['purchase_returns', 'purchase_returns_list'],
                ],
                [
                    'code' => 'action_approve_purchase_order',
                    'name_ar' => 'اعتماد طلب شراء',
                    'inherit_from' => ['purchase_orders', 'purchase_orders_list'],
                ],
                [
                    'code' => 'action_post_cash_receipt',
                    'name_ar' => 'ترحيل سند قبض',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_post_cash_payment',
                    'name_ar' => 'ترحيل سند صرف',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_post_journal_voucher',
                    'name_ar' => 'ترحيل سند قيد',
                    'inherit_from' => ['journal_voucher'],
                ],
                [
                    'code' => 'action_post_item_sale_price_adjust',
                    'name_ar' => 'ترحيل تعديل أسعار البيع',
                    'inherit_from' => ['item_sale_price_adjust'],
                ],
                [
                    'code' => 'action_post_warehouse_move',
                    'name_ar' => 'ترحيل حركة مستودع',
                    'inherit_from' => ['warehouse_moves'],
                ],
                [
                    'code' => 'action_post_employee_advance',
                    'name_ar' => 'ترحيل سلفة موظف',
                    'inherit_from' => ['hr_salaries', 'hr_employee_advances'],
                ],
                [
                    'code' => 'action_post_employee_departure',
                    'name_ar' => 'ترحيل مغادرة موظف',
                    'inherit_from' => ['hr_employee_departures'],
                ],
                [
                    'code' => 'action_post_employee_leave',
                    'name_ar' => 'ترحيل إجازة موظف',
                    'inherit_from' => ['hr_employee_leaves'],
                ],
            ],
        ],
        [
            'title' => 'حذف المستندات',
            'items' => [
                [
                    'code' => 'action_delete_sales_invoice',
                    'name_ar' => 'حذف فاتورة مبيعات',
                    'inherit_from' => ['sales_invoices', 'sales_invoices_list'],
                ],
                [
                    'code' => 'action_delete_sales_return',
                    'name_ar' => 'حذف مرتجع مبيعات',
                    'inherit_from' => ['sales_returns', 'sales_returns_list'],
                ],
                [
                    'code' => 'action_delete_sales_delivery',
                    'name_ar' => 'حذف سند تسليم بضاعة',
                    'inherit_from' => ['sales_delivery'],
                ],
                [
                    'code' => 'action_delete_purchase_invoice',
                    'name_ar' => 'حذف فاتورة شراء',
                    'inherit_from' => ['purchase_invoices', 'purchase_invoices_list'],
                ],
                [
                    'code' => 'action_delete_purchase_return',
                    'name_ar' => 'حذف مردود مشتريات',
                    'inherit_from' => ['purchase_returns', 'purchase_returns_list'],
                ],
                [
                    'code' => 'action_delete_purchase_order',
                    'name_ar' => 'حذف طلب شراء',
                    'inherit_from' => ['purchase_orders', 'purchase_orders_list'],
                ],
                [
                    'code' => 'action_convert_purchase_order',
                    'name_ar' => 'تحويل طلب شراء إلى فاتورة',
                    'inherit_from' => ['purchase_orders', 'purchase_orders_list'],
                ],
                [
                    'code' => 'action_delete_cash_receipt',
                    'name_ar' => 'حذف سند قبض',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_delete_cash_payment',
                    'name_ar' => 'حذف سند صرف',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_delete_journal_voucher',
                    'name_ar' => 'حذف سند قيد',
                    'inherit_from' => ['journal_voucher'],
                ],
                [
                    'code' => 'action_delete_warehouse_move',
                    'name_ar' => 'حذف حركة مستودع',
                    'inherit_from' => ['warehouse_moves'],
                ],
                [
                    'code' => 'action_delete_inventory_stocktake',
                    'name_ar' => 'حذف سند جرد',
                    'inherit_from' => ['inventory_stocktake'],
                ],
                [
                    'code' => 'action_delete_debit_note',
                    'name_ar' => 'حذف إشعار مدينة',
                    'inherit_from' => ['debit_notes'],
                ],
                [
                    'code' => 'action_delete_credit_note',
                    'name_ar' => 'حذف إشعار دائنة',
                    'inherit_from' => ['credit_notes'],
                ],
            ],
        ],
        [
            'title' => 'الفوترة الإلكترونية',
            'items' => [
                [
                    'code' => 'sales_send_einvoice',
                    'name_ar' => 'إرسال فاتورة/مرتجع للفوترة الإلكترونية',
                    'inherit_from' => ['sales_invoices', 'sales_returns'],
                ],
            ],
        ],
        [
            'title' => 'عمليات محاسبية حساسة',
            'items' => [
                [
                    'code' => 'action_inventory_align_warehouse',
                    'name_ar' => 'تنفيذ مواءمة المخزون مع المستودع',
                    'inherit_from' => ['inventory_align_warehouse'],
                ],
            ],
        ],
        [
            'title' => 'أرشيف مرفقات السندات',
            'items' => [
                [
                    'code' => 'action_archive_cash_receipt',
                    'name_ar' => 'أرشيف مرفقات سند قبض',
                    'inherit_from' => ['cash_receipt', 'cash_receipts_list'],
                ],
                [
                    'code' => 'action_archive_cash_payment',
                    'name_ar' => 'أرشيف مرفقات سند صرف',
                    'inherit_from' => ['cash_payment', 'cash_payments_list'],
                ],
                [
                    'code' => 'action_archive_journal_voucher',
                    'name_ar' => 'أرشيف مرفقات سند قيد',
                    'inherit_from' => ['journal_voucher'],
                ],
                [
                    'code' => 'action_archive_sales_invoice',
                    'name_ar' => 'أرشيف مرفقات فاتورة مبيعات',
                    'inherit_from' => ['sales_invoices', 'sales_invoices_list'],
                ],
                [
                    'code' => 'action_archive_purchase_invoice',
                    'name_ar' => 'أرشيف مرفقات فاتورة شراء',
                    'inherit_from' => ['purchase_invoices', 'purchase_invoices_list'],
                ],
                [
                    'code' => 'action_archive_sales_delivery',
                    'name_ar' => 'أرشيف مرفقات سند تسليم',
                    'inherit_from' => ['sales_delivery'],
                ],
                [
                    'code' => 'action_archive_sales_return',
                    'name_ar' => 'أرشيف مرفقات مرتجع مبيعات',
                    'inherit_from' => ['sales_returns', 'sales_returns_list'],
                ],
                [
                    'code' => 'action_archive_purchase_return',
                    'name_ar' => 'أرشيف مرفقات مرتجع شراء',
                    'inherit_from' => ['purchase_returns', 'purchase_returns_list'],
                ],
                [
                    'code' => 'action_archive_warehouse_move',
                    'name_ar' => 'أرشيف مرفقات حركة مستودع',
                    'inherit_from' => ['warehouse_moves'],
                ],
            ],
        ],
    ],
];
