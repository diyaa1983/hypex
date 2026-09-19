<?php
/**
 * إعدادات وكيل مزامنة ZKT — على جهاز Windows حيث att2000.mdb
 */
return [
    /** مسار XAMPP حيث مشروع Manager (عدّله إذا مختلف) */
    'manager_root' => 'C:\\xampp\\htdocs\\manager',

    'server_url' => 'https://www.biodev.gppjo.com/api/hr_attendance_push.php',

    /** انسخ الرمز من: بصمات الموظفين → رمز المزامنة */
    'sync_token' => 'e8ba26681f3cde9f4a4ce6cf602961716fd99dbd78b4c4809f5d96fea47250f1',

    'mdb_path' => 'C:\\zktdata\\att2000.mdb',

    'use_flag' => true,
    'batch_size' => 500,
    'mark_flags_after_push' => true,
];
