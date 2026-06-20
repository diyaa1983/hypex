<?php
declare(strict_types=1);

require_once app_path('includes/fin_payment_parties.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/hr_employee_advance.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
fin_voucher_ensure_schema_full($pdo);
hr_employee_advance_ensure_schema($pdo);
hr_employee_advance_ensure_post_columns($pdo);

$activeRoute = $activeRoute ?? 'fin_employee_advances';
$flash = flash_get();
$exitUrl = nav_exit_url('fin_employee_advances');
$rows = fin_payment_employee_advances_pending_all($pdo);
$sumAmount = 0.0;
foreach ($rows as $r) {
    $sumAmount += (float) ($r['total_amount'] ?? 0);
}

$payableRows = fin_payment_employee_advance_payable_account($pdo);
$payableLabel = $payableRows !== []
    ? trim((string) ($payableRows[0]['code'] ?? '')) . ' — ' . trim((string) ($payableRows[0]['label'] ?? ''))
    : '2009 — سلف موظفين مستحقة الصرف';

$cssPath = app_path('assets/css/fin-employee-advances.css');
$cssUrl = app_url('assets/css/fin-employee-advances.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');

sales_ora12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page fin-emp-adv-page fin-emp-adv-ora12"
     id="fin-emp-adv-screen"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('السلف', '', $activeRoute); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <p class="sales-ora-info muted fin-emp-adv-intro">
        السلف المعتمدة والمرحّلة من شؤون الموظفين بانتظار الصرف النقدي.
        حساب الصرف المُدين: <strong><?= esc($payableLabel) ?></strong>.
        بعد حفظ وترحيل سند الصرف تختفي السلفة من هذه القائمة.
    </p>

    <div class="sales-ora-panel card fin-emp-adv-results">
        <p class="sales-ora-info fin-emp-adv-summary">
            العدد: <strong><?= count($rows) ?></strong>
            — الإجمالي: <strong><?= esc(format_money($sumAmount)) ?></strong>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-emp-adv-table" id="fin-emp-adv-table">
                <thead>
                <tr>
                    <th>رقم السلفة</th>
                    <th>الموظف</th>
                    <th>نوع السلفة</th>
                    <th>الفترة</th>
                    <th>المبلغ</th>
                    <th>تاريخ الترحيل</th>
                    <th>ملاحظات</th>
                    <th class="fin-emp-adv-col-action">إجراء</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="8" class="muted fin-emp-adv-empty">لا توجد سلف بانتظار الصرف.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $advId = (int) ($r['id'] ?? 0);
                        $empLabel = trim((string) ($r['emp_code'] ?? ''));
                        if ($empLabel !== '') {
                            $empLabel .= ' — ';
                        }
                        $empLabel .= trim((string) ($r['emp_name'] ?? ''));
                        $postedAt = (string) ($r['posted_at'] ?? '');
                        $postedDisplay = $postedAt !== '' ? format_date_dmY(substr($postedAt, 0, 10)) : '—';
                        $notes = trim((string) ($r['notes'] ?? ''));
                        ?>
                        <tr>
                            <td><?= esc((string) ($r['advance_code'] ?? $advId)) ?></td>
                            <td><?= esc($empLabel !== '' ? $empLabel : '—') ?></td>
                            <td><?= esc((string) ($r['advance_type_label'] ?? '—')) ?></td>
                            <td><?= esc((string) ($r['period_label'] ?? '—')) ?></td>
                            <td dir="ltr" class="col-money fin-emp-adv-col-money"><?= esc(format_money((float) ($r['total_amount'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($postedDisplay) ?></td>
                            <td class="fin-emp-adv-notes"><?= esc($notes !== '' ? $notes : '—') ?></td>
                            <td class="fin-emp-adv-col-action">
                                <a class="btn btn-primary btn-sm fin-emp-adv-disburse-btn"
                                   href="<?= esc(fin_payment_disburse_advance_url($advId)) ?>">
                                    صرف السلفة
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>
