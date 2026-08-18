<?php
declare(strict_types=1);

/**
 * تسجيل دخول/خروج المندوب عند العميل (GPS أو يدوي) + اعتماد الخروج اليدوي.
 */
require_once app_path('includes/sal_rep_route.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_invoice_gps.php');

function sal_rep_visit_ensure_schema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!sal_rep_route_ensure_schema($pdo)) {
        $ok = false;
        return false;
    }
    try {
        $pdo->query('SELECT checkin_lat FROM sal_rep_route_line LIMIT 1');
        $pdo->query('SELECT in_plan FROM sal_rep_route_line LIMIT 1');
        $pdo->query('SELECT id FROM sal_no_order_reason LIMIT 1');
        $pdo->query('SELECT visit_route_line_id FROM sal_customer_order LIMIT 1');
        $pdo->query('SELECT id FROM sal_rep_visit_checkout_request LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/266_report_sales_rep_tours.sql');
        sql_migration_run_file($pdo, 'database/migrations/270_sal_rep_visit_checkin.sql');
        sql_migration_run_file($pdo, 'database/migrations/275_sal_rep_visit_in_plan.sql');
        sql_migration_run_file($pdo, 'database/migrations/276_rep_visit_no_order_reasons.sql');
        try {
            $pdo->query('SELECT checkin_lat FROM sal_rep_route_line LIMIT 1');
            $pdo->query('SELECT in_plan FROM sal_rep_route_line LIMIT 1');
            $pdo->query('SELECT id FROM sal_no_order_reason LIMIT 1');
            $pdo->query('SELECT visit_route_line_id FROM sal_customer_order LIMIT 1');
            $pdo->query('SELECT id FROM sal_rep_visit_checkout_request LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    if ($ok) {
        sal_rep_visit_ensure_mobile_screen($pdo);
    }
    return $ok;
}

/** تسجيل شاشة الموبايل ومنحها لمجموعة هاتف حتى تظهر في الرئيسية. */
function sal_rep_visit_ensure_mobile_screen(PDO $pdo): void
{
    try {
        $pdo->exec(
            "INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
             ('m_rep_route_today', 'هاتف — جولات المندوبين', 'screen', 9044),
             ('m_rep_visits', 'هاتف — جولات المندوبين', 'screen', 9045),
             ('m_rep_visit_report', 'هاتف — تقرير الزيارات', 'screen', 9046)"
        );
        $pdo->exec(
            "UPDATE sys_screen SET name_ar = 'هاتف — جولات المندوبين'
             WHERE code IN ('m_rep_route_today', 'm_rep_visits')"
        );
        $pdo->exec(
            "INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
             SELECT g.id, s.id, 1
             FROM sys_group g
             CROSS JOIN sys_screen s
             WHERE g.code IN ('MOBILE', 'ADMINS')
               AND s.code IN ('m_rep_route_today', 'm_rep_visits', 'm_rep_visit_report')"
        );
    } catch (Throwable $e) {
        error_log('sal_rep_visit_ensure_mobile_screen: ' . $e->getMessage());
    }
}

/** @return array{lat:?float,lng:?float,accuracy:?float} */
function sal_rep_visit_parse_gps(?array $source = null): array
{
    $src = $source ?? array_merge($_GET, $_POST);
    $lat = isset($src['latitude']) ? (float) $src['latitude'] : (isset($src['lat']) ? (float) $src['lat'] : null);
    $lng = isset($src['longitude']) ? (float) $src['longitude'] : (isset($src['lng']) ? (float) $src['lng'] : null);
    $acc = isset($src['accuracy']) ? (float) $src['accuracy'] : (isset($src['gps_accuracy']) ? (float) $src['gps_accuracy'] : null);
    if ($lat === null || $lng === null || !sal_invoice_gps_coords_valid($lat, $lng)) {
        return ['lat' => null, 'lng' => null, 'accuracy' => null];
    }
    return [
        'lat' => $lat,
        'lng' => $lng,
        'accuracy' => $acc !== null && $acc > 0 ? $acc : null,
    ];
}

function sal_rep_visit_distance_to_customer(PDO $pdo, int $customerId, ?float $lat, ?float $lng): ?float
{
    if ($customerId < 1 || $lat === null || $lng === null || !sal_invoice_gps_coords_valid($lat, $lng)) {
        return null;
    }
    $st = $pdo->prepare('SELECT latitude, longitude FROM crm_customer WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $clat = $row['latitude'] !== null && $row['latitude'] !== '' ? (float) $row['latitude'] : null;
    $clng = $row['longitude'] !== null && $row['longitude'] !== '' ? (float) $row['longitude'] : null;
    if ($clat === null || $clng === null || !sal_invoice_gps_coords_valid($clat, $clng)) {
        return null;
    }
    return sal_invoice_gps_distance_m($lat, $lng, $clat, $clng);
}

function sal_rep_visit_within_geofence(?float $distanceM, ?PDO $pdo = null): bool
{
    if ($distanceM === null) {
        return false;
    }
    return $distanceM <= sal_rep_visit_radius_m($pdo);
}

/** @return array{ok:bool,message?:string,route_line_id?:int,route_id?:int} */
function sal_rep_visit_ensure_route_line(PDO $pdo, int $salesRepId, int $customerId, ?int $userId = null): array
{
    if ($salesRepId < 1 || $customerId < 1) {
        return ['ok' => false, 'message' => 'بيانات غير صالحة.'];
    }
    sal_rep_visit_ensure_schema($pdo);
    $today = date('Y-m-d');
    if (!sal_rep_route_customer_is_assigned($pdo, $salesRepId, $customerId, $today)) {
        sal_rep_route_add_customer_today($pdo, $salesRepId, $customerId, $userId);
    }
    $st = $pdo->prepare(
        'SELECT l.id, l.route_id
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         WHERE r.sales_rep_id = ? AND r.route_date = ? AND r.is_active = 1 AND l.customer_id = ?
         ORDER BY l.id DESC LIMIT 1'
    );
    $st->execute([$salesRepId, $today, $customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'تعذّر إنشاء بند خط السير لهذا العميل.'];
    }
    return [
        'ok' => true,
        'route_line_id' => (int) $row['id'],
        'route_id' => (int) $row['route_id'],
    ];
}

/** @return array<string,mixed>|null */
function sal_rep_visit_line_fetch(PDO $pdo, int $routeLineId): ?array
{
    if ($routeLineId < 1 || !sal_rep_visit_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT l.*, r.sales_rep_id, r.route_date, r.is_active AS route_active,
                c.code AS customer_code, c.name_ar AS customer_name,
                c.latitude AS customer_lat, c.longitude AS customer_lng,
                COALESCE(sr.name_ar, \'\') AS sales_rep_name
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         LEFT JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
         WHERE l.id = ? LIMIT 1'
    );
    $st->execute([$routeLineId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function sal_rep_visit_list_for_rep(PDO $pdo, int $salesRepId, ?string $date = null): array
{
    sal_rep_visit_ensure_schema($pdo);
    $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    $data = sal_rep_route_customers_for_date($pdo, $salesRepId, $date);
    $routeId = (int) ($data['route']['id'] ?? 0);
    $byCust = [];
    if ($routeId > 0) {
        $st = $pdo->prepare(
            'SELECT l.id AS route_line_id, l.customer_id, l.visit_checkin_at, l.visit_checkout_at,
                    l.checkin_method, l.checkout_method, l.checkin_distance_m, l.checkout_distance_m,
                    EXISTS(
                      SELECT 1 FROM sal_customer_order o
                      WHERE o.visit_route_line_id = l.id
                        AND l.visit_checkin_at IS NOT NULL
                        AND o.created_at >= l.visit_checkin_at
                    ) AS has_order
             FROM sal_rep_route_line l WHERE l.route_id = ?'
        );
        $st->execute([$routeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
            $byCust[(int) $ln['customer_id']] = $ln;
        }
    }

    // عملاء خطة الجولة من مدير المبيعات (قبل إضافة أي زيارات اختيارية)
    $plannedIds = [];
    $seen = [];
    foreach ($data['customers'] as $c) {
        $cid = (int) ($c['id'] ?? 0);
        if ($cid > 0) {
            $plannedIds[$cid] = true;
            $seen[$cid] = true;
        }
    }

    // أضف العملاء المربوطين بالمندوب حتى لو لم يكونوا في الجولة (زيارة اختيارية)
    try {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $salesRepId);
        $st = $pdo->prepare(
            "SELECT c.id, c.code, c.name_ar AS name, c.latitude, c.longitude
             FROM crm_customer c
             WHERE c.is_active = 1 AND {$linkSql}
             ORDER BY c.name_ar LIMIT 2000"
        );
        $st->execute($linkParams);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) ($row['id'] ?? 0);
            if ($cid < 1 || isset($seen[$cid])) {
                continue;
            }
            $lat = $row['latitude'] ?? null;
            $lng = $row['longitude'] ?? null;
            $hasGps = $lat !== null && $lat !== '' && $lng !== null && $lng !== ''
                && sal_invoice_gps_coords_valid((float) $lat, (float) $lng);
            $data['customers'][] = [
                'id' => $cid,
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'sort_order' => 9999,
                'has_gps' => $hasGps,
                'latitude' => $hasGps ? (float) $lat : null,
                'longitude' => $hasGps ? (float) $lng : null,
            ];
            $seen[$cid] = true;
        }
    } catch (Throwable $e) {
    }

    $pendingByLine = [];
    try {
        $st = $pdo->prepare(
            "SELECT id, route_line_id FROM sal_rep_visit_checkout_request
             WHERE sales_rep_id = ? AND status = 'pending'"
        );
        $st->execute([$salesRepId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $req) {
            $pendingByLine[(int) $req['route_line_id']] = $req;
        }
    } catch (Throwable $e) {
    }
    $radius = (int) sal_rep_visit_radius_m($pdo);
    $wd = (int) date('w', strtotime($date)); // 0=أحد … 6=سبت
    $weekdayLabels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    $weekdayLabel = $weekdayLabels[$wd] ?? '';
    $out = [];
    foreach ($data['customers'] as $c) {
        $cid = (int) ($c['id'] ?? 0);
        $ln = $byCust[$cid] ?? null;
        $lineId = (int) ($ln['route_line_id'] ?? 0);
        $pending = $lineId > 0 ? ($pendingByLine[$lineId] ?? null) : null;
        $checkinAt = (string) ($ln['visit_checkin_at'] ?? '');
        $checkoutAt = (string) ($ln['visit_checkout_at'] ?? '');
        $status = 'idle';
        if ($checkinAt !== '' && $checkoutAt === '') {
            $status = $pending ? 'pending_manual_checkout' : 'checked_in';
        } elseif ($checkinAt !== '' && $checkoutAt !== '') {
            $coDay = date('Y-m-d', strtotime($checkoutAt));
            $status = ($coDay === $date) ? 'checked_out' : 'idle';
        }
        $out[] = [
            'customer_id' => $cid,
            'code' => (string) ($c['code'] ?? ''),
            'name' => (string) ($c['name'] ?? ''),
            'has_gps' => !empty($c['has_gps']),
            'latitude' => $c['latitude'] ?? null,
            'longitude' => $c['longitude'] ?? null,
            'route_line_id' => $lineId > 0 ? $lineId : null,
            'status' => $status,
            'visit_checkin_at' => $checkinAt !== '' ? $checkinAt : null,
            'visit_checkout_at' => $checkoutAt !== '' ? $checkoutAt : null,
            'checkin_method' => $ln['checkin_method'] ?? null,
            'checkout_method' => $ln['checkout_method'] ?? null,
            'pending_request_id' => $pending ? (int) $pending['id'] : null,
            'visit_radius_m' => $radius,
            'in_plan' => !empty($plannedIds[$cid]),
            'has_order' => !empty($ln['has_order']),
            'sort_order' => (int) ($c['sort_order'] ?? 0),
            'weekday' => $wd,
            'weekday_label' => $weekdayLabel,
            'route_date' => $date,
        ];
    }
    return $out;
}

/** @param array<string,mixed>|null $row */
function sal_rep_visit_public_row(?array $row): ?array
{
    if (!$row) {
        return null;
    }
    return [
        'route_line_id' => (int) ($row['id'] ?? 0),
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_code' => (string) ($row['customer_code'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'sales_rep_id' => (int) ($row['sales_rep_id'] ?? 0),
        'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
        'route_date' => (string) ($row['route_date'] ?? ''),
        'visit_checkin_at' => $row['visit_checkin_at'] ?? null,
        'visit_checkout_at' => $row['visit_checkout_at'] ?? null,
        'checkin_method' => $row['checkin_method'] ?? null,
        'checkout_method' => $row['checkout_method'] ?? null,
        'checkin_distance_m' => $row['checkin_distance_m'] ?? null,
        'checkout_distance_m' => $row['checkout_distance_m'] ?? null,
    ];
}

function sal_rep_visit_sync_tour_line(PDO $pdo, int $salesRepId, int $customerId, string $routeDate): void
{
    try {
        $pdo->query('SELECT visit_checkin_at FROM sal_rep_tour_line LIMIT 1');
    } catch (Throwable $e) {
        return;
    }
    try {
        // weekday في الجولة = date('w') مثل JS getDay(): 0=أحد … 6=سبت (وليس date('N'))
        $dow = (int) date('w', strtotime($routeDate));
        $st = $pdo->prepare(
            'SELECT tl.id FROM sal_rep_tour_line tl
             INNER JOIN sal_rep_tour t ON t.id = tl.tour_id
             WHERE t.sales_rep_id = ? AND t.status = \'posted\'
               AND t.date_from <= ? AND t.date_to >= ?
               AND tl.weekday = ?
               AND tl.customer_id = ?
             ORDER BY t.id DESC, tl.id DESC LIMIT 1'
        );
        $st->execute([$salesRepId, $routeDate, $routeDate, $dow, $customerId]);
        $tid = (int) $st->fetchColumn();
        if ($tid < 1) {
            return;
        }
        $rl = $pdo->prepare(
            'SELECT visit_checkin_at, visit_checkout_at, checkin_method, checkout_method
             FROM sal_rep_route_line l
             INNER JOIN sal_rep_route r ON r.id = l.route_id
             WHERE r.sales_rep_id = ? AND r.route_date = ? AND l.customer_id = ?
             ORDER BY l.id DESC LIMIT 1'
        );
        $rl->execute([$salesRepId, $routeDate, $customerId]);
        $line = $rl->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            return;
        }
        $pdo->prepare(
            'UPDATE sal_rep_tour_line SET visit_checkin_at=?, visit_checkout_at=?, checkin_method=?, checkout_method=? WHERE id=?'
        )->execute([
            $line['visit_checkin_at'], $line['visit_checkout_at'],
            $line['checkin_method'], $line['checkout_method'], $tid,
        ]);
    } catch (Throwable $e) {
        error_log('sal_rep_visit_sync_tour_line: ' . $e->getMessage());
    }
}

/**
 * @param array{lat:?float,lng:?float,accuracy:?float} $gps
 * @return array{ok:bool,message:string,visit?:array<string,mixed>}
 */
function sal_rep_visit_apply_checkout(
    PDO $pdo,
    int $lineId,
    string $method,
    array $gps,
    ?float $distance,
    int $salesRepId,
    int $customerId,
    string $routeDate
): array {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE sal_rep_route_line SET
            visit_checkout_at=?, checkout_method=?,
            checkout_lat=?, checkout_lng=?, checkout_accuracy=?, checkout_distance_m=?
         WHERE id=? AND visit_checkout_at IS NULL'
    )->execute([
        $now, $method, $gps['lat'], $gps['lng'], $gps['accuracy'],
        $distance !== null ? round($distance, 2) : null, $lineId,
    ]);
    sal_rep_visit_sync_tour_line($pdo, $salesRepId, $customerId, $routeDate);
    return [
        'ok' => true,
        'message' => $method === 'GPS' ? 'تم تسجيل الخروج (GPS).' : 'تم تسجيل الخروج يدوياً.',
        'visit' => sal_rep_visit_public_row(sal_rep_visit_line_fetch($pdo, $lineId)),
    ];
}

/** @return array<string,mixed>|null */
function sal_rep_visit_pending_request_for_line(PDO $pdo, int $lineId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM sal_rep_visit_checkout_request
         WHERE route_line_id=? AND status='pending' ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$lineId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return list<array{id:int,name_ar:string}> */
function sal_rep_visit_no_order_reasons(PDO $pdo): array
{
    sal_rep_visit_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, name_ar FROM sal_no_order_reason
             WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name_ar' => (string) $r['name_ar'],
        ], $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function sal_rep_visit_has_order(PDO $pdo, int $routeLineId): bool
{
    return sal_rep_visit_order_id($pdo, $routeLineId) > 0;
}

function sal_rep_visit_order_id(PDO $pdo, int $routeLineId): int
{
    if ($routeLineId < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT o.id
             FROM sal_customer_order o
             INNER JOIN sal_rep_route_line l ON l.id = o.visit_route_line_id
             WHERE o.visit_route_line_id = ?
               AND l.visit_checkin_at IS NOT NULL
               AND o.created_at >= l.visit_checkin_at
             ORDER BY o.id DESC
             LIMIT 1'
        );
        $st->execute([$routeLineId]);
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** @param list<int> $reasonIds */
function sal_rep_visit_save_no_order_reasons(PDO $pdo, int $routeLineId, array $reasonIds, ?int $userId): bool
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $reasonIds),
        static fn(int $v): bool => $v > 0
    )));
    if ($routeLineId < 1 || $ids === []) {
        return false;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT id FROM sal_no_order_reason
         WHERE is_active = 1 AND id IN ({$marks})"
    );
    $st->execute($ids);
    $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($valid === []) {
        return false;
    }
    $pdo->prepare('DELETE FROM sal_rep_visit_no_order_reason WHERE route_line_id = ?')
        ->execute([$routeLineId]);
    $ins = $pdo->prepare(
        'INSERT INTO sal_rep_visit_no_order_reason
         (route_line_id, reason_id, created_by) VALUES (?,?,?)'
    );
    foreach ($valid as $reasonId) {
        $ins->execute([$routeLineId, $reasonId, $userId]);
    }
    return true;
}

