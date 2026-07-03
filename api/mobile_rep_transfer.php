<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$direction = trim((string) ($_POST['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';
if (!user_can($perm)) {
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

$action = trim((string) ($_POST['_action'] ?? 'save_post'));
$moveId = (int) ($_POST['move_id'] ?? 0);
$moveDate = trim((string) ($_POST['move_date'] ?? ''));
$iso = parse_date_to_iso($moveDate) ?? '';
if ($iso === '') {
    $iso = date('Y-m-d');
}

$linesJson = (string) ($_POST['lines_json'] ?? '[]');
$lines = json_decode($linesJson, true);
if (!is_array($lines)) {
    $lines = [];
}

$userId = (int) (current_user()['id'] ?? 0);
$userNotes = trim((string) ($_POST['notes'] ?? ''));

if ($action === 'post') {
    if ($moveId < 1) {
        $save = mobile_rep_custody_save_transfer(
            $pdo,
            $ctx,
            $direction,
            0,
            $iso,
            $lines,
            $userNotes,
            $userId
        );
        if (!$save['ok']) {
            echo json_encode([
                'ok' => false,
                'error' => $save['error'] ?? 'تعذر الحفظ قبل الترحيل.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $moveId = (int) ($save['move_id'] ?? 0);
    } else {
        $save = mobile_rep_custody_save_transfer(
            $pdo,
            $ctx,
            $direction,
            $moveId,
            $iso,
            $lines,
            $userNotes,
            $userId
        );
        if (!$save['ok']) {
            echo json_encode([
                'ok' => false,
                'error' => $save['error'] ?? 'تعذر تحديث السند قبل الترحيل.',
                'move_id' => $moveId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $res = mobile_rep_custody_post_move($pdo, $ctx, $direction, $moveId, $userId);
    if (!$res['ok']) {
        echo json_encode([
            'ok' => false,
            'error' => $res['error'] ?? 'تعذر الترحيل.',
            'move_id' => $moveId,
            'move_no' => $res['move_no'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'action' => 'posted',
        'move_id' => $moveId,
        'move_no' => $res['move_no'],
        'next_move_no' => mobile_rep_custody_format_move_no(mobile_rep_custody_preview_next_move_no($pdo)),
        'gl_warning' => $res['gl_warning'],
        'message' => ($direction === 'return' ? 'تم ترحيل إرجاع العهدة.' : 'تم ترحيل تحميل العهدة.')
            . ($res['move_no'] ? ' رقم السند: ' . mobile_rep_custody_format_move_no((string) $res['move_no']) : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save' || $action === 'save_post') {
    $res = mobile_rep_custody_save_transfer(
        $pdo,
        $ctx,
        $direction,
        $moveId,
        $iso,
        $lines,
        $userNotes,
        $userId
    );
    if (!$res['ok']) {
        echo json_encode([
            'ok' => false,
            'error' => $res['error'] ?? 'تعذر الحفظ.',
            'move_id' => $res['move_id'],
            'move_no' => $res['move_no'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_post') {
        $post = mobile_rep_custody_post_move($pdo, $ctx, $direction, (int) ($res['move_id'] ?? 0), $userId);
        if (!$post['ok']) {
            echo json_encode([
                'ok' => false,
                'error' => $post['error'] ?? 'تم الحفظ لكن تعذر الترحيل.',
                'move_id' => $res['move_id'],
                'move_no' => $res['move_no'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'action' => 'posted',
            'move_id' => $res['move_id'],
            'move_no' => $post['move_no'] ?? $res['move_no'],
            'next_move_no' => mobile_rep_custody_format_move_no(mobile_rep_custody_preview_next_move_no($pdo)),
            'gl_warning' => $post['gl_warning'],
            'message' => ($direction === 'return' ? 'تم إرجاع العهدة بنجاح.' : 'تم تحميل العهدة بنجاح.')
                . ($post['move_no'] ? ' رقم السند: ' . mobile_rep_custody_format_move_no((string) $post['move_no']) : ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'action' => 'saved',
        'move_id' => $res['move_id'],
        'move_no' => mobile_rep_custody_format_move_no((string) ($res['move_no'] ?? '')),
        'status' => $res['status'],
        'message' => 'تم حفظ السند.' . ($res['move_no'] ? ' رقم السند: ' . mobile_rep_custody_format_move_no((string) $res['move_no']) : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
