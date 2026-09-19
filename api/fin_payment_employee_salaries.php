<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/fin_payment_parties.php');

require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('cash_payment')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
fin_voucher_ensure_schema_full($pdo);

$employeeId = (int) ($_GET['employee_id'] ?? 0);
if ($employeeId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف الموظف غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$salaries = fin_payment_employee_salaries_pending($pdo, $employeeId);
$payableId = fin_payment_salaries_payable_account_id($pdo);

echo json_encode([
    'ok' => true,
    'employee_id' => $employeeId,
    'salaries_payable_account_id' => $payableId,
    'salaries' => $salaries,
    'has_salaries' => $salaries !== [],
], JSON_UNESCAPED_UNICODE);
