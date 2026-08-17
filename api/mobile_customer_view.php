<?php
declare(strict_types=1);

/**
 * تفاصيل عميل للموبايل (معلومات عامة + موقع).
 * GET ?id=
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_visit.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? $_GET['customer_id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'معرّف العميل مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $uid = (int) (current_user()['id'] ?? 0);
    $repId = crm_sales_rep_id_for_user($pdo, $uid);
    if ($repId && $repId > 0 && !user_is_system_admin()) {
        if (!crm_customer_is_linked_to_sales_rep($pdo, $id, $repId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'هذا العميل غير مربوط بمندوبك.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $hasRegion = false;
    $hasRegionAddr = false;
    try {
        $pdo->query('SELECT region_id FROM crm_customer LIMIT 1');
        $hasRegion = true;
    } catch (Throwable $e) {
    }
    try {
        $pdo->query('SELECT region_address_id FROM crm_customer LIMIT 1');
        $hasRegionAddr = true;
    } catch (Throwable $e) {
    }

    $sql = 'SELECT c.id, c.code, c.name_ar, c.phone, c.tax_number, c.email, c.address_ar,
                   c.latitude, c.longitude';
    if ($hasRegion) {
        $sql .= ', c.region_id, COALESCE(rg.name_ar,\'\') AS region_name';
    }
    if ($hasRegionAddr) {
        $sql .= ', c.region_address_id, COALESCE(ra.name_ar,\'\') AS region_address_name';
    }
    $sql .= ' FROM crm_customer c';
    if ($hasRegion) {
        $sql .= ' LEFT JOIN crm_region rg ON rg.id = c.region_id';
    }
    if ($hasRegionAddr) {
        $sql .= ' LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id';
    }
    $sql .= ' WHERE c.id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'العميل غير موجود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $visit = null;
    if ($repId && $repId > 0) {
        try {
            $ens = sal_rep_visit_ensure_route_line($pdo, $repId, $id, $uid);
            if (!empty($ens['ok'])) {
                $line = sal_rep_visit_line_fetch($pdo, (int) $ens['route_line_id']);
                if ($line) {
                    $visit = sal_rep_visit_public_row($line);
                    if ($visit) {
                        $visit['has_order'] = sal_rep_visit_has_order(
                            $pdo,
                            (int) $ens['route_line_id']
                        );
                        $visit['order_id'] = sal_rep_visit_order_id(
                            $pdo,
                            (int) $ens['route_line_id']
                        );
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    $lat = $row['latitude'] !== null ? (float) $row['latitude'] : null;
    $lng = $row['longitude'] !== null ? (float) $row['longitude'] : null;

    echo json_encode([
        'ok' => true,
        'customer' => [
            'id' => (int) $row['id'],
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name_ar'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'tax_number' => (string) ($row['tax_number'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'address' => (string) ($row['address_ar'] ?? ''),
            'region_id' => $hasRegion ? (int) ($row['region_id'] ?? 0) : 0,
            'region_name' => $hasRegion ? (string) ($row['region_name'] ?? '') : '',
            'region_address_id' => $hasRegionAddr ? (int) ($row['region_address_id'] ?? 0) : 0,
            'region_address_name' => $hasRegionAddr ? (string) ($row['region_address_name'] ?? '') : '',
            'latitude' => $lat,
            'longitude' => $lng,
            'has_gps' => $lat !== null && $lng !== null,
        ],
        'visit' => $visit,
        'no_order_reasons' => sal_rep_visit_no_order_reasons($pdo),
        'visit_radius_m' => (int) sal_rep_visit_radius_m($pdo),
        'checkin_methods' => ['GPS', 'MANUAL'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_customer_view: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذّر تحميل بيانات العميل.'], JSON_UNESCAPED_UNICODE);
}
