<?php
declare(strict_types=1);

/**
 * اتصال Oracle لتكامل العملاء (قراءة).
 *
 * يتطلب على السيرفر أحد: pdo_oci | oci8 | pdo_odbc مع Oracle ODBC.
 */

/**
 * @return array<string, mixed>
 */
function oracle_config(bool $reload = false): array
{
    static $cfg = null;
    if ($reload) {
        $cfg = null;
    }
    if (is_array($cfg)) {
        return $cfg;
    }
    $file = app_path('config' . DIRECTORY_SEPARATOR . 'oracle.local.php');
    if (!is_file($file)) {
        $cfg = ['enabled' => false, '_missing_file' => true, '_path' => $file];

        return $cfg;
    }
    // require لا يعيد تحميل الملف إذا كان مُضمَّناً مسبقاً — استخدم include مع clearstatcache
    clearstatcache(true, $file);
    /** @var mixed $raw */
    $raw = include $file;
    if (!is_array($raw)) {
        $cfg = ['enabled' => false, '_bad_file' => true, '_path' => $file];

        return $cfg;
    }
    $raw['_path'] = $file;
    $cfg = $raw;

    return $cfg;
}

/**
 * حفظ إعداد Oracle المحلي (لا يُرفع لـ Git).
 *
 * @param array<string, mixed> $input
 */
function oracle_write_local_config(array $input): string
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

    $customers = is_array($existing['customers'] ?? null) ? $existing['customers'] : [];
    $data = [
        'enabled' => !empty($input['enabled']),
        'host' => trim((string) ($input['host'] ?? '')),
        'port' => (int) ($input['port'] ?? 1521),
        'sid' => trim((string) ($input['sid'] ?? '')),
        'service_name' => trim((string) ($input['service_name'] ?? '')),
        'user' => trim((string) ($input['user'] ?? '')),
        'pass' => (string) ($input['pass'] ?? ($existing['pass'] ?? '')),
        'charset' => trim((string) ($input['charset'] ?? 'AL32UTF8')) ?: 'AL32UTF8',
        'odbc_dsn' => trim((string) ($input['odbc_dsn'] ?? ($existing['odbc_dsn'] ?? ''))),
        'customers' => $customers,
    ];

    if ($data['host'] === '' || $data['user'] === '') {
        throw new InvalidArgumentException('host و user مطلوبان.');
    }
    if ($data['sid'] === '' && $data['service_name'] === '') {
        throw new InvalidArgumentException('أدخل sid أو service_name.');
    }

    $export = var_export($data, true);
    $php = "<?php\ndeclare(strict_types=1);\n\n// مولَّد من شاشة تكامل Oracle — لا ترفع هذا الملف إلى Git.\nreturn " . $export . ";\n";
    if (@file_put_contents($path, $php) === false) {
        throw new RuntimeException('تعذّر كتابة الملف: ' . $path . ' — تحقق من صلاحيات المجلد config.');
    }
    oracle_config(true);

    return $path;
}

/** حالة مشغّلات PHP لاتصال Oracle */
function oracle_php_drivers_status(): array
{
    return [
        'pdo_oci' => extension_loaded('pdo_oci'),
        'oci8' => extension_loaded('oci8') || function_exists('oci_connect'),
        'pdo_odbc' => extension_loaded('pdo_odbc'),
    ];
}

function oracle_php_has_oracle_driver(): bool
{
    $d = oracle_php_drivers_status();

    return !empty($d['pdo_oci']) || !empty($d['oci8']);
}

function oracle_is_enabled(): bool
{
    $cfg = oracle_config();

    return !empty($cfg['enabled'])
        && trim((string) ($cfg['host'] ?? '')) !== ''
        && trim((string) ($cfg['user'] ?? '')) !== '';
}

/** رسالة تشخيص عند فشل التفعيل/الإعداد */
function oracle_config_status_message(): string
{
    $cfg = oracle_config();
    $path = (string) ($cfg['_path'] ?? app_path('config/oracle.local.php'));
    if (!empty($cfg['_missing_file'])) {
        return 'ملف الإعداد غير موجود. أنشئ الملف:' . "\n" . $path
            . "\n" . 'انسخ من config/oracle.local.example.php وعدّل host/user/pass/sid.';
    }
    if (!empty($cfg['_bad_file'])) {
        return 'ملف oracle.local.php لا يُرجع مصفوفة PHP صحيحة: ' . $path;
    }
    if (empty($cfg['enabled'])) {
        return 'Oracle معطل في الإعداد (enabled => false) داخل: ' . $path;
    }
    if (trim((string) ($cfg['host'] ?? '')) === '' || trim((string) ($cfg['user'] ?? '')) === '') {
        return 'host أو user فارغ في: ' . $path;
    }

    return 'الإعداد موجود — المسار: ' . $path;
}

