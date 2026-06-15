<?php
declare(strict_types=1);

require_once app_path('includes/hr_income_tax.php');
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/account_picker.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_income_tax_ensure_schema($pdo);
acc_gl_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_income_tax_settings');
$configFormId = 'hr-it-config-form';
$editorFormId = 'hr-it-editor-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_config') {
            hr_income_tax_save_config($pdo, [
                'single_exempt_monthly' => $_POST['single_exempt_monthly'] ?? 750,
                'single_exempt_annual' => $_POST['single_exempt_annual'] ?? 9000,
                'married_exempt_monthly' => $_POST['married_exempt_monthly'] ?? 1500,
                'married_exempt_annual' => $_POST['married_exempt_annual'] ?? 18000,
            ]);
            $accId = (int) ($_POST['account_id'] ?? 0);
            if ($accId > 0) {
                $chk = $pdo->prepare('SELECT id FROM acc_account WHERE id = ? AND is_leaf = 1 LIMIT 1');
                $chk->execute([$accId]);
                if (!$chk->fetchColumn()) {
                    throw new RuntimeException('اختر حساباً نهائياً لضريبة الدخل.');
                }
                $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
                $st->execute([$accId, HR_INCOME_TAX_RULE_CODE]);
            } else {
                $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = NULL WHERE rule_code = ?');
                $st->execute([HR_INCOME_TAX_RULE_CODE]);
            }
            flash_set('success', 'تم حفظ إعدادات الإعفاء وحساب ضريبة الدخل.');
            redirect($listUrl);
        }

        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_income_tax_bracket_parse_row($_POST, $id);
            hr_income_tax_save_bracket_one($pdo, $id, $parsed);
            $label = hr_income_tax_marital_label($parsed['marital_status']);
            flash_set('success', $id > 0
                ? 'تم حفظ تعديلات شريحة الضريبة (' . $label . ').'
                : 'تم إضافة شريحة ضريبة (' . $label . ').');
            redirect($listUrl);
        }

        if ($act === 'save_batch') {
            $status = (string) ($_POST['marital_status'] ?? 'single');
            if (!in_array($status, ['single', 'married'], true)) {
                throw new RuntimeException('نوع الحالة الاجتماعية غير صالح.');
            }
            $raw = (string) ($_POST['pending_items_json'] ?? '[]');
            $items = json_decode($raw, true);
            if (!is_array($items)) {
                $items = [];
            }
            $saved = hr_income_tax_save_brackets_batch($pdo, $status, $items);
            $label = hr_income_tax_marital_label($status);
            flash_set('success', $saved === 1
                ? 'تم حفظ شريحة واحدة (' . $label . ').'
                : 'تم حفظ ' . $saved . ' شرائح (' . $label . ').');
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['marital_status'] ?? '');
            $replaceAfter = (int) ($_POST['replace_after'] ?? 0) === 1;
            if ($id < 1) {
                throw new RuntimeException('حدد شريحة للحذف.');
            }
            $deleted = hr_income_tax_delete_bracket($pdo, $id);
            $redirectUrl = $listUrl;
            if ($replaceAfter && in_array($status, ['single', 'married'], true)) {
                $redirectUrl .= '&open_add=' . rawurlencode($status);
            }
            if ($deleted) {
                flash_set(
                    'success',
                    $replaceAfter
                        ? 'تم حذف الشريحة. أدخل بيانات الشريحة الجديدة ثم احفظ.'
                        : 'تم حذف شريحة الضريبة.'
                );
            } else {
                flash_set(
                    'success',
                    $replaceAfter
                        ? 'الشريحة محذوفة مسبقاً. أدخل بيانات الشريحة الجديدة ثم احفظ.'
                        : 'الشريحة محذوفة مسبقاً.'
                );
            }
            redirect($redirectUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$config = hr_income_tax_load_config($pdo);
$singleBrackets = hr_income_tax_brackets($pdo, 'single');
$marriedBrackets = hr_income_tax_brackets($pdo, 'married');
$glSettings = acc_gl_load_settings($pdo);
$taxAccountId = (int) ($glSettings[HR_INCOME_TAX_RULE_CODE]['account_id'] ?? 0);
if ($taxAccountId < 1) {
    $taxAccountId = hr_income_tax_read_linked_account_id($pdo);
}
if ($taxAccountId < 1) {
    $taxAccountId = hr_income_tax_ensure_gl_account($pdo);
}
$accounts = acc_journal_accounts_picker($pdo, $taxAccountId > 0 ? [$taxAccountId] : []);

$openAddStatus = (string) ($_GET['open_add'] ?? '');
if (!in_array($openAddStatus, ['single', 'married'], true)) {
    $openAddStatus = '';
}

$cssPath = app_path('assets/css/hr-income-tax-settings.css');
$cssUrl = app_url('assets/css/hr-income-tax-settings.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-income-tax-settings-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-income-tax-settings-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-income-tax-settings.js');
$jsUrl = app_url('assets/js/hr-income-tax-settings.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_income_tax_settings');
account_picker_enqueue_assets();
account_picker_json_script($accounts, 'hr-it-accounts-json');

/** @param list<array{id:int, marital_status:string, salary_from:float, salary_to:float|null, tax_percent:float, sort_order:int}> $brackets */
$renderBracketRows = static function (array $brackets, string $status): void {
    if (!$brackets) {
        echo '<tr class="hr-it-row hr-it-row--empty" data-marital="' . esc($status) . '">';
        echo '<td colspan="4" class="muted">لا توجد شرائح — اضغط «شريحة جديدة».</td>';
        echo '</tr>';

        return;
    }
    $seq = 0;
    foreach ($brackets as $b) {
        $seq++;
        $rid = (int) ($b['id'] ?? 0);
        $from = (float) ($b['salary_from'] ?? 0);
        $to = $b['salary_to'] ?? null;
        $pct = (float) ($b['tax_percent'] ?? 0);
        $toDisplay = $to === null ? '∞' : hr_income_tax_format_amount((float) $to);
        echo '<tr class="hr-it-row" data-marital="' . esc($status) . '"';
        echo ' data-id="' . $rid . '"';
        echo ' data-seq="' . $seq . '"';
        echo ' data-salary-from="' . esc((string) $from) . '"';
        echo ' data-salary-to="' . esc($to === null ? '' : (string) $to) . '"';
        echo ' data-tax-percent="' . esc((string) $pct) . '"';
        echo ' tabindex="0">';
        echo '<td dir="ltr">' . esc((string) $seq) . '</td>';
        echo '<td class="hr-it-amt-cell" dir="ltr">' . esc(hr_income_tax_format_amount($from)) . '</td>';
        echo '<td class="hr-it-amt-cell" dir="ltr">' . esc($toDisplay) . '</td>';
        echo '<td class="hr-it-pct-cell" dir="ltr">' . esc(number_format($pct, 3, '.', '')) . '%';
        echo ' <button type="button" class="hr-it-row-delete" title="حذف الشريحة"';
        echo ' data-id="' . $rid . '" data-marital="' . esc($status) . '" data-seq="' . $seq . '">حذف</button></td>';
        echo '</tr>';
    }
};
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-it-ora12-screen hr-it-wrap hr-it-grid-page hr-it-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-config-form-id="<?= esc($configFormId) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-default-status="single"
     data-open-add="<?= esc($openAddStatus) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إعدادات ضريبة الدخل</h1>
        <?php nav_render_screen_close('hr_income_tax_settings'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-it-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <p class="hr-it-grid-sub muted">
        تُحسب الضريبة على الراتب بعد الاقتطاعات (خصومات + ضمان موظف). تُطبَّق فقط على الموظفين
        الذين فُعِّل لهم «خاضع لضريبة الدخل» في بطاقة الموظف.
    </p>

    <section class="dashboard-ora-panel hr-it-config-panel">
        <h2 class="dashboard-ora-panel__title">الإعدادات العامة</h2>
        <div class="dashboard-ora-panel__body">
        <form id="<?= esc($configFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-it-config-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_config">
            <div class="hr-it-config-fields">
                <label class="field hr-it-config-account">
                    <span class="field-label">حساب ضريبة الدخل المستحقة</span>
                    <?php
                    echo account_picker_field([
                        'id' => 'hr_it_account_id',
                        'name' => 'account_id',
                        'value' => $taxAccountId,
                        'placeholder' => 'اختر حساب ضريبة دخل مستحقة…',
                        'allow_clear' => true,
                        'json_id' => 'hr-it-accounts-json',
                    ]);
                    ?>
                </label>
                <div class="hr-it-config-exempt">
                    <div class="hr-it-config-exempt-block">
                        <span class="hr-it-config-exempt-label">حد الإعفاء — أعزب</span>
                        <div class="hr-it-config-exempt-grid">
                            <label class="field">
                                <span class="field-label">الحد الشهري (دينار)</span>
                                <input class="input input-num" type="number" step="0.001" min="0"
                                       name="single_exempt_monthly"
                                       value="<?= esc((string) $config['single_exempt_monthly']) ?>">
                            </label>
                            <label class="field">
                                <span class="field-label">الحد السنوي (دينار)</span>
                                <input class="input input-num" type="number" step="0.001" min="0"
                                       name="single_exempt_annual"
                                       value="<?= esc((string) $config['single_exempt_annual']) ?>">
                            </label>
                        </div>
                    </div>
                    <div class="hr-it-config-exempt-block">
                        <span class="hr-it-config-exempt-label">حد الإعفاء — متزوج</span>
                        <div class="hr-it-config-exempt-grid">
                            <label class="field">
                                <span class="field-label">الحد الشهري (دينار)</span>
                                <input class="input input-num" type="number" step="0.001" min="0"
                                       name="married_exempt_monthly"
                                       value="<?= esc((string) $config['married_exempt_monthly']) ?>">
                            </label>
                            <label class="field">
                                <span class="field-label">الحد السنوي (دينار)</span>
                                <input class="input input-num" type="number" step="0.001" min="0"
                                       name="married_exempt_annual"
                                       value="<?= esc((string) $config['married_exempt_annual']) ?>">
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        </div>
    </section>

    <div class="dashboard-ora-toolbar hr-it-top-bar hr-it-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-it-btn-add">شريحة جديدة</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-it-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-it-btn-delete" disabled>حذف</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-it-btn-replace" disabled
                title="حذف الشريحة المحددة ثم إنشاء شريحة جديدة">حذف واستبدال</button>
        <span class="hr-it-active-status muted" id="hr-it-active-status-label">الجدول النشط: أعزب</span>
    </div>
    <p class="hr-it-toolbar-hint muted">يمكنك حذف أي شريحة وإنشاء بديل لها. حدّد شريحة ثم «حذف» أو «حذف واستبدال»، أو «شريحة جديدة» لإنشاء شرائح جديدة.</p>

    <section class="dashboard-ora-panel hr-it-editor-panel hr-it-editor" id="hr-it-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-it-editor-close" id="hr-it-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-it-editor-form">
            <section class="dashboard-ora-panel hr-it-section">
            <h2 class="dashboard-ora-panel__title" id="hr-it-editor-title">إضافة شريحة</h2>
            <div class="dashboard-ora-panel__body">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" id="hr-it-form-action" value="save_one">
            <input type="hidden" name="pending_items_json" id="hr-it-pending-json" value="[]">
            <input type="hidden" name="id" id="hr-it-editor-id" value="0">
            <input type="hidden" name="marital_status" id="hr-it-editor-status" value="single">
            <div class="hr-it-editor-fields">
                <div class="field hr-it-editor-field-seq">
                    <span class="field-label">رقم الشريحة</span>
                    <div class="hr-it-seq-display" id="hr-it-editor-seq-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-it-seq-hint" id="hr-it-editor-seq-hint">يُعيَّن تلقائياً عند الحفظ</small>
                </div>
                <label class="field">
                    <span class="field-label required">من (دينار)</span>
                    <input class="input input-num" type="number" step="0.001" min="0" name="salary_from"
                           id="hr-it-editor-from" dir="ltr" inputmode="decimal" required>
                </label>
                <label class="field">
                    <span class="field-label">إلى (دينار)</span>
                    <input class="input input-num" type="number" step="0.001" min="0" name="salary_to"
                           id="hr-it-editor-to" dir="ltr" inputmode="decimal" placeholder="∞">
                </label>
                <label class="field">
                    <span class="field-label required">النسبة %</span>
                    <input class="input input-num" type="number" step="0.001" min="0" max="100" name="tax_percent"
                           id="hr-it-editor-pct" dir="ltr" inputmode="decimal" required>
                </label>
            </div>
            <div class="hr-it-bracket-add-row">
                <button type="button" class="btn btn-primary btn-sm" id="hr-it-btn-inline-add">إضافة للجدول</button>
            </div>
            </div>
            </section>
            <div class="hr-it-editor-actions" id="hr-it-editor-actions">
                <button type="button" class="btn btn-primary btn-sm" id="hr-it-btn-save-bracket">حفظ الشريحة</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-it-btn-cancel">إلغاء</button>
            </div>
            <p class="hr-it-editor-hint muted" id="hr-it-editor-hint">
                أضف كل الشرائح المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.
            </p>
        </form>
        </div>
    </section>

    <div class="hr-it-brackets-cols">
        <section class="dashboard-ora-panel hr-it-bracket-panel is-active" data-status="single" aria-label="شرائح أعزب">
            <h2 class="dashboard-ora-panel__title hr-it-bracket-panel-title">شرائح — أعزب</h2>
            <p class="hr-it-bracket-panel-exempt muted">
                حد الإعفاء: شهري <?= esc((string) $config['single_exempt_monthly']) ?>
                — سنوي <?= esc((string) $config['single_exempt_annual']) ?> دينار
            </p>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="dashboard-ora-table-wrap hr-it-grid-wrap">
                <table class="dashboard-ora-table hr-it-grid-table">
                    <thead>
                    <tr>
                        <th>رقم</th>
                        <th>من (دينار)</th>
                        <th>إلى (دينار)</th>
                        <th>النسبة %</th>
                    </tr>
                    </thead>
                    <tbody id="hr-it-pending-body-single"></tbody>
                    <tbody id="hr-it-grid-body-single">
                    <?php $renderBracketRows($singleBrackets, 'single'); ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>

        <section class="dashboard-ora-panel hr-it-bracket-panel" data-status="married" aria-label="شرائح متزوج">
            <h2 class="dashboard-ora-panel__title hr-it-bracket-panel-title">شرائح — متزوج</h2>
            <p class="hr-it-bracket-panel-exempt muted">
                حد الإعفاء: شهري <?= esc((string) $config['married_exempt_monthly']) ?>
                — سنوي <?= esc((string) $config['married_exempt_annual']) ?> دينار
            </p>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="dashboard-ora-table-wrap hr-it-grid-wrap">
                <table class="dashboard-ora-table hr-it-grid-table">
                    <thead>
                    <tr>
                        <th>رقم</th>
                        <th>من (دينار)</th>
                        <th>إلى (دينار)</th>
                        <th>النسبة %</th>
                    </tr>
                    </thead>
                    <tbody id="hr-it-pending-body-married"></tbody>
                    <tbody id="hr-it-grid-body-married">
                    <?php $renderBracketRows($marriedBrackets, 'married'); ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>
    </div>

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-it-delete-form" style="display:none;" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-it-delete-id" value="0">
        <input type="hidden" name="marital_status" id="hr-it-delete-status" value="">
        <input type="hidden" name="replace_after" id="hr-it-delete-replace" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
