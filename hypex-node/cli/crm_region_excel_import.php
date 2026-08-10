<?php
declare(strict_types=1);

/**
 * CLI: استيراد Excel لربط العملاء بالمناطق والعناوين والمندوبين.
 * Usage: php crm_region_excel_import.php <userId>
 * stdin JSON: { "path": "C:\\...\\file.xlsx", "replace_reps": true }
 * stdout: JSON سطر واحد
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$userId = (int) ($argv[1] ?? 0);
$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/crm_region_excel_import.php');

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

if ($userId < 1) {
    cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
}

// جلسة مستخدم (صلاحيات)
$u = null;
$queries = [
    'SELECT id, username, full_name_ar AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
    'SELECT id, username, COALESCE(full_name_ar, username) AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
    'SELECT id, username, username AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
];
foreach ($queries as $sql) {
    try {
        $st = db()->prepare($sql);
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $u = $row;
            break;
        }
    } catch (Throwable $e) {
        continue;
    }
}
if (!$u) {
    cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
}
$_SESSION['user'] = [
    'id' => (int) $u['id'],
    'username' => (string) ($u['username'] ?? ''),
    'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
];
$_SESSION['is_system_admin'] = user_is_system_admin((int) $u['id']);
$_SESSION['permissions'] = load_user_permissions((int) $u['id']);
$_SESSION['permissions_user_id'] = (int) $u['id'];
$_SESSION['permissions_loaded_at'] = time();
$_SESSION['app_context'] = 'desktop';

if (
    !user_is_system_admin((int) $u['id'])
    && !user_can('customer_regions')
    && !user_can('customers')
) {
    cli_out(['ok' => false, 'error' => 'لا صلاحية استيراد المناطق/العملاء.'], 1);
}

$path = trim((string) ($payload['path'] ?? $payload['file'] ?? ''));
$replaceReps = !isset($payload['replace_reps']) || $payload['replace_reps'] === true
    || $payload['replace_reps'] === 1 || $payload['replace_reps'] === '1';

if ($path === '' || !is_readable($path)) {
    cli_out(['ok' => false, 'error' => 'ملف Excel غير موجود أو غير قابل للقراءة.'], 1);
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    cli_out(['ok' => false, 'error' => 'يُقبل ملف .xlsx فقط.'], 1);
}

try {
    $result = crm_region_excel_import(db(), $path, $replaceReps);
    if (empty($result['ok'])) {
        cli_out([
            'ok' => false,
            'error' => (string) ($result['message'] ?? 'فشل الاستيراد.'),
            'message' => (string) ($result['message'] ?? 'فشل الاستيراد.'),
            'stats' => $result['stats'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ], 1);
    }
    cli_out([
        'ok' => true,
        'message' => (string) ($result['message'] ?? 'تم الاستيراد.'),
        'stats' => $result['stats'] ?? [],
        'warnings' => $result['warnings'] ?? [],
        'columns' => $result['columns'] ?? [],
        'path' => (string) ($result['path'] ?? $path),
    ]);
} catch (Throwable $e) {
    error_log('crm_region_excel_import cli: ' . $e->getMessage());
    $msg = trim($e->getMessage());
    if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
        $msg = 'تعذر إتمام الاستيراد. تأكد من صحة الملف وأعد المحاولة.';
    }
    cli_out(['ok' => false, 'error' => $msg], 1);
}
