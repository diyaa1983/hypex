<?php
declare(strict_types=1);

/**
 * عامل Oracle لواجهة Node (بدون واجهة HTML).
 * الاستخدام:
 *   php oracle_customers_sync_run.php --action=status
 *   php oracle_customers_sync_run.php --action=test
 *   php oracle_customers_sync_run.php --action=discover
 *   php oracle_customers_sync_run.php --action=list_tables --table_filter=CUST
 *   php oracle_customers_sync_run.php --action=open_manual --owner=ACCINV --table=CUSTOMER
 *   php oracle_customers_sync_run.php --action=sync --payload-file=C:\tmp\payload.json
 *   php oracle_customers_sync_run.php --action=sync_saved
 *   php oracle_customers_sync_run.php --action=save_config --payload-file=...
 */
$root = dirname(__DIR__, 2);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/oracle_sync_service.php');

header_remove();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$pdo = db();
oracle_customer_schema_ensure($pdo);

$opts = PHP_SAPI === 'cli'
    ? getopt('', [
        'action::',
        'owner::',
        'table::',
        'table_filter::',
        'payload-file::',
        'map_oracle_key::',
        'map_code::',
        'map_name::',
        'map_phone::',
        'map_email::',
        'map_tax::',
        'map_address::',
        'map_active::',
    ])
    : [];

$action = PHP_SAPI === 'cli'
    ? (string) ($opts['action'] ?? 'status')
    : (string) ($_GET['action'] ?? $_POST['action'] ?? 'status');

$payload = [];
$payloadFile = PHP_SAPI === 'cli'
    ? (string) ($opts['payload-file'] ?? '')
    : '';
