<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/hr_attendance.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('hr_employee_attendance')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'جلسة غير صالحة، أعد تحميل الصفحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maps = $_POST['maps'] ?? [];
if (!is_array($maps)) {
    $maps = [];
}

try {
    $pdo = db();
    if ($maps !== []) {
        $result = hr_attendance_save_manual_maps_batch($pdo, $maps);
    } else {
        $empCodes = $_POST['emp_codes'] ?? [];
        if (!is_array($empCodes)) {
            $empCodes = [];
        }
        $result = hr_attendance_save_manual_maps_by_emp_code_batch($pdo, $empCodes);
    }
    $saved = (int) ($result['saved'] ?? 0);
    $errors = $result['errors'] ?? [];

    if ($saved < 1) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'saved' => 0,
            'errors' => $errors,
            'error' => $errors !== []
                ? implode("\n", array_slice($errors, 0, 5))
                : 'لم يُحفظ أي ربط.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $message = 'تم حفظ ' . $saved . ' ربط.';
    if ($errors !== []) {
        $message .= ' لم يُحفظ ' . count($errors) . ' ربط: ' . implode(' — ', array_slice($errors, 0, 2));
    }

    echo json_encode([
        'ok' => true,
        'saved' => $saved,
        'errors' => $errors,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() ?: 'تعذر حفظ الربط.',
    ], JSON_UNESCAPED_UNICODE);
}
