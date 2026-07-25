<?php
declare(strict_types=1);

/**
 * جلسة تطبيق الهاتف الأصلي (Flutter) — غلاف JSON فوق جلسة الكوكيز الحالية.
 * ليس نظام توكن؛ يعتمد كوكي جلسة PHP القياسي (PHPSESSID).
 *
 *   GET  ?action=me      → حالة الجلسة + الصلاحيات + CSRF
 *   POST action=login    → تسجيل الدخول (username, password)
 *   POST action=logout   → إنهاء الجلسة
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $extra */
function mobile_session_payload(array $extra = []): array
{
    $user = current_user();
    $uid = (int) ($user['id'] ?? 0);

    $base = [
        'ok' => true,
        'authenticated' => is_logged_in() && mobile_is_context() && user_in_mobile_group(),
        'csrf' => csrf_token(),
    ];

    if ($base['authenticated'] && $uid > 0) {
        $base['user'] = [
            'id' => $uid,
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['full_name_ar'] ?? $user['username'] ?? ''),
        ];
        $base['permissions'] = load_user_mobile_permissions($uid);
    } else {
        $base['user'] = null;
        $base['permissions'] = [];
    }

    return array_merge($base, $extra);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($method === 'GET' || $action === 'me') {
    echo json_encode(mobile_session_payload(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logout') {
    logout();
    echo json_encode(['ok' => true, 'authenticated' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

// login (الافتراضي لأي POST)
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'missing_credentials',
        'message' => 'أدخل اسم المستخدم وكلمة المرور.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mobile_attempt_login($username, $password)) {
    if (is_logged_in()) {
        logout();
    }
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_credentials',
        'message' => 'بيانات الدخول غير صحيحة، أو حسابك غير مضاف لمجموعة «هاتف».',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(mobile_session_payload(), JSON_UNESCAPED_UNICODE);
