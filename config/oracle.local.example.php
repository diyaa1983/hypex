<?php
declare(strict_types=1);

/**
 * إعداد Oracle — انسخ إلى oracle.local.php
 *
 * entities للـ API/agent:
 *   customers | item_groups | items | all
 */
return [
    'enabled' => true,
    'host' => '192.168.1.94',
    'port' => 1521,
    'sid' => 'taqwa',
    'service_name' => '',
    'user' => 'system',
    'pass' => 'CHANGE_ME',
    'charset' => 'AL32UTF8',
    'sync_token' => 'CHANGE_ME_SYNC_TOKEN',

    /**
     * مزامنة تلقائية للعملاء (Task Scheduler / agent كل N دقيقة)
     * ثبّت المهمة: deploy/oracle-agent/install-customers-sync-task.ps1
     */
    'auto_sync' => [
        'enabled' => true,
        'interval_minutes' => 5,
        'entities' => 'customers',
        'last_run_at' => '',
        'last_ok' => null,
        'last_message' => '',
    ],

    'customers' => [
        'owner' => 'ACCINV',
        'table' => 'CUSTOMER',
        // فقط العملاء الذين يبدأ رقمهم بهذه البادئة
        'code_prefix' => '112',
        'columns' => [
            'oracle_key' => 'CUS_NUM',
            'code' => 'CUS_NUM',
            // الاسم لم يعد من CUSTOMER — من GLACTMF أدناه
        ],
        /**
         * اسم العميل من الدليل المحاسبي:
         * CUSTOMER.CUS_NUM (112…) يجب أن يطابق GLACTMF.ACC_NUM → الاسم = ACC_DESC
         */
        'name_from_gl' => [
            'enabled' => true,
            'owner' => 'ACCINV', // غيّر إن كان GLACTMF تحت مالك آخر
            'table' => 'GLACTMF',
            'acc_num' => 'ACC_NUM',
            'acc_desc' => 'ACC_DESC',
        ],
    ],

    /**
     * كشف حساب تفصيلي — GLVODMF (حركات) + GLCHEQF (شيكات قيد التحصيل)
     * لا حاجة لتعديله عادةً إن كانت الجداول تحت ACCINV
     */
    'statement' => [
        'owner' => 'ACCINV',
        'table' => 'GLVODMF',
        'acc' => 'VOD_ACC',
        'date' => 'VOD_DATE',
        'side' => 'VOD_SIDE',
        'amount' => 'VOD_AMOUNT',
        'num' => 'VOD_NUM',
        'remark' => 'VOD_REMARK',
        'type' => 'VOD_TYPE',
        'flag' => 'VOD_FLAG',
        'srl' => 'VOD_SR1',
        'dep' => 'VOD_DEP',
        'debit_side' => 1,
        'credit_side' => 2,
        'cheque_table' => 'GLCHEQF',
        'cheque_cus' => 'CHQ_CUS_NUM',
        // اختياري: عمود تاريخ القبض إن اختلف الاسم (يُكتشف تلقائياً عادةً)
        // 'cheque_receipt' => 'CHQ_RDATE',
    ],

    /**
     * فاتورة بيع Oracle (شاشة INV00024) — بنود من MAS.DAILY
     * TYPE = 9 مبيعات
     */
    'sales_invoice' => [
        'owner' => 'MAS',
        'table' => 'DAILY',
        'header_table' => 'MASTER_D',
        'sale_type' => 9,
        // رقم الشركة في MAS.DAILY.COMP_NUM (إلزامي)
        'comp_num' => 1,
        // رقم المستودع في أوراكل إن لم يُضبط على بطاقة المستودع
        'default_store' => 4,
        // عمود «خاضع لضريبة المبيعات» في INV00024 (عدّل إن اختلف عندكم)
        'tax_subject' => [
            'columns' => ['STAX', 'TAX_FLAG', 'ST_FLAG', 'CUS_TAX', 'TAXABLE'],
            'yes' => 1,
            'no' => 0,
        ],
        'item_card_owner' => 'MAS',
        'item_card_table' => 'MASCARD',
        // فحص رصيد المخزون قبل الترحيل (MAS.STOCK) — يمنع خطأ INV00024
        // قائمة التشغيلات في معاينة الترحيل من MAS.BALANCE (QTY_OH) مثل Toad/Forms — ليس STOCK
        'stock' => [
            'enabled' => true,
            'owner' => 'MAS',
            'table' => 'STOCK',
            'qty_column' => 'SYS_QTY',
            'multiply_by_tr_unit' => false,
            'use_man_qty' => true,
        ],
    ],

    /**
     * مجموعات / فئات المواد → inv_item_category
     * عدّل owner/table/columns بعد التحقق من Toad
     */
    'item_groups' => [
        'owner' => 'MAS',
        'table' => 'ITEM_GROUP', // مثال — غيّر للاسم الحقيقي
        'columns' => [
            'oracle_key' => 'GROUP_CODE',
            'code' => 'GROUP_CODE',
            'name_ar' => 'GROUP_NAME',
        ],
    ],

    /**
     * المواد → inv_item
     * group_key يربط بمفتاح المجموعة في Oracle
     */
    'items' => [
        'owner' => 'MAS',
        'table' => 'ITEM', // مثال — غيّر للاسم الحقيقي
        'columns' => [
            'oracle_key' => 'ITEM_CODE',
            'sku' => 'ITEM_CODE',
            'name_ar' => 'ITEM_NAME',
            'group_key' => 'GROUP_CODE',
            // اختياري:
            // 'default_cost' => 'COST',
            // 'default_sale' => 'PRICE',
            // 'unit_name' => 'UNIT',
            // 'barcode' => 'BARCODE',
        ],
    ],
];
