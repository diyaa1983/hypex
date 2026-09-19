<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_credit_note.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('credit_notes')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!fin_credit_note_ensure_schema($pdo)) {
    echo json_encode(['ok' => false, 'error' => 'schema', 'message' => 'الجداول غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $st = $pdo->query('SELECT id FROM fin_credit_note ORDER BY id DESC LIMIT 1');
    $fid = $st->fetchColumn();
    if ($fid === false) {
        echo json_encode(['ok' => false, 'error' => 'empty', 'message' => 'لا توجد إشعارات دائنة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) $fid;
}

if ($id > 0 && $dir === 'prev') {
    $nid = fin_credit_note_nav_neighbor_id($pdo, $id, 'prev');
    $id = $nid ?? 0;
} elseif ($id > 0 && $dir === 'next') {
    $nid = fin_credit_note_nav_neighbor_id($pdo, $id, 'next');
    $id = $nid ?? 0;
}

$row = null;
if ($id > 0) {
    $row = fin_credit_note_fetch($pdo, $id);
} elseif ($no !== '') {
    $st = $pdo->prepare('SELECT id FROM fin_credit_note WHERE note_no = ? LIMIT 1');
    $st->execute([$no]);
    $fid = $st->fetchColumn();
    if ($fid !== false) {
        $row = fin_credit_note_fetch($pdo, (int) $fid);
    }
}

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'الإشعار غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$noteId = (int) $row['id'];
$partyType = (string) $row['party_type'];
$partyName = '';
if ($partyType === 'customer') {
    $ps = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
    $ps->execute([(int) $row['party_id']]);
    $partyName = (string) ($ps->fetchColumn() ?: '');
} else {
    $ps = $pdo->prepare('SELECT name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
    $ps->execute([(int) $row['party_id']]);
    $partyName = (string) ($ps->fetchColumn() ?: '');
}

$lines = fin_credit_note_fetch_lines($pdo, $noteId);
$outLines = [];
foreach ($lines as $ln) {
    $outLines[] = [
        'id' => (int) $ln['id'],
        'item_id' => (int) ($ln['item_id'] ?? 0),
        'item_code' => (string) ($ln['item_code'] ?? ''),
        'item_name' => (string) ($ln['item_name'] ?? ''),
        'description_ar' => (string) ($ln['description_ar'] ?? ''),
        'qty' => (float) $ln['qty'],
        'unit_price' => (float) $ln['unit_price'],
        'line_total' => (float) $ln['line_total'],
    ];
}

echo json_encode([
    'ok' => true,
    'note' => [
        'id' => $noteId,
        'note_no' => (string) $row['note_no'],
        'note_date' => (string) $row['note_date'],
        'note_date_dmy' => format_date_dmY((string) $row['note_date']),
        'party_type' => $partyType,
        'party_id' => (int) $row['party_id'],
        'party_name' => $partyName,
        'total' => (float) $row['total'],
        'reason' => (string) ($row['reason'] ?? ''),
        'prev_id' => fin_credit_note_nav_neighbor_id($pdo, $noteId, 'prev') ?? 0,
        'next_id' => fin_credit_note_nav_neighbor_id($pdo, $noteId, 'next') ?? 0,
        'lines' => $outLines,
    ],
], JSON_UNESCAPED_UNICODE);
