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

/**
 * @param mixed $value
 */
function hr_emp_print_has_value($value): bool
{
    if ($value === null) {
        return false;
    }
    if (is_numeric($value)) {
        return true;
    }

    return trim((string) $value) !== '';
}

/**
 * @param mixed $value
 */
function hr_emp_print_format_value($value, string $dir = ''): string
{
    if (!hr_emp_print_has_value($value)) {
        return '';
    }
    $dirAttr = $dir !== '' ? ' dir="' . esc($dir) . '"' : '';

    return '<td' . $dirAttr . '>' . esc((string) $value) . '</td>';
}

/**
 * @param list<array{label:string, value:mixed, dir?:string, full?:bool}> $fields
 */
function hr_emp_print_render_section(string $title, array $fields, bool $asSubsection = false): void
{
    $rows = [];
    $pending = [];

    foreach ($fields as $field) {
        if (!hr_emp_print_has_value($field['value'] ?? '')) {
            continue;
        }
        if (!empty($field['full'])) {
            if ($pending !== []) {
                $rows[] = ['pairs' => $pending];
                $pending = [];
            }
            $rows[] = ['full' => $field];
            continue;
        }
        $pending[] = $field;
        if (count($pending) === 2) {
            $rows[] = ['pairs' => $pending];
            $pending = [];
        }
    }
    if ($pending !== []) {
        $rows[] = ['pairs' => $pending];
    }
    if ($rows === []) {
        return;
    }

    if (!$asSubsection) {
        echo '<section class="hr-emp-print-section">';
    } else {
        echo '<div class="hr-emp-print-subsection">';
    }
    echo '<h3>' . esc($title) . '</h3>';
    echo '<table class="hr-emp-print-table"><tbody>';
    foreach ($rows as $row) {
        if (isset($row['full'])) {
            $f = $row['full'];
            echo '<tr class="hr-emp-print-row hr-emp-print-row--full">';
            echo '<th>' . esc($f['label']) . '</th>';
            $dirAttr = !empty($f['dir']) ? ' dir="' . esc((string) $f['dir']) . '"' : '';
            echo '<td colspan="3"' . $dirAttr . '>' . esc((string) $f['value']) . '</td></tr>';
            continue;
        }
        $pairs = $row['pairs'];
        echo '<tr class="hr-emp-print-row">';
        foreach ($pairs as $pair) {
            echo '<th>' . esc($pair['label']) . '</th>';
            echo hr_emp_print_format_value($pair['value'], $pair['dir'] ?? '');
        }
        if (count($pairs) === 1) {
            echo '<td colspan="2"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo $asSubsection ? '</div>' : '</section>';
}

$cssPath = app_path('assets/css/hr-employee-print.css');
$cssUrl = app_url('assets/css/hr-employee-print.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');

$genderMap = ['male' => 'ذكر', 'female' => 'أنثى'];
$genderKey = (string) ($emp['gender'] ?? '');
$genderLabel = $genderMap[$genderKey] ?? '';
$maritalLabel = (int) ($emp['is_married'] ?? 0) === 1 ? 'متزوج' : 'أعزب';

$nameParts = hr_employee_name_parts_from_row($emp);
$name = trim((string) ($emp['name_ar'] ?? ''));
if ($name === '') {
    $name = hr_employee_build_full_name(
        $nameParts['first'],
        $nameParts['father'],
        $nameParts['grandfather'],
        $nameParts['family']
    );
}

$resignDate = trim((string) ($emp['resignation_date'] ?? ''));
$isResigned = $resignDate !== '' || (int) ($emp['is_resigned_posted'] ?? 0) === 1;
$statusLabel = (int) ($emp['is_active'] ?? 1) === 1 ? 'نشِط' : 'غير نشِط';
if ($isResigned) {
    $statusLabel = 'مستقيل';
}
if ($isResigned && (int) ($emp['is_resigned_posted'] ?? 0) === 1) {
    $statusLabel .= ' (مرحّل)';
}

$hireDate = trim((string) ($emp['hire_date'] ?? ''));
$birthDate = trim((string) ($emp['birth_date'] ?? ''));
$jobLabel = trim((string) ($emp['job_name'] ?? $emp['job_title'] ?? ''));
$deptLabel = trim((string) ($emp['dept_name'] ?? $emp['department'] ?? ''));
$nationalityLabel = trim((string) ($emp['nationality_name'] ?? ''));
$notes = trim((string) ($emp['notes'] ?? ''));

$printedAt = date('Y-m-d H:i');
$reportTitle = 'بيانات الموظف';
$empNav = hr_employee_browse_nav($pdo, $employeeId);
$prevPrintUrl = (int) ($empNav['prev'] ?? 0) > 0
    ? app_url('index.php?r=hr_employee_print&id=' . (int) $empNav['prev'])
    : '';
$nextPrintUrl = (int) ($empNav['next'] ?? 0) > 0
    ? app_url('index.php?r=hr_employee_print&id=' . (int) $empNav['next'])
    : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($reportTitle) ?> — <?= esc($name !== '' ? $name : ('#' . $employeeId)) ?></title>
    <link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
</head>
<body class="hr-emp-print-body">
<main class="hr-emp-print-doc">
    <?= document_print_header_html($reportTitle, $pdo) ?>

    <?php
    echo '<section class="hr-emp-print-section hr-emp-print-section--group">';
    echo '<h2 class="hr-emp-print-group-title">البيانات الشخصية</h2>';

    hr_emp_print_render_section('الهوية', [
        ['label' => 'رقم الموظف', 'value' => $emp['emp_code'] ?? '', 'dir' => 'ltr'],
        ['label' => 'اسم الموظف', 'value' => $name],
        ['label' => 'الرقم الوطني', 'value' => $emp['national_id'] ?? '', 'dir' => 'ltr'],
        ['label' => 'تاريخ الميلاد', 'value' => $birthDate !== '' ? format_date_dmY($birthDate) : '', 'dir' => 'ltr'],
        ['label' => 'الجنس', 'value' => $genderLabel],
        ['label' => 'الجنسية', 'value' => $nationalityLabel],
        ['label' => 'الحالة الاجتماعية', 'value' => $maritalLabel],
    ], true);

    hr_emp_print_render_section('معلومات الاتصال', [
        ['label' => 'الهاتف', 'value' => $emp['phone'] ?? '', 'dir' => 'ltr'],
        ['label' => 'البريد الإلكتروني', 'value' => $emp['email'] ?? '', 'dir' => 'ltr'],
    ], true);

    hr_emp_print_render_section('عنوان الموظف', [
        ['label' => 'المدينة', 'value' => $emp['address_city'] ?? ''],
        ['label' => 'الحي / المنطقة', 'value' => $emp['address_district'] ?? ''],
        ['label' => 'العنوان التفصيلي', 'value' => $emp['address_ar'] ?? '', 'full' => true],
    ], true);

    echo '</section>';

    hr_emp_print_render_section('البيانات الوظيفية', [
        ['label' => 'المسمى الوظيفي', 'value' => $jobLabel],
        ['label' => 'القسم', 'value' => $deptLabel],
        ['label' => 'تاريخ التعيين', 'value' => $hireDate !== '' ? format_date_dmY($hireDate) : '', 'dir' => 'ltr'],
        ['label' => 'تاريخ الاستقالة', 'value' => $resignDate !== '' ? format_date_dmY($resignDate) : '', 'dir' => 'ltr'],
        ['label' => 'الحالة', 'value' => $statusLabel],
        ['label' => 'الراتب الأساسي', 'value' => number_format((float) ($emp['base_salary'] ?? 0), 2), 'dir' => 'ltr'],
    ]);

    hr_emp_print_render_section('الاستقالة والضمان وضريبة الدخل', [
        ['label' => 'رقم الضمان', 'value' => $emp['social_security_no'] ?? '', 'dir' => 'ltr'],
        ['label' => 'خاضع للضمان', 'value' => (int) ($emp['subject_to_social_security'] ?? 0) === 1 ? 'نعم' : 'لا'],
        ['label' => 'خاضع لضريبة الدخل', 'value' => (int) ($emp['subject_to_income_tax'] ?? 0) === 1 ? 'نعم' : 'لا'],
        ['label' => 'معرّف الموظف', 'value' => (string) (int) ($emp['id'] ?? 0), 'dir' => 'ltr'],
    ]);
    ?>

    <?php if ($notes !== ''): ?>
        <section class="hr-emp-print-section hr-emp-print-notes">
            <h3>ملاحظات</h3>
            <p><?= esc($notes) ?></p>
        </section>
    <?php endif; ?>

    <footer class="hr-emp-print-foot">
        <span>تاريخ الطباعة: <strong dir="ltr"><?= esc($printedAt) ?></strong></span>
    </footer>
</main>

<div class="hr-emp-print-actions no-print"
     data-prev-url="<?= esc($prevPrintUrl) ?>"
     data-next-url="<?= esc($nextPrintUrl) ?>">
    <a class="btn btn-secondary<?= $prevPrintUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($prevPrintUrl !== '' ? $prevPrintUrl : '#') ?>"
       aria-disabled="<?= $prevPrintUrl === '' ? 'true' : 'false' ?>">السابق</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <a class="btn btn-secondary<?= $nextPrintUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($nextPrintUrl !== '' ? $nextPrintUrl : '#') ?>"
       aria-disabled="<?= $nextPrintUrl === '' ? 'true' : 'false' ?>">التالي</a>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
<script>
(function () {
    var actions = document.querySelector('.hr-emp-print-actions');
    if (!actions) return;
    actions.addEventListener('click', function (e) {
        var link = e.target.closest('a.is-disabled');
        if (link) {
            e.preventDefault();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (!e.altKey) return;
        var url = '';
        if (e.key === 'ArrowRight') {
            url = actions.getAttribute('data-prev-url') || '';
        } else if (e.key === 'ArrowLeft') {
            url = actions.getAttribute('data-next-url') || '';
        }
        if (url) {
            e.preventDefault();
            window.location.href = url;
        }
    });
})();
</script>
</body>
</html>
