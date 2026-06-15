<?php
declare(strict_types=1);

require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/acc_journal.php');
require_once app_path('includes/inv_item_inventory_unit_cost.php');

/** هل مُفعّل خصم المخزون عند البيع (ربط inventory + cogs). */
function acc_gl_inventory_cogs_enabled(array $settings): bool
{
    return (int) ($settings['inventory']['account_id'] ?? 0) > 0
        && (int) ($settings['cogs']['account_id'] ?? 0) > 0;
}

/**
 * تكلفة البضاعة المباعة لفاتورة بيع (كمية مستودعية × تكلفة الوحدة من الشراء/البطاقة).
 */
function acc_gl_sale_invoice_inventory_cost(PDO $pdo, int $invoiceId): float
{
    if ($invoiceId < 1) {
        return 0.0;
    }
    if (!sal_invoice_column_exists($pdo, 'inv_item', 'track_inventory')) {
        return 0.0;
    }

    $qtyExtra = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'qty_extra')
        ? 'COALESCE(il.qty_extra, 0)'
        : '0';

    $stInv = $pdo->prepare('SELECT invoice_date FROM sal_invoice WHERE id = ? LIMIT 1');
    $stInv->execute([$invoiceId]);
    $invoiceDate = (string) ($stInv->fetchColumn() ?: '');
    if ($invoiceDate === '') {
        return 0.0;
    }

    $st = $pdo->prepare(
        "SELECT il.item_id, il.qty, {$qtyExtra} AS qty_extra
         FROM sal_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ?
           AND i.track_inventory = 1
           AND (il.qty + {$qtyExtra}) > 0.000001"
    );
    $st->execute([$invoiceId]);
    $sum = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $stockQty = inv_invoice_line_stock_qty_sum((float) ($row['qty'] ?? 0), (float) ($row['qty_extra'] ?? 0));
        $sum += $stockQty * inv_item_inventory_unit_cost($pdo, $itemId, $invoiceDate);
    }

    return round(max(0, $sum), 6);
}

/**
 * تكلفة مردود مبيعات (إعادة للمخزون).
 */
function acc_gl_sale_return_inventory_cost(PDO $pdo, int $returnId): float
{
    if ($returnId < 1) {
        return 0.0;
    }
    if (!sal_invoice_column_exists($pdo, 'inv_item', 'track_inventory')) {
        return 0.0;
    }

    $stRet = $pdo->prepare('SELECT return_date FROM sal_return WHERE id = ? LIMIT 1');
    $stRet->execute([$returnId]);
    $returnDate = (string) ($stRet->fetchColumn() ?: '');
    if ($returnDate === '') {
        return 0.0;
    }

    $st = $pdo->prepare(
        'SELECT rl.item_id, rl.qty
         FROM sal_return_line rl
         INNER JOIN inv_item i ON i.id = rl.item_id
         WHERE rl.return_id = ?
           AND i.track_inventory = 1
           AND rl.qty > 0.000001'
    );
    $st->execute([$returnId]);
    $sum = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $sum += (float) ($row['qty'] ?? 0) * inv_item_inventory_unit_cost($pdo, $itemId, $returnDate);
    }

    return round(max(0, $sum), 6);
}

/**
 * أسطر قيد تكلفة المبيعات (مدين cogs / دائن inventory) أو عكسها للمردود.
 *
 * @return list<array{rule:string, debit:float, credit:float, memo?:string}>
 */
function acc_gl_cogs_lines(array $settings, float $cost, bool $isReturn): array
{
    if ($cost <= 0.000001 || !acc_gl_inventory_cogs_enabled($settings)) {
        return [];
    }

    $memo = $isReturn ? 'إعادة تكلفة مردود للمخزون' : 'تكلفة بضاعة مباعة';

    if ($isReturn) {
        return [
            ['rule' => 'inventory', 'debit' => $cost, 'credit' => 0, 'memo' => $memo],
            ['rule' => 'cogs', 'debit' => 0, 'credit' => $cost, 'memo' => $memo],
        ];
    }

    return [
        ['rule' => 'cogs', 'debit' => $cost, 'credit' => 0, 'memo' => $memo],
        ['rule' => 'inventory', 'debit' => 0, 'credit' => $cost, 'memo' => $memo],
    ];
}

/** هل القيد يحتوي بالفعل على دائن للمخزون (تكلفة مبيعات مُرحّلة). */
function acc_gl_journal_has_inventory_cogs_credit(PDO $pdo, int $journalId, int $inventoryAccountId): bool
{
    if ($journalId < 1 || $inventoryAccountId < 1) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM acc_journal_line
         WHERE journal_id = ? AND account_id = ? AND credit > 0.000001
         LIMIT 1'
    );
    $st->execute([$journalId, $inventoryAccountId]);

    return (bool) $st->fetchColumn();
}

