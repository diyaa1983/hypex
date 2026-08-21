<?php
declare(strict_types=1);

/**
 * إعداد وتشغيل المزامنة التلقائية لعملاء Oracle.
 *
 * @return array{enabled:bool,interval_minutes:int,entities:string,last_run_at:string,last_ok:bool|null,last_message:string}
 */
function oracle_customers_auto_sync_settings(): array
{
    $cfg = oracle_config();
    $a = is_array($cfg['auto_sync'] ?? null) ? $cfg['auto_sync'] : [];
    $interval = (int) ($a['interval_minutes'] ?? 5);
    if ($interval < 1) {
        $interval = 1;
    }
    if ($interval > 1440) {
        $interval = 1440;
    }
    $entities = trim((string) ($a['entities'] ?? 'customers'));
    if ($entities === '') {
        $entities = 'customers';
    }

    return [
        'enabled' => !empty($a['enabled']),
        'interval_minutes' => $interval,
        'entities' => $entities,
        'last_run_at' => (string) ($a['last_run_at'] ?? ''),
        'last_ok' => array_key_exists('last_ok', $a) ? (bool) $a['last_ok'] : null,
        'last_message' => (string) ($a['last_message'] ?? ''),
    ];
}

/**
 * حفظ إعداد المزامنة التلقائية داخل oracle.local.php.
 *
 * @param array{enabled?:bool,interval_minutes?:int,entities?:string} $input
 * @return array{ok:bool,message:string,settings:array}
 */
function oracle_customers_auto_sync_save(array $input): array
{
    $path = app_path('config' . DIRECTORY_SEPARATOR . 'oracle.local.php');
    $existing = [];
    if (is_file($path)) {
        clearstatcache(true, $path);
        $prev = include $path;
        if (is_array($prev)) {
            $existing = $prev;
        }
    }
    $cur = oracle_customers_auto_sync_settings();
    $enabled = array_key_exists('enabled', $input) ? !empty($input['enabled']) : $cur['enabled'];
    $interval = (int) ($input['interval_minutes'] ?? $cur['interval_minutes']);
    if ($interval < 1) {
        $interval = 1;
    }
    if ($interval > 1440) {
        $interval = 1440;
    }
    $entities = trim((string) ($input['entities'] ?? $cur['entities']));
    if ($entities === '') {
        $entities = 'customers';
    }

    $token = trim((string) ($existing['sync_token'] ?? ''));
    if ($token === '' || str_starts_with($token, 'CHANGE')) {
        $token = bin2hex(random_bytes(24));
    }
    $existing['sync_token'] = $token;

    // إن لم يكن تعيين العملاء محفوظاً — الافتراضي الشائع ACCINV.CUSTOMER
    $cust = is_array($existing['customers'] ?? null) ? $existing['customers'] : [];
    $owner = strtoupper(trim((string) ($cust['owner'] ?? '')));
    $table = strtoupper(trim((string) ($cust['table'] ?? '')));
    $cols = is_array($cust['columns'] ?? null) ? $cust['columns'] : [];
    if ($enabled && ($owner === '' || $table === '' || $cols === [])) {
        $existing['customers'] = array_merge($cust, [
            'owner' => 'ACCINV',
            'table' => 'CUSTOMER',
            'columns' => [
                'oracle_key' => 'CUS_NUM',
                'code' => 'CUS_NUM',
                'name_ar' => 'CUS_TITLE',
            ],
        ]);
    }

    $existing['auto_sync'] = [
        'enabled' => $enabled,
        'interval_minutes' => $interval,
        'entities' => $entities,
        'last_run_at' => (string) ($cur['last_run_at'] ?? ''),
        'last_ok' => $cur['last_ok'],
        'last_message' => (string) ($cur['last_message'] ?? ''),
    ];
    unset($existing['_path'], $existing['_missing_file'], $existing['_bad_file']);

    $php = "<?php\ndeclare(strict_types=1);\n\n// مولَّد/محدَّث من شاشة تكامل Oracle\nreturn "
        . var_export($existing, true) . ";\n";
    if (@file_put_contents($path, $php) === false) {
        return [
            'ok' => false,
            'message' => 'تعذّر كتابة الإعداد: ' . $path,
            'settings' => $cur,
        ];
    }
    oracle_config(true);

    return [
        'ok' => true,
        'message' => $enabled
            ? ('تم تفعيل المزامنة التلقائية كل ' . $interval . ' دقيقة.')
            : 'تم إيقاف المزامنة التلقائية.',
        'settings' => oracle_customers_auto_sync_settings(),
    ];
}

/**
 * تحديث نتيجة آخر تشغيل تلقائي.
 */
function oracle_customers_auto_sync_mark_run(bool $ok, string $message): void
{
    $path = app_path('config' . DIRECTORY_SEPARATOR . 'oracle.local.php');
    if (!is_file($path)) {
        return;
    }
    clearstatcache(true, $path);
    $existing = include $path;
    if (!is_array($existing)) {
        return;
    }
    $a = is_array($existing['auto_sync'] ?? null) ? $existing['auto_sync'] : [];
    $a['last_run_at'] = date('Y-m-d H:i:s');
    $a['last_ok'] = $ok;
    $a['last_message'] = mb_substr(trim($message), 0, 500);
    $existing['auto_sync'] = $a;
    unset($existing['_path'], $existing['_missing_file'], $existing['_bad_file']);
    $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($existing, true) . ";\n";
    @file_put_contents($path, $php);
    oracle_config(true);
}

/**
 * تشغيل مزامنة العملاء المحفوظة (يدوي أو تلقائي).
 *
 * @return array<string, mixed>
 */
function oracle_customers_run_saved_sync(PDO $pdo): array
{
    require_once app_path('includes/oracle_sync_service.php');
    require_once app_path('includes/oracle_customer_sync.php');
    oracle_customer_schema_ensure($pdo);
    $result = oracle_run_continuous_sync($pdo, ['customers']);
    $ok = !empty($result['ok']);
    $cust = is_array($result['customers'] ?? null) ? $result['customers'] : [];
    $msg = $ok
        ? ('مزامنة عملاء: جديد ' . (int) ($cust['inserted'] ?? 0)
            . ' · محدّث ' . (int) ($cust['updated'] ?? 0)
            . ' · متجاوز ' . (int) ($cust['skipped'] ?? 0))
        : ('فشل المزامنة: ' . implode(' | ', array_slice((array) ($result['errors'] ?? ['خطأ غير معروف']), 0, 5)));
    oracle_customers_auto_sync_mark_run($ok, $msg);
    $result['message'] = $msg;

    return $result;
}
