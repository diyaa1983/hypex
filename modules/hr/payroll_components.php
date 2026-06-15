<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_component_delete.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
hr_payroll_component_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_payroll_components');
$editorFormId = 'hr-pc-editor-form';

/**
 * @return array{code:string,name:string,comp_type:string,amount:float,is_percent:int,desc:?string,active:int}
 */
function hr_pc_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['name_ar'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));
    $compType = (string) ($row['comp_type'] ?? 'allowance');
    if (!in_array($compType, ['allowance', 'deduction'], true)) {
        $compType = 'allowance';
    }
    $isPercent = !empty($row['is_percent']) ? 1 : 0;
    $defaultAmount = (float) ($row['default_amount'] ?? 0);
    $isActive = !empty($row['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('اسم البند مطلوب.');
    }
    if ($defaultAmount < 0) {
        throw new RuntimeException('المبلغ يجب أن يكون موجباً أو صفر.');
    }
    if ($isPercent === 1 && $defaultAmount > 100) {
        throw new RuntimeException('النسبة المئوية يجب أن تكون بين 0 و 100.');
    }

    $code = '';
    if ($id > 0) {
        $stCur = $pdo->prepare(
            'SELECT comp_code, comp_type FROM hr_payroll_component WHERE id = ? LIMIT 1'
        );
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('البند غير موجود.');
        }
        $compType = (string) ($cur['comp_type'] ?? $compType);
        $code = trim((string) ($cur['comp_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_payroll_component_next_code($pdo, $compType);
        }
    } else {
        $code = hr_payroll_component_next_code($pdo, $compType);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare(
        'SELECT id FROM hr_payroll_component WHERE comp_type = ? AND comp_code = ? AND id <> ? LIMIT 1'
    );
    $stChk->execute([$compType, $code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم بند تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'comp_type' => $compType,
        'amount' => $defaultAmount,
        'is_percent' => $isPercent,
        'desc' => $description !== '' ? $description : null,
        'active' => $isActive,
    ];
}

/**
 * @param array<string, mixed> $row
 */
function hr_pc_format_amount_cell(array $row): string
{
    $val = number_format((float) ($row['default_amount'] ?? 0), 2);
    if ((int) ($row['is_percent'] ?? 0) === 1) {
        return $val . ' %';
    }

    return $val;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_pc_parse_row($pdo, $_POST, $id);
            $typeLabel = $parsed['comp_type'] === 'deduction' ? 'اقتطاع' : 'علاوة';

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_payroll_component SET comp_code = ?, name_ar = ?, comp_type = ?,
                     is_percent = ?, default_amount = ?, description = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['comp_type'], $parsed['is_percent'],
                    $parsed['amount'], $parsed['desc'], $parsed['active'], $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات ' . $typeLabel . '.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_payroll_component (comp_code, name_ar, comp_type, is_percent,
                     default_amount, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['comp_type'], $parsed['is_percent'],
                    $parsed['amount'], $parsed['desc'], $parsed['active'],
                ]);
                flash_set('success', 'تم إضافة ' . $typeLabel . ' برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_payroll_component SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل البند.' : 'تم إيقاف البند.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_payroll_component_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذا البند.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_payroll_component WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف البند.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();

$stAllow = $pdo->query(
    "SELECT id, comp_code, name_ar, comp_type, is_percent, default_amount, description, is_active
     FROM hr_payroll_component WHERE comp_type = 'allowance'
     ORDER BY CAST(comp_code AS UNSIGNED) ASC, id ASC"
);
$allowances = $stAllow->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stDeduct = $pdo->query(
    "SELECT id, comp_code, name_ar, comp_type, is_percent, default_amount, description, is_active
     FROM hr_payroll_component WHERE comp_type = 'deduction'
     ORDER BY CAST(comp_code AS UNSIGNED) ASC, id ASC"
);
$deductions = $stDeduct->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nextAllowCode = hr_payroll_component_next_code($pdo, 'allowance');
$nextDeductCode = hr_payroll_component_next_code($pdo, 'deduction');

$cssPath = app_path('assets/css/hr-payroll-components.css');
$cssUrl = app_url('assets/css/hr-payroll-components.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-payroll-components-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-payroll-components-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-payroll-components.js');
$jsUrl = app_url('assets/js/hr-payroll-components.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_payroll_components');

/**
 * @param array<int, array<string, mixed>> $rows
 */
function hr_pc_render_rows(PDO $pdo, string $listUrl, array $rows, string $panelType, string $emptyMsg): void
{
    if (!$rows) {
        ?>
        <tr class="hr-pc-row hr-pc-row--empty">
            <td colspan="4" class="muted"><?= esc($emptyMsg) ?></td>
        </tr>
        <?php
        return;
    }

    foreach ($rows as $c) {
        $rid = (int) $c['id'];
        $code = (string) ($c['comp_code'] ?? '');
        $name = (string) ($c['name_ar'] ?? '');
        $desc = (string) ($c['description'] ?? '');
        $amountCell = hr_pc_format_amount_cell($c);
        $isPercent = (int) ($c['is_percent'] ?? 0);
        $isActive = (int) ($c['is_active'] ?? 1) === 1;
        $delChk = hr_payroll_component_delete_check($pdo, $rid);
        $linked = !$delChk['can_delete'];
        ?>
        <tr class="hr-pc-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
            data-id="<?= $rid ?>"
            data-code="<?= esc($code) ?>"
            data-name="<?= esc($name) ?>"
            data-description="<?= esc($desc) ?>"
            data-amount="<?= esc((string) ($c['default_amount'] ?? '0')) ?>"
            data-is-percent="<?= $isPercent ?>"
            data-comp-type="<?= esc($panelType) ?>"
            data-active="<?= $isActive ? '1' : '0' ?>"
            data-linked="<?= $linked ? '1' : '0' ?>"
            data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
            tabindex="0">
            <td class="hr-pc-col-num"><?= esc($code !== '' ? $code : '—') ?></td>
            <td><?= esc($name !== '' ? $name : '—') ?></td>
            <td class="hr-pc-col-amount" dir="ltr"><?= esc($amountCell) ?></td>
            <td class="hr-pc-cell-active">
                <form method="post" action="<?= esc($listUrl) ?>" class="hr-pc-toggle-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <input type="hidden" name="is_active" value="0">
                    <label class="hr-pc-toggle-chk" title="<?= $isActive ? 'إيقاف البند' : 'تفعيل البند' ?>">
                        <input type="checkbox" name="is_active" value="1" class="hr-pc-active-cb"
                            <?= $isActive ? 'checked' : '' ?>>
                        <span class="sr-only">تنشيط</span>
                    </label>
                </form>
            </td>
        </tr>
        <?php
    }
}
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-bold hr-pc-wrap hr-pc-split-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code-allow="<?= esc($nextAllowCode) ?>"
     data-next-code-deduct="<?= esc($nextDeductCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إعداد العلاوات والاقتطاعات</h1>
        <?php nav_render_screen_close('hr_payroll_components'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-pc-split-flash sales-inv-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-ora-panel hr-pc-editor-panel hr-pc-editor" id="hr-pc-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-pc-editor-close" id="hr-pc-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-pc-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_one">
            <input type="hidden" name="id" id="hr-pc-editor-id" value="0">
            <input type="hidden" name="comp_type" id="hr-pc-editor-type" value="allowance">
            <section class="dashboard-ora-panel hr-pc-section">
            <h2 class="dashboard-ora-panel__title">
                <span id="hr-pc-editor-title">إضافة بند</span>
                <span class="hr-pc-editor-badge" id="hr-pc-editor-badge" aria-hidden="true"></span>
            </h2>
            <div class="dashboard-ora-panel__body hr-pc-editor-inner">
            <div class="hr-pc-editor-fields">
                <div class="field hr-pc-editor-field-code">
                    <span class="field-label" id="hr-pc-editor-code-label">رقم العلاوة</span>
                    <div class="hr-pc-code-display" id="hr-pc-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-pc-code-hint" id="hr-pc-editor-code-hint">يُولَّد تلقائياً ولا يمكن تعديله</small>
                </div>
                <label class="field">
                    <span class="field-label required" id="hr-pc-editor-name-label">اسم العلاوة</span>
                    <input class="input" type="text" name="name_ar" id="hr-pc-editor-name" required autocomplete="off"
                           placeholder="بدل مواصلات، بدل بنزين، …">
                </label>
                <label class="field hr-pc-editor-field-amount">
                    <span class="field-label">المبلغ</span>
                    <input class="input" type="number" name="default_amount" id="hr-pc-editor-amount"
                           step="0.001" min="0" value="0" dir="ltr" inputmode="decimal">
                </label>
                <label class="field hr-pc-editor-field-notes">
                    <span class="field-label">ملاحظات</span>
                    <input class="input" type="text" name="description" id="hr-pc-editor-notes" autocomplete="off">
                </label>
                <label class="field hr-pc-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-pc-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-pc-editor-active" value="1" checked>
                        <span id="hr-pc-editor-active-label">بند مفعّل</span>
                    </span>
                </label>
            </div>
            </div>
            </section>
            <div class="hr-pc-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm" id="hr-pc-editor-save-btn">حفظ</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-pc-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </section>

    <div class="hr-pc-split">
        <section class="dashboard-ora-panel hr-pc-panel hr-pc-panel--allow" data-panel-type="allowance">
            <h2 class="dashboard-ora-panel__title hr-pc-panel-title">العلاوات</h2>
            <div class="dashboard-ora-toolbar hr-pc-panel-toolbar">
                <button type="button" class="btn btn-primary btn-sm hr-pc-btn-add" data-type="allowance">
                    علاوة جديدة
                </button>
                <button type="button" class="btn btn-secondary btn-sm hr-pc-btn-edit" data-type="allowance" disabled>
                    تعديل
                </button>
            </div>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="sales-inv-table-wrap hr-pc-panel-table-wrap">
                <table class="sales-inv-table hr-pc-panel-table">
                    <thead>
                    <tr>
                        <th>رقم العلاوة</th>
                        <th>العلاوة</th>
                        <th class="hr-pc-col-amount-h">المبلغ</th>
                        <th class="hr-pc-col-active-h">تنشيط</th>
                    </tr>
                    </thead>
                    <tbody class="hr-pc-panel-body" data-panel-type="allowance">
                    <?php hr_pc_render_rows(
                        $pdo,
                        $listUrl,
                        $allowances,
                        'allowance',
                        'لا توجد علاوات — اضغط «علاوة جديدة».'
                    ); ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>

        <section class="dashboard-ora-panel hr-pc-panel hr-pc-panel--deduct" data-panel-type="deduction">
            <h2 class="dashboard-ora-panel__title hr-pc-panel-title">الاقتطاعات</h2>
            <div class="dashboard-ora-toolbar hr-pc-panel-toolbar">
                <button type="button" class="btn btn-primary btn-sm hr-pc-btn-add" data-type="deduction">
                    اقتطاع جديد
                </button>
                <button type="button" class="btn btn-secondary btn-sm hr-pc-btn-edit" data-type="deduction" disabled>
                    تعديل
                </button>
            </div>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="sales-inv-table-wrap hr-pc-panel-table-wrap">
                <table class="sales-inv-table hr-pc-panel-table">
                    <thead>
                    <tr>
                        <th>رقم الاقتطاع</th>
                        <th>الاقتطاع</th>
                        <th class="hr-pc-col-amount-h">المبلغ</th>
                        <th class="hr-pc-col-active-h">تنشيط</th>
                    </tr>
                    </thead>
                    <tbody class="hr-pc-panel-body" data-panel-type="deduction">
                    <?php hr_pc_render_rows(
                        $pdo,
                        $listUrl,
                        $deductions,
                        'deduction',
                        'لا توجد اقتطاعات — اضغط «اقتطاع جديد».'
                    ); ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>
    </div>

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-pc-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-pc-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
