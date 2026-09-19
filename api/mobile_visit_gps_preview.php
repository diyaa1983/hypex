<?php
declare(strict_types=1);

/**
 * معاينة المسافة قبل تسجيل الدخول بـ GPS.
 * POST JSON: customer_id, latitude, longitude, accuracy?
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_visit.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
$customerId = (int) ($body['customer_id'] ?? 0);
$gps = sal_rep_visit_parse_gps($body);
$radius = (int) sal_rep_visit_radius_m(db());

if ($customerId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'العميل مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($gps['lat'] === null || $gps['lng'] === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'تعذّر قراءة موقعك الحالي.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT latitude, longitude, name_ar FROM crm_customer WHERE id=? LIMIT 1');
$st->execute([$customerId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'لا يوجد موقع GPS محفوظ للعميل.',
        'customer_has_gps' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$distance = sal_rep_visit_distance_to_customer($pdo, $customerId, $gps['lat'], $gps['lng']);
$within = $distance !== null && sal_rep_visit_within_geofence($distance, $pdo);

echo json_encode([
    'ok' => true,
    'customer_id' => $customerId,
    'customer_name' => (string) ($row['name_ar'] ?? ''),
    'customer_lat' => (float) $row['latitude'],
    'customer_lng' => (float) $row['longitude'],
    'user_lat' => $gps['lat'],
    'user_lng' => $gps['lng'],
    'accuracy_m' => $gps['accuracy'],
    'distance_m' => $distance !== null ? round($distance, 1) : null,
    'visit_radius_m' => $radius,
    'within_geofence' => $within,
    'message' => $within
        ? 'أنت ضمن حدود موقع العميل.'
        : ('أنت على بعد ' . round((float) $distance) . ' أمتار من موقع العميل (المسموح ' . $radius . ' م).'),
], JSON_UNESCAPED_UNICODE);
