<?php
declare(strict_types=1);

/**
 * طلبات تعديل موقع العميل بعد الحفظ الأول.
 */

function crm_customer_gps_change_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->query('SELECT id FROM crm_customer_gps_change LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/277_crm_customer_gps_change.sql');
    }
}

function crm_customer_gps_change_user_can_approve(): bool
{
    return user_can('crm_customer_gps_approve') || user_is_system_admin();
}

function crm_customer_has_saved_gps(PDO $pdo, int $customerId): bool
{
    $st = $pdo->prepare(
        'SELECT latitude, longitude FROM crm_customer WHERE id = ? LIMIT 1'
    );
    $st->execute([$customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    return $row['latitude'] !== null && $row['latitude'] !== ''
        && $row['longitude'] !== null && $row['longitude'] !== '';
}

/**
 * @param array{latitude:?float,longitude:?float,gps_accuracy:?float,clear:bool} $gps
 * @return array{ok:bool,message:string,pending?:bool,request_id?:int}
 */
function crm_customer_gps_submit_change(
    PDO $pdo,
    int $customerId,
    int $userId,
    ?int $salesRepId,
    array $gps
): array {
    crm_customer_gps_change_ensure($pdo);
    $cur = $pdo->prepare(
        'SELECT latitude, longitude FROM crm_customer WHERE id = ? LIMIT 1'
    );
    $cur->execute([$customerId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'العميل غير موجود.'];
    }

    $clear = !empty($gps['clear']);
    $newLat = $clear ? null : $gps['latitude'];
    $newLng = $clear ? null : $gps['longitude'];
    if (!$clear && ($newLat === null || $newLng === null)) {
        return ['ok' => false, 'message' => 'إحداثيات الموقع غير صالحة.'];
    }
    if (!$clear
        && $row['latitude'] !== null && $row['latitude'] !== ''
        && $row['longitude'] !== null && $row['longitude'] !== ''
        && abs((float) $row['latitude'] - (float) $newLat) < 1e-6
        && abs((float) $row['longitude'] - (float) $newLng) < 1e-6) {
        return ['ok' => true, 'pending' => false, 'message' => 'تم حفظ بيانات العميل.'];
    }

    $pending = $pdo->prepare(
        "SELECT id FROM crm_customer_gps_change
         WHERE customer_id = ? AND status = 'pending' LIMIT 1"
    );
    $pending->execute([$customerId]);
    $pendingId = (int) ($pending->fetchColumn() ?: 0);

    $params = [
        $salesRepId,
        $userId,
        $row['latitude'],
        $row['longitude'],
        $newLat,
        $newLng,
        $clear ? null : $gps['gps_accuracy'],
        $clear ? 1 : 0,
        $customerId,
    ];

    if ($pendingId > 0) {
        $pdo->prepare(
            'UPDATE crm_customer_gps_change
             SET sales_rep_id = ?, requested_by = ?,
                 old_latitude = ?, old_longitude = ?,
                 new_latitude = ?, new_longitude = ?, new_accuracy = ?,
                 clear_gps = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([...array_slice($params, 0, 8), $pendingId]);
        $id = $pendingId;
    } else {
        $pdo->prepare(
            'INSERT INTO crm_customer_gps_change
             (sales_rep_id, requested_by, old_latitude, old_longitude,
              new_latitude, new_longitude, new_accuracy, clear_gps, customer_id, status)
             VALUES (?,?,?,?,?,?,?,?,?,\'pending\')'
        )->execute($params);
        $id = (int) $pdo->lastInsertId();
    }

    crm_customer_gps_change_invalidate_header();

    return [
        'ok' => true,
        'pending' => true,
        'request_id' => $id,
        'message' => 'تم إرسال تعديل الموقع بانتظار موافقة مدير المبيعات.',
    ];
}

/** @return list<array<string,mixed>> */
function crm_customer_gps_change_list(PDO $pdo, string $status = 'pending', int $limit = 200): array
{
    crm_customer_gps_change_ensure($pdo);
    $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';
    $limit = max(1, min(300, $limit));
    $where = $status === 'all' ? '1=1' : 'q.status = :st';
    $sql = "SELECT q.*, c.name_ar AS customer_name, c.code AS customer_code,
                   COALESCE(sr.name_ar,'') AS sales_rep_name,
                   COALESCE(u.username,'') AS requested_by_name,
                   COALESCE(d.username,'') AS decided_by_name
            FROM crm_customer_gps_change q
            INNER JOIN crm_customer c ON c.id = q.customer_id
            LEFT JOIN crm_sales_rep sr ON sr.id = q.sales_rep_id
            LEFT JOIN sys_user u ON u.id = q.requested_by
            LEFT JOIN sys_user d ON d.id = q.decided_by
            WHERE {$where}
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    if ($status !== 'all') {
        $st->bindValue(':st', $status);
    }
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function crm_customer_gps_change_pending_count(PDO $pdo): int
{
    crm_customer_gps_change_ensure($pdo);
    try {
        $n = $pdo->query(
            "SELECT COUNT(*) FROM crm_customer_gps_change WHERE status='pending'"
        );
        return (int) ($n ? $n->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<array<string,mixed>> */
function crm_customer_gps_change_pending_alerts(PDO $pdo, int $limit = 20): array
{
    if (!crm_customer_gps_change_user_can_approve()) {
        return [];
    }
    $rows = crm_customer_gps_change_list($pdo, 'pending', $limit);
    $urlBase = app_url('index.php?r=crm_customer_gps_approve&id=');
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'url' => $urlBase . $id,
            'urgency' => 'pending',
            'urgency_label' => 'بانتظار اعتماد الموقع',
            'type_label' => 'تعديل موقع عميل',
        ];
    }
    return $out;
}

/**
 * @return array{ok:bool,message:string}
 */
function crm_customer_gps_change_decide(
    PDO $pdo,
    int $id,
    bool $approve,
    int $userId,
    ?string $note = null
): array {
    crm_customer_gps_change_ensure($pdo);
    if ($id < 1) {
        return ['ok' => false, 'message' => 'طلب غير صالح.'];
    }
    $st = $pdo->prepare('SELECT * FROM crm_customer_gps_change WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'الطلب غير موجود.'];
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'هذا الطلب سبق البت فيه.'];
    }

    $pdo->beginTransaction();
    try {
        if ($approve) {
            crm_customer_ensure_gps_columns($pdo);
            if (!empty($row['clear_gps'])) {
                $pdo->prepare(
                    'UPDATE crm_customer
                     SET latitude=NULL, longitude=NULL, gps_accuracy=NULL, gps_at=NULL
                     WHERE id=?'
                )->execute([(int) $row['customer_id']]);
            } else {
                $pdo->prepare(
                    'UPDATE crm_customer
                     SET latitude=?, longitude=?, gps_accuracy=?, gps_at=NOW()
                     WHERE id=?'
                )->execute([
                    $row['new_latitude'],
                    $row['new_longitude'],
                    $row['new_accuracy'],
                    (int) $row['customer_id'],
                ]);
            }
        }
        $pdo->prepare(
            'UPDATE crm_customer_gps_change
             SET status=?, decided_by=?, decided_at=NOW(), decision_note=?
             WHERE id=?'
        )->execute([
            $approve ? 'approved' : 'rejected',
            $userId,
            $note !== null && trim($note) !== '' ? trim($note) : null,
            $id,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'تعذر حفظ القرار.'];
    }

    crm_customer_gps_change_invalidate_header();

    return [
        'ok' => true,
        'message' => $approve ? 'تم اعتماد موقع العميل.' : 'تم رفض تعديل الموقع.',
    ];
}

function crm_customer_gps_fmt_coord(mixed $lat, mixed $lng): string
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return '—';
    }

    return number_format((float) $lat, 6, '.', '') . ' ، ' . number_format((float) $lng, 6, '.', '');
}

function crm_customer_gps_change_invalidate_header(): void
{
    if (!function_exists('header_check_notifications_invalidate_cache')) {
        require_once app_path('includes/header_check_notifications.php');
    }
    if (function_exists('header_check_notifications_invalidate_cache')) {
        header_check_notifications_invalidate_cache();
    }
}
