<?php
declare(strict_types=1);

/**
 * إعدادات MySQL — انسخ إلى database.local.php (محلي أو HostGator).
 *
 * HostGator (cPanel):
 * - أنشئ قاعدة بيانات + مستخدم من MySQL® Databases
 * - Host غالباً: localhost
 * - اسم القاعدة يكون بصيغة: cpaneluser_dbname
 * - اسم المستخدم: cpaneluser_dbuser
 */
return [
    'host' => 'localhost',
    'name' => 'cpaneluser_namma_erp',
    'user' => 'cpaneluser_erpuser',
    'pass' => 'ضع_كلمة_المرور_القوية_هنا',
    'charset' => 'utf8mb4',
];
