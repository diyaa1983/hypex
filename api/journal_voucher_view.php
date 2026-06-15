<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/acc_journal.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('journal_voucher')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!acc_journal_ensure_schema($pdo)) {
    echo json_encode(['ok' => false, 'error' => 'schema', 'message' => 'جداول القيود غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = acc_journal_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد سندات قيد محفوظة بعد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $firstId;
}

if ($id > 0 && $dir === 'prev') {
    $id = acc_journal_neighbor_id($pdo, $id, 'prev') ?? 0;
} elseif ($id > 0 && $dir === 'next') {
    $id = acc_journal_neighbor_id($pdo, $id, 'next') ?? 0;
}

if ($id < 1 && $no !== '') {
    $id = acc_journal_id_by_no($pdo, $no) ?? 0;
}

if ($id < 1) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'سند القيد غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entry = acc_journal_api_entry($pdo, $id);
if (!$entry) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'سند القيد غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'entry' => $entry], JSON_UNESCAPED_UNICODE);