function oracle_tns_descriptor(array $cfg): string
{
    $host = trim((string) ($cfg['host'] ?? '127.0.0.1'));
    $port = (int) ($cfg['port'] ?? 1521);
    $sid = trim((string) ($cfg['sid'] ?? ''));
    $service = trim((string) ($cfg['service_name'] ?? ''));

    $connectData = $service !== ''
        ? '(SERVICE_NAME=' . $service . ')'
        : '(SID=' . ($sid !== '' ? $sid : 'ORCL') . ')';

    return '(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=' . $host . ')(PORT=' . $port . '))(CONNECT_DATA=' . $connectData . '))';
}

/**
 * فحص سريع أن المنفذ 1521 يستجيب قبل انتظار oci_connect طويلاً.
 *
 * @return array{ok:bool, message:string}
 */
function oracle_tcp_probe(string $host, int $port, float $timeoutSec = 5.0): array
{
    $host = trim($host);
    if ($host === '' || $port < 1) {
        return ['ok' => false, 'message' => 'host/port غير صالح.'];
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    if ($fp === false) {
        return [
            'ok' => false,
            'message' => "تعذّر الوصول إلى {$host}:{$port} خلال {$timeoutSec}ث "
                . "(errno={$errno}" . ($errstr !== '' ? " {$errstr}" : '') . ").\n"
                . "تحقق: جدار ناري / الشبكة / أن مستمع Oracle يعمل على dbserver.",
        ];
    }
    fclose($fp);

    return ['ok' => true, 'message' => "المنفذ {$host}:{$port} مفتوح."];
}

/**
 * @return array{ok:bool, driver:string, message:string, pdo:?PDO, oci:mixed}
 */
function oracle_connect(): array
{
    if (!oracle_is_enabled()) {
        return [
            'ok' => false,
            'driver' => '',
            'message' => oracle_config_status_message(),
            'pdo' => null,
            'oci' => null,
        ];
    }

    $cfg = oracle_config();
    $user = (string) $cfg['user'];
    $pass = (string) ($cfg['pass'] ?? '');
    $tns = oracle_tns_descriptor($cfg);
    $charset = (string) ($cfg['charset'] ?? 'AL32UTF8');
    $host = trim((string) ($cfg['host'] ?? ''));
    $port = (int) ($cfg['port'] ?? 1521);

    @set_time_limit(45);
    $tcp = oracle_tcp_probe($host, $port, 5.0);
    if (!$tcp['ok']) {
        return [
            'ok' => false,
            'driver' => '',
            'message' => $tcp['message'],
            'pdo' => null,
            'oci' => null,
        ];
    }

    // 1) PDO_OCI
    if (extension_loaded('pdo_oci')) {
        try {
            $dsn = 'oci:dbname=' . $tns . ';charset=' . $charset;
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 15,
            ]);

            return [
                'ok' => true,
                'driver' => 'pdo_oci',
                'message' => 'تم الاتصال عبر PDO_OCI. ' . $tcp['message'],
                'pdo' => $pdo,
                'oci' => null,
            ];
        } catch (Throwable $e) {
            $pdoErr = $e->getMessage();
        }
    } else {
        $pdoErr = 'امتداد pdo_oci غير محمّل';
    }

    // 2) OCI8
    if (function_exists('oci_connect')) {
        $conn = @oci_connect($user, $pass, $tns, $charset);
        if ($conn) {
            if (function_exists('oci_set_call_timeout')) {
                @oci_set_call_timeout($conn, 15000);
            }

            return [
                'ok' => true,
                'driver' => 'oci8',
                'message' => 'تم الاتصال عبر OCI8. ' . $tcp['message'],
                'pdo' => null,
                'oci' => $conn,
            ];
        }
        $ociErr = function_exists('oci_error') ? (oci_error()['message'] ?? 'oci_connect failed') : 'oci_connect failed';
    } else {
        $ociErr = 'امتداد oci8 غير محمّل';
    }

    // 3) PDO_ODBC (DSN اختياري في الإعداد)
    $odbcDsn = trim((string) ($cfg['odbc_dsn'] ?? ''));
    if ($odbcDsn !== '' && extension_loaded('pdo_odbc')) {
        try {
            $pdo = new PDO('odbc:' . $odbcDsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return [
                'ok' => true,
                'driver' => 'pdo_odbc',
                'message' => 'تم الاتصال عبر ODBC.',
                'pdo' => $pdo,
                'oci' => null,
            ];
        } catch (Throwable $e) {
            $odbcErr = $e->getMessage();
        }
    } else {
        $odbcErr = $odbcDsn === '' ? 'لم يُضبط odbc_dsn' : 'امتداد pdo_odbc غير محمّل';
    }

    $drivers = oracle_php_drivers_status();
    $hint = '';
    if (!$drivers['pdo_oci'] && !$drivers['oci8']) {
        $hint = "\n"
            . "سبب شائع: PHP لا يحتوي امتداد Oracle (pdo_oci / oci8).\n"
            . "الخطوات على جهاز Hypex (ليس جهاز Oracle Forms):\n"
            . "1) ثبّت Oracle Instant Client (Basic) لنظام 64-bit.\n"
            . "2) أضف مجلد Instant Client إلى PATH ثم أعد تشغيل Apache.\n"
            . "3) فعّل php_pdo_oci.dll أو php_oci8 في php.ini (XAMPP).\n"
            . "4) اختياري: اضبط odbc_dsn إن كان لديك Oracle ODBC + pdo_odbc.";
    }

    return [
        'ok' => false,
        'driver' => '',
        'message' => 'الإعداد مفعّل، لكن فشل الاتصال بـ Oracle.'
            . "\n" . 'PDO_OCI: ' . ($pdoErr ?? '')
            . "\n" . 'OCI8: ' . ($ociErr ?? '')
            . "\n" . 'ODBC: ' . ($odbcErr ?? '')
            . $hint,
        'pdo' => null,
        'oci' => null,
    ];
}

