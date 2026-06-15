<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/acc_journal.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 80);
$postedMovesOnly = (string) ($_GET['with_movements'] ?? '') === '1';
$forMapping = (string) ($_GET['for_mapping'] ?? '') === '1';
$pdo = db();

if (!acc_journal_has_tables($pdo)) {
    echo json_encode(['ok' => true, 'accounts' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = acc_accounts_picker_search($pdo, $q, $limit, $postedMovesOnly, $forMapping);
$accounts = array_map(static function (array $row): array {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'code' => (string) ($row['code'] ?? ''),
        'name_ar' => (string) ($row['name_ar'] ?? ''),
    ];
}, $rows);

echo json_encode(['ok' => true, 'accounts' => $accounts], JSON_UNESCAPED_UNICODE);
