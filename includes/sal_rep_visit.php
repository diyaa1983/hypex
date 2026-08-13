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
        $pdo->query('SELECT id FROM sal_rep_visit_checkout_request LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/266_report_sales_rep_tours.sql');
        sql_migration_run_file($pdo, 'database/migrations/270_sal_rep_visit_checkin.sql');
        try {
            $pdo->query('SELECT checkin_lat FROM sal_rep_route_line LIMIT 1');
            $pdo->query('SELECT id FROM sal_rep_visit_checkout_request LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    return $ok;
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
                    l.checkin_method, l.checkout_method, l.checkin_distance_m, l.checkout_distance_m
             FROM sal_rep_route_line l WHERE l.route_id = ?'
        );
        $st->execute([$routeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
            $byCust[(int) $ln['customer_id']] = $ln;
        }
    }

    // أضف العملاء المربوطين بالمندوب حتى لو لم يكونوا في خط السير بعد
    $seen = [];
    foreach ($data['customers'] as $c) {
        $seen[(int) ($c['id'] ?? 0)] = true;
    }
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
            $status = 'checked_out';
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
        $dow = (int) date('N', strtotime($routeDate));
        $st = $pdo->prepare(
            'SELECT tl.id FROM sal_rep_tour_line tl
             INNER JOIN sal_rep_tour t ON t.id = tl.tour_id
             WHERE t.sales_rep_id = ? AND t.status = \'posted\'
               AND t.date_from <= ? AND t.date_to >= ?
               AND (tl.weekday IS NULL OR tl.weekday = 0 OR tl.weekday = ?)
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
