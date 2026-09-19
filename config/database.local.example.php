<?php
declare(strict_types=1);

/**
 * إعدادات MySQL — انسخ إلى database.local.php (لا يُرفع إلى Git).
 *
 * ── XAMPP محلي ──
 * host: 127.0.0.1 | user: root | pass: (فارغ أو root)
 *
 * ── سيرفر مخصص (Static IP) ──
 * host: 127.0.0.1 أو localhost  (لا تستخدم IP العام لـ MySQL)
 * أنشئ مستخدماً وكلمة مرور قوية — لا تستخدم root من الويب.
 *
 * ── cPanel / HostGator ──
 * name/user بصيغة: cpaneluser_dbname
 */
return [
    'host' => '127.0.0.1',
    'name' => 'hypex',
    'user' => 'hypex_user',
    'pass' => 'ضع_كلمة_مرور_قوية_هنا',
    'charset' => 'utf8mb4',
];
