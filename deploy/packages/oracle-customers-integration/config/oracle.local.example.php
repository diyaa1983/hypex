<?php
declare(strict_types=1);

/**
 * إعداد اتصال Oracle — انسخ إلى oracle.local.php (لا يُرفع إلى Git).
 *
 * tnsnames مثال:
 *   taqwa =
 *     (DESCRIPTION = (ADDRESS = (PROTOCOL = TCP)(HOST = dbserver)(PORT = 1521))
 *       (CONNECT_DATA = (SID = taqwa)))
 */
return [
    'enabled' => true,
    'host' => '192.168.100.2',
    'port' => 1521,
    /** SID أو Service Name حسب السيرفر */
    'sid' => 'taqwa',
    'service_name' => '',
    'user' => 'system',
    'pass' => 'manager1',
    'charset' => 'AL32UTF8',
    /**
     * تعيين أعمدة جدول العملاء بعد اكتشافه.
     * اترك table/owner فارغين لشاشة الاستكشاف؛ عبّئهما بعد معرفة الجدول.
     */
    'customers' => [
        'owner' => '',
        'table' => '',
        'columns' => [
            // 'code' => 'CUST_CODE',
            // 'name_ar' => 'CUST_NAME',
            // 'phone' => 'TEL',
            // 'email' => 'EMAIL',
            // 'tax_number' => 'TAX_NO',
            // 'address_ar' => 'ADDR',
            // 'is_active' => 'ACTIVE_FLAG',
            // 'oracle_key' => 'CUST_ID', // مفتاح الربط — يُفضّل المفتاح الأساسي
        ],
        'active_true_values' => ['1', 'Y', 'YES', 'نعم', 'ACTIVE', 'A'],
    ],
];
