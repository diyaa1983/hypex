<?php
declare(strict_types=1);

/**
 * قائمة العملاء/الموردين لتطبيق الهاتف الأصلي (اختيار الطرف في كشف الحساب وسند القبض).
 * قراءة فقط، تعتمد جلسة الكوكيز الحالية ومجموعة MOBILE.
 *
 *   GET ?type=customer|supplier&q=بحث
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');

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
    $table = $type === 'supplier' ? 'crm_supplier' : 'crm_customer';

    $sql = "SELECT id, name_ar, code FROM {$table} WHERE is_active = 1";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (name_ar LIKE ? OR code LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY name_ar LIMIT 800';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $parties = array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name_ar'] ?? ''),
            'code' => (string) ($r['code'] ?? ''),
        ];
    }, $rows);

    echo json_encode([
        'ok' => true,
        'type' => $type,
        'parties' => $parties,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_parties: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
