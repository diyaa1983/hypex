<?php
declare(strict_types=1);

/**
 * جلسة تطبيق الهاتف الأصلي (Flutter) — غلاف JSON فوق جلسة الكوكيز الحالية.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_device_session.php');
require_once app_path('includes/mobile_gps_settings.php');
require_once app_path('includes/sys_user_open_session.php');
require_once app_path('includes/list_pagination.php');

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
        $base['is_system_admin'] = user_is_system_admin($uid);
        $base['permissions'] = load_user_mobile_permissions($uid);
        $base['gps_tracking'] = mobile_gps_settings_for_app();
        $base['rows_per_page'] = company_rows_per_page();
    } else {
        $base['user'] = null;
        $base['is_system_admin'] = false;
        $base['permissions'] = [];
        $base['rows_per_page'] = company_rows_per_page();
    }

    return array_merge($base, $extra);
}

/** @param array{message:string} $block */
function mobile_session_device_conflict_response(array $block, bool $endSession = true): void
{
    if ($endSession) {
        logout();
    }
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'device_in_use',
        'message' => $block['message'],
    ], JSON_UNESCAPED_UNICODE);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($method === 'GET' || $action === 'me') {
    if (is_logged_in() && mobile_is_context()) {
        $missing = mobile_device_session_require_id();
        if ($missing !== null) {
            logout();
            echo json_encode([
                'ok' => true,
                'authenticated' => false,
                'csrf' => csrf_token(),
                'user' => null,
                'permissions' => [],
                'session_end_reason' => 'device_id_required',
                'message' => $missing['message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $uid = (int) (current_user()['id'] ?? 0);
        $deviceId = mobile_device_session_id_from_request();
        if ($uid > 0) {
            $pdo = db();
            $block = mobile_device_session_blocking_conflict($pdo, $uid, $deviceId);
            if ($block !== null) {
                logout();
                echo json_encode([
                    'ok' => true,
                    'authenticated' => false,
                    'csrf' => csrf_token(),
                    'user' => null,
                    'permissions' => [],
                    'session_end_reason' => 'device_in_use',
                    'message' => $block['message'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $label = trim((string) ($_GET['device_label'] ?? ''));
            mobile_device_session_touch($pdo, $uid, $deviceId, $label !== '' ? $label : null);
            $token = sys_user_open_session_mobile_token($deviceId);
            if ($token !== '') {
                $_SESSION['open_session_token'] = $token;
                sys_user_open_session_touch($pdo, $token, [
                    'client_label' => $label !== '' ? $label : null,
                ]);
            }
        }
    }
    echo json_encode(mobile_session_payload(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logout') {
    $uid = (int) (current_user()['id'] ?? 0);
    $deviceId = mobile_device_session_id_from_request();
    $pdo = db();
    if ($uid > 0) {
        // تحرير حساب المستخدم بالكامل حتى لا يُمنع من إعادة الدخول من نفس الجهاز.
        mobile_device_session_release($pdo, $uid, $deviceId);
    } elseif ($deviceId !== '') {
        mobile_device_session_release_by_device($pdo, $deviceId);
    }
    logout();
    echo json_encode(['ok' => true, 'authenticated' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$missing = mobile_device_session_require_id();
if ($missing !== null) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $missing['error'],
        'message' => $missing['message'],
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

$uid = (int) (current_user()['id'] ?? 0);
$deviceId = mobile_device_session_id_from_request();
$pdo = db();
$deviceLabel = trim((string) ($_POST['device_label'] ?? ''));
if ($uid < 1 || !mobile_device_session_claim(
    $pdo,
    $uid,
    $deviceId,
    $deviceLabel !== '' ? $deviceLabel : null
)) {
    $block = mobile_device_session_blocking_conflict($pdo, $uid, $deviceId);
    logout();
    mobile_session_device_conflict_response(
        $block ?? ['message' => 'هذا الحساب مستخدم حالياً على جهاز آخر.']
    );
    exit;
}

$prevOpen = (string) ($_SESSION['open_session_token'] ?? '');
if ($prevOpen !== '' && str_starts_with($prevOpen, 'mw:')) {
    sys_user_open_session_close_token($pdo, $prevOpen);
}
sys_user_open_session_register_mobile(
    $uid,
    $deviceId,
    $deviceLabel !== '' ? $deviceLabel : null
);

echo json_encode(mobile_session_payload(), JSON_UNESCAPED_UNICODE);
