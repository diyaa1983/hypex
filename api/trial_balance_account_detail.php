<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/acc_report_tb_detail.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || (!user_can('report_trial_balance') && !user_can('report_trial_balance_detailed'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
acc_gl_ensure_schema($pdo);

$accountId = (int) ($_GET['account_id'] ?? 0);
$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? '';
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? '';

if ($accountId < 1 || $dateFrom === '' || $dateTo === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$colspan = max(1, (int) ($_GET['colspan'] ?? 9));
$result = acc_report_tb_detail_for_account($pdo, $accountId, $dateFrom, $dateTo, $colspan);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
