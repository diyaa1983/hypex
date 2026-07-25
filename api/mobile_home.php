<?php
declare(strict_types=1);

/**
 * الرئيسية لتطبيق الهاتف الأصلي (Flutter): مربعات الشاشات المسموح بها + بيانات الشركة.
 * يعتمد جلسة الكوكيز الحالية (مثل بقية /api).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/document_header.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
        'message' => 'الجلسة منتهية. سجّل الدخول من جديد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $user = current_user();
    $uid = (int) ($user['id'] ?? 0);

    $tiles = array_map(static function (array $tile): array {
        return [
            'code' => (string) ($tile['code'] ?? ''),
            'label' => (string) ($tile['label'] ?? ''),
            'icon' => (string) ($tile['icon'] ?? 'invoice'),
            'kind' => (string) ($tile['kind'] ?? 'doc'),
        ];
    }, mobile_home_launcher_tiles());

    $brand = document_header_brand($pdo);

    echo json_encode([
        'ok' => true,
        'company_name' => (string) ($brand['company_name_ar'] ?? 'النظام المحاسبي'),
        'logo_url' => $brand['logo_url'] ?? null,
        'user' => [
            'id' => $uid,
            'name' => (string) ($user['full_name_ar'] ?? $user['username'] ?? ''),
        ],
        'permissions' => load_user_mobile_permissions($uid),
        'tiles' => $tiles,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_home: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل الرئيسية.',
    ], JSON_UNESCAPED_UNICODE);
}
