<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_inventory.php');
require_once app_path('includes/acc_gl_inventory_reconcile.php');
require_once app_path('includes/acc_gl_inventory_cost.php');
require_once app_path('includes/inv_item_purchase_cost.php');

/**
 * ملخص الفجوة بين الدفتر والمستودع.
 *
 * @return array{
 *   as_of: string,
 *   gl_balance: float,
 *   warehouse_value: float,
 *   gap: float,
 *   closing_debit: float,
 *   sale_cogs_credit: float,
 *   purchases_debit: float,
 *   cogs_enabled: bool,
 *   misc_mapped: bool
 * }
 */
function acc_inventory_align_summary(PDO $pdo, string $asOfDate): array
{
    $detail = acc_report_inventory_period_detail($pdo, '2000-01-01', $asOfDate);
    $settings = acc_gl_load_settings($pdo);

    return [
        'as_of' => $asOfDate,
        'gl_balance' => (float) $detail['closing_balance'],
        'warehouse_value' => (float) $detail['warehouse_value'],
        'gap' => (float) $detail['warehouse_gap'],
        'closing_debit' => (float) $detail['closing_debit'],
        'sale_cogs_credit' => (float) $detail['sale_cogs_credit'],
        'purchases_debit' => (float) $detail['purchases_debit'],
        'cogs_enabled' => acc_gl_inventory_cogs_enabled($settings),
        'misc_mapped' => (int) ($settings['misc_expense']['account_id'] ?? 0) > 0,
    ];
}

/**
 * إعادة حساب COGS من آخر شراء (لا يمس الكميات في المستودع).
 *
 * @return array{ok:bool, invoices:int, returns:int, error:?string}
 */
