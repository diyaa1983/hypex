<?php
declare(strict_types=1);

require_once app_path('includes/hr_overtime.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_overtime_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_overtime_settings');
$configFormId = 'hr-ot-config-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_config') {
            hr_overtime_save_config($pdo, [
                'hour_multiplier' => $_POST['hour_multiplier'] ?? 1.25,
                'hour_multiplier_b' => $_POST['hour_multiplier_b'] ?? 1.5,
                'monthly_work_days' => $_POST['monthly_work_days'] ?? 30,
                'daily_work_hours' => $_POST['daily_work_hours'] ?? 8,
            ]);
            flash_set('success', 'تم حفظ إعدادات العمل الإضافي.');
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$config = hr_overtime_load_config($pdo);
$exampleGross = 500.0;
$exampleDays = (float) $config['monthly_work_days'];
$exampleDailyHours = (float) $config['daily_work_hours'];
$exampleHourly = hr_overtime_hourly_rate($exampleGross, $exampleDays, $exampleDailyHours);
$exampleHours = 8.0;
$multA = (float) $config['hour_multiplier'];
$multB = (float) $config['hour_multiplier_b'];

$cssPath = app_path('assets/css/hr-overtime-settings.css');
$cssUrl = app_url('assets/css/hr-overtime-settings.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-overtime-settings-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-overtime-settings-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-overtime-settings.js');
$jsUrl = app_url('assets/js/hr-overtime-settings.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_overtime_settings');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-ot-set-ora12-screen hr-ot-set-wrap hr-ot-set-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-config-form-id="<?= esc($configFormId) ?>"
     data-exit-url="<?= esc($exitUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إعدادات العمل الإضافي</h1>
        <?php nav_render_screen_close('hr_overtime_settings'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-ot-set-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <p class="hr-ot-set-hint muted">
            أجر الساعة = <strong>إجمالي الراتب ÷ أيام الشهر ÷ ساعات اليوم</strong>.
            عرّف <strong>مضاعفين</strong> للاختيار بينهما عند تسجيل الساعات (مثل 1.25 و 1.5).
            مبلغ العمل الإضافي = <strong>عدد الساعات × أجر الساعة × المضاعف المختار</strong>.
        </p>

        <div class="dashboard-ora-toolbar hr-ot-set-top-bar no-print">
            <button type="submit" class="btn btn-primary btn-sm" form="<?= esc($configFormId) ?>">حفظ</button>
        </div>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">طريقة احتساب الساعة</h2>
            <div class="dashboard-ora-panel__body">
                <form id="<?= esc($configFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-ot-set-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_config">

                    <div class="hr-ot-set-fields">
                        <label class="field">
                            <span class="field-label required">المضاعف الأول (خيار 1)</span>
                            <input class="input" type="number" name="hour_multiplier" id="hr-ot-hour-multiplier"
                                   min="0.01" max="10" step="0.01"
                                   value="<?= esc(number_format($multA, 2, '.', '')) ?>"
                                   dir="ltr" required>
                            <span class="hr-ot-set-field-hint muted">
                                <?= esc(hr_overtime_multiplier_display($multA)) ?>
                            </span>
                        </label>

                        <label class="field">
                            <span class="field-label required">المضاعف الثاني (خيار 2)</span>
                            <input class="input" type="number" name="hour_multiplier_b" id="hr-ot-hour-multiplier-b"
                                   min="0.01" max="10" step="0.01"
                                   value="<?= esc(number_format($multB, 2, '.', '')) ?>"
                                   dir="ltr" required>
                            <span class="hr-ot-set-field-hint muted">
                                <?= esc(hr_overtime_multiplier_display($multB)) ?>
                                — يُختار أحد المضاعفين عند إدخال ساعات العمل الإضافي
                            </span>
                        </label>

                        <label class="field">
                            <span class="field-label required">أيام العمل في الشهر</span>
                            <input class="input" type="number" name="monthly_work_days" id="hr-ot-monthly-days"
                                   min="1" max="31" step="1"
                                   value="<?= esc(number_format($exampleDays, 0, '.', '')) ?>"
                                   dir="ltr" required>
                        </label>

                        <label class="field">
                            <span class="field-label required">ساعات العمل اليومية</span>
                            <input class="input" type="number" name="daily_work_hours" id="hr-ot-daily-hours"
                                   min="0.01" max="24" step="0.01"
                                   value="<?= esc(number_format($exampleDailyHours, 2, '.', '')) ?>"
                                   dir="ltr" required>
                        </label>
                    </div>

                    <div class="hr-ot-set-actions">
                        <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel hr-ot-set-preview-panel">
            <h2 class="dashboard-ora-panel__title">مثال توضيحي</h2>
            <div class="dashboard-ora-panel__body">
                <p class="hr-ot-set-example muted">
                    موظف إجمالي راتبه <strong dir="ltr"><?= esc(format_amount($exampleGross)) ?></strong>،
                    أجر الساعة = <strong dir="ltr"><?= esc(format_amount($exampleHourly)) ?></strong>
                </p>
                <?php foreach (hr_overtime_multiplier_options($config) as $opt): ?>
                    <?php
                    $m = (float) $opt['value'];
                    $otHourly = round($exampleHourly * $m, 6);
                    ?>
                    <p class="hr-ot-set-example muted">
                        مضاعف <strong dir="ltr"><?= esc(number_format($m, 2, '.', '')) ?></strong>
                        (<?= esc($opt['label']) ?>) —
                        ساعة إضافية = <strong dir="ltr"><?= esc(format_amount($otHourly)) ?></strong>
                        — <?= esc(number_format($exampleHours, 0, '.', '')) ?> ساعات =
                        <strong dir="ltr"><?= esc(format_amount(hr_overtime_calc_amount(
                            $exampleGross,
                            $exampleHours,
                            $m,
                            $exampleDays,
                            $exampleDailyHours
                        ))) ?></strong>
                    </p>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<script src="<?= esc($jsUrl) ?>" defer></script>
