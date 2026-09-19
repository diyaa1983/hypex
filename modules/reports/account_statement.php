<?php
declare(strict_types=1);

$GLOBALS['acc_ledger_report_config'] = [
    'route' => 'report_account_statement',
    'title' => 'كشف حساب',
    'subtitle' => 'حركات الحساب من شجرة الحسابات — رصيد افتتاحي، القيود المرحّلة، الرصيد الجاري والختامي.',
    'empty_hint' => 'اختر حساباً من الشجرة وحدّد الفترة ثم اضغط «عرض».',
];

require app_path('modules/reports/general_ledger.php');
