<?php
declare(strict_types=1);

require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_invoice_gps.php');

/** نصف قطر الزيارة المسموح حول موقع العميل بالمتر */
function sal_rep_visit_radius_m(): float
{
    return 200.0;
}

function sal_rep_route_ensure_schema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM sal_rep_route_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/239_sal_rep_route.sql');
        try {
            $pdo->query('SELECT id FROM sal_rep_route_line LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }

    return $ok;
}

/** @return array<string,mixed>|null */
function sal_rep_route_fetch(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !sal_rep_route_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT r.*, COALESCE(sr.name_ar, \'\') AS sales_rep_name
         FROM sal_rep_route r
         INNER JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
         WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $lines = $pdo->prepare(
        'SELECT l.id, l.customer_id, l.sort_order, c.code AS customer_code, c.name_ar AS customer_name,
                c.latitude, c.longitude
         FROM sal_rep_route_line l
         INNER JOIN crm_customer c ON c.id = l.customer_id
         WHERE l.route_id = ?
         ORDER BY l.sort_order, l.id'
    );
    $lines->execute([$id]);
    $row['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function sal_rep_route_list(PDO $pdo, ?int $salesRepId = null, ?string $from = null, ?string $to = null, int $limit = 100): array
{
    if (!sal_rep_route_ensure_schema($pdo)) {
        return [];
    }
    $sql = 'SELECT r.id, r.sales_rep_id, r.route_date, r.notes, r.is_active,
                   COALESCE(sr.name_ar, \'\') AS sales_rep_name,
                   COALESCE(lc.cnt, 0) AS customer_count
            FROM sal_rep_route r
            INNER JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
            LEFT JOIN (
                SELECT route_id, COUNT(*) AS cnt FROM sal_rep_route_line GROUP BY route_id
            ) lc ON lc.route_id = r.id
            WHERE 1=1';
    $params = [];
    if ($salesRepId !== null && $salesRepId > 0) {
        $sql .= ' AND r.sales_rep_id = ?';
        $params[] = $salesRepId;
    }
    if ($from !== null && $from !== '') {
        $sql .= ' AND r.route_date >= ?';
        $params[] = $from;
    }
    if ($to !== null && $to !== '') {
        $sql .= ' AND r.route_date <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY r.route_date DESC, r.id DESC LIMIT ' . max(1, min(300, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed>|null */
function sal_rep_route_for_rep_date(PDO $pdo, int $salesRepId, string $routeDate): ?array
{
    if ($salesRepId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $routeDate) || !sal_rep_route_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1');
    $st->execute([$salesRepId, $routeDate]);
    $id = (int) $st->fetchColumn();

    return $id > 0 ? sal_rep_route_fetch($pdo, $id) : null;
}

/**
 * @param list<int> $customerIds
 */
function sal_rep_route_save(
    PDO $pdo,
    int $salesRepId,
    string $routeDate,
    array $customerIds,
    ?string $notes,
    ?int $userId,
    ?int $existingId = null
): int {
    if ($salesRepId < 1) {
        throw new RuntimeException('اختر المندوب.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $routeDate)) {
        throw new RuntimeException('تاريخ خط السير غير صالح.');
    }
    if (!sal_rep_route_ensure_schema($pdo)) {
        throw new RuntimeException('تعذر تهيئة جدول خط السير.');
    }
    $repOk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1');
    $repOk->execute([$salesRepId]);
    if (!$repOk->fetchColumn()) {
        throw new RuntimeException('المندوب غير موجود أو غير نشط.');
    }

    $ids = [];
    foreach ($customerIds as $cid) {
        $cid = (int) $cid;
        if ($cid > 0) {
            $ids[$cid] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        throw new RuntimeException('حدّد عميلاً واحداً على الأقل لخط السير.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $chk = $pdo->prepare('SELECT id FROM crm_customer WHERE id IN (' . $placeholders . ') AND is_active = 1');
    $chk->execute($ids);
    $valid = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($valid === []) {
        throw new RuntimeException('لا يوجد عملاء صالحون في القائمة.');
    }

    $pdo->beginTransaction();
    try {
        $id = (int) ($existingId ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare('SELECT id, sales_rep_id FROM sal_rep_route WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new RuntimeException('خط السير غير موجود.');
            }
            $pdo->prepare(
                'UPDATE sal_rep_route SET sales_rep_id=?, route_date=?, notes=?, is_active=1, updated_by=? WHERE id=?'
            )->execute([$salesRepId, $routeDate, $notes !== null && trim($notes) !== '' ? trim($notes) : null, $userId, $id]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1');
            $dup->execute([$salesRepId, $routeDate]);
            $dupId = (int) $dup->fetchColumn();
            if ($dupId > 0) {
                $id = $dupId;
                $pdo->prepare(
                    'UPDATE sal_rep_route SET notes=?, is_active=1, updated_by=? WHERE id=?'
                )->execute([$notes !== null && trim($notes) !== '' ? trim($notes) : null, $userId, $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, is_active, created_by, updated_by)
                     VALUES (?,?,?,1,?,?)'
                )->execute([
                    $salesRepId,
                    $routeDate,
                    $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                    $userId,
                    $userId,
                ]);
                $id = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare('DELETE FROM sal_rep_route_line WHERE route_id = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order) VALUES (?,?,?)'
        );
        $sort = 0;
        foreach ($valid as $cid) {
            $sort++;
            $ins->execute([$id, $cid, $sort]);
        }
        $pdo->commit();

        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sal_rep_route_delete(PDO $pdo, int $id): void
{
    if ($id < 1 || !sal_rep_route_ensure_schema($pdo)) {
        throw new RuntimeException('خط السير غير موجود.');
    }
    $pdo->prepare('DELETE FROM sal_rep_route WHERE id = ?')->execute([$id]);
}

function sal_rep_route_customer_is_assigned(PDO $pdo, int $salesRepId, int $customerId, ?string $routeDate = null): bool
{
    if ($salesRepId < 1 || $customerId < 1 || !sal_rep_route_ensure_schema($pdo)) {
        return false;
    }
    $routeDate = $routeDate ?: date('Y-m-d');
    $st = $pdo->prepare(
        'SELECT 1
         FROM sal_rep_route r
         INNER JOIN sal_rep_route_line l ON l.route_id = r.id
         WHERE r.sales_rep_id = ? AND r.route_date = ? AND r.is_active = 1 AND l.customer_id = ?
         LIMIT 1'
    );
    $st->execute([$salesRepId, $routeDate, $customerId]);

    return (bool) $st->fetchColumn();
}

/**
 * إضافة عميل إلى خط سير اليوم (إنشاء الخط إن لم يوجد) — يُستخدم عند تسجيل عميل جديد بموقع GPS.
 */
function sal_rep_route_add_customer_today(
    PDO $pdo,
    int $salesRepId,
    int $customerId,
    ?int $userId = null
): void {
    if ($salesRepId < 1 || $customerId < 1 || !sal_rep_route_ensure_schema($pdo)) {
        return;
    }
    $routeDate = date('Y-m-d');
    if (sal_rep_route_customer_is_assigned($pdo, $salesRepId, $customerId, $routeDate)) {
        return;
    }

    $st = $pdo->prepare('SELECT id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1');
    $st->execute([$salesRepId, $routeDate]);
    $routeId = (int) $st->fetchColumn();
    if ($routeId < 1) {
        $pdo->prepare(
            'INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, is_active, created_by, updated_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $salesRepId,
            $routeDate,
            'إضافة تلقائية عند تسجيل عميل جديد',
            1,
            $userId,
            $userId,
        ]);
        $routeId = (int) $pdo->lastInsertId();
        $sort = 1;
    } else {
        $pdo->prepare('UPDATE sal_rep_route SET is_active = 1, updated_by = ? WHERE id = ?')
            ->execute([$userId, $routeId]);
        $mx = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM sal_rep_route_line WHERE route_id = ?');
        $mx->execute([$routeId]);
        $sort = (int) $mx->fetchColumn() + 1;
    }

    try {
        $pdo->prepare(
            'INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order) VALUES (?,?,?)'
        )->execute([$routeId, $customerId, $sort]);
    } catch (Throwable $e) {
        // تكرار متزامن — العميل مضاف مسبقاً
        error_log('sal_rep_route_add_customer_today: ' . $e->getMessage());
    }
}

/** هل إعداد النظام يُلزم المندوب بنطاق موقع العميل؟ */
function sal_rep_visit_geofence_setting_enabled(?PDO $pdo = null): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        require_once app_path('includes/mobile_gps_settings.php');
        $s = mobile_gps_settings($pdo);
        $cached = !empty($s['rep_visit_geofence']);
    } catch (Throwable $e) {
        error_log('sal_rep_visit_geofence_setting_enabled: ' . $e->getMessage());
        $cached = false;
    }

    return $cached;
}

/**
 * هل يجب فرض قيد خط السير/الموقع؟
 * يعتمد على إعداد النظام + سياق الموبايل/مجموعة الموبايل.
 */
function sal_rep_visit_geofence_should_enforce(): bool
{
    if (!sal_rep_visit_geofence_setting_enabled()) {
        return false;
    }
    if (function_exists('user_is_system_admin') && user_is_system_admin()) {
        // الأدمن على سطح المكتب مستثنى؛ على الموبايل نفرض أيضاً إن كان السياق موبايل
        if (!function_exists('mobile_is_context') || !mobile_is_context()) {
            return false;
        }
    }
    if (function_exists('mobile_is_context') && mobile_is_context()) {
        return true;
    }
    if (function_exists('user_in_mobile_group') && user_in_mobile_group()) {
        return true;
    }

    return false;
}

/**
 * التحقق من صلاحية زيارة العميل: مدرج بخط السير + ضمن 200م من موقعه.
 *
 * @param array<string,mixed>|null $gpsSource طلب فيه latitude/longitude
 * @throws RuntimeException
 */
function sal_rep_visit_assert_allowed(
    PDO $pdo,
    int $customerId,
    ?array $gpsSource = null,
    ?int $salesRepId = null
): void {
    if (!sal_rep_visit_geofence_should_enforce()) {
        return;
    }
    if ($customerId < 1) {
        throw new RuntimeException('اختر العميل.');
    }

    crm_customer_ensure_gps_columns($pdo);
    sal_rep_route_ensure_schema($pdo);

    if ($salesRepId === null || $salesRepId < 1) {
        $uid = (int) (current_user()['id'] ?? 0);
        $salesRepId = crm_sales_rep_id_for_user($pdo, $uid);
    }
    if ($salesRepId === null || $salesRepId < 1) {
        throw new RuntimeException('حسابك غير مربوط بمندوب مبيعات.');
    }

    if (!sal_rep_route_customer_is_assigned($pdo, $salesRepId, $customerId, date('Y-m-d'))) {
        throw new RuntimeException(
            'هذا العميل غير مدرج في خط سير المندوب لليوم. راجع مدير المبيعات.'
        );
    }

    $st = $pdo->prepare(
        'SELECT name_ar, latitude, longitude FROM crm_customer WHERE id = ? LIMIT 1'
    );
    $st->execute([$customerId]);
    $cust = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        throw new RuntimeException('العميل غير موجود.');
    }
    $cLat = $cust['latitude'] !== null && $cust['latitude'] !== '' ? (float) $cust['latitude'] : null;
    $cLng = $cust['longitude'] !== null && $cust['longitude'] !== '' ? (float) $cust['longitude'] : null;
    if ($cLat === null || $cLng === null || !sal_invoice_gps_coords_valid($cLat, $cLng)) {
        throw new RuntimeException(
            'لا يمكن المتابعة: موقع العميل غير محدد على الخريطة. حدّد موقع العميل أولاً.'
        );
    }

    $gps = sal_invoice_gps_parse_request($gpsSource);
    if ($gps === null) {
        throw new RuntimeException(
            'فعّل GPS وحدد موقعك الحالي قبل عمل طلب شراء أو فاتورة لهذا العميل.'
        );
    }

    $dist = sal_invoice_gps_distance_m(
        (float) $gps['latitude'],
        (float) $gps['longitude'],
        $cLat,
        $cLng
    );
    $radius = sal_rep_visit_radius_m();
    if ($dist > $radius) {
        $shown = (int) round($dist);
        throw new RuntimeException(
            'أنت خارج نطاق موقع العميل (المسافة الحالية حوالي ' . $shown
            . ' متراً). يجب أن تكون ضمن ' . (int) $radius . ' متراً لعمل طلب شراء أو فاتورة.'
        );
    }
}
