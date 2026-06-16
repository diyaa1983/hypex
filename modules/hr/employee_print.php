<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$employeeId = (int) ($_GET['id'] ?? 0);
if ($employeeId < 1) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة موظف</title></head>';
    echo '<body><p>لا يوجد موظف محدد للطباعة.</p></body></html>';
    return;
}

$st = $pdo->prepare(
    'SELECT e.*,
            d.name_ar AS dept_name,
            j.name_ar AS job_name,
            n.name_ar AS nationality_name
     FROM hr_employee e
     LEFT JOIN hr_department d ON d.id = e.department_id
     LEFT JOIN hr_job_title j ON j.id = e.job_title_id
     LEFT JOIN hr_nationality n ON n.id = e.nationality_id
     WHERE e.id = ?
     LIMIT 1'
);
$st->execute([$employeeId]);
$emp = $st->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة موظف</title></head>';
    echo '<body><p>الموظف غير موجود.</p></body></html>';
    return;
}

$cssPath = app_path('assets/css/hr-employee-print.css');
$cssUrl = app_url('assets/css/hr-employee-print.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');

$genderMap = ['male' => 'ذكر', 'female' => 'أنثى'];
$genderLabel = $genderMap[(string) ($emp['gender'] ?? '')] ?? '—';
$maritalLabel = (int) ($emp['is_married'] ?? 0) === 1 ? 'متزوج' : 'أعزب';
$hireDate = trim((string) ($emp['hire_date'] ?? ''));
$resignDate = trim((string) ($emp['resignation_date'] ?? ''));
$isResigned = $resignDate !== '' || (int) ($emp['is_resigned_posted'] ?? 0) === 1;
$statusLabel = (int) ($emp['is_active'] ?? 1) === 1 ? 'نشِط' : 'غير نشِط';
if ($isResigned) {
    $statusLabel = 'مستقيل';
}
if ($isResigned && (int) ($emp['is_resigned_posted'] ?? 0) === 1) {
    $statusLabel .= ' (مرحّل)';
}

$name = trim((string) ($emp['name_ar'] ?? ''));
if ($name === '') {
    $name = '—';
}

$hireDateLabel = $hireDate !== '' ? format_date_dmY($hireDate) : '—';
$resignDateLabel = $resignDate !== '' ? format_date_dmY($resignDate) : '—';

$printedAt = date('Y-m-d H:i');
$reportTitle = 'بيانات الموظف الأساسية';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($reportTitle) ?> — <?= esc($name) ?></title>
    <link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
</head>
<body class="hr-emp-print-body">
<main class="hr-emp-print-doc">
    <?= document_print_header_html($reportTitle, $pdo) ?>

    <section class="hr-emp-print-section">
        <h3>معلومات الموظف</h3>
        <table class="hr-emp-print-table">
            <tr>
                <th>رقم الموظف</th>
                <td dir="ltr"><?= esc((string) ($emp['emp_code'] ?? '—')) ?></td>
                <th>اسم الموظف</th>
                <td><?= esc($name) ?></td>
            </tr>
            <tr>
                <th>المسمى الوظيفي</th>
                <td><?= esc((string) ($emp['job_name'] ?? $emp['job_title'] ?? '—')) ?></td>
                <th>القسم</th>
                <td><?= esc((string) ($emp['dept_name'] ?? $emp['department'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>تاريخ التعيين</th>
                <td dir="ltr"><?= esc($hireDateLabel) ?></td>
                <?php if ($isResigned): ?>
                    <th>تاريخ الاستقالة</th>
                    <td dir="ltr"><?= esc($resignDateLabel) ?></td>
                <?php else: ?>
                    <td colspan="2"></td>
                <?php endif; ?>
            </tr>
            <tr>
                <th>الحالة</th>
                <td><?= esc($statusLabel) ?></td>
                <th>الراتب الأساسي</th>
                <td dir="ltr"><?= esc(number_format((float) ($emp['base_salary'] ?? 0), 2)) ?></td>
            </tr>
            <tr>
                <th>الرقم الوطني</th>
                <td dir="ltr"><?= esc((string) ($emp['national_id'] ?? '—')) ?></td>
                <th>الجنس</th>
                <td><?= esc($genderLabel) ?></td>
            </tr>
            <tr>
                <th>الحالة الاجتماعية</th>
                <td><?= esc($maritalLabel) ?></td>
                <th>الجنسية</th>
                <td><?= esc((string) ($emp['nationality_name'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>الهاتف</th>
                <td dir="ltr"><?= esc((string) ($emp['phone'] ?? '—')) ?></td>
                <th>البريد الإلكتروني</th>
                <td dir="ltr"><?= esc((string) ($emp['email'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>المدينة</th>
                <td><?= esc((string) ($emp['address_city'] ?? '—')) ?></td>
                <th>الحي / المنطقة</th>
                <td><?= esc((string) ($emp['address_district'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>العنوان التفصيلي</th>
                <td colspan="3"><?= esc((string) ($emp['address_ar'] ?? '—')) ?></td>
            </tr>
            <tr>
                <th>رقم الضمان</th>
                <td dir="ltr"><?= esc((string) ($emp['social_security_no'] ?? '—')) ?></td>
                <th>خاضع للضمان</th>
                <td><?= (int) ($emp['subject_to_social_security'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
            </tr>
            <tr>
                <th>خاضع لضريبة الدخل</th>
                <td><?= (int) ($emp['subject_to_income_tax'] ?? 0) === 1 ? 'نعم' : 'لا' ?></td>
                <th>معرّف الموظف</th>
                <td dir="ltr"><?= (int) ($emp['id'] ?? 0) ?></td>
            </tr>
        </table>
    </section>

    <footer class="hr-emp-print-foot">
        <span>الملاحظات: <?= esc(trim((string) ($emp['notes'] ?? '')) !== '' ? (string) $emp['notes'] : '—') ?></span>
        <span class="hr-emp-print-foot-sep">|</span>
        <span>تاريخ الطباعة: <strong dir="ltr"><?= esc($printedAt) ?></strong></span>
    </footer>
</main>

<div class="hr-emp-print-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
</body>
</html>
