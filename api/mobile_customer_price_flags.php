<?php
declare(strict_types=1);

/**
 * أعلام تسعير العملاء (جملة / كبار عملاء) لتطبيق الهاتف — تحديث خفيف دون سحب الكتالوج كاملاً.
 *
 *   GET → { ok, flags: [{ id, use_wholesale_price }], count }
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/inv_item_doc_pricing.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    if (function_exists('inv_customer_price_mode_ensure')) {
        inv_customer_price_mode_ensure($pdo);
    }

    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    if (mobile_manager_sees_all()) {
        $scopedRepId = null;
    }
    if ($scopedRepId === null && !user_is_system_admin() && !mobile_manager_sees_all()) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'no_sales_rep',
            'message' => 'حسابك غير مربوط بمندوب مبيعات.',
            'flags' => [],
            'count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $params = [];
    $sql = 'SELECT c.id, c.use_wholesale_price FROM crm_customer c WHERE c.is_active = 1';
    if ($scopedRepId !== null) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
        $sql .= ' AND ' . $linkSql;
        $params = array_merge($params, $linkParams);
    }
    $sql .= ' ORDER BY c.id LIMIT 20000';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $flags = array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'use_wholesale_price' => (int) ($r['use_wholesale_price'] ?? 1) === 0 ? 0 : 1,
        ];
    }, $rows);

    echo json_encode([
        'ok' => true,
        'flags' => $flags,
        'count' => count($flags),
        'scoped_to_sales_rep' => $scopedRepId !== null,
        'sales_rep_id' => $scopedRepId,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_customer_price_flags: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
