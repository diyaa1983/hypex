<?php
declare(strict_types=1);

require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/inv_warehouse_items.php');
require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_wh_move_post.php');
require_once app_path('includes/inv_movement_type_schema.php');

/**
 * سياق عهدة المندوب للمستخدم الحالي.
 *
 * @return ?array{
 *   user_id:int,
 *   rep_id:int,
 *   rep_code:string,
 *   rep_name:string,
 *   van_warehouse_id:int,
 *   van_warehouse_name:string,
 *   van_warehouse_code:string,
 *   main_warehouse_id:int,
 *   main_warehouse_name:string,
 *   main_warehouse_code:string
 * }
 */
function mobile_rep_custody_context(PDO $pdo, ?int $userId = null): ?array
{
    crm_sales_rep_ensure_mobile_custody_schema($pdo);

    if ($userId === null) {
        $userId = (int) (current_user()['id'] ?? 0);
    }
    if ($userId < 1) {
        return null;
    }

    $rep = crm_sales_rep_row_for_user($pdo, $userId);
    if ($rep === null) {
        return null;
    }

    $vanId = (int) ($rep['warehouse_id'] ?? 0);
    if ($vanId < 1) {
        return null;
    }

    $mainId = inv_default_warehouse_id($pdo);
    if ($mainId === null || $mainId < 1) {
        return null;
    }

    $st = $pdo->prepare('SELECT id, code, name_ar FROM inv_warehouse WHERE id IN (?, ?) AND is_active = 1');
    $st->execute([$vanId, $mainId]);
    $whRows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $wh) {
        $whRows[(int) $wh['id']] = $wh;
    }
    if (!isset($whRows[$vanId], $whRows[$mainId])) {
        return null;
    }

    return [
        'user_id' => $userId,
        'rep_id' => (int) $rep['id'],
        'rep_code' => (string) ($rep['code'] ?? ''),
        'rep_name' => (string) ($rep['name_ar'] ?? ''),
        'van_warehouse_id' => $vanId,
        'van_warehouse_name' => (string) ($whRows[$vanId]['name_ar'] ?? ''),
        'van_warehouse_code' => (string) ($whRows[$vanId]['code'] ?? ''),
        'main_warehouse_id' => $mainId,
        'main_warehouse_name' => (string) ($whRows[$mainId]['name_ar'] ?? ''),
        'main_warehouse_code' => (string) ($whRows[$mainId]['code'] ?? ''),
    ];
}

function mobile_rep_custody_source_warehouse_id(array $ctx, string $direction): int
{
    return $direction === 'return'
        ? (int) $ctx['van_warehouse_id']
        : (int) $ctx['main_warehouse_id'];
}

function mobile_rep_custody_dest_warehouse_id(array $ctx, string $direction): int
{
    return $direction === 'return'
        ? (int) $ctx['main_warehouse_id']
        : (int) $ctx['van_warehouse_id'];
}

function mobile_rep_custody_direction_label(string $direction): string
{
    return $direction === 'return' ? 'إرجاع عهدة' : 'تحميل عهدة';
}

function mobile_rep_custody_note(array $ctx, string $direction, string $userNotes = ''): string
{
    $tag = $direction === 'return' ? '[MREP:return]' : '[MREP:load]';
    $base = $tag . ' ' . mobile_rep_custody_direction_label($direction)
        . ' — ' . trim((string) ($ctx['rep_name'] ?? ''));
    $userNotes = trim($userNotes);

    return $userNotes !== '' ? $base . ' | ' . $userNotes : $base;
}

/**
 * @param list<array{item_id?:mixed, qty?:mixed}> $lines
 * @return array{ok:bool, error:?string, move_id:?int, move_no:?string, status:?string}
 */
function mobile_rep_custody_save_transfer(
    PDO $pdo,
    array $ctx,
    string $direction,
    int $moveId,
    string $moveDate,
    array $lines,
    string $userNotes,
    int $userId
): array {
    $out = [
        'ok' => false,
        'error' => null,
        'move_id' => null,
        'move_no' => null,
        'status' => null,
    ];

    if (!in_array($direction, ['load', 'return'], true)) {
        $out['error'] = 'اتجاه الحركة غير صالح.';

        return $out;
    }

    inv_wh_move_ensure_schema($pdo);
    inv_movement_type_ensure_schema($pdo);

    if ($moveId > 0 && !mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
        $out['error'] = 'سند العهدة غير موجود أو غير مسموح تعديله.';

        return $out;
    }

    $fromWh = mobile_rep_custody_source_warehouse_id($ctx, $direction);
    $toWh = mobile_rep_custody_dest_warehouse_id($ctx, $direction);

    $save = inv_wh_move_save(
        $pdo,
        $moveId,
        $moveDate,
        'transfer',
        $fromWh,
        $toWh,
        mobile_rep_custody_note($ctx, $direction, $userNotes),
        $lines,
        $userId
    );

    if (!$save['ok']) {
        $out['error'] = $save['error'] ?? 'تعذر حفظ السند.';

        return $out;
    }

    $savedId = (int) ($save['id'] ?? 0);
    if ($savedId < 1) {
        $out['error'] = 'تعذر حفظ السند.';

        return $out;
    }

    $out['ok'] = true;
    $out['move_id'] = $savedId;
    $out['move_no'] = (string) ($save['move_no'] ?? '');
    $out['status'] = 'draft';

    return $out;
}

