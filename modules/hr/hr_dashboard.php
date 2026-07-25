<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/company_settings.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_employee_ensure_link_columns($pdo);

$today = date('Y-m-d');
$weekdayAr = [
    'Sunday' => 'الأحد',
    'Monday' => 'الإثنين',
    'Tuesday' => 'الثلاثاء',
    'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس',
    'Friday' => 'الجمعة',
    'Saturday' => 'السبت',
][date('l')] ?? '';

$user = current_user();
$heroCompany = company_settings($pdo);
$companyNameAr = trim((string) ($heroCompany['company_name_ar'] ?? 'الشركة'));
$userNameAr = trim((string) ($user['full_name_ar'] ?? $user['username'] ?? ''));

// شروط "على رأس العمل" / "مستقيل" كما تستخدمها تقارير الموظفين.
$activeWhere = "is_active = 1 AND COALESCE(is_resigned_posted, 0) = 0 AND (resignation_date IS NULL OR TRIM(resignation_date) = '')";
$resignedWhere = "(is_active = 0 OR COALESCE(is_resigned_posted, 0) = 1 OR (resignation_date IS NOT NULL AND TRIM(resignation_date) <> ''))";

try {
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM hr_employee WHERE {$activeWhere}")->fetchColumn();
} catch (Throwable $e) {
    $activeCount = 0;
}

try {
    $resignedCount = (int) $pdo->query("SELECT COUNT(*) FROM hr_employee WHERE {$resignedWhere}")->fetchColumn();
} catch (Throwable $e) {
    $resignedCount = 0;
}

try {
    $activeSalary = (float) $pdo->query(
        "SELECT COALESCE(SUM(COALESCE(base_salary,0) + COALESCE(allowances,0)), 0) FROM hr_employee WHERE {$activeWhere}"
    )->fetchColumn();
} catch (Throwable $e) {
    $activeSalary = 0.0;
}

$activeUrl = app_url('index.php?r=report_hr_employees&run=1&status=active');
$resignedUrl = app_url('index.php?r=report_hr_employees_resigned&run=1&department_id=0');

$cssPath = app_path('assets/css/dashboard.css');
$cssUrl = app_url('assets/css/dashboard.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">
            مؤشرات رئيسية — شؤون الموظفين<?= $userNameAr !== '' ? ', ' . esc($userNameAr) : '' ?>
        </h1>
        <span class="dashboard-ora-screen-title__meta">
            <?= esc($companyNameAr) ?><?php if ($weekdayAr !== ''): ?> · <?= esc($weekdayAr) ?><?php endif; ?> · <?= esc(format_date_dmY($today)) ?>
        </span>
    </header>

    <section class="dashboard-ora-panel" aria-label="مؤشرات رئيسية شؤون الموظفين">
        <h2 class="dashboard-ora-panel__title">مؤشرات رئيسية</h2>
        <div class="dashboard-ora-panel__body">
            <div class="dashboard-ora-kpi-grid">
                <a
                    class="dashboard-ora-kpi dashboard-ora-kpi--uniform dashboard-ora-kpi--success"
                    href="<?= esc($activeUrl) ?>"
                    title="عرض الموظفين على رأس العمل"
                >
                    <span class="dashboard-ora-kpi-label">على رأس العمل</span>
                    <span class="dashboard-ora-kpi-value"><?= esc(number_format($activeCount)) ?></span>
                </a>

                <a
                    class="dashboard-ora-kpi dashboard-ora-kpi--uniform dashboard-ora-kpi--warn"
                    href="<?= esc($resignedUrl) ?>"
                    title="عرض الموظفين المستقيلين"
                >
                    <span class="dashboard-ora-kpi-label">المستقيلون</span>
                    <span class="dashboard-ora-kpi-value"><?= esc(number_format($resignedCount)) ?></span>
                </a>

                <a
                    class="dashboard-ora-kpi dashboard-ora-kpi--uniform dashboard-ora-kpi--primary"
                    href="<?= esc($activeUrl) ?>"
                    title="عرض الموظفين على رأس العمل"
                >
                    <span class="dashboard-ora-kpi-label">إجمالي رواتب الموظفين (على رأس العمل)</span>
                    <span class="dashboard-ora-kpi-value" dir="ltr"><?= esc(format_money($activeSalary)) ?></span>
                </a>
            </div>
        </div>
    </section>
</div>


