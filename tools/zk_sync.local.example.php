<?php
/**
 * مثال إعدادات وكيل مزامنة ZKT — انسخ إلى zk_sync.local.php وعدّل القيم.
 */
return [
    /** رابط API على السيرفر (من شاشة بصمات الموظفين) */
    'server_url' => 'https://biodev.gppjo.com/manager/api/hr_attendance_push.php',

    /** رمز المزامنة — انسخه من شاشة بصمات الموظفين على السيرفر */
    'sync_token' => 'PASTE_SYNC_TOKEN_HERE',

    /** مسار att2000.mdb على جهاز ZKT (Windows) */
    'mdb_path' => 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb',

    /** true = يرسل فقط السجلات ذات Flag=0 ثم يعلّمها محلياً بعد النجاح */
    'use_flag' => true,

    /** عدد السجلات في كل طلب */
    'batch_size' => 500,

    /** تعليم Flag=1 في Access بعد إرسال ناجح */
    'mark_flags_after_push' => true,
];