/** @param array{lat:?float,lng:?float,accuracy:?float} $gps */
function sal_rep_visit_create_checkout_request(
    PDO $pdo,
    int $lineId,
    int $salesRepId,
    int $customerId,
    int $userId,
    ?string $reason,
    array $gps,
    ?float $distance
): int {
    sal_rep_visit_ensure_schema($pdo);
    $pdo->prepare(
        'INSERT INTO sal_rep_visit_checkout_request
            (route_line_id, sales_rep_id, customer_id, requested_by, reason,
             request_lat, request_lng, request_accuracy, request_distance_m, status)
         VALUES (?,?,?,?,?,?,?,?,?,\'pending\')'
    )->execute([
        $lineId, $salesRepId, $customerId, max(1, $userId),
        $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : 'نسي الخروج بـ GPS من موقع العميل',
        $gps['lat'], $gps['lng'], $gps['accuracy'],
        $distance !== null ? round($distance, 2) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * @param array{lat:?float,lng:?float,accuracy:?float} $gps
 * @return array{ok:bool,message:string,visit?:array<string,mixed>,requires_approval?:bool,request_id?:int,distance_m?:float,visit_radius_m?:int,suggest_manual?:bool}
 */
function sal_rep_visit_checkin(
    PDO $pdo,
    int $salesRepId,
    int $customerId,
    string $method,
    array $gps,
    ?int $userId = null
): array {
    $method = strtoupper(trim($method)) === 'MANUAL' ? 'MANUAL' : 'GPS';
    if (!crm_customer_is_linked_to_sales_rep($pdo, $customerId, $salesRepId)) {
        return ['ok' => false, 'message' => 'هذا العميل غير مربوط بمندوبك.'];
    }
    $ens = sal_rep_visit_ensure_route_line($pdo, $salesRepId, $customerId, $userId);
    if (!$ens['ok']) {
        return ['ok' => false, 'message' => (string) ($ens['message'] ?? 'تعذّر التحضير.')];
    }
    $lineId = (int) $ens['route_line_id'];
    $line = sal_rep_visit_line_fetch($pdo, $lineId);
    if (!$line) {
        return ['ok' => false, 'message' => 'بند الزيارة غير موجود.'];
    }
    if (!empty($line['visit_checkin_at']) && empty($line['visit_checkout_at'])) {
        return ['ok' => false, 'message' => 'يوجد دخول مفتوح لهذا العميل. أكمل الخروج أولاً.'];
    }

    // لا يُسمح بالدخول لعميل آخر قبل الخروج من الزيارة المفتوحة.
    try {
        $stOpen = $pdo->prepare(
            "SELECT l.id, l.customer_id, c.name_ar
             FROM sal_rep_route_line l
             INNER JOIN sal_rep_route r ON r.id = l.route_id
             INNER JOIN crm_customer c ON c.id = l.customer_id
             WHERE r.sales_rep_id = ?
               AND l.visit_checkin_at IS NOT NULL
               AND l.visit_checkout_at IS NULL
               AND l.customer_id <> ?
             ORDER BY l.visit_checkin_at DESC
             LIMIT 1"
        );
        $stOpen->execute([$salesRepId, $customerId]);
        $openOther = $stOpen->fetch(PDO::FETCH_ASSOC);
        if ($openOther) {
            return [
                'ok' => false,
                'message' => 'سجّل الخروج من العميل «' . (string) ($openOther['name_ar'] ?? '') . '» أولاً قبل الدخول لعميل آخر.',
                'open_customer_id' => (int) ($openOther['customer_id'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        // لا نمنع الدخول إن فشل الاستعلام
    }

    $distance = null;
    if ($method === 'GPS') {
        if ($gps['lat'] === null || $gps['lng'] === null) {
            return ['ok' => false, 'message' => 'تعذّر قراءة موقع GPS. جرّب يدوياً أو فعّل الموقع.'];
        }
        $distance = sal_rep_visit_distance_to_customer($pdo, $customerId, $gps['lat'], $gps['lng']);
        if ($distance === null) {
            return ['ok' => false, 'message' => 'لا يوجد موقع GPS محفوظ للعميل. سجّل الدخول يدوياً أو حدّث موقع العميل.'];
        }
        if (!sal_rep_visit_within_geofence($distance, $pdo)) {
            $radius = (int) sal_rep_visit_radius_m($pdo);
            return [
                'ok' => false,
                'message' => 'أنت خارج حدود منطقة العميل (' . round($distance) . ' م / المسموح ' . $radius . ' م). استخدم دخولاً يدوياً أو اقترب من الموقع.',
                'distance_m' => round($distance, 1),
                'visit_radius_m' => $radius,
            ];
        }
    } elseif ($gps['lat'] !== null && $gps['lng'] !== null) {
        $distance = sal_rep_visit_distance_to_customer($pdo, $customerId, $gps['lat'], $gps['lng']);
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE sal_rep_route_line SET
            visit_checkin_at=?, visit_checkout_at=NULL,
            checkin_method=?, checkout_method=NULL,
            checkin_lat=?, checkin_lng=?, checkin_accuracy=?, checkin_distance_m=?,
            checkout_lat=NULL, checkout_lng=NULL, checkout_accuracy=NULL, checkout_distance_m=NULL
         WHERE id=?'
    )->execute([
        $now, $method, $gps['lat'], $gps['lng'], $gps['accuracy'],
        $distance !== null ? round($distance, 2) : null, $lineId,
    ]);
    try {
        $pdo->prepare(
            "UPDATE sal_rep_visit_checkout_request
             SET status='rejected', decided_at=?, decision_note='أُلغي بدخول جديد'
             WHERE route_line_id=? AND status='pending'"
        )->execute([$now, $lineId]);
    } catch (Throwable $e) {
    }
    sal_rep_visit_sync_tour_line($pdo, $salesRepId, $customerId, (string) ($line['route_date'] ?? date('Y-m-d')));
    return [
        'ok' => true,
        'message' => $method === 'GPS' ? 'تم تسجيل الدخول (GPS).' : 'تم تسجيل الدخول يدوياً.',
        'visit' => sal_rep_visit_public_row(sal_rep_visit_line_fetch($pdo, $lineId)),
    ];
}

/**
 * @param array{lat:?float,lng:?float,accuracy:?float} $gps
 * @return array{ok:bool,message:string,visit?:array<string,mixed>,requires_approval?:bool,request_id?:int,distance_m?:float,visit_radius_m?:int,suggest_manual?:bool}
 */
function sal_rep_visit_checkout(
    PDO $pdo,
    int $salesRepId,
    int $customerId,
    string $method,
    array $gps,
    ?string $reason = null,
    array $noOrderReasonIds = [],
    ?int $userId = null
): array {
    $method = strtoupper(trim($method)) === 'MANUAL' ? 'MANUAL' : 'GPS';
    $ens = sal_rep_visit_ensure_route_line($pdo, $salesRepId, $customerId, $userId);
    if (!$ens['ok']) {
        return ['ok' => false, 'message' => (string) ($ens['message'] ?? 'تعذّر التحضير.')];
    }
    $lineId = (int) $ens['route_line_id'];
    $line = sal_rep_visit_line_fetch($pdo, $lineId);
    if (!$line || empty($line['visit_checkin_at'])) {
        return ['ok' => false, 'message' => 'لا يوجد دخول مفتوح لهذا العميل.'];
    }
    if (!empty($line['visit_checkout_at'])) {
        return ['ok' => false, 'message' => 'تم الخروج مسبقاً من هذه الزيارة.'];
    }
    $hasOrder = sal_rep_visit_has_order($pdo, $lineId);
    if (!$hasOrder && !sal_rep_visit_save_no_order_reasons($pdo, $lineId, $noOrderReasonIds, $userId)) {
        return [
            'ok' => false,
            'message' => 'لم تُسجّل طلبية لهذا العميل. اختر سبباً واحداً على الأقل لعدم الطلب.',
            'requires_no_order_reason' => true,
            'no_order_reasons' => sal_rep_visit_no_order_reasons($pdo),
        ];
    }
    $checkinMethod = strtoupper((string) ($line['checkin_method'] ?? ''));
    $distance = null;
    if ($method === 'GPS') {
        if ($gps['lat'] === null || $gps['lng'] === null) {
            return ['ok' => false, 'message' => 'تعذّر قراءة موقع GPS للخروج.'];
        }
        $distance = sal_rep_visit_distance_to_customer($pdo, $customerId, $gps['lat'], $gps['lng']);
        if ($distance === null) {
            return ['ok' => false, 'message' => 'لا يوجد موقع GPS للعميل. استخدم خروجاً يدوياً.'];
        }
        if (!sal_rep_visit_within_geofence($distance, $pdo)) {
            $radius = (int) sal_rep_visit_radius_m($pdo);
            return [
                'ok' => false,
                'message' => 'أنت خارج حدود المنطقة للخروج بـ GPS (' . round($distance) . ' م / ' . $radius . ' م). استخدم خروجاً يدوياً.',
                'distance_m' => round($distance, 1),
                'visit_radius_m' => $radius,
                'suggest_manual' => true,
            ];
        }
    } else {
        if ($gps['lat'] !== null && $gps['lng'] !== null) {
            $distance = sal_rep_visit_distance_to_customer($pdo, $customerId, $gps['lat'], $gps['lng']);
        }
        if ($checkinMethod === 'GPS') {
            $existing = sal_rep_visit_pending_request_for_line($pdo, $lineId);
            if ($existing) {
                return [
                    'ok' => true,
                    'message' => 'يوجد طلب خروج يدوي بانتظار موافقة المدير.',
                    'requires_approval' => true,
                    'request_id' => (int) $existing['id'],
                ];
            }
            $reqId = sal_rep_visit_create_checkout_request(
                $pdo, $lineId, $salesRepId, $customerId, (int) ($userId ?? 0), $reason, $gps, $distance
            );
            if (function_exists('header_check_notifications_invalidate_cache')) {
                header_check_notifications_invalidate_cache();
            }
            return [
                'ok' => true,
                'message' => 'طلب الخروج اليدوي أُرسل للمسؤول. لن يُغلق الدخول إلا بعد الموافقة.',
                'requires_approval' => true,
                'request_id' => $reqId,
            ];
        }
    }
    return sal_rep_visit_apply_checkout(
        $pdo, $lineId, $method, $gps, $distance, $salesRepId, $customerId,
        (string) ($line['route_date'] ?? date('Y-m-d'))
    );
}

/** @return array{ok:bool,message:string} */
function sal_rep_visit_decide_checkout_request(
    PDO $pdo,
    int $requestId,
    bool $approve,
    int $deciderUserId,
    ?string $note = null
): array {
    sal_rep_visit_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM sal_rep_visit_checkout_request WHERE id=? LIMIT 1');
    $st->execute([$requestId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return ['ok' => false, 'message' => 'الطلب غير موجود.'];
    }
    if ((string) ($req['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'تمت معالجة هذا الطلب مسبقاً.'];
    }
    $now = date('Y-m-d H:i:s');
    if (!$approve) {
        $pdo->prepare(
            "UPDATE sal_rep_visit_checkout_request
             SET status='rejected', decided_by=?, decided_at=?, decision_note=? WHERE id=?"
        )->execute([$deciderUserId, $now, $note, $requestId]);
        if (function_exists('header_check_notifications_invalidate_cache')) {
            header_check_notifications_invalidate_cache();
        }
        return ['ok' => true, 'message' => 'تم رفض طلب الخروج اليدوي.'];
    }
    $lineId = (int) $req['route_line_id'];
    $line = sal_rep_visit_line_fetch($pdo, $lineId);
    if (!$line || empty($line['visit_checkin_at'])) {
        $pdo->prepare(
            "UPDATE sal_rep_visit_checkout_request
             SET status='rejected', decided_by=?, decided_at=?, decision_note=? WHERE id=?"
        )->execute([$deciderUserId, $now, 'لا يوجد دخول مفتوح', $requestId]);
        return ['ok' => false, 'message' => 'لا يوجد دخول مفتوح مرتبط بالطلب.'];
    }
    if (empty($line['visit_checkout_at'])) {
        $gps = [
            'lat' => $req['request_lat'] !== null ? (float) $req['request_lat'] : null,
            'lng' => $req['request_lng'] !== null ? (float) $req['request_lng'] : null,
            'accuracy' => $req['request_accuracy'] !== null ? (float) $req['request_accuracy'] : null,
        ];
        $distance = $req['request_distance_m'] !== null ? (float) $req['request_distance_m'] : null;
        sal_rep_visit_apply_checkout(
            $pdo, $lineId, 'MANUAL', $gps, $distance,
            (int) $req['sales_rep_id'], (int) $req['customer_id'],
            (string) ($line['route_date'] ?? date('Y-m-d'))
        );
    }
    $pdo->prepare(
        "UPDATE sal_rep_visit_checkout_request
         SET status='approved', decided_by=?, decided_at=?, decision_note=? WHERE id=?"
    )->execute([$deciderUserId, $now, $note, $requestId]);
    if (function_exists('header_check_notifications_invalidate_cache')) {
        header_check_notifications_invalidate_cache();
    }
    return ['ok' => true, 'message' => 'تمت الموافقة وتسجيل الخروج اليدوي.'];
}

function sal_rep_visit_checkout_notifications_user_can_see(): bool
{
    return user_can('sales_rep_visit_checkout_approve') || user_is_system_admin();
}

function sal_rep_visit_pending_checkout_count(PDO $pdo): int
{
    if (!sal_rep_visit_checkout_notifications_user_can_see() || !sal_rep_visit_ensure_schema($pdo)) {
        return 0;
    }
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM sal_rep_visit_checkout_request WHERE status='pending'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<array<string,mixed>> */
function sal_rep_visit_pending_checkout_alerts(PDO $pdo, int $limit = 20): array
{
    if (!sal_rep_visit_checkout_notifications_user_can_see() || !sal_rep_visit_ensure_schema($pdo)) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->query(
            "SELECT q.id, q.created_at, q.reason,
                    c.name_ar AS customer_name, c.code AS customer_code,
                    COALESCE(sr.name_ar,'') AS sales_rep_name
             FROM sal_rep_visit_checkout_request q
             INNER JOIN crm_customer c ON c.id=q.customer_id
             LEFT JOIN crm_sales_rep sr ON sr.id=q.sales_rep_id
             WHERE q.status='pending'
             ORDER BY q.created_at ASC, q.id ASC
             LIMIT {$limit}"
        );
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
    $urlBase = app_url('index.php?r=sales_rep_visit_checkout_approve&id=');
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
            'reason' => (string) ($row['reason'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'url' => $urlBase . $id,
            'urgency' => 'pending',
            'urgency_label' => 'بانتظار اعتماد الخروج',
            'type_label' => 'خروج يدوي من زيارة',
        ];
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function sal_rep_visit_pending_checkout_list(PDO $pdo, string $status = 'pending', int $limit = 100): array
{
    sal_rep_visit_ensure_schema($pdo);
    $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';
    $limit = max(1, min(300, $limit));
    $sql = "SELECT q.*, c.name_ar AS customer_name, c.code AS customer_code,
                   COALESCE(sr.name_ar,'') AS sales_rep_name,
                   COALESCE(u.username,'') AS requested_by_name,
                   COALESCE(du.username,'') AS decided_by_name
            FROM sal_rep_visit_checkout_request q
            INNER JOIN crm_customer c ON c.id=q.customer_id
            LEFT JOIN crm_sales_rep sr ON sr.id=q.sales_rep_id
            LEFT JOIN sys_user u ON u.id=q.requested_by
            LEFT JOIN sys_user du ON du.id=q.decided_by";
    $params = [];
    if ($status !== 'all') {
        $sql .= ' WHERE q.status=?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY q.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sal_rep_visit_method_label(?string $method): string
{
    $m = strtoupper(trim((string) $method));
    if ($m === '') {
        return '—';
    }
    if ($m === 'GPS') {
        return 'GPS';
    }
    if ($m === 'MANUAL') {
        return 'Manual';
    }

    return (string) $method;
}

/**
 * أجندة جولة المندوب لشهر كامل: كل يوم + تاريخ + عملاء الخطة المرحّلة.
 *
 * @return array{ok:bool,month:string,date_from:string,date_to:string,days:list<array<string,mixed>>,items:list<array<string,mixed>>,count:int}
 */
function sal_rep_visit_month_agenda_for_rep(PDO $pdo, int $salesRepId, string $monthYm = ''): array
{
    sal_rep_visit_ensure_schema($pdo);
    $monthYm = trim($monthYm);
    if ($monthYm === '' || !preg_match('/^\d{4}-\d{2}$/', $monthYm)) {
        $monthYm = date('Y-m');
    }
    $dateFrom = $monthYm . '-01';
    $ts = strtotime($dateFrom);
    $dateTo = date('Y-m-t', $ts !== false ? $ts : time());
    $weekdayLabels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    $empty = [
        'ok' => true,
        'month' => $monthYm,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'days' => [],
        'items' => [],
        'count' => 0,
    ];

    if ($salesRepId < 1) {
        return $empty;
    }

    try {
        $st = $pdo->prepare(
            "SELECT t.id AS tour_id, t.date_from, t.date_to, tl.customer_id, tl.weekday, tl.sort_order,
                    c.code AS customer_code, c.name_ar AS customer_name,
                    c.latitude, c.longitude
             FROM sal_rep_tour t
             INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
             INNER JOIN crm_customer c ON c.id = tl.customer_id
             WHERE t.sales_rep_id = ?
               AND t.status = 'posted'
               AND IFNULL(t.is_active, 1) = 1
               AND t.date_from <= ?
               AND t.date_to >= ?
             ORDER BY tl.weekday, tl.sort_order, c.name_ar, tl.id"
        );
        $st->execute([$salesRepId, $dateTo, $dateFrom]);
        $tourLines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sal_rep_visit_month_agenda_for_rep: ' . $e->getMessage());
        return $empty;
    }

    // حالة الزيارة اليومية لهذا الشهر
    $visitByKey = [];
    try {
        $st = $pdo->prepare(
            'SELECT r.route_date, l.customer_id, l.id AS route_line_id,
                    l.visit_checkin_at, l.visit_checkout_at, l.checkin_method, l.checkout_method
             FROM sal_rep_route r
             INNER JOIN sal_rep_route_line l ON l.route_id = r.id
             WHERE r.sales_rep_id = ? AND r.route_date BETWEEN ? AND ?'
        );
        $st->execute([$salesRepId, $dateFrom, $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = substr((string) $row['route_date'], 0, 10) . ':' . (int) $row['customer_id'];
            $visitByKey[$key] = $row;
        }
    } catch (Throwable $e) {
    }

    $items = [];
    $cursor = strtotime($dateFrom);
    $end = strtotime($dateTo);
    if ($cursor === false || $end === false) {
        return $empty;
    }
    while ($cursor <= $end) {
        $day = date('Y-m-d', $cursor);
        $wd = (int) date('w', $cursor);
        foreach ($tourLines as $ln) {
            $lineWd = (int) ($ln['weekday'] ?? -1);
            if ($lineWd !== $wd) {
                continue;
            }
            $tFrom = substr((string) ($ln['date_from'] ?? ''), 0, 10);
            $tTo = substr((string) ($ln['date_to'] ?? ''), 0, 10);
            if ($tFrom !== '' && $day < $tFrom) {
                continue;
            }
            if ($tTo !== '' && $day > $tTo) {
                continue;
            }
            $cid = (int) ($ln['customer_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $v = $visitByKey[$day . ':' . $cid] ?? null;
            $checkinAt = (string) ($v['visit_checkin_at'] ?? '');
            $checkoutAt = (string) ($v['visit_checkout_at'] ?? '');
            $status = 'idle';
            if ($checkinAt !== '' && $checkoutAt === '') {
                $status = 'checked_in';
            } elseif ($checkinAt !== '' && $checkoutAt !== '') {
                $status = 'checked_out';
            }
            $lat = $ln['latitude'] ?? null;
            $lng = $ln['longitude'] ?? null;
            $hasGps = $lat !== null && $lat !== '' && $lng !== null && $lng !== ''
                && function_exists('sal_invoice_gps_coords_valid')
                && sal_invoice_gps_coords_valid((float) $lat, (float) $lng);
            $items[] = [
                'route_date' => $day,
                'weekday' => $wd,
                'weekday_label' => $weekdayLabels[$wd] ?? '',
                'tour_id' => (int) ($ln['tour_id'] ?? 0),
                'customer_id' => $cid,
                'code' => (string) ($ln['customer_code'] ?? ''),
                'name' => (string) ($ln['customer_name'] ?? ''),
                'sort_order' => (int) ($ln['sort_order'] ?? 0),
                'in_plan' => true,
                'status' => $status,
                'visit_checkin_at' => $checkinAt !== '' ? $checkinAt : null,
                'visit_checkout_at' => $checkoutAt !== '' ? $checkoutAt : null,
                'route_line_id' => isset($v['route_line_id']) ? (int) $v['route_line_id'] : null,
                'has_gps' => $hasGps,
                'latitude' => $hasGps ? (float) $lat : null,
                'longitude' => $hasGps ? (float) $lng : null,
            ];
        }
        $cursor = strtotime('+1 day', $cursor);
        if ($cursor === false) {
            break;
        }
    }

    $daysMap = [];
    foreach ($items as $it) {
        $d = (string) $it['route_date'];
        if (!isset($daysMap[$d])) {
            $daysMap[$d] = [
                'route_date' => $d,
                'weekday' => (int) $it['weekday'],
                'weekday_label' => (string) $it['weekday_label'],
                'customers' => [],
            ];
        }
        $daysMap[$d]['customers'][] = $it;
    }

    return [
        'ok' => true,
        'month' => $monthYm,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'days' => array_values($daysMap),
        'items' => $items,
        'count' => count($items),
    ];
}

/** يوم الأسبوع بالعربية لتاريخ ISO Y-m-d */
function sal_rep_visit_weekday_ar(?string $isoDate): string
{
    $iso = trim((string) $isoDate);
    if ($iso === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
        return '';
    }
    $wd = (int) date('w', strtotime($iso));
    $labels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    return $labels[$wd] ?? '';
}

function sal_rep_visit_date_with_weekday(?string $isoDate): string
{
    $dmy = function_exists('format_date_dmY') ? format_date_dmY((string) $isoDate) : (string) $isoDate;
    $day = sal_rep_visit_weekday_ar($isoDate);

    return $day !== '' ? ($day . "\u{00A0}" . $dmy) : $dmy;
}

function sal_rep_visit_fmt_ts($v): string
{
    $s = trim((string) $v);
    if ($s === '') {
        return '—';
    }
    $iso = substr($s, 0, 10);
    $t = strlen($s) >= 16 ? substr($s, 11, 5) : '';
    $date = function_exists('format_date_dmY') ? format_date_dmY($iso) : $iso;

    return $date . ($t !== '' ? ' ' . $t : '');
}

function sal_rep_visit_duration_label(?string $checkinAt, ?string $checkoutAt): string
{
    $a = strtotime((string) $checkinAt);
    $b = strtotime((string) $checkoutAt);
    if ($a === false || $b === false || $b < $a) {
        return '—';
    }
    $mins = (int) floor(($b - $a) / 60);
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    if ($h > 0) {
        return $h . 'س ' . $m . 'د';
    }

    return $m . ' د';
}

function sal_rep_visit_fmt_time_only($v): string
{
    $s = trim((string) $v);
    if ($s === '' || strlen($s) < 16) {
        return '';
    }

    return substr($s, 11, 5);
}

function sal_rep_visit_timing_compact(array $r): string
{
    $parts = [];
    $cin = sal_rep_visit_fmt_time_only($r['visit_checkin_at'] ?? '');
    $cout = sal_rep_visit_fmt_time_only($r['visit_checkout_at'] ?? '');
    if ($cin !== '') {
        $parts[] = 'د ' . $cin;
    }
    if ($cout !== '') {
        $parts[] = 'خ ' . $cout;
    }
    $dur = sal_rep_visit_duration_label(
        ($r['visit_checkin_at'] ?? '') !== '' ? (string) $r['visit_checkin_at'] : null,
        ($r['visit_checkout_at'] ?? '') !== '' ? (string) $r['visit_checkout_at'] : null
    );
    if ($dur !== '—') {
        $parts[] = $dur;
    }
    $cm = trim((string) ($r['checkin_method_label'] ?? ''));
    $com = trim((string) ($r['checkout_method_label'] ?? ''));
    $methods = array_values(array_unique(array_filter(
        [$cm, $com],
        static fn(string $m): bool => $m !== '' && $m !== '—'
    )));
    if ($methods !== []) {
        $parts[] = implode('/', $methods);
    }
    if ($parts === []) {
        return '—';
    }

    return '<span class="si-ts-compact" dir="ltr">' . esc(implode(' · ', $parts)) . '</span>';
}

function sal_rep_visit_customer_inline(array $r): string
{
    $name = esc((string) ($r['customer_name'] ?? '—'));
    $code = trim((string) ($r['customer_code'] ?? ''));
    if ($code === '') {
        return $name;
    }

    return $name . ' <span class="muted si-cust-code" dir="ltr">(' . esc($code) . ')</span>';
}

function sal_rep_visit_location_inline(array $r): string
{
    $reg = trim((string) ($r['region_name'] ?? ''));
    $addr = trim((string) ($r['address_name'] ?? ''));
    if ($reg !== '' && $addr !== '') {
        return esc($reg . ' / ' . $addr);
    }
    if ($reg !== '') {
        return esc($reg);
    }
    if ($addr !== '') {
        return esc($addr);
    }

    return '—';
}

/**
 * تقرير تسجيلات الدخول/الخروج الفعلية من خط السير اليومي.
 *
 * @param array{from?:?string,to?:?string,sales_rep_id?:int,customer_id?:int,method?:string,status?:string,limit?:int} $filters
 * @return list<array<string,mixed>>
 */
function sal_rep_visit_report_rows(PDO $pdo, array $filters = []): array
{
    sal_rep_visit_ensure_schema($pdo);
    $from = (string) ($filters['from'] ?? '');
    $to = (string) ($filters['to'] ?? '');
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = '';
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = '';
    }
    $salesRepId = (int) ($filters['sales_rep_id'] ?? 0);
    $customerId = (int) ($filters['customer_id'] ?? 0);
    $method = strtoupper(trim((string) ($filters['method'] ?? '')));
    $status = trim((string) ($filters['status'] ?? ''));
    $limit = max(1, min(2000, (int) ($filters['limit'] ?? 800)));

    $where = ['l.visit_checkin_at IS NOT NULL'];
    $params = [];
    if ($from !== '') {
        $where[] = 'r.route_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = 'r.route_date <= ?';
        $params[] = $to;
    }
    if ($salesRepId > 0) {
        $where[] = 'r.sales_rep_id = ?';
        $params[] = $salesRepId;
    }
    if ($customerId > 0) {
        $where[] = 'l.customer_id = ?';
        $params[] = $customerId;
    }
    if ($method === 'GPS' || $method === 'MANUAL') {
        $where[] = '(UPPER(IFNULL(l.checkin_method,\'\')) = ? OR UPPER(IFNULL(l.checkout_method,\'\')) = ?)';
        $params[] = $method;
        $params[] = $method;
    }
    if ($status === 'open') {
        $where[] = 'l.visit_checkout_at IS NULL';
        $where[] = 'q.id IS NULL';
    } elseif ($status === 'closed') {
        $where[] = 'l.visit_checkout_at IS NOT NULL';
    } elseif ($status === 'pending') {
        $where[] = 'q.id IS NOT NULL';
        $where[] = 'l.visit_checkout_at IS NULL';
    }

    $sql = "SELECT l.id AS line_id, r.route_date, r.sales_rep_id,
                   COALESCE(sr.name_ar,'') AS sales_rep_name, COALESCE(sr.code,'') AS sales_rep_code,
                   l.customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                   COALESCE(rg.name_ar,'') AS region_name,
                   COALESCE(ra.name_ar,'') AS address_name,
                   l.visit_checkin_at, l.visit_checkout_at,
                   l.checkin_method, l.checkout_method,
                   l.checkin_lat, l.checkin_lng, l.checkin_accuracy, l.checkin_distance_m,
                   l.checkout_lat, l.checkout_lng, l.checkout_accuracy, l.checkout_distance_m,
                   COALESCE(l.in_plan, 1) AS in_plan,
                   (
                     SELECT GROUP_CONCAT(nr.name_ar ORDER BY nr.sort_order, nr.id SEPARATOR '، ')
                     FROM sal_rep_visit_no_order_reason vr
                     INNER JOIN sal_no_order_reason nr ON nr.id = vr.reason_id
                     WHERE vr.route_line_id = l.id
                   ) AS no_order_reasons,
                   (
                     SELECT GROUP_CONCAT(o.order_no ORDER BY o.id SEPARATOR '، ')
                     FROM sal_customer_order o
                     WHERE o.visit_route_line_id = l.id
                       AND o.created_at >= l.visit_checkin_at
                   ) AS order_numbers,
                   (
                     SELECT COUNT(*)
                     FROM sal_customer_order o
                     WHERE o.visit_route_line_id = l.id
                       AND o.created_at >= l.visit_checkin_at
                   ) AS order_count,
                   (
                     SELECT COALESCE(SUM(o.total), 0)
                     FROM sal_customer_order o
                     WHERE o.visit_route_line_id = l.id
                       AND o.created_at >= l.visit_checkin_at
                   ) AS order_total,
                   q.id AS pending_request_id, q.status AS checkout_request_status, q.reason AS checkout_reason
            FROM sal_rep_route_line l
            INNER JOIN sal_rep_route r ON r.id = l.route_id
            INNER JOIN crm_customer c ON c.id = l.customer_id
            LEFT JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
            LEFT JOIN crm_region rg ON rg.id = c.region_id
            LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
            LEFT JOIN (
                SELECT route_line_id, MAX(id) AS id
                FROM sal_rep_visit_checkout_request
                WHERE status = 'pending'
                GROUP BY route_line_id
            ) qp ON qp.route_line_id = l.id
            LEFT JOIN sal_rep_visit_checkout_request q ON q.id = qp.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.route_date DESC, l.visit_checkin_at DESC, l.id DESC
            LIMIT " . $limit;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sal_rep_visit_report_rows: ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $checkinAt = (string) ($r['visit_checkin_at'] ?? '');
        $checkoutAt = (string) ($r['visit_checkout_at'] ?? '');
        $pending = !empty($r['pending_request_id']) && $checkoutAt === '';
        if ($pending) {
            $stLabel = 'pending';
            $stText = 'بانتظار موافقة';
        } elseif ($checkoutAt !== '') {
            $stLabel = 'closed';
            $stText = 'مكتملة';
        } else {
            $stLabel = 'open';
            $stText = 'داخل الزيارة';
        }
        $out[] = [
            'line_id' => (int) ($r['line_id'] ?? 0),
            'route_date' => (string) ($r['route_date'] ?? ''),
            'sales_rep_id' => (int) ($r['sales_rep_id'] ?? 0),
            'sales_rep_name' => (string) ($r['sales_rep_name'] ?? ''),
            'sales_rep_code' => (string) ($r['sales_rep_code'] ?? ''),
            'customer_id' => (int) ($r['customer_id'] ?? 0),
            'customer_code' => (string) ($r['customer_code'] ?? ''),
            'customer_name' => (string) ($r['customer_name'] ?? ''),
            'region_name' => (string) ($r['region_name'] ?? ''),
            'address_name' => (string) ($r['address_name'] ?? ''),
            'visit_checkin_at' => $checkinAt !== '' ? $checkinAt : null,
            'visit_checkout_at' => $checkoutAt !== '' ? $checkoutAt : null,
            'checkin_method' => $r['checkin_method'] ?? null,
            'checkout_method' => $r['checkout_method'] ?? null,
            'checkin_method_label' => sal_rep_visit_method_label($r['checkin_method'] ?? null),
            'checkout_method_label' => sal_rep_visit_method_label($r['checkout_method'] ?? null),
            'checkin_lat' => $r['checkin_lat'] ?? null,
            'checkin_lng' => $r['checkin_lng'] ?? null,
            'checkin_accuracy' => $r['checkin_accuracy'] ?? null,
            'checkin_distance_m' => $r['checkin_distance_m'] ?? null,
            'checkout_lat' => $r['checkout_lat'] ?? null,
            'checkout_lng' => $r['checkout_lng'] ?? null,
            'checkout_accuracy' => $r['checkout_accuracy'] ?? null,
            'checkout_distance_m' => $r['checkout_distance_m'] ?? null,
            'in_plan' => (int) ($r['in_plan'] ?? 1) === 1,
            'plan_scope' => (int) ($r['in_plan'] ?? 1) === 1 ? 'inside' : 'outside',
            'plan_scope_label' => (int) ($r['in_plan'] ?? 1) === 1 ? 'داخل الجولة' : 'خارج الجولة',
            'no_order_reasons' => (string) ($r['no_order_reasons'] ?? ''),
            'order_numbers' => (string) ($r['order_numbers'] ?? ''),
            'order_count' => (int) ($r['order_count'] ?? 0),
            'order_total' => (float) ($r['order_total'] ?? 0),
            'duration_label' => sal_rep_visit_duration_label($checkinAt !== '' ? $checkinAt : null, $checkoutAt !== '' ? $checkoutAt : null),
            'status' => $stLabel,
            'status_label' => $stText,
            'pending_request_id' => $pending ? (int) $r['pending_request_id'] : null,
            'checkout_reason' => $r['checkout_reason'] ?? null,
        ];
    }

    return $out;
}
