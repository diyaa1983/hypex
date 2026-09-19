<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_movement_type_schema.php');
require_once app_path('includes/inv_item_inventory_unit_cost.php');
require_once app_path('includes/acc_gl.php');

function inv_wh_move_type_affects_gl_by_code(string $typeCode): bool
{
    return in_array($typeCode, ['adjust_in', 'adjust_out', 'disposal'], true);
}

/** @param array<string, mixed> $typeRow */
function inv_wh_move_type_should_post_gl(PDO $pdo, array $typeRow): bool
{
    inv_movement_type_ensure_affects_gl_column($pdo);
    $code = (string) ($typeRow['code'] ?? '');
    if ($code === '') {
        return false;
    }
    if (array_key_exists('affects_gl', $typeRow)) {
        return (int) ($typeRow['affects_gl'] ?? 0) === 1;
    }

    return inv_wh_move_type_affects_gl_by_code($code);
}

function inv_wh_move_item_tracks_inventory(PDO $pdo, int $itemId): bool
{
    if ($itemId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT track_inventory FROM inv_item WHERE id = ? LIMIT 1');
        $st->execute([$itemId]);

        return (int) $st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return true;
    }
}

function inv_wh_move_inventory_cost_total(PDO $pdo, int $moveId, string $moveDate): float
{
    $sum = 0.0;
    foreach (inv_wh_move_lines($pdo, $moveId) as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $qty = (float) ($ln['qty'] ?? 0);
        if ($itemId < 1 || $qty <= 0 || !inv_wh_move_item_tracks_inventory($pdo, $itemId)) {
            continue;
        }
        $sum += $qty * inv_item_inventory_unit_cost($pdo, $itemId, $moveDate);
    }

    return round(max(0, $sum), 6);
}

/** @param array<string, array{account_id:?int}> $settings */
function inv_wh_move_gl_decrease_offset_rule(array $settings): ?string
{
    if ((int) ($settings['misc_expense']['account_id'] ?? 0) > 0) {
        return 'misc_expense';
    }
    if ((int) ($settings['cogs']['account_id'] ?? 0) > 0) {
        return 'cogs';
    }

    return null;
}

/**
 * @param array<string, array{account_id:?int, label_ar?:string}> $settings
 * @return list<array{rule?:string, debit:float, credit:float, memo?:string}>
 */
function inv_wh_move_gl_journal_lines(array $settings, string $typeCode, float $cost, string $memo): array
{
    if ($cost <= 0.000001) {
        return [];
    }
    if ((int) ($settings['inventory']['account_id'] ?? 0) < 1) {
        return [];
    }

    if ($typeCode === 'adjust_in') {
        if ((int) ($settings['misc_expense']['account_id'] ?? 0) < 1) {
            return [];
        }

        return [
            ['rule' => 'inventory', 'debit' => $cost, 'credit' => 0, 'memo' => $memo],
            ['rule' => 'misc_expense', 'debit' => 0, 'credit' => $cost, 'memo' => $memo],
        ];
    }

    if ($typeCode === 'adjust_out' || $typeCode === 'disposal') {
        $offsetRule = inv_wh_move_gl_decrease_offset_rule($settings);
        if ($offsetRule === null) {
            return [];
        }

        return [
            ['rule' => $offsetRule, 'debit' => $cost, 'credit' => 0, 'memo' => $memo],
            ['rule' => 'inventory', 'debit' => 0, 'credit' => $cost, 'memo' => $memo],
        ];
    }

    return [];
}

/**
 * @return array{ok:bool, skipped:bool, error:?string, warning:?string}
 */
function acc_gl_post_warehouse_move(PDO $pdo, int $moveId): array
{
    $base = ['ok' => true, 'skipped' => true, 'error' => null, 'warning' => null];

    if ($moveId < 1 || !acc_gl_ensure_schema($pdo)) {
        return $base;
    }

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        $base['ok'] = false;
        $base['error'] = 'الحركة غير موجودة.';

        return $base;
    }

    $typeCode = (string) ($move['movement_type_code'] ?? '');
    $typeRow = inv_movement_type_by_code($pdo, $typeCode);
    if ($typeRow === null || !inv_wh_move_type_should_post_gl($pdo, $typeRow)) {
        return $base;
    }

    if (acc_gl_ref_exists($pdo, 'warehouse_move', $moveId)) {
        $base['skipped'] = false;

        return $base;
    }

    $moveDate = (string) ($move['move_date'] ?? '');
    $moveNo = (string) ($move['move_no'] ?? '');
    $typeName = (string) ($typeRow['name_ar'] ?? $typeCode);
    $cost = inv_wh_move_inventory_cost_total($pdo, $moveId, $moveDate);
    if ($cost <= 0.000001) {
        return $base;
    }

    $settings = acc_gl_load_settings($pdo);
    $memo = 'حركة مستودع ' . ($moveNo !== '' ? '#' . $moveNo : '') . ' — ' . $typeName;
    $lines = inv_wh_move_gl_journal_lines($settings, $typeCode, $cost, $memo);
    if ($lines === []) {
        $base['warning'] = 'لم يُرحَّل قيد محاسبي: ربط حساب المخزون و/أو حساب المصروف (misc_expense أو cogs) من «ربط الحسابات».';

        return $base;
    }

    $desc = 'حركة مستودع';
    if ($moveNo !== '') {
        $desc .= ' ' . $moveNo;
    }
    $desc .= ' — ' . $typeName;
    $notes = trim((string) ($move['notes'] ?? ''));
    if ($notes !== '') {
        $desc .= ' — ' . $notes;
    }

    $gl = acc_gl_wrap_post(static function () use ($pdo, $moveId, $moveDate, $desc, $lines): void {
        acc_gl_post_entry($pdo, 'warehouse_move', $moveId, $moveDate, $desc, $lines);
    });

    $base['ok'] = (bool) ($gl['ok'] ?? false);
    $base['skipped'] = (bool) ($gl['skipped'] ?? true);
    $base['error'] = $gl['error'] ?? null;

    return $base;
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_unpost_warehouse_move(PDO $pdo, int $moveId): array
{
    return acc_gl_unpost_ref($pdo, 'warehouse_move', $moveId);
}
