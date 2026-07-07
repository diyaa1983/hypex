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

function mobile_can_archive_warehouse_move(): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (user_can_action('action_archive_warehouse_move')) {
        return true;
    }
    if (!mobile_is_context()) {
        return user_can('warehouse_moves');
    }

    return user_can('m_rep_load') || user_can('m_rep_return') || user_can('warehouse_moves');
}

/** رفع صورة/مرفق إلى أرشيف حركة المستودع من طلب الحفظ على الموبايل. */
function mobile_wh_move_archive_upload_photo_from_request(PDO $pdo, int $moveId, array $file): ?string
{
    if ($moveId < 1) {
        return 'معرّف الحركة غير صالح.';
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        return 'تعذر رفع الملف إلى السيرفر (رمز ' . $err . ').';
    }

    require_once app_path('includes/fin_voucher_archive.php');
    if (!mobile_can_archive_warehouse_move() && !user_can_action('action_archive_warehouse_move')) {
        return 'لا تملك صلاحية رفع مرفقات الأرشيف.';
    }

    try {
        $userId = (int) (current_user()['id'] ?? 0);
        fin_voucher_archive_upload($pdo, 'warehouse_move', $moveId, $file, $userId > 0 ? $userId : 0);

        return null;
    } catch (Throwable $e) {
        return $e->getMessage() !== '' ? $e->getMessage() : 'تعذر حفظ المرفق على السيرفر.';
    }
}

function mobile_can_access_rep_custody_list(): bool
{
    if (!is_logged_in()) {
        return false;
    }

    return user_can('m_rep_custody_list') || user_can('m_rep_load');
}

/**
 * @return list<array<string, mixed>>
 */
function mobile_rep_custody_list_rows(
    PDO $pdo,
    array $ctx,
    string $filter = 'all',
    string $search = '',
    int $limit = 100
): array {
    require_once app_path('includes/helpers.php');

    $fromWh = mobile_rep_custody_source_warehouse_id($ctx, 'load');
    $toWh = mobile_rep_custody_dest_warehouse_id($ctx, 'load');
    $tag = '[MREP:load]';
    $userId = (int) ($ctx['user_id'] ?? 0);
    $limit = max(1, min(200, $limit));
    $filter = in_array($filter, ['all', 'posted', 'unposted'], true) ? $filter : 'all';

    $sql = 'SELECT m.id, m.move_no, m.move_date, m.status, m.posted_at, m.notes,
            (SELECT COUNT(*) FROM inv_wh_move_line l WHERE l.move_id = m.id) AS line_count
            FROM inv_wh_move m
            WHERE m.movement_type_code = ?
              AND m.notes LIKE ?
              AND m.warehouse_id = ?
              AND m.warehouse_to_id = ?';
    $params = ['transfer', '%' . $tag . '%', $fromWh, $toWh];

    if ($filter === 'posted') {
        $sql .= ' AND m.status = ?';
        $params[] = 'posted';
    } elseif ($filter === 'unposted') {
        $sql .= ' AND m.status = ?';
        $params[] = 'draft';
    }

    if ($userId > 0) {
        $sql .= ' AND (m.created_by IS NULL OR m.created_by = 0 OR m.created_by = ?)';
        $params[] = $userId;
    }

    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND m.move_no LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY COALESCE(m.posted_at, m.move_date) DESC, m.id DESC LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $repName = (string) ($ctx['rep_name'] ?? '');
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $dateIso = (string) ($row['move_date'] ?? '');
        $dateDmy = $dateIso !== '' ? format_date_dmY($dateIso) : '';
        $moveNo = mobile_rep_custody_format_move_no((string) ($row['move_no'] ?? ''));
        $lineCount = (int) ($row['line_count'] ?? 0);
        $isPosted = (string) ($row['status'] ?? '') === 'posted';
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'move_no' => $moveNo,
            'move_date' => $dateIso,
            'move_date_dmy' => $dateDmy,
            'line_count' => $lineCount,
            'line_count_fmt' => (string) $lineCount,
            'rep_name' => $repName,
            'direction' => 'load',
            'direction_label' => mobile_rep_custody_direction_label('load'),
            'is_posted' => $isPosted,
            'status_label' => $isPosted ? 'مرحّلة' : 'غير مرحّلة',
        ];
    }

    return $rows;
}

/**
 * @return ?array{id:int, move_no:string, move_date:string, move_date_dmy:string, is_posted:bool, lines:list<array{item_id:int, sku:string, name_ar:string, qty:float}>}
 */
function mobile_rep_custody_fetch_for_edit(
    PDO $pdo,
    array $ctx,
    string $direction,
    int $moveId,
    int $userId
): ?array {
    if ($moveId < 1 || !in_array($direction, ['load', 'return'], true)) {
        return null;
    }
    if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
        return null;
    }

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null || (string) ($move['status'] ?? '') !== 'draft') {
        return null;
    }

    require_once app_path('includes/helpers.php');
    $dateIso = (string) ($move['move_date'] ?? '');
    $lines = [];
    foreach (inv_wh_move_lines($pdo, $moveId) as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $qty = (float) ($ln['qty'] ?? 0);
        if ($itemId < 1 || $qty <= 0) {
            continue;
        }
        $lines[] = [
            'item_id' => $itemId,
            'sku' => (string) ($ln['sku'] ?? ''),
            'name_ar' => (string) ($ln['item_name'] ?? ''),
            'qty' => $qty,
        ];
    }

    return [
        'id' => $moveId,
        'move_no' => mobile_rep_custody_format_move_no((string) ($move['move_no'] ?? '')),
        'move_date' => $dateIso,
        'move_date_dmy' => $dateIso !== '' ? format_date_dmY($dateIso) : '',
        'is_posted' => false,
        'lines' => $lines,
    ];
}
