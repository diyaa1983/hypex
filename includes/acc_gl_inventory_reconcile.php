<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report_inventory.php');

/** ref_id لقيد التسوية من تاريخ ISO (YYYYMMDD). */
function acc_gl_inventory_reconcile_ref_id(string $asOfDate): int
{
    $digits = preg_replace('/\D/', '', $asOfDate) ?? '';

    return (int) substr($digits, 0, 8);
}

/** إلغاء ترحيل كل قيود تسوية المخزون. */
function acc_gl_inventory_unpost_all_reconciles(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    $count = 0;
    try {
        $st = $pdo->query(
            "SELECT ref_id FROM acc_journal_entry
             WHERE ref_type = 'inventory_reconcile' AND status = 'posted'
             ORDER BY entry_date ASC, id ASC"
        );
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawRef) {
            $refId = (int) $rawRef;
            if ($refId < 1) {
                continue;
            }
            acc_gl_unpost_ref($pdo, 'inventory_reconcile', $refId);
            $count++;
        }
    } catch (Throwable $e) {
        return $count;
    }

    return $count;
}

/** إلغاء ترحيل قيود التسوية التي تاريخها بعد التاريخ المعطى (يُبقي تسوية نفس اليوم). */
function acc_gl_inventory_unpost_reconciles_after(PDO $pdo, string $afterDate): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    $count = 0;
    try {
        $st = $pdo->prepare(
            "SELECT ref_id FROM acc_journal_entry
             WHERE ref_type = 'inventory_reconcile'
               AND status = 'posted'
               AND entry_date > ?
             ORDER BY entry_date ASC, id ASC"
        );
        $st->execute([$afterDate]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawRef) {
            $refId = (int) $rawRef;
            if ($refId < 1) {
                continue;
            }
            acc_gl_unpost_ref($pdo, 'inventory_reconcile', $refId);
            $count++;
        }
    } catch (Throwable $e) {
        return $count;
    }

    return $count;
}

/**
 * تواريخ نهاية الأشهر بين تاريخين (شامل) + تاريخ النهاية إن لم يكن آخر شهر.
 *
 * @return list<string> تواريخ ISO مرتبة تصاعدياً
 */
function acc_gl_inventory_period_end_dates(string $dateFrom, string $dateTo): array
{
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    $set = [$dateTo];
    try {
        $cur = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
        $cur = $cur->modify('last day of this month');
        while ($cur <= $end) {
            if ($cur >= new DateTimeImmutable($dateFrom)) {
                $set[] = $cur->format('Y-m-d');
            }
            $cur = $cur->modify('first day of next month')->modify('last day of this month');
        }
    } catch (Throwable $e) {
        $set[] = $dateTo;
    }

    $set = array_values(array_unique($set));
    sort($set);

    return $set;
}

/**
 * تسوية متسلسلة: نهاية كل شهر من بداية الفترة حتى التاريخ (الأقدم أولاً ثم الأحدث).
 *
 * @return array{ok:bool, dates:list<string>, results:list<array{date:string, ok:bool, skipped:bool, adjustment:float, error:?string}>}
 */
function acc_gl_inventory_reconcile_cascade(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $out = ['ok' => true, 'dates' => [], 'results' => []];
    $dates = acc_gl_inventory_period_end_dates($dateFrom, $dateTo);
    $out['dates'] = $dates;

    foreach ($dates as $d) {
        $r = acc_gl_inventory_reconcile_to_warehouse($pdo, $d, false);
        $out['results'][] = [
            'date' => $d,
            'ok' => (bool) ($r['ok'] ?? false),
            'skipped' => (bool) ($r['skipped'] ?? true),
            'adjustment' => (float) ($r['adjustment'] ?? 0),
            'error' => $r['error'] ?? null,
        ];
        if (!($r['ok'] ?? false)) {
            $out['ok'] = false;
            break;
        }
    }

    return $out;
}

/**
 * مواءمة رصيد حساب المخزون مع مجموع أرصدة المستودع المالية.
 *
 * @param bool $clearLaterReconciles إلغاء تسويات بتاريخ لاحق (عند تسوية تاريخ قديم يدوياً).
 * @return array{ok:bool, skipped:bool, error:?string, gl_balance:float, warehouse:float, adjustment:float}
 */
function acc_gl_inventory_reconcile_to_warehouse(PDO $pdo, string $asOfDate, bool $clearLaterReconciles = true): array
{
    $out = [
        'ok' => true,
        'skipped' => true,
        'error' => null,
        'gl_balance' => 0.0,
        'warehouse' => 0.0,
        'adjustment' => 0.0,
    ];

    if (!acc_gl_is_ready($pdo)) {
        $out['error'] = 'الربط المحاسبي غير مكتمل.';

        return $out;
    }

    $settings = acc_gl_load_settings($pdo);
    $invId = (int) ($settings['inventory']['account_id'] ?? 0);
    $varId = (int) ($settings['misc_expense']['account_id'] ?? 0);
    if ($invId < 1) {
        $out['error'] = 'حساب المخزون غير مربوط.';

        return $out;
    }
    if ($varId < 1) {
        $out['error'] = 'حساب مصروفات متنوعة (misc_expense) مطلوب لتسوية الفرق.';

        return $out;
    }

    $sums = acc_report_account_sums($pdo, $invId, null, $asOfDate, false);
    $glBalance = round($sums['balance'], 6);
    $warehouse = inv_warehouse_financial_grand_total($pdo, $asOfDate);
    $diff = round($glBalance - $warehouse, 6);

    $out['gl_balance'] = $glBalance;
    $out['warehouse'] = $warehouse;
    $out['adjustment'] = $diff;

    if ($clearLaterReconciles) {
        acc_gl_inventory_unpost_reconciles_after($pdo, $asOfDate);
    }

    $refId = acc_gl_inventory_reconcile_ref_id($asOfDate);
    acc_gl_unpost_ref($pdo, 'inventory_reconcile', $refId);

    if (abs($diff) < 0.01) {
        return $out;
    }

    $amount = abs($diff);
    $memo = 'تسوية مخزون مع أرصدة المستودع المالية';
    if ($diff > 0) {
        $lines = [
            ['account_id' => $invId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
            ['account_id' => $varId, 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
        ];
    } else {
        $lines = [
            ['account_id' => $invId, 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
            ['account_id' => $varId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
        ];
    }

    try {
        acc_gl_post_entry(
            $pdo,
            'inventory_reconcile',
            $refId,
            $asOfDate,
            'تسوية رصيد المخزون — ' . format_date_dmY($asOfDate),
            $lines
        );
        $out['skipped'] = false;
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}
