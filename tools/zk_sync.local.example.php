<?php
/**
 * مثال إعدادات وكيل مزامنة ZKT — انسخ إلى zk_sync.local.php وعدّل القيم.
 */
return [
    /** مسار مشروع Manager (XAMPP) — مطلوب إذا tools ليس داخل manager */
    'manager_root' => 'C:\\xampp\\htdocs\\manager',

    /** رابط API على السيرفر (من شاشة بصمات الموظفين) */
    'server_url' => 'https://www.biodev.gppjo.com/api/hr_attendance_push.php',

    /** رمز المزامنة — انسخه من شاشة بصمات الموظفين على السيرفر */
    'sync_token' => 'e8ba26681f3cde9f4a4ce6cf602961716fd99dbd78b4c4809f5d96fea47250f1',

    /** مسار att2000.mdb على جهاز ZKT (Windows) */
    'mdb_path' => 'C:\\zktdata\\att2000.mdb',

    /** true = يرسل فقط السجلات ذات Flag=0 ثم يعلّمها محلياً بعد النجاح */
    'use_flag' => true,

    /** عدد السجلات في كل طلب */
    'batch_size' => 500,

    /** تعليم Flag=1 في Access بعد إرسال ناجح */
    'mark_flags_after_push' => true,
];