/**
 * @param array{pdo:?PDO, oci:mixed} $conn
 * @return list<array<string, mixed>>
 */
function oracle_query_all(array $conn, string $sql, array $binds = []): array
{
    if (!empty($conn['pdo']) && $conn['pdo'] instanceof PDO) {
        $st = $conn['pdo']->prepare($sql);
        foreach ($binds as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with((string) $k, ':') ? (string) $k : ':' . $k);
            $st->bindValue($name, $v);
        }
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (!empty($conn['oci'])) {
        $st = oci_parse($conn['oci'], $sql);
        if ($st === false) {
            $err = oci_error($conn['oci']);
            throw new RuntimeException('oci_parse: ' . ($err['message'] ?? 'error'));
        }
        $bindVars = [];
        foreach ($binds as $k => $v) {
            $name = is_int($k) ? (string) ($k + 1) : ltrim((string) $k, ':');
            $bindVars[$name] = $v;
        }
        foreach ($bindVars as $name => &$val) {
            oci_bind_by_name($st, ':' . $name, $val);
        }
        unset($val);
        if (!@oci_execute($st)) {
            $err = oci_error($st);
            throw new RuntimeException('oci_execute: ' . ($err['message'] ?? 'error'));
        }
        $rows = [];
        while (($row = oci_fetch_assoc($st)) !== false) {
            $norm = [];
            foreach ($row as $col => $val) {
                if (is_object($val) && method_exists($val, 'load')) {
                    $val = $val->load();
                }
                $norm[(string) $col] = $val;
            }
            $rows[] = $norm;
        }

        return $rows;
    }

    throw new RuntimeException('لا يوجد اتصال Oracle فعّال.');
}

/**
 * جداول مرشّحة لمزامنة العملاء.
 *
 * @return list<array{owner:string, table_name:string}>
 */