/** @return array{ok:bool, error:?string, move_id:?int, move_no:?string, gl_warning:?string} */
function mobile_rep_custody_post_move(PDO $pdo, array $ctx, string $direction, int $moveId, int $userId): array
{
    $out = [
        'ok' => false,
        'error' => null,
        'move_id' => $moveId > 0 ? $moveId : null,
        'move_no' => null,
        'gl_warning' => null,
    ];

    if ($moveId < 1) {
        $out['error'] = 'احفظ السند أولاً.';

        return $out;
    }

    if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
        $out['error'] = 'سند العهدة غير موجود أو غير مسموح ترحيله.';

        return $out;
    }

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        $out['error'] = 'السند غير موجود.';

        return $out;
    }
    if ((string) ($move['status'] ?? '') === 'posted') {
        $out['error'] = 'السند مرحّل مسبقاً.';
        $out['move_no'] = (string) ($move['move_no'] ?? '');

        return $out;
    }

    $post = inv_wh_move_post_document($pdo, $moveId);
    if (!$post['ok']) {
        $out['error'] = $post['error'] ?? 'تعذر الترحيل.';

        return $out;
    }

    $out['ok'] = true;
    $out['move_no'] = (string) ($move['move_no'] ?? '');
    $out['gl_warning'] = trim((string) ($post['gl_warning'] ?? '')) !== ''
        ? (string) $post['gl_warning']
        : null;

    return $out;
}

function mobile_rep_custody_preview_next_move_no(PDO $pdo): string
{
    if (!inv_wh_move_ensure_schema($pdo)) {
        return '1';
    }

    try {
        $st = $pdo->query('SELECT move_no FROM inv_wh_move ORDER BY id DESC LIMIT 800');
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $no) {
            $no = trim((string) $no);
            if ($no !== '' && preg_match('/^(\d+)$/', $no, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return (string) ($max + 1);
    } catch (Throwable $e) {
        return '1';
    }
}

/** عرض رقم السند بدون أصفار بادئة (1 بدل 000001). */
function mobile_rep_custody_format_move_no(string $moveNo): string
{
    $moveNo = trim($moveNo);
    if ($moveNo === '') {
        return '';
    }
    if (preg_match('/^(\d+)$/', $moveNo, $m)) {
        return (string) ((int) $m[1]);
    }

    return $moveNo;
}

function mobile_rep_custody_move_belongs_to_rep(
    PDO $pdo,
    array $ctx,
    string $direction,
    int $moveId,
    int $userId
): bool {
    if ($moveId < 1) {
        return false;
    }

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        return false;
    }

    if ((string) ($move['movement_type_code'] ?? '') !== 'transfer') {
        return false;
    }

    $tag = $direction === 'return' ? '[MREP:return]' : '[MREP:load]';
    $notes = (string) ($move['notes'] ?? '');
    if (!str_contains($notes, $tag)) {
        return false;
    }

    $fromWh = mobile_rep_custody_source_warehouse_id($ctx, $direction);
    $toWh = mobile_rep_custody_dest_warehouse_id($ctx, $direction);
    if ((int) ($move['warehouse_id'] ?? 0) !== $fromWh) {
        return false;
    }
    if ((int) ($move['warehouse_to_id'] ?? 0) !== $toWh) {
        return false;
    }

    $createdBy = (int) ($move['created_by'] ?? 0);

    return $createdBy < 1 || $createdBy === $userId;
}

/**
 * @param list<array{item_id?:mixed, qty?:mixed}> $lines
 * @return array{ok:bool, error:?string, move_id:?int, move_no:?string, gl_warning:?string}
 */
function mobile_rep_custody_post_transfer(
    PDO $pdo,
    array $ctx,
    string $direction,
    string $moveDate,
    array $lines,
    string $userNotes,
    int $userId
): array {
    $save = mobile_rep_custody_save_transfer(
        $pdo,
        $ctx,
        $direction,
        0,
        $moveDate,
        $lines,
        $userNotes,
        $userId
    );
    if (!$save['ok']) {
        return [
            'ok' => false,
            'error' => $save['error'],
            'move_id' => $save['move_id'],
            'move_no' => $save['move_no'],
            'gl_warning' => null,
        ];
    }

    $post = mobile_rep_custody_post_move($pdo, $ctx, $direction, (int) ($save['move_id'] ?? 0), $userId);
    if (!$post['ok']) {
        return [
            'ok' => false,
            'error' => $post['error'],
            'move_id' => $save['move_id'],
            'move_no' => $save['move_no'],
            'gl_warning' => null,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'move_id' => $save['move_id'],
        'move_no' => $post['move_no'] ?? $save['move_no'],
        'gl_warning' => $post['gl_warning'],
    ];
}
