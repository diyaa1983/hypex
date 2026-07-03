<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/inv_warehouse_items_report.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('m_rep_stock')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_rep',
        'message' => 'حسابك غير مربوط بمندوب أو مستودع عهدة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$lines = inv_report_warehouse_items_lines($pdo, (int) $ctx['van_warehouse_id'], true);

if ($q !== '') {
    $qLower = mb_strtolower($q, 'UTF-8');
    $lines = array_values(array_filter($lines, static function (array $row) use ($qLower): bool {
        $hay = mb_strtolower(
            (string) ($row['item_name'] ?? '') . ' ' . (string) ($row['item_sku'] ?? ''),
            'UTF-8'
        );

        return str_contains($hay, $qLower);
    }));
}

foreach ($lines as &$row) {
    $row['qty'] = company_round_amount((float) ($row['qty'] ?? 0));
}
unset($row);

echo json_encode([
    'ok' => true,
    'items' => $lines,
    'warehouse' => [
        'id' => (int) $ctx['van_warehouse_id'],
        'name_ar' => (string) $ctx['van_warehouse_name'],
        'code' => (string) $ctx['van_warehouse_code'],
    ],
    'rep_name' => (string) $ctx['rep_name'],
], JSON_UNESCAPED_UNICODE);