function oracle_discover_customer_tables(array $conn): array
{
    $sql = "SELECT owner, table_name
            FROM all_tables
            WHERE owner NOT IN (
                'SYS','SYSTEM','OUTLN','DBSNMP','WMSYS','XDB','CTXSYS','MDSYS','ORDSYS','ORDDATA',
                'OLAPSYS','EXFSYS','DMSYS','TSMSYS','ANONYMOUS','SI_INFORMTN_SCHEMA','MGMT_VIEW'
              )
              AND (
                UPPER(table_name) LIKE '%CUST%'
                OR UPPER(table_name) LIKE '%CLIENT%'
                OR UPPER(table_name) LIKE '%CUSTOMER%'
                OR UPPER(table_name) LIKE '%CUS\\_%' ESCAPE '\\'
                OR UPPER(table_name) LIKE 'C\\_%CUSTOMER%' ESCAPE '\\'
                OR UPPER(table_name) LIKE '%DEBTOR%'
                OR UPPER(table_name) LIKE '%PARTY%'
                OR UPPER(table_name) LIKE '%ACCOUNT%'
                OR UPPER(table_name) LIKE '%زبون%'
                OR UPPER(table_name) LIKE '%عميل%'
              )
            ORDER BY owner, table_name";

    try {
        $rows = oracle_query_all($conn, $sql);
    } catch (Throwable $e) {
        $rows = oracle_query_all(
            $conn,
            "SELECT owner, table_name FROM dba_tables
             WHERE owner NOT IN ('SYS','SYSTEM','OUTLN','DBSNMP')
               AND (UPPER(table_name) LIKE '%CUST%' OR UPPER(table_name) LIKE '%CLIENT%' OR UPPER(table_name) LIKE '%CUSTOMER%')
             ORDER BY owner, table_name"
        );
    }

    return oracle_normalize_owner_table_rows($rows);
}

/**
 * جميع جداول التطبيق (غير النظام) مع فلتر اختياري على الاسم.
 *
 * @return list<array{owner:string, table_name:string}>
 */
function oracle_list_app_tables(array $conn, string $nameFilter = '', int $limit = 400): array
{
    $limit = max(50, min(1000, $limit));
    $filter = strtoupper(trim($nameFilter));
    $binds = [];
    $whereExtra = '';
    if ($filter !== '') {
        $whereExtra = ' AND UPPER(table_name) LIKE :nf ';
        $binds['nf'] = '%' . $filter . '%';
    }
    $sql = "SELECT owner, table_name
            FROM all_tables
            WHERE owner NOT IN (
                'SYS','SYSTEM','OUTLN','DBSNMP','WMSYS','XDB','CTXSYS','MDSYS','ORDSYS','ORDDATA',
                'OLAPSYS','EXFSYS','DMSYS','TSMSYS','ANONYMOUS','SI_INFORMTN_SCHEMA','MGMT_VIEW'
              )
            {$whereExtra}
            ORDER BY owner, table_name";
    // ROWNUM wrapper for Oracle 10g
    $wrapped = 'SELECT * FROM (' . $sql . ') WHERE ROWNUM <= ' . $limit;
    try {
        $rows = oracle_query_all($conn, $wrapped, $binds);
    } catch (Throwable $e) {
        $rows = oracle_query_all(
            $conn,
            "SELECT owner, table_name FROM user_tables
             WHERE 1=1 {$whereExtra}
             ORDER BY table_name",
            $binds
        );
        // user_tables has no OWNER — fill current user
        $u = oracle_query_all($conn, 'SELECT USER AS U FROM dual');
        $owner = (string) ($u[0]['U'] ?? $u[0]['u'] ?? 'UNKNOWN');
        foreach ($rows as &$r) {
            if (!isset($r['OWNER']) && !isset($r['owner'])) {
                $r['OWNER'] = $owner;
            }
        }
        unset($r);
    }

    return oracle_normalize_owner_table_rows($rows);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{owner:string, table_name:string}>
 */
function oracle_normalize_owner_table_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'owner' => (string) ($r['OWNER'] ?? $r['owner'] ?? ''),
            'table_name' => (string) ($r['TABLE_NAME'] ?? $r['table_name'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function oracle_preview_table(array $conn, string $owner, string $table, int $limit = 20): array
{
    $owner = strtoupper(trim($owner));
    $table = strtoupper(trim($table));
    $limit = max(1, min(100, $limit));
    // Oracle versions differ: ROWNUM vs FETCH FIRST
    $sql = 'SELECT * FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '" WHERE ROWNUM <= ' . $limit;

    return oracle_query_all($conn, $sql);
}
