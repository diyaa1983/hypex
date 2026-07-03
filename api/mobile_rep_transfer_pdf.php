<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/mobile_rep_transfer_print.php');
require_once app_path('includes/mobile_dompdf.php');
require_once app_path('includes/inv_wh_move_schema.php');

/**
 * @param list<array{item_id?:mixed, qty?:mixed}> $lines
 * @return array{ok:bool, error?:string, move_id?:int, move_no?:string, next_move_no?:string, direction?:string}
 */
function mobile_rep_transfer_pdf_resolve_move(
    PDO $pdo,
    array $ctx,
    string $direction,
    int $userId,
    int $moveId,
    string $moveDate,
    array $lines,
    string $userNotes,
    bool $allowPostFromCart
): array {
    if ($moveId > 0) {
        $move = inv_wh_move_by_id($pdo, $moveId);
        if ($move === null || !mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
            return ['ok' => false, 'error' => 'السند غير موجود أو غير مسموح.'];
        }

        if ((string) ($move['status'] ?? '') === 'posted') {
            return [
                'ok' => true,
                'move_id' => $moveId,
                'move_no' => mobile_rep_custody_format_move_no((string) ($move['move_no'] ?? '')),
                'direction' => $direction,
            ];
        }

        if ($lines !== []) {
            $save = mobile_rep_custody_save_transfer(
                $pdo,
                $ctx,
                $direction,
                $moveId,
                $moveDate,
                $lines,
                $userNotes,
                $userId
            );
            if (!$save['ok']) {
                return ['ok' => false, 'error' => $save['error'] ?? 'تعذر تحديث السند.'];
            }
        }

        $post = mobile_rep_custody_post_move($pdo, $ctx, $direction, $moveId, $userId);
        if (!$post['ok']) {
            return ['ok' => false, 'error' => $post['error'] ?? 'تعذر الترحيل.'];
        }

        return [
            'ok' => true,
            'move_id' => $moveId,
            'move_no' => mobile_rep_custody_format_move_no((string) ($post['move_no'] ?? '')),
            'direction' => $direction,
        ];
    }

    if (!$allowPostFromCart || $lines === []) {
        return ['ok' => false, 'error' => 'لا توجد مواد للترحيل.'];
    }

    $res = mobile_rep_custody_post_transfer(
        $pdo,
        $ctx,
        $direction,
        $moveDate,
        $lines,
        $userNotes,
        $userId
    );
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'تعذر الترحيل.')];
    }

    return [
        'ok' => true,
        'move_id' => (int) ($res['move_id'] ?? 0),
        'move_no' => mobile_rep_custody_format_move_no((string) ($res['move_no'] ?? '')),
        'next_move_no' => mobile_rep_custody_format_move_no(mobile_rep_custody_preview_next_move_no($pdo)),
        'direction' => $direction,
    ];
}

function mobile_rep_transfer_pdf_json_error(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function mobile_rep_transfer_pdf_stream(PDO $pdo, array $ctx, string $direction, int $moveId): never
{
    $doc = mobile_rep_transfer_print_document($pdo, $moveId, $ctx, $direction);
    if ($doc === null) {
        mobile_rep_transfer_pdf_json_error(404, 'تعذر إنشاء مستند PDF.');
    }

    $html = (string) ($doc['html_pdf'] ?? '');
    if ($html === '') {
        mobile_rep_transfer_pdf_json_error(500, 'محتوى PDF فارغ.');
    }

    $fname = mobile_rep_transfer_print_pdf_filename($direction, (string) ($doc['move_no'] ?? ''));
    mobile_dompdf_stream_pdf($html, $fname);
}

$direction = trim((string) ($_REQUEST['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';

if (!is_logged_in()) {
    mobile_rep_transfer_pdf_json_error(403, 'يجب تسجيل الدخول.');
}

if (!user_can($perm)) {
    mobile_rep_transfer_pdf_json_error(403, 'لا توجد صلاحية.');
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    mobile_rep_transfer_pdf_json_error(403, 'حسابك غير مربوط بمندوب.');
}

$userId = (int) (current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $moveId = (int) ($_GET['id'] ?? 0);
    if ($moveId < 1) {
        mobile_rep_transfer_pdf_json_error(400, 'معرّف السند مطلوب.');
    }

    if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
        $alt = $direction === 'return' ? 'load' : 'return';
        if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $alt, $moveId, $userId)) {
            mobile_rep_transfer_pdf_json_error(404, 'السند غير موجود.');
        }
        $direction = $alt;
    }

    mobile_rep_transfer_pdf_stream($pdo, $ctx, $direction, $moveId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mobile_rep_transfer_pdf_json_error(405, 'طريقة غير مدعومة.');
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    mobile_rep_transfer_pdf_json_error(403, 'انتهت الجلسة. أعد تحميل الصفحة.');
}

$moveId = (int) ($_POST['move_id'] ?? 0);
$moveDate = trim((string) ($_POST['move_date'] ?? ''));
$iso = parse_date_to_iso($moveDate) ?? date('Y-m-d');
$userNotes = trim((string) ($_POST['notes'] ?? ''));

$linesJson = (string) ($_POST['lines_json'] ?? '[]');
$lines = json_decode($linesJson, true);
if (!is_array($lines)) {
    $lines = [];
}

$resolved = mobile_rep_transfer_pdf_resolve_move(
    $pdo,
    $ctx,
    $direction,
    $userId,
    $moveId,
    $iso,
    $lines,
    $userNotes,
    true
);

if (!$resolved['ok']) {
    mobile_rep_transfer_pdf_json_error(400, (string) ($resolved['error'] ?? 'تعذر تجهيز PDF.'));
}

$finalMoveId = (int) ($resolved['move_id'] ?? 0);
$finalDirection = (string) ($resolved['direction'] ?? $direction);
if ($finalMoveId < 1) {
    mobile_rep_transfer_pdf_json_error(500, 'تعذر تحديد السند.');
}

header('X-Rep-Move-Id: ' . $finalMoveId);
if (!empty($resolved['move_no'])) {
    header('X-Rep-Move-No: ' . rawurlencode((string) $resolved['move_no']));
}
if (!empty($resolved['next_move_no'])) {
    header('X-Rep-Next-Move-No: ' . rawurlencode((string) $resolved['next_move_no']));
}

mobile_rep_transfer_pdf_stream($pdo, $ctx, $finalDirection, $finalMoveId);
