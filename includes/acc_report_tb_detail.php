<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/acc_report_vat_jordan.php');
require_once app_path('includes/acc_report_inventory.php');

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function acc_report_tb_by_ref_type(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT COALESCE(NULLIF(TRIM(e.ref_type), \'\'), \'_other\') AS ref_type,
                COALESCE(SUM(l.debit), 0) AS sum_debit,
                COALESCE(SUM(l.credit), 0) AS sum_credit
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE l.account_id = ?
           AND e.status = \'posted\'
           AND e.entry_date >= ?
           AND e.entry_date <= ?
         GROUP BY COALESCE(NULLIF(TRIM(e.ref_type), \'\'), \'_other\')'
    );
    $st->execute([$accountId, $dateFrom, $dateTo]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) ($row['ref_type'] ?? '_other')] = [
            'debit' => (float) ($row['sum_debit'] ?? 0),
            'credit' => (float) ($row['sum_credit'] ?? 0),
        ];
    }

    return $out;
}

/** @return array<string, string> */
function acc_report_tb_ref_type_labels(): array
{
    return array_merge(acc_report_ref_type_labels(), [
        'hr_payroll_month' => 'ترحيل رواتب',
        'hr_payroll_employer_ss' => 'ترحيل ضمان (قديم)',
        'inventory_reconcile' => 'تسوية مخزون',
        'inventory_stocktake' => 'جرد مخزون',
        'opening_balance' => 'رصيد افتتاحي',
        'journal_entry' => 'قيد يومية',
        '_other' => 'قيود أخرى / يدوية',
    ]);
}

function acc_report_tb_ref_type_label(string $refType): string
{
    return acc_report_tb_ref_type_labels()[$refType] ?? $refType;
}

/**
 * @return list<array{label:string, amount:float, side:string, emphasis?:bool, prefix?:string}>
 */
function acc_report_tb_detail_rows(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    $vatIds = acc_report_vat_account_ids($pdo);
    $vatInId = (int) ($vatIds['input'] ?? 0);
    $vatOutId = (int) ($vatIds['output'] ?? 0);

    if ($accountId === $vatInId && $vatInId > 0) {
        $vat = acc_report_vat_tb_period_detail($pdo, $accountId, $dateFrom, $dateTo, false);
        if ($vat === null) {
            return [];
        }

        return [
            ['label' => (string) $vat['gross_label'], 'amount' => (float) $vat['gross'], 'side' => 'مدين'],
            [
                'label' => (string) $vat['return_label'],
                'amount' => (float) $vat['return_amount'],
                'side' => 'دائن',
                'prefix' => '− ',
            ],
            [
                'label' => (string) $vat['net_label'],
                'amount' => (float) $vat['net'],
                'side' => 'ختامي مدين',
                'emphasis' => true,
            ],
        ];
    }

    if ($accountId === $vatOutId && $vatOutId > 0) {
        $vat = acc_report_vat_tb_period_detail($pdo, $accountId, $dateFrom, $dateTo, true);
        if ($vat === null) {
            return [];
        }

        return [
            ['label' => (string) $vat['gross_label'], 'amount' => (float) $vat['gross'], 'side' => 'دائن'],
            [
                'label' => (string) $vat['return_label'],
                'amount' => (float) $vat['return_amount'],
                'side' => 'مدين',
                'prefix' => '− ',
            ],
            [
                'label' => (string) $vat['net_label'],
                'amount' => (float) $vat['net'],
                'side' => 'ختامي دائن',
                'emphasis' => true,
            ],
        ];
    }

    $invDetail = acc_report_tb_inventory_detail_rows($pdo, $accountId, $dateFrom, $dateTo);
    if ($invDetail !== []) {
        return $invDetail;
    }

    $purchId = acc_report_purchases_account_id($pdo);
    if ($accountId === $purchId && $purchId > 0) {
        $purch = acc_report_tb_purchases_period_detail($pdo, $dateFrom, $dateTo);
        if ($purch !== null && (int) ($purch['account_id'] ?? 0) === $purchId) {
            return [
                ['label' => (string) $purch['gross_label'], 'amount' => (float) $purch['gross'], 'side' => 'مدين'],
                [
                    'label' => (string) $purch['return_label'],
                    'amount' => (float) $purch['return_amount'],
                    'side' => 'دائن',
                    'prefix' => '− ',
                ],
                [
                    'label' => (string) $purch['net_label'],
                    'amount' => abs((float) $purch['net']),
                    'side' => (float) $purch['net'] >= 0 ? 'صافي مدين' : 'صافي دائن',
                    'emphasis' => true,
                ],
            ];
        }
    }

    $byRef = acc_report_tb_by_ref_type($pdo, $accountId, $dateFrom, $dateTo);
    if (!$byRef) {
        return [];
    }

    $lines = [];
    $sorted = $byRef;
    uasort($sorted, static function (array $a, array $b): int {
        $ta = (float) ($a['debit'] ?? 0) + (float) ($a['credit'] ?? 0);
        $tb = (float) ($b['debit'] ?? 0) + (float) ($b['credit'] ?? 0);

        return $tb <=> $ta;
    });

    foreach ($sorted as $refType => $sums) {
        $dr = round((float) ($sums['debit'] ?? 0), 6);
        $cr = round((float) ($sums['credit'] ?? 0), 6);
        if ($dr <= 0.0005 && $cr <= 0.0005) {
            continue;
        }
        $label = acc_report_tb_ref_type_label((string) $refType);
        if ($dr > 0.0005) {
            $lines[] = ['label' => $label, 'amount' => $dr, 'side' => 'مدين'];
        }
        if ($cr > 0.0005) {
            $lines[] = ['label' => $label, 'amount' => $cr, 'side' => 'دائن'];
        }
    }

    if (!$lines) {
        return [];
    }

    $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
    $net = round((float) ($period['balance'] ?? 0), 6);
    if (abs($net) > 0.0005) {
        $lines[] = [
            'label' => 'صافي حركة الفترة',
            'amount' => abs($net),
            'side' => $net > 0 ? 'ختامي مدين' : 'ختامي دائن',
            'emphasis' => true,
        ];
    }

    return $lines;
}

/**
 * @param list<array{label:string, amount:float, side:string, emphasis?:bool, prefix?:string}> $rows
 */
function acc_report_tb_detail_render_html(array $rows, int $colspan = 9): string
{
    if (!$rows) {
        return '';
    }

    $buf = '<tr class="tb-account-detail-row tb-vat-detail-row"><td colspan="' . (int) $colspan . '">';
    $buf .= '<table class="data-table report-acc-grid-table tb-vat-inner-table tb-account-inner-table"><tbody>';

    foreach ($rows as $row) {
        $emphasis = !empty($row['emphasis']);
        $prefix = (string) ($row['prefix'] ?? '');
        $trClass = $emphasis ? ' class="report-acc-total"' : '';
        $buf .= '<tr' . $trClass . '>';
        $buf .= '<td>' . ($emphasis ? '<strong>' : '') . esc((string) $row['label']) . ($emphasis ? '</strong>' : '') . '</td>';
        $buf .= '<td class="col-money" style="text-align:end;width:8rem;">';
        $buf .= ($emphasis ? '<strong>' : '') . esc($prefix . format_money((float) $row['amount'])) . ($emphasis ? '</strong>' : '');
        $buf .= '</td>';
        $buf .= '<td class="muted" style="width:5.5rem;text-align:center;font-size:0.8rem;">';
        $buf .= ($emphasis ? '<strong>' : '') . esc((string) $row['side']) . ($emphasis ? '</strong>' : '');
        $buf .= '</td></tr>';
    }

    $buf .= '</tbody></table></td></tr>';

    return $buf;
}

function acc_report_tb_detail_for_account(
    PDO $pdo,
    int $accountId,
    string $dateFrom,
    string $dateTo,
    int $colspan = 9
): array {
    $rows = acc_report_tb_detail_rows($pdo, $accountId, $dateFrom, $dateTo);

    return [
        'ok' => true,
        'account_id' => $accountId,
        'has_detail' => $rows !== [],
        'rows' => $rows,
        'html' => acc_report_tb_detail_render_html($rows, $colspan),
    ];
}
