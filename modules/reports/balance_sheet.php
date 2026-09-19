<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$asOf = parse_date_to_iso(trim((string) ($_GET['as_of'] ?? ''))) ?? app_default_date_to();
$bs = acc_report_balance_sheet($pdo, $asOf);
$yearStart = substr($asOf, 0, 4) . '-01-01';

$cssPath = app_path('assets/css/report-acc.css');
$cssUrl = app_url('assets/css/report-acc.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');

$renderSection = static function (string $title, array $rows, float $total) use ($asOf, $yearStart): void {
    echo '<h3 class="report-acc-section-title">' . esc($title) . '</h3>';
    echo '<div class="report-acc-grid-wrap"><table class="data-table report-acc-table report-acc-grid-table">';
    echo '<thead><tr><th>حساب</th><th class="col-money">الرصيد</th><th class="col-act no-print">إجراء</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="3" class="muted" style="text-align:center;">لا أرصدة.</td></tr>';
    }
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        $depth = (int) ($r['depth'] ?? 0);
        $isGroup = !empty($r['is_group']);
        $ledgerUrl = !$isGroup && $id > 0 ? acc_report_general_ledger_url($id, $yearStart, $asOf) : null;
        $namePad = 0.35 + $depth * 1.15;
        $rowClass = $isGroup ? 'report-bs-group-row' : ($depth > 0 ? 'report-bs-child-row' : '');
        echo '<tr' . ($rowClass !== '' ? ' class="' . esc($rowClass) . '"' : '') . '>';
        echo '<td style="padding-inline-start:' . esc((string) $namePad) . 'rem">';
        if ($isGroup) {
            echo '<span class="report-bs-group-mark" aria-hidden="true">▸ </span>';
        }
        echo esc(acc_account_format_code((string) $r['code']) . ' — ' . (string) $r['name_ar']);
        echo '</td>';
        echo '<td class="col-money">' . esc(format_money((float) $r['balance'])) . '</td>';
        echo '<td class="no-print">';
        if ($ledgerUrl) {
            echo '<a class="btn btn-secondary btn-sm" href="' . esc($ledgerUrl) . '">دفتر</a>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody><tfoot><tr class="report-acc-total"><td><strong>المجموع</strong></td>';
    echo '<td class="col-money"><strong>' . esc(format_money($total)) . '</strong></td><td></td></tr></tfoot></table></div>';
};
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<div class="card report-acc-wrap">
    <h2 class="report-acc-title">الميزانية العمومية</h2>
    <p class="muted report-acc-sub">أرصدة الأصول والخصوم وحقوق الملكية حتى التاريخ، مع صافي ربح السنة من <?= esc(format_date_dmY($yearStart)) ?>.</p>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row report-acc-filters">
        <input type="hidden" name="r" value="report_balance_sheet">
        <label class="field">
            <span class="field-label">حتى تاريخ</span>
            <input class="input js-date-dmy" type="text" name="as_of" value="<?= esc(format_date_dmY($asOf)) ?>" dir="ltr">
        </label>
        <div class="field" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
        </div>
    </form>

    <div class="report-acc-summary-grid">
        <div class="report-acc-summary-card">
            <span class="muted">إجمالي الأصول</span>
            <strong><?= esc(format_money($bs['total_assets'])) ?></strong>
        </div>
        <div class="report-acc-summary-card">
            <span class="muted">الخصوم + حقوق الملكية</span>
            <strong><?= esc(format_money($bs['total_liabilities_equity'])) ?></strong>
        </div>
        <?php if (abs($bs['total_assets'] - $bs['total_liabilities_equity']) >= 0.01): ?>
        <div class="report-acc-summary-card">
            <span class="muted">فرق التوازن</span>
            <strong style="color:var(--danger,#ef4444);"><?= esc(format_money(abs($bs['total_assets'] - $bs['total_liabilities_equity']))) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <?php $renderSection('الأصول', $bs['assets'], $bs['total_assets']); ?>
    <?php $renderSection('الخصوم', $bs['liabilities'], $bs['total_liabilities']); ?>

    <h3 class="report-acc-section-title">حقوق الملكية</h3>
    <div class="report-acc-grid-wrap">
        <table class="data-table report-acc-table report-acc-grid-table">
            <thead><tr><th>البند</th><th class="col-money">الرصيد</th></tr></thead>
            <tbody>
            <?php foreach ($bs['equity'] as $r): ?>
                <tr>
                    <td><?= esc(acc_account_format_code((string) $r['code']) . ' — ' . (string) $r['name_ar']) ?></td>
                    <td class="col-money"><?= esc(format_money((float) $r['balance'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td>صافي ربح / خسارة السنة (من <?= esc(format_date_dmY($yearStart)) ?>)</td>
                <td class="col-money"><?= esc(format_money($bs['net_income'])) ?></td>
            </tr>
            </tbody>
            <tfoot>
            <tr class="report-acc-total">
                <td><strong>إجمالي حقوق الملكية</strong></td>
                <td class="col-money"><strong><?= esc(format_money($bs['total_equity'])) ?></strong></td>
            </tr>
            </tfoot>
        </table>
    </div>
</div>