function acc_inventory_align_refresh_cogs(PDO $pdo): array
{
    $out = ['ok' => true, 'invoices' => 0, 'returns' => 0, 'error' => null];
    acc_gl_ensure_schema($pdo);
    $settings = acc_gl_load_settings($pdo);

    if (!acc_gl_inventory_cogs_enabled($settings)) {
        $out['ok'] = false;
        $out['error'] = 'ربط حسابي المخزون (inventory) وتكلفة المبيعات (cogs) غير مكتمل.';

        return $out;
    }

    try {
        $invIds = $pdo->query('SELECT id FROM pur_invoice WHERE status = \'confirmed\' ORDER BY invoice_date, id')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($invIds as $raw) {
            inv_item_sync_costs_after_purchase_invoice_post($pdo, (int) $raw);
        }

        $stInv = $pdo->query(
            "SELECT i.id FROM sal_invoice i
             INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_invoice' AND e.ref_id = i.id AND e.source = 'auto'
             ORDER BY i.invoice_date, i.id"
        );
        foreach ($stInv->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $id = (int) $raw;
            $cost = acc_gl_sale_invoice_inventory_cost($pdo, $id);
            acc_gl_journal_refresh_cogs_lines($pdo, 'sale_invoice', $id, $cost, false);
            $out['invoices']++;
        }

        $stRet = $pdo->query(
            "SELECT r.id FROM sal_return r
             INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_return' AND e.ref_id = r.id AND e.source = 'auto'
             ORDER BY r.return_date, r.id"
        );
        foreach ($stRet->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $id = (int) $raw;
            $cost = acc_gl_sale_return_inventory_cost($pdo, $id);
            acc_gl_journal_refresh_cogs_lines($pdo, 'sale_return', $id, $cost, true);
            $out['returns']++;
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}

/** تكلفة COGS المرحّلة في قيد بيع/مردود (من سطر حساب المخزون). */
function acc_inventory_gl_cogs_amount(PDO $pdo, string $refType, int $refId): float
{
    if ($refId < 1 || !in_array($refType, ['sale_invoice', 'sale_return'], true)) {
        return 0.0;
    }

    $settings = acc_gl_load_settings($pdo);
    $invId = (int) ($settings['inventory']['account_id'] ?? 0);
    if ($invId < 1 || !acc_journal_has_tables($pdo)) {
        return 0.0;
    }

    $st = $pdo->prepare(
        "SELECT e.id FROM acc_journal_entry e
         WHERE e.ref_type = ? AND e.ref_id = ? AND e.source = 'auto' AND e.status = 'posted'
         LIMIT 1"
    );
    $st->execute([$refType, $refId]);
    $journalId = (int) $st->fetchColumn();
    if ($journalId < 1) {
        return 0.0;
    }

    if ($refType === 'sale_invoice') {
        $ln = $pdo->prepare(
            'SELECT COALESCE(SUM(credit), 0) FROM acc_journal_line
             WHERE journal_id = ? AND account_id = ? AND credit > 0.000001'
        );
    } else {
        $ln = $pdo->prepare(
            'SELECT COALESCE(SUM(debit), 0) FROM acc_journal_line
             WHERE journal_id = ? AND account_id = ? AND debit > 0.000001'
        );
    }
    $ln->execute([$journalId, $invId]);

    return round(max(0, (float) $ln->fetchColumn()), 6);
}

/**
 * إغلاق فجوة التقريب المتبقية عبر تعديل COGS على أكبر فاتورة بيع.
 */
function acc_inventory_align_cogs_close_remainder(PDO $pdo, string $asOfDate, float $gap): bool
{
    if (abs($gap) < 0.01) {
        return false;
    }

    $sql =
        "SELECT i.id FROM sal_invoice i
         INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_invoice' AND e.ref_id = i.id
             AND e.source = 'auto' AND e.status = 'posted'
         WHERE e.entry_date <= ?
         ORDER BY (
             SELECT COALESCE(SUM(l.credit), 0) FROM acc_journal_line l
             INNER JOIN acc_journal_entry e2 ON e2.id = l.journal_id
             WHERE e2.ref_type = 'sale_invoice' AND e2.ref_id = i.id
         ) DESC, i.id DESC
         LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute([$asOfDate]);
    $refId = (int) $st->fetchColumn();
    if ($refId < 1) {
        return false;
    }

    $cost = acc_inventory_gl_cogs_amount($pdo, 'sale_invoice', $refId);
    if ($cost <= 0.000001) {
        return false;
    }

    // gap = GL − المستودع: سالب → خفّض COGS؛ موجب → زِد COGS
    $newCost = round(max(0, $cost + $gap), 6);
    if (abs($newCost - $cost) < 0.000001) {
        return false;
    }

    acc_gl_journal_refresh_cogs_lines($pdo, 'sale_invoice', $refId, $newCost, false);

    return true;
}

/**
 * مطابقة رصيد المخزون مع المستودع عبر تعديل COGS فقط (فواتير بيع + مردودات بيع).
 * لا يمس المشتريات ولا قيود inventory_reconcile ولا misc_expense.
 *
 * @return array{
 *   ok: bool,
 *   skipped: bool,
 *   error: ?string,
 *   invoices: int,
 *   returns: int,
 *   gap_before: float,
 *   gap_after: float,
 *   scale_factor: float
 * }
 */
function acc_inventory_align_cogs_only(PDO $pdo, string $asOfDate): array
{
    $out = [
        'ok' => true,
        'skipped' => true,
        'error' => null,
        'invoices' => 0,
        'returns' => 0,
        'gap_before' => 0.0,
        'gap_after' => 0.0,
        'scale_factor' => 1.0,
    ];

    acc_gl_ensure_schema($pdo);
    $settings = acc_gl_load_settings($pdo);
    if (!acc_gl_inventory_cogs_enabled($settings)) {
        $out['ok'] = false;
        $out['error'] = 'ربط حسابي المخزون (inventory) وتكلفة المبيعات (cogs) غير مكتمل.';

        return $out;
    }

    try {
        acc_gl_inventory_unpost_all_reconciles($pdo);

        $refresh = acc_inventory_align_refresh_cogs($pdo);
        if (!($refresh['ok'] ?? false)) {
            $out['ok'] = false;
            $out['error'] = $refresh['error'] ?? 'فشل تحديث تكلفة المبيعات.';

            return $out;
        }

        $summaryBefore = acc_inventory_align_summary($pdo, $asOfDate);
        $gap = round((float) $summaryBefore['gap'], 6);
        $out['gap_before'] = $gap;

        if (abs($gap) < 0.01) {
            $out['invoices'] = (int) ($refresh['invoices'] ?? 0);
            $out['returns'] = (int) ($refresh['returns'] ?? 0);

            return $out;
        }

        $docs = [];

        $stInv = $pdo->prepare(
            "SELECT i.id FROM sal_invoice i
             INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_invoice' AND e.ref_id = i.id
                 AND e.source = 'auto' AND e.status = 'posted'
             WHERE e.entry_date <= ?
             ORDER BY i.invoice_date, i.id"
        );
        $stInv->execute([$asOfDate]);
        foreach ($stInv->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $id = (int) $raw;
            $cost = acc_inventory_gl_cogs_amount($pdo, 'sale_invoice', $id);
            if ($cost > 0.000001) {
                $docs[] = ['type' => 'sale_invoice', 'id' => $id, 'cost' => $cost];
            }
        }

        $stRet = $pdo->prepare(
            "SELECT r.id FROM sal_return r
             INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_return' AND e.ref_id = r.id
                 AND e.source = 'auto' AND e.status = 'posted'
             WHERE e.entry_date <= ?
             ORDER BY r.return_date, r.id"
        );
        $stRet->execute([$asOfDate]);
        foreach ($stRet->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $id = (int) $raw;
            $cost = acc_inventory_gl_cogs_amount($pdo, 'sale_return', $id);
            if ($cost > 0.000001) {
                $docs[] = ['type' => 'sale_return', 'id' => $id, 'cost' => $cost];
            }
        }

        if ($docs === []) {
            $out['ok'] = false;
            $out['error'] = 'لا توجد قيود تكلفة مبيعات لتوزيع الفرق عليها.';

            return $out;
        }

        $netEffect = 0.0;
        foreach ($docs as $doc) {
            if ($doc['type'] === 'sale_invoice') {
                $netEffect -= $doc['cost'];
            } else {
                $netEffect += $doc['cost'];
            }
        }

        if (abs($netEffect) < 0.000001) {
            $out['ok'] = false;
            $out['error'] = 'صافي تأثير COGS على المخزون صفر — لا يمكن توزيع الفرق.';

            return $out;
        }

        $deltaInventory = -$gap;
        $newNetEffect = round($netEffect + $deltaInventory, 6);
        $factor = round($newNetEffect / $netEffect, 12);
        $out['scale_factor'] = $factor;

        foreach ($docs as $doc) {
            $newCost = round(max(0, $doc['cost'] * $factor), 6);
            if ($newCost <= 0.000001) {
                continue;
            }
            $isReturn = $doc['type'] === 'sale_return';
            acc_gl_journal_refresh_cogs_lines($pdo, $doc['type'], $doc['id'], $newCost, $isReturn);
            if ($isReturn) {
                $out['returns']++;
            } else {
                $out['invoices']++;
            }
        }

        $out['skipped'] = false;

        for ($pass = 0; $pass < 8; $pass++) {
            $summaryAfter = acc_inventory_align_summary($pdo, $asOfDate);
            $remGap = round((float) $summaryAfter['gap'], 6);
            $out['gap_after'] = $remGap;
            if (abs($remGap) < 0.01) {
                break;
            }
            if (!acc_inventory_align_cogs_close_remainder($pdo, $asOfDate, $remGap)) {
                break;
            }
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذرت مطابقة COGS.';
    }

    return $out;
}

/**
 * إلغاء كل قيود تسوية المخزون (inventory_reconcile) — إرجاع رصيد المخزون وإزالة المبلغ من misc_expense.
 *
 * @return array{ok:bool, removed:int, error:?string}
 */
function acc_inventory_undo_all_reconciles(PDO $pdo): array
{
    $out = ['ok' => true, 'removed' => 0, 'error' => null];
    acc_gl_ensure_schema($pdo);

    try {
        $out['removed'] = acc_gl_inventory_unpost_all_reconciles($pdo);
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}

/**
 * مطابقة المخزون مع المستودع لكل يوم في الفترة (أي تاريخ نهاية يطابق بعدها).
 *
 * @return array{ok:bool, days:int, posted:int, error:?string, cogs_invoices:int, cogs_returns:int}
 */
function acc_inventory_match_all_dates(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $out = [
        'ok' => true,
        'days' => 0,
        'posted' => 0,
        'error' => null,
        'cogs_invoices' => 0,
        'cogs_returns' => 0,
    ];

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    try {
        $start = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = 'تواريخ غير صالحة.';

        return $out;
    }

    $dayCount = (int) $start->diff($end)->days + 1;
    if ($dayCount > 500) {
        $out['ok'] = false;
        $out['error'] = 'الفترة أطول من 500 يوماً — قصّر الفترة أو تواصل مع الدعم.';

        return $out;
    }

    $cogs = acc_inventory_align_refresh_cogs($pdo);
    $out['cogs_invoices'] = (int) ($cogs['invoices'] ?? 0);
    $out['cogs_returns'] = (int) ($cogs['returns'] ?? 0);
    if (!($cogs['ok'] ?? false)) {
        $out['ok'] = false;
        $out['error'] = $cogs['error'] ?? 'فشل تحديث تكلفة المبيعات.';

        return $out;
    }

    acc_gl_inventory_unpost_all_reconciles($pdo);

    $cur = $start;
    while ($cur <= $end) {
        $out['days']++;
        $iso = $cur->format('Y-m-d');
        $r = acc_gl_inventory_reconcile_to_warehouse($pdo, $iso, false);
        if (!($r['ok'] ?? false)) {
            $out['ok'] = false;
            $out['error'] = $r['error'] ?? 'فشل التسوية بتاريخ ' . format_date_dmY($iso);

            return $out;
        }
        if (!($r['skipped'] ?? true)) {
            $out['posted']++;
        }
        $cur = $cur->modify('+1 day');
    }

    return $out;
}
