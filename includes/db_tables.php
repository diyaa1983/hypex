<?php
declare(strict_types=1);

/**
 * مبدأ البيانات: كل كيان في جدول MySQL مستقل (لا تخزين مجمّع في JSON أو أعمدة عامة).
 *
 * عند إضافة شاشة/عملية جديدة:
 * 1) جدول رأس (مثال sal_invoice) + جدول بنود إن وُجدت (sal_invoice_line)
 * 2) ملف ترحيل في database/migrations/NNN_اسم.sql
 * 3) تحديث database/schema.sql (CREATE + DROP + بذور إن لزم)
 * 4) تسجيل الكيان هنا + routes.php + nav_menu.php
 *
 * البادئات: sys_ نظام | acc_ محاسبة | crm_ أطراف | inv_ مخزون | sal_ مبيعات | pur_ مشتريات | fin_ نقدية
 */

/** @return array<string, string> مفتاح الكيان => اسم الجدول */
function db_entity_tables(): array
{
    return [
        // نظام
        'user' => 'sys_user',
        'group' => 'sys_group',
        'user_group' => 'sys_user_group',
        'screen' => 'sys_screen',
        'group_permission' => 'sys_group_permission',
        'company_settings' => 'sys_company_settings',
        'einvoice_settings' => 'sys_einvoice_settings',
        'tax_rate' => 'sys_tax_rate',

        // محاسبة
        'account' => 'acc_account',
        'journal_entry' => 'acc_journal_entry',
        'journal_line' => 'acc_journal_line',

        // أطراف
        'customer' => 'crm_customer',
        'customer_ledger' => 'crm_customer_ledger',
        'supplier' => 'crm_supplier',
        'sales_rep' => 'crm_sales_rep',

        // مخزون
        'warehouse' => 'inv_warehouse',
        'item_category' => 'inv_item_category',
        'unit' => 'inv_unit',
        'item' => 'inv_item',
        'stock_move' => 'inv_stock_move',
        'movement_type' => 'inv_movement_type',
        'warehouse_move' => 'inv_wh_move',
        'warehouse_move_line' => 'inv_wh_move_line',

        // مبيعات
        'sales_invoice' => 'sal_invoice',
        'sales_invoice_line' => 'sal_invoice_line',
        'sales_return' => 'sal_return',
        'sales_return_line' => 'sal_return_line',
        'sales_delivery' => 'sal_delivery',
        'sales_delivery_line' => 'sal_delivery_line',

        // مشتريات
        'purchase_invoice' => 'pur_invoice',
        'purchase_invoice_line' => 'pur_invoice_line',

        // نقدية وإشعارات
        'voucher' => 'fin_voucher',
        'debit_note' => 'fin_debit_note',
        'debit_note_line' => 'fin_debit_note_line',
        'credit_note' => 'fin_credit_note',
        'credit_note_line' => 'fin_credit_note_line',
    ];
}

function db_table_for(string $entity): ?string
{
    return db_entity_tables()[$entity] ?? null;
}
