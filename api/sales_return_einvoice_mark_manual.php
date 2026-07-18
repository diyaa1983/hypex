<?php
declare(strict_types=1);

/**
 * اعتبار مرتجع مبيعات مرسلاً للفوترة يدوياً (بدون إرسال من هذا النظام).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/einvoice_schema.php');
require_once app_path('includes/einvoice_send_return.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_returns') || !user_can_action('sales_send_einvoice')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnId = (int) ($_POST['return_id'] ?? 0);
if ($returnId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
einvoice_ensure_schema($pdo);

if (einvoice_return_is_sent($pdo, $returnId)) {
    echo json_encode(['ok' => true, 'message' => 'هذا المرتجع مُعلَّم كمرسل مسبقاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare('SELECT id, return_no FROM sal_return WHERE id = ? LIMIT 1');
$st->execute([$returnId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'المرتجع غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$note = 'تم اعتباره مرسلاً يدوياً من منصة JoFotara — بدون إرسال من هذا النظام.';
$sets = [];
$vals = [];
foreach ([
    'einv_qr' => 'MANUAL',
    'einv_status' => 'SUBMITTED',
    'einv_results' => $note,
    'einv_num' => 'MANUAL',
    'einv_sent_at' => date('Y-m-d H:i:s'),
] as $col => $val) {
    if (!einvoice_column_exists($pdo, 'sal_return', $col)) {
        continue;
    }
    $sets[] = "`{$col}` = ?";
    $vals[] = $val;
}
if ($sets === []) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'أعمدة الفوترة غير متوفرة.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$vals[] = $returnId;
$pdo->prepare('UPDATE sal_return SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

echo json_encode([
    'ok' => true,
    'message' => 'تم اعتبار المرتجع ' . $row['return_no'] . ' مرسلاً للفوترة يدوياً.',
], JSON_UNESCAPED_UNICODE);
