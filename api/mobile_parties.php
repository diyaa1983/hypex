<?php
declare(strict_types=1);

/**
 * قائمة العملاء/الموردين لتطبيق الهاتف.
 * العملاء: إن كان المستخدم مربوطاً بمندوب → عملاؤه فقط.
 *
 *   GET ?type=customer|supplier&q=بحث
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = trim((string) ($_GET['type'] ?? 'customer'));
$type = $type === 'supplier' ? 'supplier' : 'customer';
$q = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = db();
    $scopedRepId = $type === 'customer' ? crm_mobile_scoped_sales_rep_id($pdo) : null;
    // مدير النظام يرى كل العملاء
    if ($type === 'customer' && user_is_system_admin()) {
        $scopedRepId = null;
    }
    $params = [];

    if ($type === 'customer') {
        $sql = 'SELECT c.id, c.name_ar, c.code, c.phone, c.address_ar, c.latitude, c.longitude FROM crm_customer c WHERE c.is_active = 1';
        if ($scopedRepId !== null) {
            [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
            $sql .= ' AND ' . $linkSql;
            $params = array_merge($params, $linkParams);
        }
        if ($q !== '') {
            $sql .= ' AND (c.name_ar LIKE ? OR c.code LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY c.name_ar LIMIT 2000';
    } else {
        $sql = 'SELECT id, name_ar, code FROM crm_supplier WHERE is_active = 1';
        if ($q !== '') {
            $sql .= ' AND (name_ar LIKE ? OR code LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY name_ar LIMIT 2000';
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $parties = array_map(static function (array $r) use ($type): array {
        $out = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name_ar'] ?? ''),
            'code' => (string) ($r['code'] ?? ''),
        ];
        if ($type === 'customer') {
            $lat = isset($r['latitude']) && $r['latitude'] !== null ? (float) $r['latitude'] : null;
            $lng = isset($r['longitude']) && $r['longitude'] !== null ? (float) $r['longitude'] : null;
            $out['phone'] = (string) ($r['phone'] ?? '');
            $out['address'] = (string) ($r['address_ar'] ?? '');
            $out['latitude'] = $lat;
            $out['longitude'] = $lng;
            $out['has_gps'] = $lat !== null && $lng !== null;
        }
        return $out;
    }, $rows);

    echo json_encode([
        'ok' => true,
        'type' => $type,
        'scoped_to_sales_rep' => $scopedRepId !== null,
        'sales_rep_id' => $scopedRepId,
        'parties' => $parties,
        'count' => count($parties),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_parties: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