if ($payloadFile !== '' && is_file($payloadFile)) {
    $raw = file_get_contents($payloadFile);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$owner = strtoupper(trim((string) ($payload['owner'] ?? $opts['owner'] ?? $_POST['owner'] ?? '')));
$table = strtoupper(trim((string) ($payload['table'] ?? $opts['table'] ?? $_POST['table'] ?? '')));
$tableFilter = trim((string) ($payload['table_filter'] ?? $opts['table_filter'] ?? $_POST['table_filter'] ?? ''));

$mapIn = is_array($payload['map'] ?? null) ? $payload['map'] : [];
$map = [
    'oracle_key' => strtoupper(trim((string) ($mapIn['oracle_key'] ?? $opts['map_oracle_key'] ?? ''))),
    'code' => strtoupper(trim((string) ($mapIn['code'] ?? $opts['map_code'] ?? ''))),
    'name_ar' => strtoupper(trim((string) ($mapIn['name_ar'] ?? $opts['map_name'] ?? ''))),
    'phone' => strtoupper(trim((string) ($mapIn['phone'] ?? $opts['map_phone'] ?? ''))),
    'email' => strtoupper(trim((string) ($mapIn['email'] ?? $opts['map_email'] ?? ''))),
    'tax_number' => strtoupper(trim((string) ($mapIn['tax_number'] ?? $opts['map_tax'] ?? ''))),
    'address_ar' => strtoupper(trim((string) ($mapIn['address_ar'] ?? $opts['map_address'] ?? ''))),
    'is_active' => strtoupper(trim((string) ($mapIn['is_active'] ?? $opts['map_active'] ?? ''))),
];

$out = [
    'ok' => false,
    'action' => $action,
    'message' => '',
    'enabled' => oracle_is_enabled(),
    'has_driver' => oracle_php_has_oracle_driver(),
    'drivers' => oracle_php_drivers_status(),
    'config' => null,
    'mapping' => null,
    'candidate_tables' => [],
    'app_tables' => [],
    'describe_cols' => [],
    'preview_rows' => [],
    'owner' => $owner,
    'table' => $table,
    'table_filter' => $tableFilter,
    'map' => $map,
    'sync' => null,
    'driver' => '',
];

$cfg = oracle_config();
$cfgPath = (string) ($cfg['_path'] ?? app_path('config/oracle.local.php'));
$out['config'] = [
    'path' => $cfgPath,
    'file_exists' => is_file($cfgPath),
    'enabled' => !empty($cfg['enabled']),
    'host' => (string) ($cfg['host'] ?? ''),
    'port' => (int) ($cfg['port'] ?? 1521),
    'sid' => (string) ($cfg['sid'] ?? ''),
    'service_name' => (string) ($cfg['service_name'] ?? ''),
    'user' => (string) ($cfg['user'] ?? ''),
    'charset' => (string) ($cfg['charset'] ?? 'AL32UTF8'),
    'odbc_dsn' => (string) ($cfg['odbc_dsn'] ?? ''),
    'has_password' => trim((string) ($cfg['pass'] ?? '')) !== '',
    'status_message' => oracle_is_enabled() ? '' : oracle_config_status_message(),
];

$mapSaved = oracle_customers_saved_mapping();
if ($mapSaved) {
    $out['mapping'] = [
        'owner' => $mapSaved['owner'],
        'table' => $mapSaved['table'],
        'columns' => $mapSaved['columns'],
        'last_synced_at' => $mapSaved['last_synced_at'] ?? '',
    ];
    if ($owner === '') {
        $owner = $mapSaved['owner'];
        $out['owner'] = $owner;
    }
    if ($table === '') {
        $table = $mapSaved['table'];
        $out['table'] = $table;
    }
    foreach ($map as $k => $v) {
        if ($v === '' && !empty($mapSaved['columns'][$k])) {
            $map[$k] = $mapSaved['columns'][$k];
        }
    }
    $out['map'] = $map;
}

$suggest = static function (array $cols, array $needles): string {
    foreach ($cols as $c) {
        $n = strtoupper((string) ($c['column_name'] ?? ''));
        foreach ($needles as $needle) {
            if (str_contains($n, $needle)) {
                return $n;
            }
        }
    }

    return '';
};

try {
    if ($action === 'status') {
        $out['ok'] = true;
        $out['message'] = $mapSaved
            ? ('تعيين محفوظ: ' . $mapSaved['owner'] . '.' . $mapSaved['table'])
            : 'لا يوجد تعيين محفوظ بعد.';
    } elseif ($action === 'save_config') {
        $cfgIn = is_array($payload['config'] ?? null) ? $payload['config'] : $payload;
        $path = oracle_write_local_config([
            'enabled' => !empty($cfgIn['enabled']),
            'host' => (string) ($cfgIn['host'] ?? ''),
            'port' => (int) ($cfgIn['port'] ?? 1521),
            'sid' => (string) ($cfgIn['sid'] ?? ''),
            'service_name' => (string) ($cfgIn['service_name'] ?? ''),
            'user' => (string) ($cfgIn['user'] ?? ''),
            'pass' => (string) ($cfgIn['pass'] ?? ''),
            'charset' => (string) ($cfgIn['charset'] ?? 'AL32UTF8'),
            'odbc_dsn' => (string) ($cfgIn['odbc_dsn'] ?? ''),
        ]);
        $out['ok'] = true;
        $out['message'] = "تم حفظ الإعداد بنجاح:\n" . $path
            . "\nالحالة الآن: " . (oracle_is_enabled() ? 'مفعّل' : 'غير مفعّل — راجع الحقول');
        if (!oracle_php_has_oracle_driver()) {
            $out['message'] .= "\nتنبيه: مشغّل OCI غير محمّل على هذا الجهاز — ثبّت Instant Client + pdo_oci/oci8.";
        }
        $cfg = oracle_config(true);
        $out['enabled'] = oracle_is_enabled();
        $out['has_driver'] = oracle_php_has_oracle_driver();
        $out['config']['path'] = $path;
        $out['config']['file_exists'] = true;
        $out['config']['enabled'] = !empty($cfg['enabled']);
        $out['config']['host'] = (string) ($cfg['host'] ?? '');
        $out['config']['port'] = (int) ($cfg['port'] ?? 1521);
        $out['config']['sid'] = (string) ($cfg['sid'] ?? '');
        $out['config']['service_name'] = (string) ($cfg['service_name'] ?? '');
        $out['config']['user'] = (string) ($cfg['user'] ?? '');
        $out['config']['has_password'] = trim((string) ($cfg['pass'] ?? '')) !== '';
    } else {
        $connInfo = oracle_connect();
        if (!$connInfo['ok']) {
            $out['ok'] = false;
            $out['message'] = (string) ($connInfo['message'] ?? 'فشل الاتصال');
            $out['driver'] = (string) ($connInfo['driver'] ?? '');
        } else {
            $out['driver'] = (string) ($connInfo['driver'] ?? '');
            if ($action === 'test') {
                $out['ok'] = true;
                $out['message'] = 'نجح الاتصال — المشغّل: ' . $out['driver'] . ' — ' . (string) ($connInfo['message'] ?? '');
                $one = oracle_query_all($connInfo, 'SELECT USER AS U, BANNER FROM V$VERSION WHERE ROWNUM = 1');
                if ($one) {
                    $out['message'] .= ' | USER=' . ($one[0]['U'] ?? $one[0]['u'] ?? '')
                        . ' | ' . ($one[0]['BANNER'] ?? $one[0]['banner'] ?? '');
                }
            } elseif ($action === 'discover') {
                $out['candidate_tables'] = oracle_discover_customer_tables($connInfo);
                $out['ok'] = true;
                $out['message'] = 'عُثر على ' . count($out['candidate_tables']) . ' جدول مرشّح لاسمّ يحتوي CUST/CLIENT/…';
            } elseif ($action === 'list_tables') {
                $out['app_tables'] = oracle_list_app_tables($connInfo, $tableFilter, 500);
                $out['ok'] = true;
                $out['message'] = 'عُرض ' . count($out['app_tables']) . ' جدول تطبيق'
                    . ($tableFilter !== '' ? ' (فلتر: ' . $tableFilter . ')' : '')
                    . '. اختر الجدول أو أدخل المالك.الاسم يدوياً.';
            } elseif ($action === 'open_manual' || $action === 'describe') {
                if ($owner === '' || $table === '') {
                    $out['message'] = 'أدخل Schema (owner) واسم الجدول.';
                } else {
                    $out['describe_cols'] = oracle_describe_table($connInfo, $owner, $table);
                    $out['preview_rows'] = oracle_preview_table($connInfo, $owner, $table, 15);
                    if ($map['oracle_key'] === '') {
                        $map['oracle_key'] = $suggest($out['describe_cols'], ['CUST_ID', 'CUSTOMER_ID', 'CLIENT_ID', 'CUS_NUM', 'ID_NO', 'CODE', 'NO'])
                            ?: (string) ($out['describe_cols'][0]['column_name'] ?? '');
                    }
                    if ($map['code'] === '') {
                        $map['code'] = $suggest($out['describe_cols'], ['CUS_NUM', 'CODE', 'NO', 'NUM', 'رقم']);
                    }
                    if ($map['name_ar'] === '') {
                        $map['name_ar'] = $suggest($out['describe_cols'], ['NAME', 'NAME_A', 'اسم', 'CNAME']);
                    }
                    if ($map['phone'] === '') {
                        $map['phone'] = $suggest($out['describe_cols'], ['PHONE', 'TEL', 'MOBILE', 'هاتف']);
                    }
                    if ($map['tax_number'] === '') {
                        $map['tax_number'] = $suggest($out['describe_cols'], ['TAX', 'VAT', 'ضريب']);
                    }
                    if ($map['address_ar'] === '') {
                        $map['address_ar'] = $suggest($out['describe_cols'], ['ADDR', 'ADDRESS', 'عنوان']);
                    }
                    $out['map'] = $map;
                    $out['owner'] = $owner;
                    $out['table'] = $table;
                    $out['ok'] = true;
                    $out['message'] = 'هيكل ' . $owner . '.' . $table . ' — ' . count($out['describe_cols']) . ' عمود.';
                }
            } elseif ($action === 'sync') {
                if ($owner === '' || $table === '') {
                    $out['message'] = 'أدخل Schema واسم الجدول.';
                } elseif ($map['oracle_key'] === '' && $map['code'] === '') {
                    $out['message'] = 'اربط على الأقل oracle_key أو code (رقم العميل).';
                } else {
                    if ($map['oracle_key'] === '') {
                        $map['oracle_key'] = $map['code'];
                    }
                    $sync = oracle_sync_customers_to_mysql($pdo, $connInfo, $owner, $table, $map);
                    $out['sync'] = $sync;
                    $out['ok'] = empty($sync['errors']);
                    $out['message'] = 'انتهت المزامنة: جديد ' . (int) ($sync['inserted'] ?? 0)
                        . ' | محدّث ' . (int) ($sync['updated'] ?? 0)
                        . ' | متجاوز ' . (int) ($sync['skipped'] ?? 0);
                    if (!empty($sync['errors'])) {
                        $out['message'] .= "\n" . implode("\n", array_slice($sync['errors'], 0, 15));
                    } elseif (((int) ($sync['inserted'] ?? 0) + (int) ($sync['updated'] ?? 0)) > 0) {
                        try {
                            oracle_customers_save_mapping($owner, $table, $map);
                            $out['message'] .= "\nتم حفظ التعيين — يمكن التحديث لاحقاً من زر المزامنة المحفوظة.";
                            $mapSaved = oracle_customers_saved_mapping();
                            if ($mapSaved) {
                                $out['mapping'] = [
                                    'owner' => $mapSaved['owner'],
                                    'table' => $mapSaved['table'],
                                    'columns' => $mapSaved['columns'],
                                    'last_synced_at' => $mapSaved['last_synced_at'] ?? '',
                                ];
                            }
                        } catch (Throwable $e) {
                            $out['message'] .= "\n(تحذير) لم يُحفظ التعيين: " . $e->getMessage();
                        }
                    }
                    $out['describe_cols'] = oracle_describe_table($connInfo, $owner, $table);
                    $out['preview_rows'] = oracle_preview_table($connInfo, $owner, $table, 15);
                    $out['owner'] = $owner;
                    $out['table'] = $table;
                    $out['map'] = $map;
                }
            } elseif ($action === 'sync_saved') {
                $sync = oracle_sync_customers_from_saved_config($pdo);
                $out['sync'] = $sync;
                $out['ok'] = empty($sync['errors']);
                $out['message'] = 'مزامنة محفوظة: جديد ' . (int) ($sync['inserted'] ?? 0)
                    . ' | محدّث ' . (int) ($sync['updated'] ?? 0)
                    . ' | متجاوز ' . (int) ($sync['skipped'] ?? 0);
                if (!empty($sync['errors'])) {
                    $out['message'] .= "\n" . implode("\n", array_slice($sync['errors'], 0, 15));
                }
            } else {
                $out['message'] = 'إجراء غير معروف: ' . $action;
            }
        }
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['message'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo PHP_EOL;
