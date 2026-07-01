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

$zkUserId = (int) ($_POST['zk_user_id'] ?? 0);
$employeeId = (int) ($_POST['employee_id'] ?? 0);

try {
    $pdo = db();
    hr_attendance_save_manual_map($pdo, $zkUserId, $employeeId);
    echo json_encode([
        'ok' => true,
        'message' => 'تم ربط مستخدم البصمة بالموظف.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() ?: 'تعذر حفظ الربط.',
    ], JSON_UNESCAPED_UNICODE);
}
