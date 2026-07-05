<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_archive.php');

if (!is_logged_in()) {
    http_response_code(403);
    if (in_array((string) ($_GET['action'] ?? ''), ['download', 'view'], true)) {
        exit('Forbidden');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$kind = trim((string) ($_GET['kind'] ?? $_POST['kind'] ?? ''));

try {
    $pdo = db();
    fin_voucher_archive_ensure_schema($pdo);

    if ($action === 'download') {
        $docId = (int) ($_GET['id'] ?? 0);
        $file = fin_voucher_archive_download($pdo, $docId, $kind);
        $name = (string) $file['name'];
        $path = (string) $file['path'];
        $mime = (string) $file['mime'];
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    if ($action === 'view') {
        $docId = (int) ($_GET['id'] ?? 0);
        $file = fin_voucher_archive_download($pdo, $docId, $kind);
        $name = (string) $file['name'];
        $path = (string) $file['path'];
        $mime = (string) $file['mime'];
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!verify_csrf($csrf)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'meta') {
        $voucherId = (int) ($_GET['voucher_id'] ?? 0);
        if ($voucherId < 1) {
            echo json_encode([
                'ok' => true,
                'file_count' => 0,
                'read_only' => false,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $meta = fin_voucher_archive_meta($pdo, $kind, $voucherId);
        echo json_encode([
            'ok' => true,
            'file_count' => (int) ($meta['file_count'] ?? 0),
            'read_only' => !empty($meta['read_only']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'list') {
        $voucherId = (int) ($_GET['voucher_id'] ?? 0);
        $files = fin_voucher_archive_list($pdo, $kind, $voucherId);
        $settings = fin_voucher_archive_settings($pdo);
        $readOnly = $voucherId > 0 && fin_voucher_archive_is_read_only($pdo, $kind, $voucherId);
        echo json_encode([
            'ok' => true,
            'files' => $files,
            'file_count' => count($files),
            'read_only' => $readOnly,
            'max_mb' => (int) $settings['document_archive_max_mb'],
            'allowed' => fin_voucher_archive_allowed_extensions(),
            'path_issue' => fin_voucher_archive_path_issue($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $voucherId = (int) ($_POST['voucher_id'] ?? 0);
        $userId = (int) (current_user()['id'] ?? 0);
        $file = $_FILES['file'] ?? [];
        if (!is_array($file)) {
            throw new RuntimeException('لم يُرفَع أي ملف.');
        }
        $saved = fin_voucher_archive_upload($pdo, $kind, $voucherId, $file, $userId);
        echo json_encode([
            'ok' => true,
            'message' => 'تم رفع الملف إلى السيرفر وحفظه في الأرشيف.',
            'file' => $saved,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $docId = (int) ($_POST['id'] ?? 0);
        fin_voucher_archive_delete($pdo, $docId, $kind);
        echo json_encode(['ok' => true, 'message' => 'تم حذف الملف.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير مدعوم.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (in_array($action, ['download', 'view'], true)) {
        http_response_code(404);
        exit($e->getMessage() ?: 'Not found');
    }
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage() ?: 'تعذر إتمام العملية.',
    ], JSON_UNESCAPED_UNICODE);
}
