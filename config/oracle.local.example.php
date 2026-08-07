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
