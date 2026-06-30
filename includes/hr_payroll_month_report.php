<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/document_header.php');

function hr_payroll_month_report_title(): string
{
    return 'تقرير قيود الرواتب حسب الشهر';
}

/**
 * @param list<array<string, mixed>> $allRows
 * @return array{rows:list<array<string, mixed>>, totals:array<string, float>}
 */
function hr_payroll_month_report_filter_rows(array $allRows): array
{
    $rows = [];
    $totals = [
        'base' => 0.0,
        'perm_allow' => 0.0,
        'month_allow' => 0.0,
        'deductions' => 0.0,
        'ss' => 0.0,
        'tax' => 0.0,
        'net' => 0.0,
    ];

    foreach ($allRows as $r) {
        if ((string) ($r['status'] ?? 'none') === 'none') {
            continue;
        }
        $rows[] = $r;
        $totals['base'] += (float) ($r['base_salary'] ?? 0);
        $totals['perm_allow'] += (float) ($r['permanent_allow_total'] ?? 0);
        $totals['month_allow'] += (float) ($r['monthly_allow_total'] ?? 0);
        $totals['deductions'] += (float) ($r['deductions'] ?? 0);
        $totals['ss'] += (float) ($r['social_security_emp'] ?? 0);
        $totals['tax'] += (float) ($r['income_tax'] ?? 0);
        $totals['net'] += (float) ($r['net_salary'] ?? 0);
    }
    foreach ($totals as $k => $v) {
        $totals[$k] = round($v, 3);
    }

    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * @return array{movement_no:string, movement_desc:string, movement_date:string}
 */
function hr_payroll_month_report_movement(PDO $pdo, int $payYear, int $payMonth): array
{
    $movementNo = sprintf('%04d-%02d', $payYear, $payMonth);
    $movementDesc = 'رواتب ' . hr_payroll_period_label($payYear, $payMonth);
    $movementDate = sprintf('%04d-%02d-01', $payYear, $payMonth);

    $journal = hr_payroll_month_journal_entry($pdo, $payYear, $payMonth);
    if ($journal) {
        $movementDate = (string) ($journal['entry_date'] ?? $movementDate);
        if ((string) ($journal['description_ar'] ?? '') !== '') {
            $movementDesc = (string) $journal['description_ar'];
        }
    }

    return [
        'movement_no' => $movementNo,
        'movement_desc' => $movementDesc,
        'movement_date' => $movementDate,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, float> $totals
 * @param array{code:string, label:string} $monthStatus
 */
function hr_payroll_month_report_render_doc(
    PDO $pdo,
    int $payYear,
    int $payMonth,
    array $rows,
    array $totals,
    array $monthStatus,
    string $movementNo,
    string $movementDesc,
    string $movementDate,
    string $reportTitle,
    string $reportDate,
    ?string $filterLabel = null
): void {
    $periodLabel = hr_payroll_period_label($payYear, $payMonth);
    ?>
    <div class="hr-pr-month-rpt-doc report-sales-print-area doc-print-watermark-scope">
        <?= document_print_watermark_html($pdo) ?>
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="doc-print-meta">
            <table>
                <tr>
                    <td><strong>الفترة:</strong> <?= esc($periodLabel) ?></td>
                    <td><strong>تاريخ التقرير:</strong> <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span></td>
                    <td><strong>الحالة:</strong> <?= esc((string) ($monthStatus['label'] ?? '—')) ?></td>
                </tr>
                <?php if ($filterLabel !== null && $filterLabel !== '' && $filterLabel !== '—' && $filterLabel !== 'جميع الموظفين'): ?>
                <tr>
                    <td colspan="3"><strong>الفلتر:</strong> <?= esc($filterLabel) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <table class="hr-pr-month-rpt-move">
            <tr>
                <th>رقم الحركة</th>
                <td dir="ltr"><?= esc($movementNo) ?></td>
                <th>وصف الحركة</th>
                <td><?= esc($movementDesc) ?></td>
                <th>تاريخ الحركة</th>
                <td dir="ltr"><?= esc(format_date_dmY($movementDate)) ?></td>
            </tr>
        </table>

        <?php if (!$rows): ?>
            <p class="hr-pr-month-rpt-empty muted">لا توجد قيود رواتب محتسبة أو مرحّلة لهذا الشهر.</p>
        <?php else: ?>
        <table class="hr-pr-month-rpt-table">
            <thead>
            <tr>
                <th>تسلسل</th>
                <th>رقم الموظف</th>
                <th>اسم الموظف</th>
                <th>الراتب الأساسي</th>
                <th>إجمالي العلاوات</th>
                <th>العلاوات الشهرية</th>
                <th>الاقتطاعات</th>
                <th>ضمان الموظف</th>
                <th>ضريبة الدخل</th>
                <th>صافي الراتب</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $idx => $r):
                $status = (string) ($r['status'] ?? 'none');
                $statusLabel = hr_payroll_employee_status_label($status, !empty($r['has_setup']));
                $eid = (int) ($r['id'] ?? 0);
                ?>
                <tr data-employee-id="<?= $eid ?>">
                    <td dir="ltr"><?= (int) $idx + 1 ?></td>
                    <td dir="ltr"><?= esc((string) ($r['emp_code'] ?? '—')) ?></td>
                    <td><?= esc((string) ($r['name_ar'] ?? '')) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['base_salary'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['permanent_allow_total'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['monthly_allow_total'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['deductions'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['social_security_emp'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['income_tax'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['net_salary'] ?? 0), 3)) ?></td>
                    <td><?= esc($statusLabel) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="3">الإجمالي</td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['base'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['perm_allow'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['month_allow'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['deductions'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['ss'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['tax'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['net'], 3)) ?></td>
                <td></td>
            </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
