<?php
declare(strict_types=1);

/**
 * CLI: ربط/بحث عميل Oracle
 *   php customer_oracle_link.php lookup <oracle_key>
 *   php customer_oracle_link.php link <customer_id> <oracle_key>
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/crm_sales_rep_schema.php');

$action = strtolower(trim((string) ($argv[1] ?? '')));
$arg2 = trim((string) ($argv[2] ?? ''));
$arg3 = trim((string) ($argv[3] ?? ''));

try {
    if ($action === 'lookup') {
        $result = crm_customer_oracle_lookup_name($arg2);
    } elseif ($action === 'link') {
        $result = crm_customer_link_oracle(db(), (int) $arg2, $arg3);
    } else {
        $result = ['ok' => false, 'message' => 'إجراء غير معروف.'];
    }
} catch (Throwable $e) {
    $result = ['ok' => false, 'message' => $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
