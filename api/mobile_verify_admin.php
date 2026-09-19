<?php
declare(strict_types=1);

/**
 * التحقق من بيانات مدير النظام (مجموعة ADMINS) دون تغيير جلسة المندوب الحالية.
 * يُستخدم لقفل إعدادات تتبّع الموقع في تطبيق الهاتف.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method', 'message' => 'الطريقة غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
        'message' => 'الجلسة منتهية. سجّل الدخول من جديد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'missing_credentials',
        'message' => 'أدخل اسم مستخدم المدير وكلمة المرور.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = db()->prepare(
    'SELECT id, username, password_hash, full_name_ar, is_active
     FROM sys_user WHERE username = ? LIMIT 1'
);
$st->execute([$username]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row || !(int) ($row['is_active'] ?? 0)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_credentials',
        'message' => 'بيانات المدير غير صحيحة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!password_verify($password, (string) ($row['password_hash'] ?? ''))) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_credentials',
        'message' => 'بيانات المدير غير صحيحة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId = (int) ($row['id'] ?? 0);
if ($adminId < 1 || !user_is_system_admin($adminId)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'not_admin',
        'message' => 'هذا الحساب ليس ضمن مجموعة مديرو النظام (ADMINS).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'admin' => [
        'id' => $adminId,
        'username' => (string) ($row['username'] ?? ''),
        'name' => (string) ($row['full_name_ar'] ?? $row['username'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE);
