<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_salary.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_salary_line_ensure_schema($pdo);

$employeeId = (int) ($_GET['id'] ?? ($_GET['employee_id'] ?? 0));
$row = hr_employee_salary_load_for_print($pdo, $employeeId);
if (!$row) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قسيمة راتب</title></head>';
    echo '<body><p>الموظف غير موجود.</p></body></html>';
    return;
}

$allowLines = $row['lines'];

$cssPath = app_path('assets/css/hr-salary-slip.css');
$cssUrl = app_url('assets/css/hr-salary-slip.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بيان راتب — <?= esc((string) ($row['emp_name'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
</head>
<body class="hr-slip-body">
<div class="hr-slip-doc">
    <?= document_print_header_html('قسيمة الراتب', $pdo, 'بيان الراتب الأساسي والعلاوات') ?>
    <p class="hr-slip-subtitle muted">إعداد راتب الموظف — ليس قيداً شهرياً</p>

    <section class="hr-slip-emp">
        <table class="hr-slip-info-table">
            <tr>
                <th>الموظف</th>
                <td><?= esc((string) ($row['emp_name'] ?? '—')) ?></td>
                <th>الرقم الوظيفي</th>
                <td dir="ltr"><?= esc((string) ($row['emp_code'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>المسمى</th>
                <td><?= esc((string) ($row['job_title'] ?? '—')) ?></td>
                <th>القسم</th>
                <td><?= esc((string) ($row['department'] ?? '—')) ?></td>
            </tr>
            <?php
            $bank = trim((string) ($row['salary_bank_name'] ?? $row['bank_name'] ?? ''));
            $acct = trim((string) ($row['bank_account'] ?? ''));
            if ($bank !== '' || $acct !== ''):
            ?>
            <tr>
                <th>البنك</th>
                <td><?= esc($bank !== '' ? $bank : '—') ?></td>
                <th>الحساب</th>
                <td dir="ltr"><?= esc($acct !== '' ? $acct : '—') ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </section>

    <section class="hr-slip-breakdown">
        <h3 class="hr-slip-allow-title">العلاوات</h3>
        <table class="hr-slip-allow-table">
            <thead>
            <tr><th>البند</th><th>المبلغ</th></tr>
            </thead>
            <tbody>
            <?php if (!$allowLines): ?>
                <tr><td colspan="2" class="muted">—</td></tr>
            <?php endif; ?>
            <?php foreach ($allowLines as $ln): ?>
                <tr>
                    <td><?= esc((string) ($ln['name_ar'] ?? '')) ?></td>
                    <td dir="ltr"><?= esc(number_format((float) ($ln['amount'] ?? 0), 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="hr-slip-summary">
        <table class="hr-slip-summary-table">
            <tr>
                <td>الراتب الأساسي</td>
                <td dir="ltr"><?= esc(number_format((float) $row['base_salary'], 2)) ?></td>
            </tr>
            <tr>
                <td>إجمالي العلاوات</td>
                <td dir="ltr"><?= esc(number_format((float) $row['allowances'], 2)) ?></td>
            </tr>
            <tr class="hr-slip-net">
                <td>إجمالي الراتب</td>
                <td dir="ltr"><?= esc(number_format((float) ($row['gross_salary'] ?? 0), 2)) ?></td>
            </tr>
        </table>
    </section>

    <footer class="hr-slip-footer">
        <p>معرّف الموظف: <?= (int) $row['id'] ?></p>
    </footer>
</div>

<div class="hr-slip-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
</body>
</html>