/**
 * إضافة أسطر COGS لقيد تلقائي موجود (ترحيل قديم).
 */
function acc_gl_journal_append_cogs_lines(
    PDO $pdo,
    string $refType,
    int $refId,
    float $cost,
    bool $isReturn
): bool {
    if ($refId < 1 || $cost <= 0.000001) {
        return false;
    }

    require_once app_path('includes/acc_gl.php');
    $settings = acc_gl_load_settings($pdo);
    if (!acc_gl_inventory_cogs_enabled($settings)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM acc_journal_entry
         WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
    );
    $st->execute([$refType, $refId]);
    $journalId = (int) $st->fetchColumn();
    if ($journalId < 1) {
        return false;
    }

    $invId = (int) ($settings['inventory']['account_id'] ?? 0);
    if (acc_gl_journal_has_inventory_cogs_credit($pdo, $journalId, $invId) && !$isReturn) {
        return false;
    }
    if ($isReturn) {
        $st = $pdo->prepare(
            'SELECT 1 FROM acc_journal_line WHERE journal_id = ? AND account_id = ? AND debit > 0.000001 LIMIT 1'
        );
        $st->execute([$journalId, $invId]);
        if ($st->fetch()) {
            return false;
        }
    }

    $entry = acc_journal_load_entry($pdo, $journalId);
    if (!$entry) {
        return false;
    }

    $resolved = [];
    foreach ($entry['lines'] as $ln) {
        $resolved[] = [
            'account_id' => (int) $ln['account_id'],
            'debit' => (float) $ln['debit'],
            'credit' => (float) $ln['credit'],
            'memo' => (string) ($ln['memo'] ?? ''),
        ];
    }

    foreach (acc_gl_cogs_lines($settings, $cost, $isReturn) as $ruleLn) {
        $rule = (string) ($ruleLn['rule'] ?? '');
        $accountId = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($accountId < 1) {
            continue;
        }
        $resolved[] = [
            'account_id' => $accountId,
            'debit' => (float) $ruleLn['debit'],
            'credit' => (float) $ruleLn['credit'],
            'memo' => (string) ($ruleLn['memo'] ?? ''),
        ];
    }

    $normalized = acc_journal_normalize_lines($resolved);
    acc_journal_replace_lines($pdo, $journalId, $normalized['lines']);

    return true;
}

/**
 * إعادة بناء أسطر COGS في قيد بيع/مردود (بعد تحديث تكلفة الشراء).
 */
function acc_gl_journal_refresh_cogs_lines(
    PDO $pdo,
    string $refType,
    int $refId,
    float $cost,
    bool $isReturn
): bool {
    if ($refId < 1 || !in_array($refType, ['sale_invoice', 'sale_return'], true)) {
        return false;
    }

    require_once app_path('includes/acc_gl.php');
    $settings = acc_gl_load_settings($pdo);
    if (!acc_gl_inventory_cogs_enabled($settings)) {
        return false;
    }

    $invId = (int) ($settings['inventory']['account_id'] ?? 0);
    $cogsId = (int) ($settings['cogs']['account_id'] ?? 0);
    if ($invId < 1 || $cogsId < 1) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM acc_journal_entry
         WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
    );
    $st->execute([$refType, $refId]);
    $journalId = (int) $st->fetchColumn();
    if ($journalId < 1) {
        return false;
    }

    $entry = acc_journal_load_entry($pdo, $journalId);
    if (!$entry) {
        return false;
    }

    $resolved = [];
    foreach ($entry['lines'] as $ln) {
        $aid = (int) $ln['account_id'];
        if ($aid === $invId || $aid === $cogsId) {
            continue;
        }
        $resolved[] = [
            'account_id' => $aid,
            'debit' => (float) $ln['debit'],
            'credit' => (float) $ln['credit'],
            'memo' => (string) ($ln['memo'] ?? ''),
        ];
    }

    if ($cost > 0.000001) {
        foreach (acc_gl_cogs_lines($settings, $cost, $isReturn) as $ruleLn) {
            $rule = (string) ($ruleLn['rule'] ?? '');
            $accountId = (int) ($settings[$rule]['account_id'] ?? 0);
            if ($accountId < 1) {
                continue;
            }
            $resolved[] = [
                'account_id' => $accountId,
                'debit' => (float) $ruleLn['debit'],
                'credit' => (float) $ruleLn['credit'],
                'memo' => (string) ($ruleLn['memo'] ?? ''),
            ];
        }
    }

    $normalized = acc_journal_normalize_lines($resolved);
    acc_journal_replace_lines($pdo, $journalId, $normalized['lines']);

    return true;
}
